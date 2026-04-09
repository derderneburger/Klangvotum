<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$admin = sv_require_admin();
$pdo   = sv_pdo();
$base  = sv_base_url();

// ── Alle Stücke löschen ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_all_pieces') {
  sv_csrf_check();
  $pdo->exec("DELETE FROM pieces");
  sv_log($admin['id'], 'pieces_delete_all', 'all pieces deleted');
  sv_flash_set('success', 'Alle Bibliotheksstücke wurden gelöscht.');
  header('Location: ' . $base . '/admin/bibliothek_import.php');
  exit;
}

// ── CSV Export ────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'export') {
  $rows = $pdo->query("
    SELECT title, youtube_url, composer, arranger, publisher, duration, genre, difficulty,
           owner, has_scan, has_score_scan, has_original_score, binder,
           shop_url, shop_price, info
    FROM pieces ORDER BY title ASC
  ")->fetchAll();

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="bibliothek_' . date('Y-m-d') . '.csv"');
  $f = fopen('php://output', 'w');
  fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF));
  fputcsv($f, ['titel','youtube_url','komponist','arrangeur','verlag','laenge','genre','grad',
               'eigentuemer','stimmen_scan','partitur_scan','original_partitur','mappe',
               'haendler_url','preis','info'], ';');
  foreach ($rows as $r) {
    fputcsv($f, [
      $r['title'], $r['youtube_url'] ?? '', $r['composer'], $r['arranger'], $r['publisher'],
      $r['duration'], $r['genre'], $r['difficulty'], $r['owner'],
      $r['has_scan'] ? 'ja' : 'nein',
      $r['has_score_scan'] ? 'ja' : 'nein',
      $r['has_original_score'] ? 'ja' : 'nein',
      $r['binder'] ? 'ja' : 'nein',
      $r['shop_url'], $r['shop_price'], $r['info'],
    ], ';');
  }
  fclose($f);
  exit;
}

