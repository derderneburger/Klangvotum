<?php
require_once __DIR__ . '/db.php';

function sv_backup_dir(): string {
  return __DIR__ . '/../backups';
}

function sv_ensure_backup_dir(): void {
  $dir = sv_backup_dir();
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  $ht = $dir . '/.htaccess';
  if (!file_exists($ht)) {
    @file_put_contents($ht, "Deny from all\n");
  }
}

function sv_db_name(PDO $pdo): string {
  $name = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
  if ($name === '') {
    throw new Exception('Konnte den Datenbanknamen nicht ermitteln.');
  }
  return $name;
}

function sv_backup_tables(PDO $pdo): array {
  $tables = [];
  $stmt = $pdo->query('SHOW TABLES');
  while (($table = $stmt->fetchColumn()) !== false) {
    $tables[] = (string)$table;
  }
  natcasesort($tables);
  return array_values($tables);
}

function sv_backup_filename(string $type, string $ext, ?int $ts = null): string {
  $ts = $ts ?? time();
  return 'klangvotum_' . $type . '_backup_' . date('Y-m-d_H-i-s', $ts) . '.' . $ext;
}

function sv_backup_type_from_name(string $filename): ?string {
  if (preg_match('/^klangvotum_(db|code)_backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.(json|zip)$/', $filename, $m)) {
    return $m[1];
  }
  return null;
}

function sv_backup_timestamp_from_name(string $filename): int {
  if (preg_match('/_(\d{4}-\d{2}-\d{2})_(\d{2}-\d{2}-\d{2})\./', $filename, $m)) {
    $dt = $m[1] . ' ' . str_replace('-', ':', $m[2]);
    $ts = strtotime($dt);
    if ($ts !== false) return $ts;
  }
  return 0;
}

function sv_list_backups(): array {
  sv_ensure_backup_dir();
  $dir = sv_backup_dir();
  $items = [];

  foreach (scandir($dir) as $f) {
    if ($f === '.' || $f === '..' || $f === '.htaccess' || sv_str_starts_with($f, '.last_')) continue;
    $path = $dir . '/' . $f;
    if (!is_file($path)) continue;

    $type = sv_backup_type_from_name($f);
    if (!$type) continue;

    $items[] = [
      'name' => $f,
      'path' => $path,
      'type' => $type,
      'size' => (int)@filesize($path),
      'mtime' => (int)@filemtime($path),
      'ts_from_name' => sv_backup_timestamp_from_name($f),
    ];
  }

  usort($items, function(array $a, array $b) {
    $ta = $a['ts_from_name'] ?: $a['mtime'];
    $tb = $b['ts_from_name'] ?: $b['mtime'];
    return $tb <=> $ta;
  });

  return $items;
}

function sv_prune_backups(int $keepPerType = 3): array {
  sv_ensure_backup_dir();
  $all = sv_list_backups();
  $byType = ['db' => [], 'code' => []];
  foreach ($all as $item) {
    $byType[$item['type']][] = $item;
  }

  $deleted = [];
  foreach ($byType as $type => $items) {
    usort($items, function(array $a, array $b) {
      $ta = $a['ts_from_name'] ?: $a['mtime'];
      $tb = $b['ts_from_name'] ?: $b['mtime'];
      return $tb <=> $ta;
    });

    $toDelete = array_slice($items, $keepPerType);
    foreach ($toDelete as $item) {
      if (@unlink($item['path'])) {
        $deleted[] = $item['name'];
      }
    }
  }

  return $deleted;
}

function sv_create_json_backup(PDO $pdo): string {
  sv_ensure_backup_dir();
  $tables = sv_backup_tables($pdo);

  $snapshot = [
    'app' => 'klangvotum',
    'format' => 'json-snapshot-v2',
    'created_at' => date('c'),
    'database' => sv_db_name($pdo),
    'tables' => [],
  ];

  foreach ($tables as $t) {
    $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    $snapshot['tables'][$t] = $rows;
  }

  $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) {
    throw new Exception('Backup konnte nicht als JSON erzeugt werden.');
  }

  $fname = sv_backup_filename('db', 'json');
  $path = sv_backup_dir() . '/' . $fname;
  @file_put_contents($path, $json);

  return $fname;
}

