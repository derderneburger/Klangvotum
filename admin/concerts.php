<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user    = sv_require_chronik();
$pdo     = sv_pdo();
$base    = sv_base_url();
$canEdit = sv_can_edit_chronik($user);
$isAdmin = sv_is_admin($user);

// ── Formular-Helper ────────────────────────────────────────────────────────────
function concertFormHtml(array $con = null, string $action): string {
  $cid  = $con ? (int)$con['id'] : 0;
  $name = htmlspecialchars($con['name']     ?? '', ENT_QUOTES);
  $date = htmlspecialchars($con['date']     ?? '', ENT_QUOTES);
  $loc  = htmlspecialchars($con['location'] ?? '', ENT_QUOTES);
  $note = htmlspecialchars($con['notes']    ?? '', ENT_QUOTES);
  $csrf = htmlspecialchars(sv_csrf_token(), ENT_QUOTES);
  $btn  = $action === 'create' ? 'Anlegen' : 'Speichern';
  $cidF = $cid ? '<input type="hidden" name="cid" value="' . $cid . '">' : '';
  return '<form method="post" class="grid" style="gap:12px">'
    . '<input type="hidden" name="csrf" value="' . $csrf . '">'
    . '<input type="hidden" name="action" value="' . $action . '">'
    . $cidF
    . '<label style="grid-column:1/-1">Name *<br>'
    .   '<input name="name" required value="' . $name . '" placeholder="z.B. Jahreskonzert 2024" style="width:100%;margin-top:5px"></label>'
    . '<label>Datum<br>'
    .   '<input name="date" type="date" value="' . $date . '" style="width:100%;margin-top:5px"></label>'
    . '<label>Ort<br>'
    .   '<input name="location" value="' . $loc . '" placeholder="z.B. Stadthalle Hildesheim" style="width:100%;margin-top:5px"></label>'
    . '<label style="grid-column:1/-1">Notizen<br>'
    .   '<textarea name="notes" rows="2" style="width:100%;margin-top:5px">' . $note . '</textarea></label>'
    . '<div style="grid-column:1/-1;display:flex;gap:8px">'
    .   '<button class="btn primary" type="submit">' . $btn . '</button>'
    .   '<button class="btn" type="button" data-close-dialog>Abbrechen</button>'
    . '</div></form>';
}

