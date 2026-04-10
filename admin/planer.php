<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$admin = sv_require_admin();
$pdo   = sv_pdo();
$base  = sv_base_url();

// ── POST-Handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $action = $_POST['action'] ?? '';

  // ── Plan erstellen ──
  if ($action === 'create_plan') {
    $name    = trim($_POST['name'] ?? '');
    $variant = trim($_POST['variant'] ?? 'A') ?: 'A';
    if ($name === '') {
      sv_flash_set('error', 'Name ist erforderlich.');
      header('Location: ' . $base . '/admin/planer.php');
      exit;
    }
    $stmt = $pdo->prepare("INSERT INTO concert_plans (name, variant, user_id) VALUES (?, ?, ?)");
    $stmt->execute([$name, $variant, $admin['id']]);
    $newId = $pdo->lastInsertId();
    header('Location: ' . $base . '/admin/planer.php?plan_id=' . $newId);
    exit;

  // ── Plan loeschen ──
  } elseif ($action === 'delete_plan') {
    $planId = (int)($_POST['plan_id'] ?? 0);
    if ($planId) {
      $stmt = $pdo->prepare("DELETE FROM concert_plans WHERE id = ?");
      $stmt->execute([$planId]);
    }
    header('Location: ' . $base . '/admin/planer.php');
    exit;

  // ── Variante duplizieren ──
  } elseif ($action === 'duplicate_plan') {
    $planId     = (int)($_POST['plan_id'] ?? 0);
    $newVariant = trim($_POST['new_variant'] ?? 'B') ?: 'B';
    if ($planId) {
      $old = $pdo->prepare("SELECT * FROM concert_plans WHERE id = ?");
      $old->execute([$planId]);
      $old = $old->fetch();
      if ($old) {
        $stmt = $pdo->prepare("INSERT INTO concert_plans (name, variant, user_id) VALUES (?, ?, ?)");
        $stmt->execute([$old['name'], $newVariant, $admin['id']]);
        $newId = $pdo->lastInsertId();
        // Items kopieren
        $items = $pdo->prepare("SELECT * FROM concert_plan_items WHERE plan_id = ? ORDER BY position ASC");
        $items->execute([$planId]);
        $ins = $pdo->prepare("INSERT INTO concert_plan_items (plan_id, position, item_type, piece_id, source, label, duration_override) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($items as $it) {
          $ins->execute([$newId, $it['position'], $it['item_type'], $it['piece_id'], $it['source'], $it['label'], $it['duration_override']]);
        }
        header('Location: ' . $base . '/admin/planer.php?plan_id=' . $newId);
        exit;
      }
    }
    header('Location: ' . $base . '/admin/planer.php');
    exit;

  // ── Item hinzufuegen (AJAX) ──
  } elseif ($action === 'add_item') {
    $planId   = (int)($_POST['plan_id'] ?? 0);
    $type     = $_POST['item_type'] ?? 'piece';
    $pieceId  = ($_POST['piece_id'] ?? '') !== '' ? (int)$_POST['piece_id'] : null;
    $source   = $_POST['source'] ?? null;
    $label    = trim($_POST['label'] ?? '');
    $durOvr   = trim($_POST['duration_override'] ?? '');
    $afterPos = isset($_POST['after_position']) ? (int)$_POST['after_position'] : null;

    if (!$planId) { header('Content-Type: application/json'); echo '{"ok":false,"error":"no plan"}'; exit; }

    // Halftime-Check: max 1
    if ($type === 'halftime') {
      $ht = $pdo->prepare("SELECT COUNT(*) FROM concert_plan_items WHERE plan_id=? AND item_type='halftime'");
      $ht->execute([$planId]);
      if ((int)$ht->fetchColumn() > 0) {
        header('Content-Type: application/json');
        echo '{"ok":false,"error":"halftime_exists"}';
        exit;
      }
    }

    // Position bestimmen
    if ($afterPos !== null) {
      // Nachfolgende Positionen verschieben
      $pdo->prepare("UPDATE concert_plan_items SET position = position + 1 WHERE plan_id = ? AND position > ?")->execute([$planId, $afterPos]);
      $newPos = $afterPos + 1;
    } else {
      $mx = $pdo->prepare("SELECT COALESCE(MAX(position),0) FROM concert_plan_items WHERE plan_id=?");
      $mx->execute([$planId]);
      $newPos = (int)$mx->fetchColumn() + 1;
    }

    $ins = $pdo->prepare("INSERT INTO concert_plan_items (plan_id, position, item_type, piece_id, source, label, duration_override) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([$planId, $newPos, $type, $pieceId, $source, $label ?: null, $durOvr ?: null]);
    $itemId = $pdo->lastInsertId();

    // Touch plan
    $pdo->prepare("UPDATE concert_plans SET updated_at = NOW() WHERE id = ?")->execute([$planId]);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'item_id' => (int)$itemId, 'position' => $newPos]);
    exit;

  // ── Item entfernen (AJAX) ──
  } elseif ($action === 'remove_item') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $planId = (int)($_POST['plan_id'] ?? 0);
    if ($itemId) {
      $pdo->prepare("DELETE FROM concert_plan_items WHERE id = ? AND plan_id = ?")->execute([$itemId, $planId]);
      // Re-sequence
      $rows = $pdo->prepare("SELECT id FROM concert_plan_items WHERE plan_id = ? ORDER BY position ASC");
      $rows->execute([$planId]);
      $upd = $pdo->prepare("UPDATE concert_plan_items SET position = ? WHERE id = ?");
      $pos = 1;
      foreach ($rows as $r) { $upd->execute([$pos++, $r['id']]); }
      $pdo->prepare("UPDATE concert_plans SET updated_at = NOW() WHERE id = ?")->execute([$planId]);
    }
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;

  // ── Reihenfolge aendern (AJAX) ──
  } elseif ($action === 'reorder') {
    $planId  = (int)($_POST['plan_id'] ?? 0);
    $itemIds = isset($_POST['item_ids']) && is_array($_POST['item_ids']) ? $_POST['item_ids'] : [];
    if ($planId && $itemIds) {
      $upd = $pdo->prepare("UPDATE concert_plan_items SET position = ? WHERE id = ? AND plan_id = ?");
      foreach ($itemIds as $pos => $id) {
        $upd->execute([$pos + 1, (int)$id, $planId]);
      }
      $pdo->prepare("UPDATE concert_plans SET updated_at = NOW() WHERE id = ?")->execute([$planId]);
    }
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;

  // ── Block bearbeiten (AJAX) ──
  } elseif ($action === 'update_item') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    $label  = trim($_POST['label'] ?? '');
    $durOvr = trim($_POST['duration_override'] ?? '');
    if ($itemId) {
      $pdo->prepare("UPDATE concert_plan_items SET label = ?, duration_override = ? WHERE id = ?")->execute([$label ?: null, $durOvr ?: null, $itemId]);
    }
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
  }
}