// ── CSV Import ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete_all_pieces') {
  sv_csrf_check();

  if (empty($_FILES['csv_file']['tmp_name'])) {
    sv_flash_set('error', 'Keine Datei hochgeladen.');
  } else {
    $f = fopen($_FILES['csv_file']['tmp_name'], 'r');
    if (!$f) {
      sv_flash_set('error', 'Datei konnte nicht gelesen werden.');
    } else {
      $bom = fread($f, 3);
      if ($bom !== "\xEF\xBB\xBF") rewind($f);

      $header   = null;
      $line     = 0;
      $sep      = ';';
      $imported = 0;
      $skipped  = 0;
      $skipLog   = [];
      $importLog = [];

      while (($row = fgetcsv($f, 2000, $sep)) !== false) {
        $line++;
        if ($line === 1) {
          if (count($row) < 2 && strpos(implode('', $row), ',') !== false) {
            rewind($f);
            $bom2 = fread($f, 3);
            if ($bom2 !== "\xEF\xBB\xBF") rewind($f);
            $sep = ',';
            $row = fgetcsv($f, 2000, $sep);
          }
          $header = array_map('strtolower', array_map('trim', $row));
          continue;
        }
        if (!$header || count($row) < 1) continue;
        $data = array_combine(array_slice($header, 0, count($row)), $row);

        $title = trim($data['titel'] ?? $data['title'] ?? '');
        if ($title === '') {
          $skipped++;
          $skipLog[] = ['titel' => '(leer)', 'arrangeur' => '', 'grund' => 'Kein Titel'];
          continue;
        }

        // Duplikat: gleicher Titel UND gleicher Arrangeur
        $arrImport = trim($data['arrangeur'] ?? $data['arranger'] ?? '');
        $exists = $pdo->prepare("SELECT id FROM pieces WHERE title=? AND COALESCE(arranger,'')=?");
        $exists->execute([$title, $arrImport]);
        if ($exists->fetch()) {
          $skipped++;
          $skipLog[] = ['titel' => $title, 'arrangeur' => $arrImport, 'grund' => 'Bereits vorhanden (Titel + Arrangeur)'];
          continue;
        }

        $diff  = isset($data['grad']) && $data['grad'] !== '' ? (float)str_replace(',','.',$data['grad']) : null;
        $price = isset($data['preis'])         && $data['preis'] !== ''         ? (float)str_replace(',','.',$data['preis'])         : null;
        $yn    = function($k) use ($data) { return in_array(strtolower(trim($data[$k] ?? '')), ['ja','x','1','yes']) ? 1 : 0; };

        try {
          $stmt = $pdo->prepare("INSERT INTO pieces
            (title, youtube_url, composer, arranger, publisher, duration, genre, difficulty,
             owner, has_scan, has_score_scan, has_original_score, binder,
             shop_url, shop_price, info)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
          $stmt->execute([
            $title,
            trim($data['youtube_url'] ?? ''),
            trim($data['komponist']   ?? $data['composer']  ?? ''),
            trim($data['arrangeur']   ?? $data['arranger']  ?? ''),
            trim($data['verlag']      ?? $data['publisher'] ?? ''),
            sv_normalize_duration(trim($data['laenge'] ?? $data['duration'] ?? '')),
            trim($data['genre']       ?? ''),
            $diff,
            trim($data['eigentuemer'] ?? $data['owner'] ?? ''),
            $yn('stimmen_scan'), $yn('partitur_scan'), $yn('original_partitur'),
            $yn('mappe') ? 'x' : '',
            trim($data['haendler_url'] ?? $data['shop_url'] ?? ''),
            $price,
            trim($data['info'] ?? ''),
          ]);
          $imported++;
          $importLog[] = ['titel' => $title, 'arrangeur' => $arrImport];
        } catch (Throwable $e) {
          $skipped++;
          $skipLog[] = ['titel' => $title, 'arrangeur' => $arrImport, 'grund' => 'DB-Fehler: ' . $e->getMessage()];
        }
      }
      fclose($f);

      if ($imported > 0) sv_log($admin['id'], 'pieces_import', "imported=$imported skipped=$skipped");
      $msg = "$imported Stücke importiert";
      if ($skipped) $msg .= ", $skipped übersprungen";
      sv_flash_set($imported > 0 ? 'success' : 'error', $msg . '.');
      if ($skipLog || $importLog) {
        $_SESSION['import_skip_log'] = $skipLog;
        $_SESSION['import_log'] = $importLog;
      }
    }
  }
  header('Location: ' . $base . '/admin/bibliothek_import.php');
  exit;
}

sv_header('Admin – Bibliothek Import/Export', $admin);

$skipLog   = $_SESSION['import_skip_log'] ?? [];
$importLog = $_SESSION['import_log'] ?? [];
unset($_SESSION['import_skip_log'], $_SESSION['import_log']);
?>

<?php if (!empty($skipLog) || !empty($importLog)): ?>
<dialog id="dialog-skip-log" class="sv-dialog" style="max-width:680px;width:95vw">
  <div class="sv-dialog__panel" style="max-height:80vh;display:flex;flex-direction:column">
    <div class="sv-dialog__head">
      <div>
        <div class="sv-dialog__title">⚠️ Übersprungene Einträge</div>
        <div class="sv-dialog__sub"><?=count($importLog)?> importiert · <?=count($skipLog)?> übersprungen</div>
      </div>
      <button class="sv-dialog__close" type="button" data-close-dialog>✕</button>
    </div>
    <div class="sv-dialog__section" style="overflow-y:auto;flex:1">
      <div class="table-scroll">
        <table>
          <?php if($importLog): ?>
          <div style="font-weight:700;font-size:13px;color:var(--green);margin-bottom:6px">✓ Importiert (<?=count($importLog)?>)</div>
          <table style="margin-bottom:16px">
            <thead><tr><th>Titel</th><th>Arrangeur</th></tr></thead>
            <tbody>
              <?php foreach ($importLog as $row): ?>
              <tr>
                <td><strong><?=h($row['titel'])?></strong></td>
                <td class="small"><?=h($row['arrangeur'] ?: '–')?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
          <?php if($skipLog): ?>
          <div style="font-weight:700;font-size:13px;color:var(--red);margin-bottom:6px">✗ Übersprungen (<?=count($skipLog)?>)</div>
          <?php endif; ?>
          <thead><tr><th>Titel</th><th>Arrangeur</th><th>Grund</th></tr></thead>
          <tbody>
            <?php foreach ($skipLog as $row): ?>
            <tr>
              <td><strong><?=h($row['titel'])?></strong></td>
              <td class="small"><?=h($row['arrangeur'] ?? '–')?></td>
              <td class="small" style="color:var(--muted)"><?=h($row['grund'])?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</dialog>