// ── POST ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
  sv_csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'create' || $action === 'update') {
    $cid      = (int)($_POST['cid'] ?? 0);
    $name     = trim($_POST['name']     ?? '');
    $date     = trim($_POST['date']     ?? '') !== '' ? $_POST['date'] : null;
    $year     = $date ? (int)date('Y', strtotime($date)) : null;
    $location = trim($_POST['location'] ?? '') ?: null;
    $notes    = trim($_POST['notes']    ?? '') ?: null;
    if ($name === '') {
      sv_flash_set('error', 'Name ist Pflichtfeld.');
    } else {
      try {
        if ($action === 'create') {
          $pdo->prepare("INSERT INTO concerts (name, date, year, location, notes) VALUES (?,?,?,?,?)")
            ->execute([$name, $date, $year, $location, $notes]);
          sv_log($user['id'], 'concert_create', $name);
          sv_flash_set('success', 'Auftritt angelegt.');
        } else {
          $pdo->prepare("UPDATE concerts SET name=?, date=?, year=?, location=?, notes=? WHERE id=?")
            ->execute([$name, $date, $year, $location, $notes, $cid]);
          sv_log($user['id'], 'concert_update', "cid=$cid");
          sv_flash_set('success', 'Auftritt aktualisiert.');
        }
      } catch (Throwable $e) {
        sv_flash_set('error', 'Fehler: ' . $e->getMessage());
      }
    }
    header('Location: ' . $base . '/admin/concerts.php');
    exit;

  } elseif ($action === 'delete') {
    $cid = (int)($_POST['cid'] ?? 0);
    if ($cid) {
      if ($isAdmin) {
        $pdo->prepare("DELETE FROM concerts WHERE id=?")->execute([$cid]);
        sv_log($user['id'], 'concert_delete', "cid=$cid");
        sv_flash_set('success', 'Auftritt endgültig gelöscht.');
      } else {
        $reason = trim($_POST['delete_reason'] ?? '');
        if ($reason === '') {
          sv_flash_set('error', 'Bitte einen Grund für die Löschung angeben.');
        } else {
          $pdo->prepare("UPDATE concerts SET deleted_at=NOW(), deleted_by=?, delete_reason=? WHERE id=?")->execute([$user['id'], $reason, $cid]);
          sv_log($user['id'], 'concert_soft_delete', "cid=$cid reason=$reason");
          sv_flash_set('success', 'Auftritt zur Löschung vorgemerkt — Admin prüft.');
        }
      }
    }
    header('Location: ' . $base . '/admin/concerts.php');
    exit;

  } elseif ($action === 'delete_all') {
    if (!$isAdmin) { http_response_code(403); exit('Forbidden'); }
    $count = (int)$pdo->query("SELECT COUNT(*) FROM concerts")->fetchColumn();
    $pdo->exec("DELETE FROM concert_pieces");
    $pdo->exec("DELETE FROM concerts");
    sv_log($user['id'], 'concert_delete_all', "$count Auftritte gelöscht");
    sv_flash_set('success', "$count Auftritte gelöscht.");
    header('Location: ' . $base . '/admin/concerts.php');
    exit;

  } elseif ($action === 'add_piece') {
    $cid = (int)($_POST['cid'] ?? 0);
    $pid = (int)($_POST['pid'] ?? 0);
    if ($cid && $pid) {
      try {
        $mp = $pdo->prepare("SELECT COALESCE(MAX(position),0) FROM concert_pieces WHERE concert_id=?");
        $mp->execute([$cid]);
        $maxPos = (int)$mp->fetchColumn();
        $pdo->prepare("INSERT IGNORE INTO concert_pieces (concert_id, piece_id, position) VALUES (?,?,?)")
          ->execute([$cid, $pid, $maxPos + 1]);
      } catch (Throwable $e) {}
    }
    header('Location: ' . $base . '/admin/concerts.php?cid=' . $cid);
    exit;

  } elseif ($action === 'remove_piece') {
    $cid = (int)($_POST['cid'] ?? 0);
    $pid = (int)($_POST['pid'] ?? 0);
    if ($cid && $pid) {
      $pdo->prepare("DELETE FROM concert_pieces WHERE concert_id=? AND piece_id=?")->execute([$cid, $pid]);
    }
    header('Location: ' . $base . '/admin/concerts.php?cid=' . $cid);
    exit;

  } elseif ($action === 'csv_import') {
    $file = $_FILES['csv_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
      sv_flash_set('error', 'Keine Datei hochgeladen.');
    } else {
      $text = file_get_contents($file['tmp_name']);
      // BOM entfernen, Zeilenenden normalisieren
      $text = preg_replace('/^ï»¿/', '', $text);
      $lines = preg_split('/
?
/', trim($text));
      $imported = 0; $skipped = 0; $piecesMissed = [];
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        // Semikolon-getrennt
        $cols = array_map('trim', explode(';', $line));
        if (count($cols) < 2) continue;
        $cname    = $cols[0];
        $rawDate  = $cols[1] ?? '';
        // Rückwärtskompatibel: 4-stellig = Jahr, sonst Datum parsen
        if ($rawDate !== '' && preg_match('/^\d{4}$/', $rawDate)) {
          $cdate = null;
          $cyear = (int)$rawDate;
        } elseif ($rawDate !== '') {
          $ts = strtotime(str_replace('.', '-', $rawDate));
          $cdate = $ts ? date('Y-m-d', $ts) : null;
          $cyear = $cdate ? (int)date('Y', $ts) : null;
        } else {
          $cdate = null;
          $cyear = null;
        }
        $cloc     = isset($cols[2]) && $cols[2] !== '' ? $cols[2] : null;
        $titles   = array_slice($cols, 3);
        if ($cname === '') continue;
        // Konzert anlegen oder holen
        $ex = $pdo->prepare("SELECT id FROM concerts WHERE name=?");
        $ex->execute([$cname]);
        $cid = $ex->fetchColumn();
        if (!$cid) {
          $pdo->prepare("INSERT INTO concerts (name, date, year, location) VALUES (?,?,?,?)")
            ->execute([$cname, $cdate, $cyear, $cloc]);
          $cid = (int)$pdo->lastInsertId();
          $imported++;
        } else {
          $pdo->prepare("UPDATE concerts SET date=COALESCE(date,?), year=COALESCE(year,?), location=COALESCE(location,?) WHERE id=?")
            ->execute([$cdate, $cyear, $cloc, $cid]);
          $skipped++;
        }
        // Stücke verknüpfen
        $mp = $pdo->prepare("SELECT COALESCE(MAX(position),0) FROM concert_pieces WHERE concert_id=?");
        $mp->execute([$cid]);
        $pos = (int)$mp->fetchColumn();
        foreach (array_filter($titles) as $title) {
          $sp = $pdo->prepare("SELECT id FROM pieces WHERE title=?");
          $sp->execute([$title]);
          $pid = $sp->fetchColumn();
          if (!$pid) {
            // Ersten Teil bei Schrägstrich
            if (strpos($title, '/') !== false) {
              $sp->execute([trim(explode('/', $title)[0])]);
              $pid = $sp->fetchColumn();
            }
          }
          if ($pid) {
            try {
              $pdo->prepare("INSERT IGNORE INTO concert_pieces (concert_id, piece_id, position) VALUES (?,?,?)")
                ->execute([$cid, $pid, ++$pos]);
            } catch (Throwable $e) {}
          } else {
            $piecesMissed[] = $title;
          }
        }
      }
      $msg = "$imported neu importiert, $skipped aktualisiert.";
      if ($piecesMissed) $msg .= ' Nicht gefunden: ' . implode(', ', array_unique($piecesMissed));
      sv_flash_set('success', $msg);
    }
    header('Location: ' . $base . '/admin/concerts.php');
    exit;

  } elseif ($action === 'reorder_list') {
    // Reorder concert list
    $cids = isset($_POST['cids']) && is_array($_POST['cids']) ? $_POST['cids'] : [];
    if ($cids) {
      $stmt = $pdo->prepare("UPDATE concerts SET sort_order=? WHERE id=?");
      foreach ($cids as $pos => $cid) $stmt->execute([$pos + 1, (int)$cid]);
    }
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;

  } elseif ($action === 'reorder') {
    $cid  = (int)($_POST['cid'] ?? 0);
    $pids = isset($_POST['pids']) && is_array($_POST['pids']) ? $_POST['pids'] : [];
    if ($cid && $pids) {
      $stmt = $pdo->prepare("UPDATE concert_pieces SET position=? WHERE concert_id=? AND piece_id=?");
      foreach ($pids as $pos => $pid) {
        $stmt->execute([$pos + 1, $cid, (int)$pid]);
      }
    }
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
  }
}

// ── Daten laden ────────────────────────────────────────────────────────────────
$search     = trim($_GET['q']   ?? '');
$selectedId = (int)($_GET['cid'] ?? 0);
$exportMode = isset($_GET['export']) && $_GET['export'] === 'html';

$conds  = [];
$params = [];
if (!$isAdmin) {
  $conds[] = "deleted_at IS NULL";
}
if ($search) {
  $conds[] = "(name LIKE ? OR location LIKE ?)";
  $params[] = "%$search%";
  $params[] = "%$search%";
}
$where = $conds ? "WHERE " . implode(" AND ", $conds) : "";
$stmt   = $pdo->prepare("SELECT * FROM concerts $where ORDER BY sort_order ASC, date DESC, year DESC, id DESC");
$stmt->execute($params);
$concerts = $stmt->fetchAll();

// Stückzahlen vorladen
$pieceCounts = [];
if ($concerts) {
  $ids    = implode(',', array_map(function($r){ return (int)$r['id']; }, $concerts));
  $pcRows = $pdo->query("SELECT concert_id, COUNT(*) AS cnt FROM concert_pieces WHERE concert_id IN ($ids) GROUP BY concert_id")->fetchAll();
  foreach ($pcRows as $pc) $pieceCounts[(int)$pc['concert_id']] = (int)$pc['cnt'];
}

// Alle Stücke für Live-Suche
$allPieces = $pdo->query("SELECT id, title, composer, arranger FROM pieces ORDER BY title ASC")->fetchAll();

$selected  = null;
$selPieces = [];

if ($selectedId) {
  $s = $pdo->prepare("SELECT * FROM concerts WHERE id=?");
  $s->execute([$selectedId]);
  $selected = $s->fetch() ?: null;
  if ($selected) {
    $sp = $pdo->prepare("SELECT cp.position, p.id, p.title, p.composer, p.arranger, p.duration, p.genre, p.difficulty, p.youtube_url, p.info, p.querverweis FROM concert_pieces cp JOIN pieces p ON p.id=cp.piece_id WHERE cp.concert_id=? ORDER BY cp.position ASC, p.title ASC");
    $sp->execute([$selectedId]);
    $selPieces = $sp->fetchAll();
  }
}