// ── Daten laden ───────────────────────────────────────────────────────────────
$planId = (int)($_GET['plan_id'] ?? 0);

// Alle Plaene
try {
  $plans = $pdo->query("SELECT id, name, variant, updated_at FROM concert_plans ORDER BY name ASC, variant ASC")->fetchAll();
} catch (Throwable $e) {
  $plans = [];
}

// Aktueller Plan + Items
$currentPlan = null;
$planItems   = [];
if ($planId) {
  $stmt = $pdo->prepare("SELECT * FROM concert_plans WHERE id = ?");
  $stmt->execute([$planId]);
  $currentPlan = $stmt->fetch();
  if ($currentPlan) {
    $stmt = $pdo->prepare("
      SELECT cpi.*,
             CASE WHEN cpi.source='song'  THEN s.title    ELSE p.title    END AS piece_title,
             CASE WHEN cpi.source='song'  THEN s.composer ELSE p.composer END AS piece_composer,
             CASE WHEN cpi.source='song'  THEN s.arranger ELSE p.arranger END AS piece_arranger,
             CASE WHEN cpi.source='song'  THEN s.duration ELSE p.duration END AS piece_duration
      FROM concert_plan_items cpi
      LEFT JOIN songs  s ON cpi.source='song'  AND cpi.piece_id = s.id
      LEFT JOIN pieces p ON cpi.source='piece' AND cpi.piece_id = p.id
      WHERE cpi.plan_id = ?
      ORDER BY cpi.position ASC
    ");
    $stmt->execute([$planId]);
    $planItems = $stmt->fetchAll();
  } else {
    $planId = 0;
  }
}

// ── Druckansicht ──────────────────────────────────────────────────────────────
if ($planId && $currentPlan && isset($_GET['export']) && $_GET['export'] === 'print') {
  $cfg = sv_config();
  $org = $cfg['branding']['org_name'] ?? 'SBO Hildesheim';
  $logoPath = __DIR__ . '/../assets/logo.svg';
  $logoData = file_exists($logoPath) ? base64_encode(file_get_contents($logoPath)) : '';
  $logoSrc  = $logoData ? 'data:image/svg+xml;base64,' . $logoData : '';

  // Zeiten berechnen
  $firstSec = 0; $secondSec = 0; $pieceCount = 0; $inSecond = false;
  foreach ($planItems as $it) {
    if ($it['item_type'] === 'halftime') { $inSecond = true; continue; }
    $dur = $it['item_type'] === 'block' ? ($it['duration_override'] ?? '') : ($it['piece_duration'] ?? '');
    $sec = 0;
    if (preg_match("/^(\d+)[:'](\\d{1,2})/", $dur, $dm)) $sec = (int)$dm[1]*60 + (int)$dm[2];
    elseif (preg_match('/^(\d+)$/', $dur, $dm)) $sec = (int)$dm[1]*60;
    if ($inSecond) $secondSec += $sec; else $firstSec += $sec;
    if ($it['item_type'] === 'piece') $pieceCount++;
  }
  $totalSec = $firstSec + $secondSec;
  $hasHalftime = false;
  foreach ($planItems as $it) { if ($it['item_type'] === 'halftime') { $hasHalftime = true; break; } }

  $fmtDur = function(int $s): string { return floor($s/60) . ':' . str_pad($s%60, 2, '0', STR_PAD_LEFT); };

  header('Content-Type: text/html; charset=utf-8');
  $accentRed   = sv_setting_get('color_primary', '#c1090f');
  $accentHover = sv_color_darken($accentRed, 0.15);
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Konzertplan: <?=h($currentPlan['name'])?> – Variante <?=h($currentPlan['variant'])?></title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: 'Georgia', serif; font-size: 11pt; color: #1a1a1a; background: #fff; }
  .page { width:210mm; min-height:297mm; margin:0 auto; padding:18mm 18mm 20mm 18mm; background:#fff; }
  .header { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; margin-bottom:10mm; padding-bottom:6mm; border-bottom:2px solid <?=$accentRed?>; }
  .header-text h1 { font-size:20pt; font-weight:700; color:<?=$accentRed?>; line-height:1.1; margin-bottom:3px; }
  .header-text .subtitle { font-size:10pt; color:#666; }
  .header-logo img { height:28mm; width:auto; }
  .meta { display:flex; justify-content:space-between; font-size:9pt; color:#888; margin-bottom:7mm; }
  table { width:100%; border-collapse:collapse; }
  thead tr { background:<?=$accentRed?>; color:#fff; }
  thead th { padding:5px 8px; text-align:left; font-size:8.5pt; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
  tbody tr { border-bottom:.5px solid #e0e0e0; }
  tbody tr:nth-child(even) { background:#fafafa; }
  td { padding:5px 8px; vertical-align:top; font-size:9.5pt; line-height:1.35; }
  td.nr { text-align:center; font-size:8.5pt; color:#555; width:8mm; }
  td.title { font-weight:600; }
  td.dur { text-align:right; white-space:nowrap; font-size:9pt; }
  .arr { font-size:8pt; color:#888; display:block; }
  .halftime-row td { text-align:center; font-weight:700; font-size:10pt; letter-spacing:.05em; border-top:2px solid #333; border-bottom:2px solid #333; padding:8px; background:#f5f2ee; }
  .block-row td { color:#666; font-style:italic; }
  .time-summary { margin-top:8mm; padding:10px 14px; border:1.5px solid <?=$accentRed?>; border-radius:8px; font-size:10pt; }
  .time-summary strong { color:<?=$accentRed?>; }
  .time-grid { display:flex; gap:24px; flex-wrap:wrap; }
  .time-item { display:flex; flex-direction:column; }
  .time-label { font-size:8pt; text-transform:uppercase; letter-spacing:.05em; color:#888; }
  .time-val { font-size:14pt; font-weight:700; }
  .footer { margin-top:8mm; padding-top:4mm; border-top:1px solid #ddd; font-size:8pt; color:#aaa; text-align:center; }
  @media screen {
    body { background:#e8e5e0; }
    .page { margin:20px auto; box-shadow:0 4px 40px rgba(0,0,0,.2); }
    .print-btn { display:block; text-align:center; padding:14px; background:<?=$accentRed?>; color:#fff; font-family:sans-serif; font-size:14px; font-weight:700; cursor:pointer; border:none; width:100%; letter-spacing:.04em; }
    .print-btn:hover { background:<?=$accentHover?>; }
  }
  @media print {
    body { background:#fff; }
    .page { width:100%; margin:0; padding:15mm; box-shadow:none; }
    .print-btn { display:none; }
    thead { display:table-header-group; }
    tr { page-break-inside:avoid; }
    th { background:none!important; color:#000!important; font-weight:700; border-bottom:2px solid #000; }
  }
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()">Drucken / Als PDF speichern</button>
<div class="page">
  <div class="header">
    <div class="header-text">
      <h1><?=h($currentPlan['name'])?></h1>
      <div class="subtitle">Variante <?=h($currentPlan['variant'])?> · <?=h($org)?></div>
    </div>
    <?php if ($logoSrc): ?><div class="header-logo"><img src="<?=$logoSrc?>" alt="Logo"></div><?php endif; ?>
  </div>
  <div class="meta">
    <span><?=$pieceCount?> Stück<?= $pieceCount !== 1 ? 'e' : '' ?></span>
    <span>Stand: <?=date('d.m.Y')?></span>
  </div>
  <table>
    <thead><tr><th style="width:8mm">#</th><th>Titel</th><th>Komponist / Arrangeur</th><th style="text-align:right">Dauer</th></tr></thead>
    <tbody>
    <?php $nr = 1; foreach ($planItems as $it): ?>
      <?php if ($it['item_type'] === 'halftime'): ?>
        <tr class="halftime-row"><td colspan="4">Halbzeit</td></tr>
      <?php elseif ($it['item_type'] === 'block'): ?>
        <tr class="block-row">
          <td class="nr"><?=$nr++?></td>
          <td class="title"><?=h($it['label'] ?? 'Block')?></td>
          <td>–</td>
          <td class="dur"><?=h($it['duration_override'] ?? '–')?></td>
        </tr>
      <?php else: ?>
        <tr>
          <td class="nr"><?=$nr++?></td>
          <td class="title"><?=h($it['piece_title'] ?? '–')?></td>
          <td>
            <?=h($it['piece_composer'] ?? '–')?>
            <?php if (!empty($it['piece_arranger'])): ?><span class="arr">Arr. <?=h($it['piece_arranger'])?></span><?php endif; ?>
          </td>
          <td class="dur"><?=h($it['piece_duration'] ?? '–')?></td>
        </tr>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$planItems): ?><tr><td colspan="4" style="text-align:center;padding:20px;color:#aaa">Keine Einträge.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <div class="time-summary">
    <div class="time-grid">
      <?php if ($hasHalftime): ?>
        <div class="time-item"><span class="time-label">1. Hälfte</span><span class="time-val"><?=$fmtDur($firstSec)?></span></div>
        <div class="time-item"><span class="time-label">2. Hälfte</span><span class="time-val"><?=$fmtDur($secondSec)?></span></div>
      <?php endif; ?>
      <div class="time-item"><span class="time-label">Gesamt</span><span class="time-val"><strong><?=$fmtDur($totalSec)?></strong></span></div>
      <div class="time-item"><span class="time-label">Stücke</span><span class="time-val"><?=$pieceCount?></span></div>
    </div>
  </div>
  <div class="footer">Erstellt am <?=date('d.m.Y')?> · KlangVotum · <?=h($org)?></div>
</div>
</body></html>
<?php
  exit;
}

// ── Abstimmungstitel laden ────────────────────────────────────────────────────
$songs = $pdo->query("
  SELECT s.id, s.title, s.composer, s.arranger, s.duration, s.difficulty,
         s.youtube_url, s.piece_id,
         SUM(v.vote='ja')      AS ja_count,
         SUM(v.vote='nein')    AS nein_count,
         SUM(v.vote='neutral') AS neutral_count
  FROM songs s
  LEFT JOIN votes v ON v.song_id = s.id
  WHERE s.is_active = 1 AND s.deleted_at IS NULL
  GROUP BY s.id
  ORDER BY (SUM(v.vote='ja') - SUM(v.vote='nein')) DESC, s.title ASC
")->fetchAll();

// Bibliothek (nicht in Abstimmung)
$activePieceIds = array_filter(array_column($songs, 'piece_id'));
$ph = $activePieceIds ? implode(',', array_map('intval', $activePieceIds)) : '0';
$pieces = $pdo->query("
  SELECT id, title, composer, arranger, duration, difficulty, youtube_url
  FROM pieces
  WHERE id NOT IN ($ph)
  ORDER BY title ASC
")->fetchAll();

// Tags vorladen für Suche
$planerSongIds = array_column($songs, 'id');
$planerPieceIds = array_column($pieces, 'id');
$planerSongTags = sv_tags_for_songs($planerSongIds);
$planerPieceTags = sv_tags_for_pieces($planerPieceIds);

// Set der bereits im Plan genutzten Stuecke
$usedKeys = [];
foreach ($planItems as $it) {
  if ($it['item_type'] === 'piece' && $it['piece_id'] && $it['source']) {
    $usedKeys[$it['source'] . ':' . $it['piece_id']] = true;
  }
}

$hasHalftime = false;
foreach ($planItems as $it) { if ($it['item_type'] === 'halftime') { $hasHalftime = true; break; } }

$csrf = sv_csrf_token();

sv_header('Konzertplaner', $admin);
?>

<div class="page-header">
  <div>
    <h2>Konzertplaner</h2>
    <div class="muted">Konzertprogramme planen, speichern und drucken</div>
  </div>
  <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
</div>

<!-- Plan-Auswahl -->
<div class="card" style="margin-bottom:12px">
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <label style="flex:1;min-width:200px">
      <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Plan auswählen</span><br>
      <select id="plan-select" class="input" style="width:100%;margin-top:4px;appearance:auto;-webkit-appearance:menulist" onchange="if(this.value)location.href='?plan_id='+this.value;else location.href='?';">
        <option value="">— Bitte wählen —</option>
        <?php foreach ($plans as $pl): ?>
          <option value="<?=$pl['id']?>"<?= $pl['id'] == $planId ? ' selected' : '' ?>><?=h($pl['name'])?> · Variante <?=h($pl['variant'])?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <div style="display:flex;gap:6px;padding-top:18px">
      <button class="btn" onclick="document.getElementById('dlg-new-plan').showModal()">+ Neuer Plan</button>
      <?php if ($currentPlan): ?>
        <button class="btn" onclick="document.getElementById('dlg-duplicate').showModal()">Variante duplizieren</button>
        <a class="btn" href="?plan_id=<?=$planId?>&export=print" target="_blank">Drucken</a>
        <button class="btn" style="color:var(--red)" onclick="if(confirm('Plan «<?=h(addslashes($currentPlan['name']))?>» (Variante <?=h(addslashes($currentPlan['variant']))?>)\nwirklich löschen?')){var f=document.getElementById('frm-delete');f.submit();}">Löschen</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($currentPlan): ?>
<!-- Zwei-Spalten-Layout -->
<div style="display:flex;gap:16px;align-items:flex-start" id="planer-layout">

  <!-- LINKS: Quell-Tabellen -->
  <div style="flex:1;min-width:0">
    <!-- Suche -->
    <div class="card" style="margin-bottom:0">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <label style="flex:1;min-width:200px">Suche<br>
          <input id="q" class="input" type="search" placeholder="Titel, Komponist, Genre…" style="width:100%;margin-top:5px">
        </label>
      </div>
    </div>

    <!-- Abstimmungstitel -->
    <div class="card" style="margin-top:8px;margin-bottom:8px">
      <h3 style="margin-bottom:10px">Abstimmung <span class="small" style="color:var(--muted);font-weight:400">(<?=count($songs)?>)</span></h3>
      <div class="table-scroll">
      <table id="songTable">
        <thead><tr>
          <th style="width:36px"></th>
          <th>Titel</th>
          <th style="width:70px;text-align:center">Score</th>
          <th style="white-space:nowrap">Dauer</th>
        </tr></thead>
        <tbody>
        <?php foreach ($songs as $s):
          $score = (int)$s['ja_count'] - (int)$s['nein_count'];
          $scColor = $score > 0 ? 'var(--score)' : ($score < 0 ? 'var(--red)' : 'var(--muted)');
          $scBg    = $score > 0 ? 'var(--score-light)' : ($score < 0 ? 'var(--red-soft)' : '#f5f2ee');
          $scBorder= $score > 0 ? 'var(--score-mid)' : ($score < 0 ? 'rgba(193,9,15,.3)' : '#ddd');
          $used = isset($usedKeys['song:' . $s['id']]);
        ?>
          <tr data-search="<?=h(strtolower($s['title'].' '.($s['composer']??'').' '.($s['arranger']??'').' '.implode(' ', $planerSongTags[(int)$s['id']] ?? [])))?>"
              data-source="song" data-piece-id="<?=$s['id']?>"
              data-title="<?=h($s['title'])?>"
              data-composer="<?=h($s['composer'] ?? '')?>"
              data-arranger="<?=h($s['arranger'] ?? '')?>"
              data-duration="<?=h($s['duration'] ?? '')?>">
            <td style="text-align:center">
              <button class="btn add-piece-btn" style="padding:2px 8px;font-size:16px;line-height:1;<?= $used ? 'opacity:.3;pointer-events:none' : '' ?>"
                      onclick="addPiece('song',<?=$s['id']?>,this)" <?= $used ? 'disabled' : '' ?>>+</button>
            </td>
            <td>
              <strong><?=h($s['title'])?></strong>
              <?php if ($s['composer'] || $s['arranger']): ?>
                <div class="small" style="color:var(--muted)"><?=h($s['composer'] ?? '')?><?php if ($s['arranger']): ?> · Arr. <?=h($s['arranger'])?><?php endif; ?></div>
              <?php endif; ?>
            </td>
            <td style="text-align:center">
              <span style="display:inline-block;background:<?=$scBg?>;color:<?=$scColor?>;border:1px solid <?=$scBorder?>;border-radius:8px;padding:2px 8px;font-weight:700;font-size:13px"><?=($score>0?'+':'').h($score)?></span>
            </td>
            <td class="small" style="white-space:nowrap"><?=h($s['duration'] ?? '–')?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$songs): ?><tr><td colspan="4" class="small" style="text-align:center;padding:16px">Keine Abstimmungstitel.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>

    <!-- Bibliothek -->
    <div class="card">
      <h3 style="margin-bottom:10px">Bibliothek <span class="small" style="color:var(--muted);font-weight:400">(<?=count($pieces)?>)</span></h3>
      <div class="table-scroll">
      <table id="pieceTable">
        <thead><tr>
          <th style="width:36px"></th>
          <th>Titel</th>
          <th style="white-space:nowrap">Dauer</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pieces as $p):
          $used = isset($usedKeys['piece:' . $p['id']]);
        ?>
          <tr data-search="<?=h(strtolower($p['title'].' '.($p['composer']??'').' '.($p['arranger']??'').' '.implode(' ', $planerPieceTags[(int)$p['id']] ?? [])))?>"
              data-source="piece" data-piece-id="<?=$p['id']?>"
              data-title="<?=h($p['title'])?>"
              data-composer="<?=h($p['composer'] ?? '')?>"
              data-arranger="<?=h($p['arranger'] ?? '')?>"
              data-duration="<?=h($p['duration'] ?? '')?>">
            <td style="text-align:center">
              <button class="btn add-piece-btn" style="padding:2px 8px;font-size:16px;line-height:1;<?= $used ? 'opacity:.3;pointer-events:none' : '' ?>"
                      onclick="addPiece('piece',<?=$p['id']?>,this)" <?= $used ? 'disabled' : '' ?>>+</button>
            </td>
            <td>
              <strong><?=h($p['title'])?></strong>
              <?php if ($p['composer'] || $p['arranger']): ?>
                <div class="small" style="color:var(--muted)"><?=h($p['composer'] ?? '')?><?php if ($p['arranger']): ?> · Arr. <?=h($p['arranger'])?><?php endif; ?></div>
              <?php endif; ?>
            </td>
            <td class="small" style="white-space:nowrap"><?=h($p['duration'] ?? '–')?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$pieces): ?><tr><td colspan="3" class="small" style="text-align:center;padding:16px">Keine Stücke.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <!-- RECHTS: Der Plan -->
  <div style="flex:1;min-width:0;position:sticky;top:12px">
    <div class="card">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <h3><?=h($currentPlan['name'])?> <span class="badge" style="vertical-align:middle"><?=h($currentPlan['variant'])?></span></h3>
      </div>

      <div id="plan-list">
        <?php if (!$planItems): ?>
          <div id="plan-empty" class="small" style="text-align:center;padding:24px;color:var(--muted)">
            Noch keine Stücke. Füge Stücke aus den Tabellen links hinzu.
          </div>
        <?php endif; ?>

        <?php foreach ($planItems as $idx => $it): ?>
          <?php if ($it['item_type'] === 'halftime'): ?>
            <div class="plan-item plan-halftime" data-item-id="<?=$it['id']?>" data-type="halftime" data-duration="0" draggable="true">
              <span class="drag-handle" style="cursor:grab;color:var(--muted);font-size:14px;padding:0 6px">☰</span>
              <div style="flex:1;text-align:center;font-weight:700;letter-spacing:.05em;color:var(--muted)">── Halbzeit ──</div>
              <button class="btn" style="padding:2px 8px;font-size:12px;color:var(--red)" onclick="removeItem(<?=$it['id']?>)">×</button>
            </div>
          <?php elseif ($it['item_type'] === 'block'): ?>
            <div class="plan-item plan-block" data-item-id="<?=$it['id']?>" data-type="block" data-duration="<?=h($it['duration_override'] ?? '')?>" draggable="true">
              <span class="drag-handle" style="cursor:grab;color:var(--muted);font-size:14px;padding:0 6px">☰</span>
              <span class="plan-nr"></span>
              <div style="flex:1">
                <strong style="color:var(--muted)"><?=h($it['label'] ?? 'Block')?></strong>
              </div>
              <span class="small" style="white-space:nowrap;margin:0 8px"><?=h($it['duration_override'] ?? '–')?></span>
              <button class="btn" style="padding:2px 8px;font-size:11px" onclick="editBlock(<?=$it['id']?>,'<?=h(addslashes($it['label'] ?? ''))?>','<?=h(addslashes($it['duration_override'] ?? ''))?>')">✎</button>
              <button class="btn" style="padding:2px 8px;font-size:12px;color:var(--red)" onclick="removeItem(<?=$it['id']?>)">×</button>
            </div>
          <?php else: ?>
            <div class="plan-item" data-item-id="<?=$it['id']?>" data-type="piece" data-duration="<?=h($it['piece_duration'] ?? '')?>" data-source="<?=h($it['source'] ?? '')?>" data-piece-id="<?=$it['piece_id'] ?? ''?>" draggable="true">
              <span class="drag-handle" style="cursor:grab;color:var(--muted);font-size:14px;padding:0 6px">☰</span>
              <span class="plan-nr"></span>
              <div style="flex:1">
                <strong><?=h($it['piece_title'] ?? '–')?></strong>
                <?php if ($it['piece_composer'] || $it['piece_arranger']): ?>
                  <div class="small" style="color:var(--muted)"><?=h($it['piece_composer'] ?? '')?><?php if ($it['piece_arranger']): ?> · Arr. <?=h($it['piece_arranger'])?><?php endif; ?></div>
                <?php endif; ?>
              </div>
              <span class="small" style="white-space:nowrap;margin:0 8px"><?=h($it['piece_duration'] ?? '–')?></span>
              <button class="btn" style="padding:2px 8px;font-size:12px;color:var(--red)" onclick="removeItem(<?=$it['id']?>)">×</button>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>

      <!-- Insert-Buttons -->
      <div style="display:flex;gap:6px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)">
        <button class="btn" style="font-size:12px" onclick="addBlockDialog()">+ Block</button>
        <button class="btn" style="font-size:12px" id="btn-halftime" onclick="addHalftime()" <?= $hasHalftime ? 'disabled style="font-size:12px;opacity:.3;pointer-events:none"' : '' ?>>+ Halbzeit</button>
      </div>

      <!-- Zeitsummen -->
      <div style="margin-top:12px;padding:10px 14px;background:var(--green-light);border:1.5px solid var(--green-mid);border-radius:10px">
        <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:center">
          <div id="time-first-wrap" style="<?= $hasHalftime ? '' : 'display:none' ?>">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">1. Hälfte</div>
            <div style="font-family:'Fraunces',serif;font-size:1.3rem;font-weight:700;color:var(--green)" id="time-first">0:00</div>
          </div>
          <div id="time-second-wrap" style="<?= $hasHalftime ? '' : 'display:none' ?>">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">2. Hälfte</div>
            <div style="font-family:'Fraunces',serif;font-size:1.3rem;font-weight:700;color:var(--green)" id="time-second">0:00</div>
          </div>
          <div>
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Gesamt</div>
            <div style="font-family:'Fraunces',serif;font-size:1.3rem;font-weight:700;color:var(--green)" id="time-total">0:00</div>
          </div>
          <div>
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Stücke</div>
            <div style="font-family:'Fraunces',serif;font-size:1.3rem;font-weight:700;color:var(--green)" id="time-count">0</div>
          </div>
        </div>
      </div>

    </div>
  </div>

</div>
<?php else: ?>
  <?php if (!$plans): ?>
    <div class="card" style="text-align:center;padding:40px">
      <div class="small" style="margin-bottom:12px;color:var(--muted)">Noch keine Pläne vorhanden.</div>
      <button class="btn" onclick="document.getElementById('dlg-new-plan').showModal()">+ Ersten Plan erstellen</button>
    </div>
  <?php else: ?>
    <div class="card" style="text-align:center;padding:40px">
      <div class="small" style="color:var(--muted)">Bitte einen Plan auswählen oder einen neuen erstellen.</div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<!-- Dialoge -->
<dialog id="dlg-new-plan" class="sv-dialog">
  <div class="sv-dialog__panel" tabindex="-1">
    <div class="sv-dialog__head">
      <div class="sv-dialog__title">Neuer Konzertplan</div>
      <button class="sv-dialog__close" type="button" data-close-dialog>✕</button>
    </div>
    <div class="sv-dialog__section">
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="create_plan">
        <label>Name *<br><input class="input" name="name" required style="width:100%;margin-top:4px" placeholder="z.B. Jahreskonzert 2026"></label>
        <label style="display:block;margin-top:10px">Variante<br><input class="input" name="variant" value="A" style="width:100%;margin-top:4px" placeholder="A"></label>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">
          <button type="button" class="btn" onclick="this.closest('dialog').close()">Abbrechen</button>
          <button type="submit" class="btn" style="background:var(--red);color:#fff">Erstellen</button>
        </div>
      </form>
    </div>
  </div>
</dialog>

<?php if ($currentPlan): ?>
<dialog id="dlg-duplicate" class="sv-dialog">
  <div class="sv-dialog__panel" tabindex="-1">
    <div class="sv-dialog__head">
      <div class="sv-dialog__title">Variante duplizieren</div>
      <button class="sv-dialog__close" type="button" data-close-dialog>✕</button>
    </div>
    <div class="sv-dialog__section">
      <form method="post">
        <input type="hidden" name="csrf" value="<?=h($csrf)?>">
        <input type="hidden" name="action" value="duplicate_plan">
        <input type="hidden" name="plan_id" value="<?=$planId?>">
        <div class="small" style="margin-bottom:10px">Plan: <strong><?=h($currentPlan['name'])?></strong> (aktuell: <?=h($currentPlan['variant'])?>)</div>
        <label>Neue Varianten-Bezeichnung<br><input class="input" name="new_variant" required style="width:100%;margin-top:4px" placeholder="B"></label>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">
          <button type="button" class="btn" onclick="this.closest('dialog').close()">Abbrechen</button>
          <button type="submit" class="btn" style="background:var(--red);color:#fff">Duplizieren</button>
        </div>
      </form>
    </div>
  </div>
</dialog>

<dialog id="dlg-block" class="sv-dialog">
  <div class="sv-dialog__panel" tabindex="-1">
    <div class="sv-dialog__head">
      <div class="sv-dialog__title" id="dlg-block-title">Block einfügen</div>
      <button class="sv-dialog__close" type="button" data-close-dialog>✕</button>
    </div>
    <div class="sv-dialog__section">
      <form onsubmit="submitBlock(event)">
        <input type="hidden" id="block-edit-id" value="">
        <label>Bezeichnung *<br><input class="input" id="block-label" required style="width:100%;margin-top:4px" placeholder="z.B. Pause, Vororchester spielt"></label>
        <label style="display:block;margin-top:10px">Dauer (M:SS)<br><input class="input" id="block-duration" style="width:100%;margin-top:4px" placeholder="10:00"></label>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">
          <button type="button" class="btn" onclick="this.closest('dialog').close()">Abbrechen</button>
          <button type="submit" class="btn" style="background:var(--red);color:#fff" id="dlg-block-submit">Einfügen</button>
        </div>
      </form>
    </div>
  </div>
</dialog>

<form id="frm-delete" method="post" style="display:none">
  <input type="hidden" name="csrf" value="<?=h($csrf)?>">
  <input type="hidden" name="action" value="delete_plan">
  <input type="hidden" name="plan_id" value="<?=$planId?>">
</form>
<?php endif; ?>

<style>
  .plan-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 6px;
    border-bottom: 1px solid var(--border);
    background: #fff;
    transition: background .15s;
  }
  .plan-item:last-child { border-bottom: none; }
  .plan-item.dragging { opacity: .35; }
  .plan-item.drag-over { background: var(--green-light); border-top: 2px solid var(--green); }
  .plan-halftime { background: #f5f2ee; border: 1px dashed var(--border); border-radius: 6px; margin: 4px 0; }
  .plan-block { background: #faf8f5; }
  .plan-nr {
    display: inline-flex; align-items: center; justify-content: center;
    width: 24px; height: 24px; border-radius: 50%;
    background: var(--green-light); color: var(--green); border: 1px solid var(--green-mid);
    font-size: 11px; font-weight: 700; flex-shrink: 0;
  }
  @media (max-width: 900px) {
    #planer-layout { flex-direction: column !important; }
  }
</style>

<script>
// ── Dialog Close ─────────────────────────────────────────────────────────────
document.addEventListener('click', function(e) {
  var closeBtn = e.target.closest('[data-close-dialog]');
  if (closeBtn) { var d = closeBtn.closest('dialog'); if (d) d.close(); }
});

var CSRF  = '<?=h($csrf)?>';
var PLAN_ID = <?=$planId?>;
var usedKeys = {};
<?php foreach ($usedKeys as $k => $v): ?>usedKeys['<?=h($k)?>'] = true;
<?php endforeach; ?>

// ── AJAX Helper ──────────────────────────────────────────────────────────────
function planPost(action, params, callback) {
  var body = 'action=' + encodeURIComponent(action) + '&csrf=' + encodeURIComponent(CSRF) + '&plan_id=' + PLAN_ID;
  var keys = Object.keys(params);
  for (var i = 0; i < keys.length; i++) {
    var k = keys[i];
    if (Array.isArray(params[k])) {
      for (var j = 0; j < params[k].length; j++) {
        body += '&' + k + '[]=' + encodeURIComponent(params[k][j]);
      }
    } else {
      body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }
  }
  fetch(location.pathname, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body
  }).then(function(r) { return r.json(); }).then(function(data) {
    if (callback) callback(data);
  });
}

// ── Dauer-Parser ─────────────────────────────────────────────────────────────
function durParseSec(s) {
  if (!s) return 0;
  s = s.replace(/'+$/, '').trim();
  var m = s.match(/^(\d+)[:'](\d{1,2})$/);
  if (m) return parseInt(m[1],10)*60 + parseInt(m[2],10);
  m = s.match(/^(\d+)$/);
  if (m) return parseInt(m[1],10)*60;
  return 0;
}
function fmtDur(sec) {
  var m = Math.floor(sec/60), s = sec%60;
  return m + ':' + String(s).padStart(2,'0');
}

// ── Zeitsummen ───────────────────────────────────────────────────────────────
function recalcTimes() {
  var items = document.querySelectorAll('#plan-list .plan-item');
  var first = 0, second = 0, count = 0, hasHalf = false, inSecond = false;
  var nr = 1;
  items.forEach(function(el) {
    var nrEl = el.querySelector('.plan-nr');
    if (el.dataset.type === 'halftime') {
      hasHalf = true; inSecond = true;
      if (nrEl) nrEl.style.display = 'none';
      return;
    }
    if (nrEl) { nrEl.style.display = ''; nrEl.textContent = nr++; }
    var sec = durParseSec(el.dataset.duration);
    if (inSecond) second += sec; else first += sec;
    if (el.dataset.type === 'piece') count++;
  });
  document.getElementById('time-first').textContent = fmtDur(first);
  document.getElementById('time-second').textContent = fmtDur(second);
  document.getElementById('time-total').textContent = fmtDur(first + second);
  document.getElementById('time-count').textContent = count;
  document.getElementById('time-first-wrap').style.display = hasHalf ? '' : 'none';
  document.getElementById('time-second-wrap').style.display = hasHalf ? '' : 'none';

  // Empty state
  var emptyEl = document.getElementById('plan-empty');
  if (emptyEl) emptyEl.style.display = items.length ? 'none' : '';

  // Halftime button
  var htBtn = document.getElementById('btn-halftime');
  if (htBtn) {
    htBtn.disabled = hasHalf;
    htBtn.style.opacity = hasHalf ? '.3' : '';
    htBtn.style.pointerEvents = hasHalf ? 'none' : '';
  }
}

// ── Stueck hinzufuegen ───────────────────────────────────────────────────────
function addPiece(source, pieceId, btn) {
  var key = source + ':' + pieceId;
  if (usedKeys[key]) return;

  var tr = btn.closest('tr');
  var title    = tr.dataset.title;
  var composer = tr.dataset.composer;
  var arranger = tr.dataset.arranger;
  var duration = tr.dataset.duration;

  planPost('add_item', {
    item_type: 'piece',
    piece_id: pieceId,
    source: source
  }, function(data) {
    if (!data.ok) return;
    usedKeys[key] = true;
    btn.disabled = true;
    btn.style.opacity = '.3';
    btn.style.pointerEvents = 'none';

    var emptyEl = document.getElementById('plan-empty');
    if (emptyEl) emptyEl.style.display = 'none';

    var el = document.createElement('div');
    el.className = 'plan-item';
    el.draggable = true;
    el.dataset.itemId = data.item_id;
    el.dataset.type = 'piece';
    el.dataset.duration = duration;
    el.dataset.source = source;
    el.dataset.pieceId = pieceId;

    var composerHtml = '';
    if (composer || arranger) {
      composerHtml = '<div class="small" style="color:var(--muted)">' + escHtml(composer);
      if (arranger) composerHtml += ' · Arr. ' + escHtml(arranger);
      composerHtml += '</div>';
    }

    el.innerHTML =
      '<span class="drag-handle" style="cursor:grab;color:var(--muted);font-size:14px;padding:0 6px">☰</span>' +
      '<span class="plan-nr"></span>' +
      '<div style="flex:1"><strong>' + escHtml(title) + '</strong>' + composerHtml + '</div>' +
      '<span class="small" style="white-space:nowrap;margin:0 8px">' + escHtml(duration || '–') + '</span>' +
      '<button class="btn" style="padding:2px 8px;font-size:12px;color:var(--red)" onclick="removeItem(' + data.item_id + ')">×</button>';

    document.getElementById('plan-list').appendChild(el);
    initDrag(el);
    recalcTimes();
  });
}

function escHtml(s) {
  var div = document.createElement('div');
  div.textContent = s;
  return div.innerHTML;
}

// ── Block / Halbzeit ─────────────────────────────────────────────────────────
function addBlockDialog() {
  document.getElementById('block-edit-id').value = '';
  document.getElementById('block-label').value = '';
  document.getElementById('block-duration').value = '';
  document.getElementById('dlg-block-title').textContent = 'Block einfügen';
  document.getElementById('dlg-block-submit').textContent = 'Einfügen';
  document.getElementById('dlg-block').showModal();
}

function editBlock(itemId, label, dur) {
  document.getElementById('block-edit-id').value = itemId;
  document.getElementById('block-label').value = label;
  document.getElementById('block-duration').value = dur;
  document.getElementById('dlg-block-title').textContent = 'Block bearbeiten';
  document.getElementById('dlg-block-submit').textContent = 'Speichern';
  document.getElementById('dlg-block').showModal();
}

function submitBlock(e) {
  e.preventDefault();
  var editId = document.getElementById('block-edit-id').value;
  var label  = document.getElementById('block-label').value.trim();
  var dur    = document.getElementById('block-duration').value.trim();
  if (!label) return;

  if (editId) {
    // Update
    planPost('update_item', { item_id: editId, label: label, duration_override: dur }, function(data) {
      if (!data.ok) return;
      var el = document.querySelector('.plan-item[data-item-id="' + editId + '"]');
      if (el) {
        el.dataset.duration = dur;
        el.querySelector('strong').textContent = label;
        var durSpan = el.querySelector('.small[style*="white-space"]');
        if (durSpan) durSpan.textContent = dur || '–';
      }
      recalcTimes();
      document.getElementById('dlg-block').close();
    });
  } else {
    // Create
    planPost('add_item', { item_type: 'block', label: label, duration_override: dur }, function(data) {
      if (!data.ok) return;
      var emptyEl = document.getElementById('plan-empty');
      if (emptyEl) emptyEl.style.display = 'none';

      var el = document.createElement('div');
      el.className = 'plan-item plan-block';
      el.draggable = true;
      el.dataset.itemId = data.item_id;
      el.dataset.type = 'block';
      el.dataset.duration = dur;
      el.innerHTML =
        '<span class="drag-handle" style="cursor:grab;color:var(--muted);font-size:14px;padding:0 6px">☰</span>' +
        '<span class="plan-nr"></span>' +
        '<div style="flex:1"><strong style="color:var(--muted)">' + escHtml(label) + '</strong></div>' +
        '<span class="small" style="white-space:nowrap;margin:0 8px">' + escHtml(dur || '–') + '</span>' +
        '<button class="btn" style="padding:2px 8px;font-size:11px" onclick="editBlock(' + data.item_id + ',\'' + escAttr(label) + '\',\'' + escAttr(dur) + '\')">✎</button>' +
        '<button class="btn" style="padding:2px 8px;font-size:12px;color:var(--red)" onclick="removeItem(' + data.item_id + ')">×</button>';
      document.getElementById('plan-list').appendChild(el);
      initDrag(el);
      recalcTimes();
      document.getElementById('dlg-block').close();
    });
  }
}

function escAttr(s) {
  return (s || '').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
}

function addHalftime() {
  planPost('add_item', { item_type: 'halftime' }, function(data) {
    if (!data.ok) {
      if (data.error === 'halftime_exists') alert('Es gibt bereits eine Halbzeit in diesem Plan.');
      return;
    }
    var emptyEl = document.getElementById('plan-empty');
    if (emptyEl) emptyEl.style.display = 'none';

    var el = document.createElement('div');
    el.className = 'plan-item plan-halftime';
    el.draggable = true;
    el.dataset.itemId = data.item_id;
    el.dataset.type = 'halftime';
    el.dataset.duration = '0';
    el.innerHTML =
      '<span class="drag-handle" style="cursor:grab;color:var(--muted);font-size:14px;padding:0 6px">☰</span>' +
      '<div style="flex:1;text-align:center;font-weight:700;letter-spacing:.05em;color:var(--muted)">── Halbzeit ──</div>' +
      '<button class="btn" style="padding:2px 8px;font-size:12px;color:var(--red)" onclick="removeItem(' + data.item_id + ')">×</button>';
    document.getElementById('plan-list').appendChild(el);
    initDrag(el);
    recalcTimes();
  });
}

// ── Item entfernen ───────────────────────────────────────────────────────────
function removeItem(itemId) {
  planPost('remove_item', { item_id: itemId }, function(data) {
    if (!data.ok) return;
    var el = document.querySelector('.plan-item[data-item-id="' + itemId + '"]');
    if (el) {
      // Quell-Button wieder aktivieren
      if (el.dataset.type === 'piece' && el.dataset.source && el.dataset.pieceId) {
        var key = el.dataset.source + ':' + el.dataset.pieceId;
        delete usedKeys[key];
        var rows = document.querySelectorAll('tr[data-source="' + el.dataset.source + '"][data-piece-id="' + el.dataset.pieceId + '"]');
        rows.forEach(function(r) {
          var btn = r.querySelector('.add-piece-btn');
          if (btn) { btn.disabled = false; btn.style.opacity = ''; btn.style.pointerEvents = ''; }
        });
      }
      el.remove();
    }
    recalcTimes();
  });
}

// ── Drag & Drop ──────────────────────────────────────────────────────────────
var dragEl = null;

function initDrag(el) {
  el.addEventListener('dragstart', function(e) {
    dragEl = el;
    el.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
  });
  el.addEventListener('dragend', function() {
    el.classList.remove('dragging');
    document.querySelectorAll('.plan-item.drag-over').forEach(function(x) { x.classList.remove('drag-over'); });
    dragEl = null;
    saveOrder();
  });
  el.addEventListener('dragover', function(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (dragEl && dragEl !== el) {
      el.classList.add('drag-over');
    }
  });
  el.addEventListener('dragleave', function() {
    el.classList.remove('drag-over');
  });
  el.addEventListener('drop', function(e) {
    e.preventDefault();
    el.classList.remove('drag-over');
    if (!dragEl || dragEl === el) return;
    var list = document.getElementById('plan-list');
    var items = Array.from(list.querySelectorAll('.plan-item'));
    var fromIdx = items.indexOf(dragEl);
    var toIdx   = items.indexOf(el);
    if (fromIdx < toIdx) {
      list.insertBefore(dragEl, el.nextSibling);
    } else {
      list.insertBefore(dragEl, el);
    }
  });
}

function saveOrder() {
  var items = document.querySelectorAll('#plan-list .plan-item');
  var ids = [];
  items.forEach(function(el) { ids.push(el.dataset.itemId); });
  planPost('reorder', { item_ids: ids }, function() {
    recalcTimes();
  });
}

// ── Suche ────────────────────────────────────────────────────────────────────
(function() {
  var q = document.getElementById('q');
  if (!q) return;
  q.addEventListener('input', function() {
    var query = q.value.toLowerCase().trim();
    document.querySelectorAll('#songTable tbody tr, #pieceTable tbody tr').forEach(function(tr) {
      var search = tr.dataset.search || '';
      tr.style.display = !query || search.indexOf(query) >= 0 ? '' : 'none';
    });
  });
})();

// ── Init ─────────────────────────────────────────────────────────────────────
document.querySelectorAll('#plan-list .plan-item').forEach(function(el) { initDrag(el); });
recalcTimes();
</script>

<?php sv_footer(); ?>
