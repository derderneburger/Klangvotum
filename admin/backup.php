<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/backup_helper.php';

$u    = sv_require_admin();
$pdo  = sv_pdo();
$base = sv_base_url();

$err = '';
$msg = '';
$tables = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'download_db_json') {
    try {
      $tables = sv_backup_tables($pdo);
      sv_output_json_backup_download($pdo);
      exit;
    } catch (Exception $e) {
      $err = 'Backup fehlgeschlagen: ' . $e->getMessage();
    }
  } elseif ($action === 'download_code_zip') {
    try {
      sv_output_code_backup_download();
      exit;
    } catch (Exception $e) {
      $err = 'Code-Backup fehlgeschlagen: ' . $e->getMessage();
    }
  } elseif ($action === 'restore_db_json') {
    try {
      $file = $_FILES['backup_file'] ?? null;
      if (!$file || $file['error'] !== UPLOAD_ERR_OK) throw new Exception('Keine Datei hochgeladen.');
      $json = file_get_contents($file['tmp_name']);
      $data = json_decode($json, true);
      if (!$data) throw new Exception('Ungültige JSON-Datei.');
      sv_restore_from_json($pdo, $data);
      $msg = 'Restore erfolgreich.';
    } catch (Exception $e) {
      $err = 'Restore fehlgeschlagen: ' . $e->getMessage();
    }
  }
}

try {
  $tables  = sv_backup_tables($pdo);
  $history = sv_list_backups();
} catch (Exception $e) {
  $history = [];
}

sv_header('Admin – Backup', $u);
?>

<div class="page-header">
  <div>
    <h2>Backup</h2>
    <div class="muted">Datenbank und Code sichern oder wiederherstellen.</div>
  </div>
  <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
</div>

<?php if ($err): ?>
  <div class="notice error" style="margin-bottom:12px"><?=h($err)?></div>
<?php endif; ?>
<?php if ($msg): ?>
  <div class="notice success" style="margin-bottom:12px"><?=h($msg)?></div>
<?php endif; ?>

<!-- Backup erstellen -->
<div class="card" style="margin-bottom:12px">
  <h3>Backup erstellen</h3>
  <div class="small" style="margin-bottom:14px">
    <strong>DB-Backup</strong> sichert alle Tabellen als JSON.
    <strong>Code-Backup</strong> ist ein ZIP der PHP/CSS-Dateien.<br>
    <strong>Automatik:</strong> Beim ersten Login eines Tages wird automatisch gesichert. Es bleiben je die letzten 3 DB- und 3 Code-Backups.
  </div>
  <?php if ($tables): ?>
    <div class="small" style="margin-bottom:12px">Erkannte Tabellen: <?=h(implode(', ', $tables))?></div>
  <?php endif; ?>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
      <input type="hidden" name="action" value="download_db_json">
      <button class="btn primary" type="submit">💾 DB Backup (JSON)</button>
    </form>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
      <input type="hidden" name="action" value="download_code_zip">
      <button class="btn" type="submit">📦 Code Backup (ZIP)</button>
    </form>
  </div>
</div>

<!-- Wiederherstellen -->
<div class="card" style="margin-bottom:12px">
  <h3>Backup wiederherstellen</h3>
  <div class="small" style="margin-bottom:12px">Achtung: Restore überschreibt den kompletten Datenbestand der enthaltenen Tabellen.</div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
    <input type="hidden" name="action" value="restore_db_json">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <input class="input" type="file" name="backup_file" accept="application/json,.json" required style="flex:1;min-width:200px">
      <button class="btn danger" type="submit" onclick="return confirm('Wirklich wiederherstellen? Aktuelle Daten werden überschrieben.');">↩ DB Restore</button>
    </div>
  </form>
</div>

<!-- Backup-Historie -->
<div class="card">
  <h3>Backup-Historie</h3>
  <div class="small" style="margin-bottom:12px">Neueste zuerst. Automatisch nur die letzten 3 DB- und 3 Code-Backups.</div>
  <?php if ($history): ?>
    <div class="table-scroll">
      <table>
        <thead>
          <tr><th>Typ</th><th>Datei</th><th>Größe</th><th>Datum</th></tr>
        </thead>
        <tbody>
          <?php foreach ($history as $item): ?>
            <tr>
              <td><span class="badge"><?= $item['type'] === 'db' ? 'DB' : 'Code' ?></span></td>
              <td class="small"><?=h($item['name'])?></td>
              <td class="small" style="white-space:nowrap"><?=h(number_format($item['size'] / 1024, 1, ',', '.'))?> KB</td>
              <td class="small" style="white-space:nowrap"><?=h(date('d.m.Y H:i', $item['mtime']))?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="small">Noch keine Backups vorhanden.</div>
  <?php endif; ?>
</div>

<?php sv_footer(); ?>
