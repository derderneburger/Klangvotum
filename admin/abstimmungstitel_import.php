<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$admin = sv_require_admin();
$pdo   = sv_pdo();
$base  = sv_base_url();

// ── Alle Titel löschen ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_all_songs') {
  sv_csrf_check();
  $pdo->exec("DELETE FROM songs");
  sv_log($admin['id'], 'songs_delete_all', 'all songs deleted');
  sv_flash_set('success', 'Alle Abstimmungstitel wurden gelöscht.');
  header('Location: ' . $base . '/admin/abstimmungstitel_import.php');
  exit;
}

// ── CSV Export ────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'export') {
  $rows = $pdo->query("
    SELECT title, youtube_url, composer, arranger, publisher, duration, genre,
           difficulty, shop_url, shop_price, info, is_active
    FROM songs ORDER BY title ASC
  ")->fetchAll();

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="abstimmung_titel_' . date('Y-m-d') . '.csv"');
  $f = fopen('php://output', 'w');
  fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF));
  fputcsv($f, ['titel','youtube_url','komponist','arrangeur','verlag','laenge','genre',
               'grad','haendler_url','preis','info','aktiv'], ';');
  foreach ($rows as $r) {
    fputcsv($f, [
      $r['title'], $r['youtube_url'], $r['composer'], $r['arranger'],
      $r['publisher'], $r['duration'], $r['genre'], $r['difficulty'],
      $r['shop_url'], $r['shop_price'], $r['info'], $r['is_active'] ? 'ja' : 'nein'
    ], ';');
  }
  fclose($f);
  exit;
}