function sv_output_json_backup_download(PDO $pdo): void {
  $fname = sv_create_json_backup($pdo);
  $path = sv_backup_dir() . '/' . $fname;
  $json = (string)@file_get_contents($path);

  header('Content-Type: application/json; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $fname . '"');
  header('Content-Length: ' . strlen($json));
  echo $json;
  exit;
}

function sv_create_code_backup_zip(): string {
  sv_ensure_backup_dir();
  if (!class_exists('ZipArchive')) {
    throw new Exception('ZipArchive ist auf diesem Server nicht verfügbar.');
  }

  $root = realpath(__DIR__ . '/..');
  if (!$root) {
    throw new Exception('Projektverzeichnis konnte nicht ermittelt werden.');
  }

  $fname = sv_backup_filename('code', 'zip');
  $zipPath = sv_backup_dir() . '/' . $fname;

  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new Exception('Konnte ZIP nicht erstellen.');
  }

  $backupDir = realpath(sv_backup_dir());

  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
  );

  foreach ($it as $file) {
    $filePath = $file->getRealPath();
    if (!$filePath) continue;

    if ($backupDir && strpos($filePath, $backupDir) === 0) continue;

    $rel = ltrim(str_replace($root, '', $filePath), DIRECTORY_SEPARATOR);
    $zip->addFile($filePath, $rel);
  }

  $zip->close();
  return $fname;
}

function sv_output_code_backup_download(): void {
  $fname = sv_create_code_backup_zip();
  $zipPath = sv_backup_dir() . '/' . $fname;

  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="' . $fname . '"');
  header('Content-Length: ' . filesize($zipPath));
  readfile($zipPath);
  exit;
}

function sv_restore_from_json(PDO $pdo, array $data): void {
  if (!isset($data['format']) || !in_array($data['format'], ['json-snapshot-v1', 'json-snapshot-v2'], true)) {
    throw new Exception('Unbekanntes Backup-Format.');
  }
  if (!isset($data['tables']) || !is_array($data['tables'])) {
    throw new Exception('Backup ist ungültig (tables fehlt).');
  }

  $currentTables = sv_backup_tables($pdo);
  $backupTables = array_keys($data['tables']);

  if (!$backupTables) {
    throw new Exception('Backup enthält keine Tabellen.');
  }

  foreach ($backupTables as $t) {
    if (!in_array($t, $currentTables, true)) {
      throw new Exception("Tabelle `$t` existiert in der aktuellen Datenbank nicht.");
    }
  }

  $tablesToRestore = array_values(array_intersect($currentTables, $backupTables));

  $pdo->beginTransaction();
  try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

    foreach ($tablesToRestore as $t) {
      $pdo->exec("TRUNCATE TABLE `$t`");
    }

    foreach ($tablesToRestore as $t) {
      $rows = $data['tables'][$t] ?? [];
      if (!is_array($rows) || count($rows) === 0) continue;

      $cols = array_keys($rows[0]);
      if (!$cols) continue;

      $colList = '`' . implode('`,`', $cols) . '`';
      $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
      $sql = "INSERT INTO `$t` ($colList) VALUES $placeholders";
      $stmt = $pdo->prepare($sql);

      foreach ($rows as $r) {
        $vals = [];
        foreach ($cols as $c) {
          $vals[] = $r[$c] ?? null;
        }
        $stmt->execute($vals);
      }
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $e2) {}
    throw $e;
  }
}

function sv_run_daily_login_backup(PDO $pdo, int $userId): array {
  // Tobias / derderneburger
  if ($userId !== 1) {
    return ['ran' => false, 'db' => null, 'code' => null, 'deleted' => []];
  }

  sv_ensure_backup_dir();
  $markerFile = sv_backup_dir() . '/.last_login_backup_date';
  $today = date('Y-m-d');
  $last = is_file($markerFile) ? trim((string)@file_get_contents($markerFile)) : '';

  if ($last === $today) {
    return ['ran' => false, 'db' => null, 'code' => null, 'deleted' => []];
  }

  $dbFile = sv_create_json_backup($pdo);
  $codeFile = sv_create_code_backup_zip();
  @file_put_contents($markerFile, $today);

  $deleted = sv_prune_backups(3);

  return ['ran' => true, 'db' => $dbFile, 'code' => $codeFile, 'deleted' => $deleted];
}