<script>
document.addEventListener('DOMContentLoaded',function(){
  document.getElementById('dialog-skip-log').showModal();
});
document.addEventListener('click',function(e){
  var c=e.target.closest('[data-close-dialog]');
  if(c){var d=c.closest('dialog');if(d)d.close();}
});
</script>
<?php endif; ?>

<div class="page-header">
  <div>
    <h2>Bibliothek Import / Export</h2>
    <div class="muted">CSV-Import und Export für die Notenbibliothek.</div>
  </div>
  <div class="row">
    <a class="btn" href="<?=h($base)?>/admin/bibliothek.php">← Bibliothek</a>
    <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
  </div>
</div>

<!-- Export -->
<div class="card" style="margin-bottom:12px">
  <h3>📤 CSV Export</h3>
  <p class="small" style="margin-bottom:12px">Exportiert alle Archivstücke mit allen Feldern als CSV (Semikolon-getrennt, UTF-8 mit BOM für Excel).</p>
  <a class="btn primary" href="?action=export">⬇ CSV herunterladen</a>
</div>

<!-- Import -->
<div class="card" style="margin-bottom:12px">
  <h3>📥 CSV Import</h3>
  <div class="small" style="margin-bottom:16px">
    Importiert Stücke aus einer CSV-Datei ins Archiv. Trennzeichen: Semikolon <code>;</code> oder Komma <code>,</code>.<br>
    Bereits vorhandene Titel werden übersprungen — verglichen wird nach <strong>Titel&nbsp;+&nbsp;Arrangeur</strong>. Gleicher Titel mit anderem Arrangeur gilt als separates Stück.
  </div>

  <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:20px">
    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
    <input class="input" type="file" name="csv_file" accept=".csv,text/csv" required style="flex:1;min-width:200px">
    <button class="btn primary" type="submit">Importieren</button>
  </form>

  <div style="margin-bottom:16px">
    <div style="font-weight:700;font-size:13px;margin-bottom:8px">Alle Spalten:</div>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Spaltenname</th><th>Pflicht</th><th>Beschreibung</th></tr></thead>
        <tbody>
          <tr><td><code>titel</code></td><td><span class="badge" style="background:var(--red-soft);color:var(--red);border-color:rgba(193,9,15,.3)">Pflicht</span></td><td class="small">Titel des Stücks</td></tr>
          <tr><td><code>youtube_url</code></td><td><span class="badge">optional</span></td><td class="small">YouTube-Link</td></tr>
          <tr><td><code>komponist</code></td><td><span class="badge">optional</span></td><td class="small">Komponist</td></tr>
          <tr><td><code>arrangeur</code></td><td><span class="badge">optional</span></td><td class="small">Arrangeur</td></tr>
          <tr><td><code>verlag</code></td><td><span class="badge">optional</span></td><td class="small">Verlag</td></tr>
          <tr><td><code>laenge</code></td><td><span class="badge">optional</span></td><td class="small">Länge, z.B. 6:30</td></tr>
          <tr><td><code>genre</code></td><td><span class="badge">optional</span></td><td class="small">Genre</td></tr>
          <tr><td><code>grad</code></td><td><span class="badge">optional</span></td><td class="small">Zahl 1.0–6.0</td></tr>
          <tr><td><code>eigentuemer</code></td><td><span class="badge">optional</span></td><td class="small">Eigentümer der Noten</td></tr>
          <tr><td><code>stimmen_scan</code></td><td><span class="badge">optional</span></td><td class="small">ja / nein</td></tr>
          <tr><td><code>partitur_scan</code></td><td><span class="badge">optional</span></td><td class="small">ja / nein</td></tr>
          <tr><td><code>original_partitur</code></td><td><span class="badge">optional</span></td><td class="small">ja / nein</td></tr>
          <tr><td><code>mappe</code></td><td><span class="badge">optional</span></td><td class="small">ja / nein</td></tr>
          <tr><td><code>haendler_url</code></td><td><span class="badge">optional</span></td><td class="small">Link zum Händler</td></tr>
          <tr><td><code>preis</code></td><td><span class="badge">optional</span></td><td class="small">Preis in €</td></tr>
          <tr><td><code>info</code></td><td><span class="badge">optional</span></td><td class="small">Freitext-Info</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div style="font-weight:700;font-size:13px;margin-bottom:6px">Minimal-Beispiel:</div>
    <pre style="background:#f5f2ee;border-radius:8px;padding:12px;font-size:12px;overflow-x:auto;line-height:1.6">titel