// ── CSV Import ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        if (!$header || count($row) < 2) continue;
        $data = array_combine(array_slice($header, 0, count($row)), $row);

        $title = trim($data['titel'] ?? $data['title'] ?? '');
        $url   = trim($data['youtube_url'] ?? '');

        if ($title === '' || $url === '') {
          $skipped++;
          $skipLog[] = ['titel' => $title ?: '(leer)', 'arrangeur' => '', 'grund' => 'Pflichtfelder fehlen (Titel oder YouTube-URL)'];
          continue;
        }

        // Duplikat: gleicher Titel UND gleicher Arrangeur
        $arrImport = trim($data['arrangeur'] ?? $data['arranger'] ?? '');
        $exists = $pdo->prepare("SELECT id FROM songs WHERE title=? AND COALESCE(arranger,'')=?");
        $exists->execute([$title, $arrImport]);
        if ($exists->fetch()) {
          $skipped++;
          $skipLog[] = ['titel' => $title, 'arrangeur' => $arrImport, 'grund' => 'Bereits vorhanden (Titel + Arrangeur)'];
          continue;
        }

        $diff  = isset($data['grad']) && $data['grad'] !== '' ? (float)str_replace(',','.',$data['grad']) : null;
        $price = isset($data['preis'])         && $data['preis'] !== ''         ? (float)str_replace(',','.',$data['preis'])         : null;

        try {
          $stmt = $pdo->prepare("INSERT INTO songs
            (title, youtube_url, composer, arranger, publisher, duration, genre,
             difficulty, shop_url, shop_price, info, is_active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,1)");
          $stmt->execute([
            $title, $url,
            trim($data['komponist'] ?? $data['composer']  ?? ''),
            trim($data['arrangeur'] ?? $data['arranger']  ?? ''),
            trim($data['verlag']    ?? $data['publisher'] ?? ''),
            sv_normalize_duration(trim($data['laenge'] ?? $data['duration'] ?? '')),
            trim($data['genre']     ?? ''),
            $diff,
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

      if ($imported > 0) sv_log($admin['id'], 'songs_import', "imported=$imported skipped=$skipped");
      $msg = "$imported Titel importiert";
      if ($skipped) $msg .= ", $skipped übersprungen";
      sv_flash_set($imported > 0 ? 'success' : 'error', $msg . '.');
      if ($skipLog || $importLog) {
        $_SESSION['import_skip_log'] = $skipLog;
        $_SESSION['import_log'] = $importLog;
      }
    }
  }
  header('Location: ' . $base . '/admin/abstimmungstitel_import.php');
  exit;
}

sv_header('Admin – Titel Import/Export', $admin);

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
    <h2>Titel Import / Export</h2>
    <div class="muted">CSV-Import und Export für Abstimmungstitel.</div>
  </div>
  <div class="row">
    <a class="btn" href="<?=h($base)?>/admin/abstimmungstitel.php">← Titel verwalten</a>
    <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
  </div>
</div>

<!-- Export -->
<div class="card" style="margin-bottom:12px">
  <h3>📤 CSV Export</h3>
  <p class="small" style="margin-bottom:12px">Exportiert alle Abstimmungstitel mit allen Feldern als CSV-Datei (Semikolon-getrennt, UTF-8). Kann direkt in Excel geöffnet werden.</p>
  <a class="btn primary" href="?action=export">⬇ CSV herunterladen</a>
</div>

<!-- Import -->
<div class="card" style="margin-bottom:12px">
  <h3>📥 CSV Import</h3>
  <div class="small" style="margin-bottom:16px">
    Importiert Titel aus einer CSV-Datei. Trennzeichen: Semikolon <code>;</code> oder Komma <code>,</code>.<br>
    Bereits vorhandene Titel werden übersprungen — verglichen wird nach <strong>Titel&nbsp;+&nbsp;Arrangeur</strong>. Gleicher Titel mit anderem Arrangeur gilt als separates Stück.
  </div>

  <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:20px">
    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
    <input class="input" type="file" name="csv_file" accept=".csv,text/csv" required style="flex:1;min-width:200px">
    <button class="btn primary" type="submit">Importieren</button>
  </form>

  <div style="margin-bottom:16px">
    <div style="font-weight:700;font-size:13px;margin-bottom:8px">Pflichtfelder (Minimalformat):</div>
    <div class="table-scroll">
      <table>
        <thead><tr><th>Spaltenname</th><th>Pflicht</th><th>Beschreibung</th></tr></thead>
        <tbody>
          <tr><td><code>titel</code></td><td><span class="badge" style="background:var(--red-soft);color:var(--red);border-color:rgba(193,9,15,.3)">Pflicht</span></td><td class="small">Titel des Stücks</td></tr>
          <tr><td><code>youtube_url</code></td><td><span class="badge" style="background:var(--red-soft);color:var(--red);border-color:rgba(193,9,15,.3)">Pflicht</span></td><td class="small">YouTube-Link</td></tr>
          <tr><td><code>komponist</code></td><td><span class="badge">optional</span></td><td class="small">Komponist</td></tr>
          <tr><td><code>arrangeur</code></td><td><span class="badge">optional</span></td><td class="small">Arrangeur</td></tr>
          <tr><td><code>verlag</code></td><td><span class="badge">optional</span></td><td class="small">Verlag</td></tr>
          <tr><td><code>laenge</code></td><td><span class="badge">optional</span></td><td class="small">Länge — 3'30, 3:30 oder 03:30 werden automatisch zu 3'30</td></tr>
          <tr><td><code>genre</code></td><td><span class="badge">optional</span></td><td class="small">Genre</td></tr>
          <tr><td><code>grad</code></td><td><span class="badge">optional</span></td><td class="small">Zahl 1.0–6.0</td></tr>
          <tr><td><code>haendler_url</code></td><td><span class="badge">optional</span></td><td class="small">Link zum Händler</td></tr>
          <tr><td><code>preis</code></td><td><span class="badge">optional</span></td><td class="small">Preis in €, z.B. 45.00</td></tr>
          <tr><td><code>info</code></td><td><span class="badge">optional</span></td><td class="small">Freitext-Info</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <div>
    <div style="font-weight:700;font-size:13px;margin-bottom:6px">Minimal-Beispiel:</div>
    <pre style="background:#f5f2ee;border-radius:8px;padding:12px;font-size:12px;overflow-x:auto;line-height:1.6">titel;youtube_url
Coldplay in Symphony;https://youtu.be/abc123
Nordische Fahrt;https://youtu.be/def456</pre>
    <div style="font-weight:700;font-size:13px;margin:12px 0 6px">Vollständiges Beispiel:</div>
    <pre style="background:#f5f2ee;border-radius:8px;padding:12px;font-size:12px;overflow-x:auto;line-height:1.6">titel;youtube_url;komponist;arrangeur;verlag;laenge;genre;grad;preis
Coldplay in Symphony;https://youtu.be/abc;diverse;Bert Appermont;Beriato;7'42;Popmusik;4.5;89.00
Nordische Fahrt;https://youtu.be/def;Ernest Majo;;Edition Helbling;6';Marsch;3.0;</pre>
    <div class="small" style="margin-top:8px">Tipp: Den Export dieser Seite kannst du direkt bearbeiten und wieder importieren. Bestehende Titel werden dabei übersprungen.</div>
  </div>
</div>

<!-- Alle Titel löschen -->
<div class="card" style="margin-bottom:12px;border:2px solid var(--red);background:var(--red-soft)">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
    <span style="font-size:24px">⚠️</span>
    <div>
      <div style="font-weight:800;font-size:15px;color:var(--red)">Alle Abstimmungstitel löschen</div>
      <div class="small" style="color:var(--red);opacity:.8">Löscht alle Titel unwiderruflich. Stimmen und Notizen ohne Archiv-Link gehen verloren.</div>
    </div>
  </div>
  <form method="post" onsubmit="return checkConfirmSongs(this)">
    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
    <input type="hidden" name="action" value="delete_all_songs">
    <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <label style="flex:1;min-width:200px">
        <span class="small" style="font-weight:700;color:var(--red)">Zur Bestätigung <strong>LÖSCHEN</strong> eintippen:</span><br>
        <input class="input" type="text" id="confirmSongs" autocomplete="off" style="margin-top:5px;border-color:var(--red);width:100%">
      </label>
      <button class="btn" type="submit" style="background:var(--red);color:#fff;border-color:var(--red);font-weight:700">🗑 Alle Titel löschen</button>
    </div>
  </form>
</div>
<script>
function checkConfirmSongs(f) {
  if (document.getElementById('confirmSongs').value !== 'LÖSCHEN') {
    alert('Bitte genau LÖSCHEN eintippen.');
    return false;
  }
  return confirm('Wirklich ALLE Abstimmungstitel löschen? Das kann nicht rückgängig gemacht werden.');
}
</script>

<?php sv_footer(); ?>