// ── Export ─────────────────────────────────────────────────────────────────────
// CSV Export alle Events (nur Admin)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  if (!sv_is_admin($user)) { http_response_code(403); exit('Forbidden'); }
  $allC = $pdo->query("SELECT * FROM concerts ORDER BY sort_order ASC, date DESC, year DESC, id DESC")->fetchAll();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="chronik_' . date('Y-m-d') . '.csv"');
  echo "ï»¿";
  echo "Name;Datum;Ort;Stücke
";
  foreach ($allC as $con) {
    $sp2 = $pdo->prepare("SELECT p.title FROM concert_pieces cp JOIN pieces p ON p.id=cp.piece_id WHERE cp.concert_id=? ORDER BY cp.position ASC, p.title ASC");
    $sp2->execute([(int)$con['id']]);
    $titles = array_column($sp2->fetchAll(), 'title');
    $csvDate = !empty($con['date']) ? date('d.m.Y', strtotime($con['date'])) : ($con['year'] ?? '');
    $row = [
      '"'.str_replace('"','""',$con['name']).'"',
      '"'.$csvDate.'"',
      '"'.str_replace('"','""',$con['location']??'').'"',
    ];
    foreach ($titles as $t) $row[] = '"'.str_replace('"','""',$t).'"';
    echo implode(';', $row) . "
";
  }
  exit;
}