Coldplay in Symphony
Nordische Fahrt</pre>
    <div style="font-weight:700;font-size:13px;margin:12px 0 6px">Vollständiges Beispiel:</div>
    <pre style="background:#f5f2ee;border-radius:8px;padding:12px;font-size:12px;overflow-x:auto;line-height:1.6">titel;komponist;arrangeur;verlag;laenge;genre;grad;eigentuemer;stimmen_scan;mappe
Coldplay in Symphony;diverse;Bert Appermont;Beriato;7'42;Popmusik;4.5;BPH;ja;ja
Nordische Fahrt;Ernest Majo;;Edition Helbling;6';Marsch;3.0;M.Müller;ja;nein</pre>
    <div class="small" style="margin-top:8px">Tipp: Den Export dieser Seite kannst du direkt bearbeiten und wieder importieren — bestehende Titel werden übersprungen.</div>
  </div>
</div>

<!-- Alle Stücke löschen -->
<div class="card" style="margin-bottom:12px;border:2px solid var(--red);background:var(--red-soft)">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
    <span style="font-size:24px">⚠️</span>
    <div>
      <div style="font-weight:800;font-size:15px;color:var(--red)">Alle Bibliotheksstücke löschen</div>
      <div class="small" style="color:var(--red);opacity:.8">Löscht alle Stücke im Archiv unwiderruflich.</div>
    </div>
  </div>
  <form method="post" onsubmit="return checkConfirmPieces(this)">
    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
    <input type="hidden" name="action" value="delete_all_pieces">
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <label style="flex:1;min-width:200px">
        <span class="small" style="font-weight:700;color:var(--red)">Zur Bestätigung <strong>LÖSCHEN</strong> eintippen:</span><br>
        <input class="input" type="text" id="confirmPieces" autocomplete="off" style="margin-top:5px;border-color:var(--red);width:100%">
      </label>
      <button class="btn" type="submit" style="background:var(--red);color:#fff;border-color:var(--red);font-weight:700">🗑 Alle Stücke löschen</button>
    </div>
  </form>
</div>
<script>
function checkConfirmPieces(f) {
  if (document.getElementById('confirmPieces').value !== 'LÖSCHEN') {
    alert('Bitte genau LÖSCHEN eintippen.');
    return false;
  }
  return confirm('Wirklich ALLE Bibliotheksstücke löschen? Das kann nicht rückgängig gemacht werden.');
}
</script>

<?php sv_footer(); ?>