// Export alle Events HTML (oder ausgewählte per ?ids=1,2,3)
if (isset($_GET['export']) && $_GET['export'] === 'all') {
  $exportIds = isset($_GET['ids']) ? array_filter(array_map('intval', explode(',', $_GET['ids']))) : [];
  if ($exportIds) {
    $ph = implode(',', array_fill(0, count($exportIds), '?'));
    $st = $pdo->prepare("SELECT * FROM concerts WHERE id IN ($ph) ORDER BY sort_order ASC, date DESC, year DESC, id DESC");
    $st->execute($exportIds);
    $allConcerts = $st->fetchAll();
  } else {
    $allConcerts = $pdo->query("SELECT * FROM concerts ORDER BY sort_order ASC, date DESC, year DESC, id DESC")->fetchAll();
  }
  $allPrograms = [];
  foreach ($allConcerts as $con) {
    $sp2 = $pdo->prepare("SELECT cp.position, p.title, p.composer, p.arranger, p.duration, p.querverweis FROM concert_pieces cp JOIN pieces p ON p.id=cp.piece_id WHERE cp.concert_id=? ORDER BY cp.position ASC, p.title ASC");
    $sp2->execute([(int)$con['id']]);
    $allPrograms[(int)$con['id']] = $sp2->fetchAll();
  }
  $accentRed   = sv_setting_get('color_primary', '#c1090f');
  $accentHover = sv_color_darken($accentRed, 0.15);
?><!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title>Auftrittchronik SBO Hildesheim<?=$exportIds ? ' ('.count($allConcerts).' Auftritte)' : ''?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Georgia',serif;color:#1a1a1a}
h1{font-size:22pt;color:<?=$accentRed?>;margin-bottom:4px}
.section{margin-bottom:32px;page-break-inside:avoid}
h2{font-size:14pt;color:<?=$accentRed?>;margin:0 0 4px 0;border-bottom:2px solid <?=$accentRed?>;padding-bottom:4px}
.meta{color:#888;font-size:10pt;margin-bottom:8px}
table{width:100%;border-collapse:collapse;margin-bottom:4px}
th{text-align:left;padding:5px 8px;background:<?=$accentRed?>;color:#fff;font-size:8.5pt;text-transform:uppercase}
td{padding:4px 8px;border-bottom:1px solid #eee;font-size:9.5pt}
tr:nth-child(even){background:#fafafa}
.num{color:#aaa;text-align:center;width:24px}
.page{max-width:800px;margin:0 auto;padding:0 20px}
@media screen{body{background:#e8e5e0}.page{margin:20px auto;box-shadow:0 4px 40px rgba(0,0,0,.2);background:#fff;padding:30px}.print-btn{display:block;text-align:center;padding:14px;background:<?=$accentRed?>;color:#fff;font-family:sans-serif;font-size:14px;font-weight:700;cursor:pointer;border:none;width:100%;letter-spacing:.04em}.print-btn:hover{background:<?=$accentHover?>}}
@media print{.print-btn{display:none}th{background:none!important;color:#000!important;font-weight:700;border-bottom:2px solid #000}}
</style></head>
<body>
<button class="print-btn" onclick="window.print()">🖨 Drucken / Als PDF speichern</button>
<div class="page">
<h1>Auftrittchronik</h1>
<p style="color:#888;font-size:10pt">SBO Hildesheim · Stand <?=date('d.m.Y')?> · <?=count($allConcerts)?> Auftritte</p>
<?php foreach ($allConcerts as $con): ?>
<?php $prog = $allPrograms[(int)$con['id']] ?? []; ?>
<div class="section">
  <h2><?=htmlspecialchars($con['name'])?></h2>
  <div class="meta">
    <?php $conDate = concertDate($con); ?>
    <?php if($conDate): ?><?=htmlspecialchars($conDate)?><?php endif; ?>
    <?php if($con['location']): ?><?=$conDate?' · ':''?><?=htmlspecialchars($con['location'])?><?php endif; ?>
    · <?=count($prog)?> Stücke
  </div>
  <?php if ($prog): ?>
  <table><thead><tr><th>#</th><th>Titel</th><th>Komponist / Arrangeur</th><th>Länge</th></tr></thead><tbody>
  <?php foreach ($prog as $i => $p): ?>
  <tr>
    <td class="num"><?=$i+1?></td>
    <td><?=htmlspecialchars($p['title'])?><?php if(!empty($p['querverweis'])): ?> <small style="color:#aaa">/ <?=htmlspecialchars($p['querverweis'])?></small><?php endif; ?></td>
    <td style="color:#555"><?=htmlspecialchars($p['composer']??'')?><?php if(!empty($p['arranger'])): ?> / Arr. <?=htmlspecialchars($p['arranger'])?><?php endif; ?></td>
    <td style="white-space:nowrap"><?=htmlspecialchars($p['duration']??'–')?></td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php else: ?><p style="color:#aaa;font-size:9pt">Kein Programm eingetragen.</p><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</body></html>
<?php exit; }

if ($exportMode && $selected) {
  $exportCid  = $selectedId;
  $exportName = $selected['name'];
  $exportDate = concertDate($selected);
  $exportLoc  = $selected['location'] ?? '';
  $totalSec   = 0;
  foreach ($selPieces as $p) {
    $d = trim($p['duration'] ?? '');
    $d = rtrim($d, "'\"");
    if (preg_match('/^(\d+)[:\'.](\d{1,2})$/', $d, $m)) $totalSec += (int)$m[1]*60+(int)$m[2];
    elseif (preg_match('/^\d+$/', $d)) $totalSec += (int)$d * 60;
  }
?><!DOCTYPE html>
<html lang="de">
<head><meta charset="UTF-8"><title><?=htmlspecialchars($exportName)?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Georgia',serif;color:#1a1a1a}
h1{font-size:24pt;color:<?=$accentRed?>;margin-bottom:4px}
.sub{color:#666;font-size:11pt;margin-bottom:20px}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:6px 10px;background:<?=$accentRed?>;color:#fff;font-size:9pt;text-transform:uppercase;letter-spacing:.04em}
td{padding:6px 10px;border-bottom:1px solid #eee;font-size:10pt}
tr:nth-child(even){background:#fafafa}
.num{color:#999;text-align:center;width:30px}
.total{font-weight:700;text-align:right;padding-right:10px}
.page{max-width:700px;margin:0 auto;padding:0 20px}
@media screen{body{background:#e8e5e0}.page{margin:20px auto;box-shadow:0 4px 40px rgba(0,0,0,.2);background:#fff;padding:30px}.print-btn{display:block;text-align:center;padding:14px;background:<?=$accentRed?>;color:#fff;font-family:sans-serif;font-size:14px;font-weight:700;cursor:pointer;border:none;width:100%;letter-spacing:.04em}.print-btn:hover{background:<?=$accentHover?>}}
@media print{.print-btn{display:none}th{background:none!important;color:#000!important;font-weight:700;border-bottom:2px solid #000}}
</style></head>
<body>
<button class="print-btn" onclick="window.print()">🖨 Drucken / Als PDF speichern</button>
<div class="page">
<h1><?=htmlspecialchars($exportName)?></h1>
<div class="sub">
  <?php if($exportDate): ?><?=htmlspecialchars($exportDate)?><?php endif; ?>
  <?php if($exportLoc): ?> · <?=htmlspecialchars($exportLoc)?><?php endif; ?>
  · <?=count($selPieces)?> Stücke
</div>
<table>
<thead><tr><th>#</th><th>Titel</th><th>Komponist / Arrangeur</th><th>Länge</th></tr></thead>
<tbody>
<?php foreach ($selPieces as $i => $p): ?>
<tr>
  <td class="num"><?=$i+1?></td>
  <td><?=htmlspecialchars($p['title'])?><?php if(!empty($p['querverweis'])): ?> <small style="color:#999">/ <?=htmlspecialchars($p['querverweis'])?></small><?php endif; ?></td>
  <td style="color:#555"><?=htmlspecialchars($p['composer']??'')?><?php if(!empty($p['arranger'])): ?><br><small>Arr. <?=htmlspecialchars($p['arranger'])?></small><?php endif; ?></td>
  <td style="white-space:nowrap"><?=htmlspecialchars($p['duration']??'–')?></td>
</tr>
<?php endforeach; ?>
<?php if ($totalSec > 0): ?>
<tr><td></td><td class="total" colspan="2">Gesamtdauer:</td><td style="font-weight:700"><?=floor($totalSec/60)?>:<?=str_pad($totalSec%60,2,'0',STR_PAD_LEFT)?></td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</body></html>
<?php
  exit;
}

// ── JSON für Live-Suche vorbereiten ────────────────────────────────────────────
$allPiecesJson = json_encode(
  array_map(function($ap){ return ['id'=>(int)$ap['id'],'title'=>$ap['title'],'composer'=>$ap['composer']??'','arranger'=>$ap['arranger']??'']; }, $allPieces),
  JSON_UNESCAPED_UNICODE
);
$usedIdsJson = json_encode(array_map('intval', array_column($selPieces, 'id')));

function concertDate(array $c): string {
  if (!empty($c['date'])) return date('d.m.Y', strtotime($c['date']));
  if (!empty($c['year'])) return (string)(int)$c['year'];
  return '';
}

// ── Ausgabe ────────────────────────────────────────────────────────────────────
sv_header('Auftrittchronik', $user);
?>
<div class="page-header">
  <div>
    <h2>Auftrittchronik</h2>
    <div class="muted">Konzerte und Auftritte dokumentieren</div>
  </div>
  <div class="row">
    <?php if ($canEdit): ?>
    <a class="btn primary" href="#" data-open-dialog="dialog-new-concert">+ Neuer Auftritt</a>
    <?php endif; ?>
    <?php if (sv_is_admin($user) && file_exists(__DIR__ . '/concerts_import.php')): ?>
    <a class="btn" href="<?=h($base)?>/admin/concerts_import.php">📥 Importieren</a>
    <?php endif; ?>
    <a class="btn" href="?export=all" target="_blank">📋 Alle exportieren</a>
    <?php if (sv_is_admin($user)): ?>
    <a class="btn" href="?export=csv">📥 CSV Export</a>
    <?php endif; ?>
    <?php if (sv_is_admin($user)): ?>
    <button class="btn" type="button" data-open-dialog="dlg-csv-import">📥 CSV Import</button>
    <?php endif; ?>
    <?php if (sv_is_admin($user)): ?>
    <button class="btn" style="color:var(--red)" type="button" data-open-dialog="dlg-delete-all">Alle löschen</button>
    <?php endif; ?>
    <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start">

  <!-- Liste -->
  <div>
    <div class="card" style="margin-bottom:12px">
      <form method="get" style="display:flex;gap:8px">
        <input class="input" type="search" name="q" value="<?=h($search)?>" placeholder="Suche…" style="flex:1">
        <?php if ($selectedId): ?><input type="hidden" name="cid" value="<?=$selectedId?>"><?php endif; ?>
        <button class="btn primary" type="submit">🔍</button>
        <?php if ($search): ?><a class="btn" href="concerts.php<?=$selectedId?'?cid='.$selectedId:''?>">✕</a><?php endif; ?>
      </form>
    </div>
    <button id="btn-export-sel" class="btn" style="display:none;width:100%;justify-content:center;margin-bottom:8px" onclick="exportSelected()">📄 Auswahl exportieren</button>
    <div class="card" style="padding:0;overflow:hidden">
      <?php if (!$concerts): ?>
        <div style="padding:20px" class="small"><?=$search?'Keine Treffer.':'Noch keine Auftritte angelegt.'?></div>
      <?php endif; ?>
      <?php foreach ($concerts as $c): ?>
        <?php $active = $selectedId===(int)$c['id']; $cIsDeleted = !empty($c['deleted_at']); ?>
        <div class="list-row" data-cid="<?=h($c['id'])?>" style="padding:11px 14px;border-bottom:1px solid var(--border);<?=$active?'background:#f0f5e8;border-left:3px solid var(--green)':''?><?php if($cIsDeleted && !$active): ?>;border-left:3px solid var(--red)<?php endif; ?>;display:flex;align-items:flex-start;gap:6px;flex-wrap:wrap">
          <input type="checkbox" class="export-check" value="<?=h($c['id'])?>" onclick="event.stopPropagation();updateExportBtn()" style="flex-shrink:0;margin-top:3px;cursor:pointer;accent-color:var(--red)">
          <?php if ($canEdit): ?><span style="color:var(--muted);cursor:grab;font-size:16px;flex-shrink:0;margin-top:2px;user-select:none" title="Ziehen zum Sortieren">⠿</span><?php endif; ?>
          <a href="?cid=<?=h($c['id'])?><?=$search?'&q='.urlencode($search):''?>" style="text-decoration:none;flex:1;display:block">
            <div style="font-weight:700;font-size:13px;color:var(--text)"><?=h($c['name'])?></div>
            <?php $cDate = concertDate($c); ?>
            <div class="small" style="color:var(--muted);margin-top:2px">
              <?php if ($cDate): ?><span><?=h($cDate)?></span><?php endif; ?>
              <?php if ($c['location']): ?><span><?=$cDate?' · ':''?><?=h($c['location'])?></span><?php endif; ?>
            </div>
            <?php $cnt = $pieceCounts[(int)$c['id']] ?? 0; ?>
            <?php if ($cnt): ?><div class="small" style="color:var(--green);margin-top:3px">🎵 <?=$cnt?> Stück<?=$cnt!==1?'e':''?></div><?php endif; ?>
          </a>
          <?php if ($canEdit): ?>
          <div style="position:relative;flex-shrink:0">
            <button class="btn" style="padding:3px 8px;font-size:14px" onclick="toggleMenu(this)">⋯</button>
            <div class="piece-menu" style="display:none;position:fixed;background:#fff;border:1.5px solid var(--border);border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.1);z-index:9999;min-width:160px;overflow:hidden">
              <button class="piece-menu-item" type="button" data-open-dialog="dlg-edit-<?=h($c['id'])?>" onclick="closeMenus()">✏️ Bearbeiten</button>
              <?php if ($isAdmin): ?>
              <form method="post" onsubmit="return confirm('Auftritt wirklich löschen?')">
                <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="cid" value="<?=h($c['id'])?>">
                <button class="piece-menu-item" type="submit" style="color:var(--red)">🗑 Löschen</button>
              </form>
              <?php else: ?>
              <button class="piece-menu-item" type="button" data-open-dialog="dlg-softdel-<?=h($c['id'])?>" onclick="closeMenus()">🗑 Löschen</button>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
          <?php if($cIsDeleted): ?><div style="width:100%;order:99"><a href="<?=h($base)?>/admin/geloeschte.php" class="badge" style="background:var(--red-soft);color:var(--red);border-color:rgba(193,9,15,.3);font-size:11px;text-decoration:none">🗑 Löschung vorgemerkt</a></div><?php endif; ?>
        </div>
        <dialog id="dlg-edit-<?=h($c['id'])?>" class="sv-dialog">
          <div class="sv-dialog__panel" tabindex="-1">
            <div class="sv-dialog__head">
              <div class="sv-dialog__title">Auftritt bearbeiten</div>
              <button class="sv-dialog__close" type="button" data-close-dialog>✕</button>
            </div>
            <div class="sv-dialog__section"><?=concertFormHtml($c,'update')?></div>
          </div>
        </dialog>
        <?php if ($canEdit && !$isAdmin): ?>
        <dialog id="dlg-softdel-<?=h($c['id'])?>" class="sv-dialog">
          <div class="sv-dialog__panel" tabindex="-1" style="max-width:440px">
            <div class="sv-dialog__head">
              <div>
                <div class="sv-dialog__title">Auftritt löschen</div>
                <div class="sv-dialog__sub"><?=h($c['name'])?></div>
              </div>
              <button class="sv-dialog__close" type="button" data-close-dialog>✕</button>
            </div>
            <div class="sv-dialog__section">
              <div class="small" style="background:var(--red-soft);border:1px solid rgba(193,9,15,.3);border-radius:8px;padding:8px 12px;margin-bottom:12px;color:var(--red)">
                Dieser Auftritt wird zur Prüfung als gelöscht markiert. Der Admin kann ihn wiederherstellen oder endgültig löschen.
              </div>
              <form method="post">
                <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="cid" value="<?=h($c['id'])?>">
                <label style="display:block;margin-bottom:16px">Grund <span style="color:var(--red)">*</span><br>
                  <input class="input" type="text" name="delete_reason" required placeholder="z.B. Doppelter Eintrag, falsches Datum" style="width:100%;margin-top:5px">
                </label>
                <div style="display:flex;gap:8px;justify-content:flex-end">
                  <button class="btn" type="submit" style="color:var(--red)">🗑 Als gelöscht markieren</button>
                  <button class="btn" type="button" data-close-dialog>Abbrechen</button>
                </div>
              </form>
            </div>
          </div>
        </dialog>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Detail -->
  <div>
    <?php if ($selected): ?>
      <div class="card" style="margin-bottom:12px">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px">
          <div>
            <h3 style="margin-bottom:4px"><?=h($selected['name'])?></h3>
            <?php $selDate = concertDate($selected); ?>
            <div class="small" style="color:var(--muted)">
              <?php if ($selDate): ?><?=h($selDate)?><?php endif; ?>
              <?php if ($selected['location']): ?><?=$selDate?' · ':''?><?=h($selected['location'])?><?php endif; ?>
            </div>
            <?php if ($selected['notes']): ?><div class="small" style="margin-top:5px"><?=nl2br(h($selected['notes']))?></div><?php endif; ?>
            <?php if (!empty($selected['deleted_at'])): ?>
            <div style="margin-top:8px;padding:8px 12px;background:var(--red-soft);border:1.5px solid rgba(193,9,15,.2);border-radius:8px">
              <div class="small" style="color:var(--red)">🗑 Löschung vorgemerkt — <a href="<?=h($base)?>/admin/geloeschte.php" style="color:var(--red);text-decoration:underline">Zur Prüfung →</a></div>
            </div>
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:8px;flex-shrink:0">
            <a class="btn" href="?cid=<?=$selectedId?>&export=html" target="_blank">📄 Export</a>
            <?php if ($canEdit): ?>
            <button class="btn" type="button" data-open-dialog="dlg-edit-<?=$selectedId?>">✏️</button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:12px">
        <h3 style="margin-bottom:12px">🎵 Programm (<?=count($selPieces)?> Stücke)</h3>
        <?php if ($selPieces): ?>
          <?php
            $totalSec = 0;
            foreach ($selPieces as $p) {
              $d = trim($p['duration'] ?? '');
              $d = rtrim($d, "'\"");
              if (preg_match('/^(\d+)[:\'.](\d{1,2})$/', $d, $m)) $totalSec += (int)$m[1]*60+(int)$m[2];
              elseif (preg_match('/^\d+$/', $d)) $totalSec += (int)$d * 60;
            }
          ?>
          <table>
            <thead><tr>
              <?php if ($canEdit): ?><th style="width:28px"></th><?php endif; ?>
              <th>Titel</th>
              <th>Komponist / Arrangeur</th>
              <th style="width:55px">Länge</th>
              <?php if ($canEdit): ?><th style="width:36px"></th><?php endif; ?>
            </tr></thead>
            <tbody id="prog-tbody">
            <?php foreach ($selPieces as $i => $p): ?>
              <tr class="prog-row" data-pid="<?=h($p['id'])?>">
                <?php if ($canEdit): ?><td style="color:var(--muted);text-align:center;cursor:grab;font-size:16px;user-select:none">⠿</td><?php endif; ?>
                <td>
                  <button type="button" onclick="openPieceDetail(this)"
                    style="background:none;border:none;padding:0;cursor:pointer;text-align:left;font-family:inherit;font-size:inherit;line-height:inherit"
                    data-title="<?=h($p['title'])?>"
                    data-composer="<?=h($p['composer']??'')?>"
                    data-arranger="<?=h($p['arranger']??'')?>"
                    data-genre="<?=h($p['genre']??'')?>"
                    data-duration="<?=h($p['duration']??'')?>"
                    data-difficulty="<?=h($p['difficulty']??'')?>"
                    data-youtube="<?=h($p['youtube_url']??'')?>"
                    data-info="<?=h($p['info']??'')?>"
                  ><strong><?=h($p['title'])?></strong><?php if(!empty($p['querverweis'])): ?><span class="small" style="color:var(--muted)"> / <?=h($p['querverweis'])?></span><?php endif; ?></button>
                </td>
                <td class="small" style="color:var(--muted)"><?=h($p['composer']??'')?><?php if(!empty($p['arranger'])): ?><div style="font-size:11px">Arr. <?=h($p['arranger'])?></div><?php endif; ?></td>
                <td class="small" style="white-space:nowrap"><?=h($p['duration']??'–')?></td>
                <?php if ($canEdit): ?>
                <td>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
                    <input type="hidden" name="action" value="remove_piece">
                    <input type="hidden" name="cid" value="<?=$selectedId?>">
                    <input type="hidden" name="pid" value="<?=h($p['id'])?>">
                    <button class="btn" type="submit" style="padding:2px 7px;font-size:12px;color:var(--red)">✕</button>
                  </form>
                </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
            <?php if ($totalSec > 0): ?>
              <tr>
                <td colspan="<?=$canEdit ? 3 : 2?>" style="font-weight:700;text-align:right;padding-right:12px;border-top:2px solid var(--border)">Gesamtdauer:</td>
                <td class="small" style="font-weight:700;border-top:2px solid var(--border)"><?=floor($totalSec/60)?>:<?=str_pad($totalSec%60,2,'0',STR_PAD_LEFT)?></td>
                <?php if ($canEdit): ?><td style="border-top:2px solid var(--border)"></td><?php endif; ?>
              </tr>
            <?php endif; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="small" style="color:var(--muted)">Noch keine Stücke hinzugefügt.</div>
        <?php endif; ?>
      </div>

      <?php if ($canEdit): ?>
      <div class="card">
        <h3 style="margin-bottom:10px">Stück hinzufügen</h3>
        <form method="post" id="add-form" style="display:flex;gap:8px;align-items:flex-end">
          <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
          <input type="hidden" name="action" value="add_piece">
          <input type="hidden" name="cid" value="<?=$selectedId?>">
          <input type="hidden" name="pid" id="add-pid" value="">
          <label style="flex:1;position:relative">
            <input type="text" id="add-search" autocomplete="off" placeholder="Stück suchen…" style="width:100%">
            <div id="add-results" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1.5px solid var(--border);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.1);z-index:9999;max-height:240px;overflow-y:auto;margin-top:3px"></div>
          </label>
          <button class="btn primary" type="submit" id="add-btn" disabled>+ Hinzufügen</button>
        </form>
      </div>
      <?php endif; ?>

    <?php else: ?>
      <div class="card" style="display:flex;align-items:center;justify-content:center;min-height:180px;color:var(--muted)">
        ← Auftritt aus der Liste auswählen
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Neuer Auftritt Dialog -->
<dialog id="dialog-new-concert" class="sv-dialog">
  <div class="sv-dialog__panel" tabindex="-1">
    <div class="sv-dialog__head">
      <div class="sv-dialog__title">Neuer Auftritt</div>
      <button class="sv-dialog__close" type="button" data-close-dialog>✕</button>
    </div>
    <div class="sv-dialog__section"><?=concertFormHtml(null,'create')?></div>
  </div>
</dialog>

<!-- Stück Detail Dialog -->
<dialog id="piece-detail-dlg" class="sv-dialog" style="width:min(500px,calc(100vw - 24px))">
  <div class="sv-dialog__panel" tabindex="-1">
    <div class="sv-dialog__head">
      <div>
        <div class="sv-dialog__title" id="pd-title"></div>
        <div class="sv-dialog__sub" id="pd-sub"></div>
      </div>
      <button class="sv-dialog__close" type="button" data-close-dialog>✕</button>
    </div>
    <div class="sv-dialog__section">
      <div id="pd-info" style="display:grid;grid-template-columns:1fr 1fr;gap:5px 16px;font-size:13px;margin-bottom:8px"></div>
      <div id="pd-yt"></div>
      <div id="pd-note" style="margin-top:8px;font-size:13px;color:var(--muted)"></div>
    </div>
  </div>
</dialog>

<style>
.piece-menu-item{display:block;width:100%;text-align:left;padding:9px 14px;font-size:13px;font-weight:600;background:none;border:none;cursor:pointer;color:var(--text);border-bottom:1px solid var(--border)}
.piece-menu-item:last-child{border-bottom:none}
.piece-menu-item:hover{background:#faf8f5}
.piece-menu form{margin:0}
.prog-row.drag-over td{background:var(--green-light)}
.prog-row.dragging{opacity:.35}
#add-results .ri{padding:9px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border)}
#add-results .ri:last-child{border-bottom:none}
#add-results .ri:hover{background:#faf8f5}
#add-results .ri.used{color:#bbb;cursor:default}
</style>
<script>
// Export-Auswahl
function updateExportBtn() {
  var checks = document.querySelectorAll('.export-check:checked');
  var btn = document.getElementById('btn-export-sel');
  if (checks.length > 0) {
    btn.style.display = 'inline-flex';
    btn.textContent = '📄 ' + checks.length + ' Event' + (checks.length !== 1 ? 's' : '') + ' exportieren';
  } else {
    btn.style.display = 'none';
  }
}
function exportSelected() {
  var ids = Array.from(document.querySelectorAll('.export-check:checked')).map(function(c){ return c.value; });
  if (ids.length) window.open('?export=all&ids=' + ids.join(','), '_blank');
}

// Dialoge
document.addEventListener('click', function(e) {
  var o = e.target.closest('[data-open-dialog]');
  if (o) { var d = document.getElementById(o.getAttribute('data-open-dialog')); if (d) d.showModal(); return; }
  var cl = e.target.closest('[data-close-dialog]');
  if (cl) { var d2 = cl.closest('dialog'); if (d2) d2.close(); }
});

// Menü
function toggleMenu(btn) {
  var menu = btn.nextElementSibling;
  var open = menu.style.display !== 'none';
  closeMenus();
  if (!open) {
    menu.style.display = 'block';
    var r = btn.getBoundingClientRect(), mh = menu.offsetHeight || 100;
    menu.style.top   = (window.innerHeight - r.bottom < mh+8) ? (r.top-mh-4)+'px' : (r.bottom+4)+'px';
    menu.style.right = (window.innerWidth - r.right)+'px';
    setTimeout(function(){ document.addEventListener('click', closeOnOutside); }, 0);
  }
}
function closeMenus() {
  document.querySelectorAll('.piece-menu').forEach(function(m){ m.style.display='none'; });
  document.removeEventListener('click', closeOnOutside);
}
function closeOnOutside(e) {
  if (!e.target.closest('.piece-menu') && !e.target.closest('[onclick*="toggleMenu"]')) closeMenus();
}

// Drag & Drop Reihenfolge
var dragRow = null;
document.querySelectorAll('.prog-row').forEach(function(row) {
  row.setAttribute('draggable', 'true');
  row.addEventListener('dragstart', function(e) { dragRow = row; row.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
  row.addEventListener('dragend',   function()  { row.classList.remove('dragging'); document.querySelectorAll('.prog-row').forEach(function(r){ r.classList.remove('drag-over'); }); saveOrder(); });
  row.addEventListener('dragover',  function(e) { e.preventDefault(); document.querySelectorAll('.prog-row').forEach(function(r){ r.classList.remove('drag-over'); }); row.classList.add('drag-over'); });
  row.addEventListener('drop',      function(e) {
    e.preventDefault();
    if (dragRow && dragRow !== row) {
      var rows = Array.from(row.closest('tbody').querySelectorAll('.prog-row'));
      if (rows.indexOf(dragRow) < rows.indexOf(row)) row.after(dragRow); else row.before(dragRow);
    }
  });
});

function saveOrder() {
  var rows = document.querySelectorAll('.prog-row');
  if (!rows.length) return;
  var pids = Array.from(rows).map(function(r){ return r.dataset.pid; });
  var body = 'action=reorder&csrf='+encodeURIComponent('<?=addslashes(sv_csrf_token())?>')+'&cid=<?=$selectedId?>';
  pids.forEach(function(pid){ body += '&pids[]='+pid; });
  fetch('concerts.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body });
}

// Live Suche
var allPieces  = <?=$allPiecesJson?>;
var usedIds    = <?=$usedIdsJson?>;
var addSearch  = document.getElementById('add-search');
var addResults = document.getElementById('add-results');
var addPid     = document.getElementById('add-pid');
var addBtn     = document.getElementById('add-btn');

if (addSearch) {
  addSearch.addEventListener('input', function() {
    var q = this.value.trim().toLowerCase();
    addResults.innerHTML = '';
    addPid.value = '';
    if (addBtn) addBtn.disabled = true;
    if (!q) { addResults.style.display = 'none'; return; }
    var matches = allPieces.filter(function(p){
      return p.title.toLowerCase().indexOf(q) !== -1 || (p.composer||'').toLowerCase().indexOf(q) !== -1 || (p.arranger||'').toLowerCase().indexOf(q) !== -1;
    }).slice(0, 12);
    if (!matches.length) { addResults.style.display = 'none'; return; }
    matches.forEach(function(p) {
      var div = document.createElement('div');
      var used = usedIds.indexOf(p.id) !== -1;
      div.className = 'ri' + (used ? ' used' : '');
      var info = [];
      if (p.composer) info.push(p.composer);
      if (p.arranger) info.push('Arr. ' + p.arranger);
      div.textContent = p.title + (info.length ? ' (' + info.join(', ') + ')' : '') + (used ? ' ✓' : '');
      if (!used) {
        div.addEventListener('click', function() {
          addPid.value = p.id;
          var selInfo = [];
          if (p.composer) selInfo.push(p.composer);
          if (p.arranger) selInfo.push('Arr. ' + p.arranger);
          addSearch.value = p.title + (selInfo.length ? ' (' + selInfo.join(', ') + ')' : '');
          addResults.style.display = 'none';
          if (addBtn) addBtn.disabled = false;
        });
      }
      addResults.appendChild(div);
    });
    addResults.style.display = 'block';
  });
  document.addEventListener('click', function(e) {
    if (addSearch && !addSearch.contains(e.target) && addResults && !addResults.contains(e.target)) {
      addResults.style.display = 'none';
    }
  });
}

// Stück Detail
function openPieceDetail(btn) {
  var dlg = document.getElementById('piece-detail-dlg');
  if (!dlg) return;
  document.getElementById('pd-title').textContent = btn.dataset.title || '';
  var sub = [];
  if (btn.dataset.composer) sub.push(btn.dataset.composer);
  if (btn.dataset.arranger) sub.push('Arr. ' + btn.dataset.arranger);
  document.getElementById('pd-sub').textContent = sub.join(' · ');
  var info = document.getElementById('pd-info');
  info.innerHTML = '';
  var fields = [['Genre', btn.dataset.genre], ['Länge', btn.dataset.duration], ['Grad', btn.dataset.difficulty ? parseFloat(btn.dataset.difficulty).toFixed(1) : '']];
  fields.forEach(function(f) {
    if (!f[1]) return;
    var d = document.createElement('div');
    d.innerHTML = '<span style="color:var(--muted)">' + f[0] + ':</span> <strong>' + escH(f[1]) + '</strong>';
    info.appendChild(d);
  });
  document.getElementById('pd-yt').innerHTML = btn.dataset.youtube
    ? '<a class="song-link" href="' + escH(btn.dataset.youtube) + '" target="_blank" rel="noopener">▶ YouTube öffnen</a>' : '';
  document.getElementById('pd-note').textContent = btn.dataset.info || '';
  dlg.showModal();
}

function escH(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

// ── Liste Drag & Drop ─────────────────────────────────────────────────────────
var listDragRow = null;
var listContainer = document.querySelector('.card[style*="padding:0"]');
document.querySelectorAll('.list-row').forEach(function(row) {
  row.setAttribute('draggable', 'true');
  row.addEventListener('dragstart', function(e) { listDragRow = row; row.style.opacity = '.4'; e.dataTransfer.effectAllowed = 'move'; });
  row.addEventListener('dragend',   function()  { row.style.opacity = ''; document.querySelectorAll('.list-row').forEach(function(r){ r.style.background=''; }); saveListOrder(); });
  row.addEventListener('dragover',  function(e) { e.preventDefault(); document.querySelectorAll('.list-row').forEach(function(r){ r.style.outline=''; }); row.style.outline = '2px solid var(--green)'; });
  row.addEventListener('dragleave', function()  { row.style.outline = ''; });
  row.addEventListener('drop',      function(e) {
    e.preventDefault(); row.style.outline = '';
    if (listDragRow && listDragRow !== row) {
      var all = Array.from(document.querySelectorAll('.list-row'));
      if (all.indexOf(listDragRow) < all.indexOf(row)) row.after(listDragRow); else row.before(listDragRow);
    }
  });
});

function saveListOrder() {
  var rows = document.querySelectorAll('.list-row');
  if (!rows.length) return;
  var cids = Array.from(rows).map(function(r){ return r.dataset.cid; });
  var csrf = '<?=addslashes(sv_csrf_token())?>';
  var body = 'action=reorder_list&csrf='+encodeURIComponent(csrf);
  cids.forEach(function(cid){ body += '&cids[]='+cid; });
  fetch('concerts.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body });
}
</script>
<!-- CSV Import Dialog -->
<dialog id="dlg-csv-import" class="sv-dialog" style="width:min(620px,calc(100vw - 24px))">
  <div class="sv-dialog__panel" tabindex="-1">
    <div class="sv-dialog__head">
      <div class="sv-dialog__title">CSV Import</div>
      <button class="sv-dialog__close" type="button" data-close-dialog>✕</button>
    </div>
    <div class="sv-dialog__section">
      <div style="background:#f5f2ee;border-radius:10px;padding:12px 14px;font-size:13px;margin-bottom:14px">
        <div style="font-weight:700;margin-bottom:6px">📋 CSV-Format (Semikolon-getrennt):</div>
        <code style="font-size:12px;line-height:1.7;display:block">
          Name;Datum;Ort;Stück1;Stück2;Stück3;...<br>
          Jahreskonzert 2024;05.04.2024;Stadthalle Hildesheim;Nordische Fahrt;Halleluja;Persis<br>
          Weihnachtskonzert 2023;2023;Marktkirche;Jingle Bells;O Holy Night
        </code>
        <div style="margin-top:8px;color:var(--muted)">
          · Erste Spalte = Name (Pflicht) · Zweite = Datum (dd.mm.yyyy) oder Jahr · Dritte = Ort (optional)<br>
          · Ab Spalte 4 = Stücktitel exakt wie in der Bibliothek<br>
          · Zeilen mit # am Anfang werden übersprungen (Kommentare)<br>
          · Bereits vorhandene Konzerte werden nicht überschrieben, nur neue Stücke ergänzt
        </div>
      </div>
      <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:flex-end">
        <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
        <input type="hidden" name="action" value="csv_import">
        <label style="flex:1">CSV-Datei wählen<br>
          <input type="file" name="csv_file" accept=".csv,.txt" required style="margin-top:5px;width:100%">
        </label>
        <button class="btn primary" type="submit">Importieren</button>
      </form>
    </div>
  </div>
</dialog>

<?php if (sv_is_admin($user)): ?>
<!-- Alle löschen Dialog -->
<dialog id="dlg-delete-all" class="sv-dialog">
  <div class="sv-dialog__panel" tabindex="-1">
    <div class="sv-dialog__head">
      <div class="sv-dialog__title">Alle Auftritte löschen</div>
      <button class="sv-dialog__close" type="button" data-close-dialog>✕</button>
    </div>
    <div class="sv-dialog__section">
      <div style="background:var(--red-soft);border:1px solid rgba(193,9,15,.2);border-radius:10px;padding:14px;margin-bottom:14px;font-size:13px">
        <strong style="color:var(--red)">Achtung:</strong> Dies löscht <strong>alle <?=(int)$pdo->query("SELECT COUNT(*) FROM concerts")->fetchColumn()?> Auftritte</strong> und deren Programmzuordnungen unwiderruflich.
        <div style="margin-top:6px;color:var(--muted)">Die Stücke in der Bibliothek bleiben erhalten.</div>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
        <input type="hidden" name="action" value="delete_all">
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn" data-close-dialog>Abbrechen</button>
          <button type="submit" class="btn" style="background:var(--red);color:#fff">Ja, alle löschen</button>
        </div>
      </form>
    </div>
  </div>
</dialog>
<?php endif; ?>

<?php sv_footer(); ?>
