<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = sv_require_login();
if (!sv_is_admin($user)) {
  http_response_code(403);
  exit('Forbidden');
}
$pdo  = sv_pdo();
$base = sv_base_url();
$cfg  = sv_config();
$brand = $cfg['branding'] ?? [];

// ── POST-Aktionen ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $action = $_POST['action'] ?? '';

  // Logos-Ordner sicherstellen
  $logosDir = __DIR__ . '/../assets/logos';
  if (!is_dir($logosDir)) @mkdir($logosDir, 0755, true);

  if ($action === 'save_settings') {
    $fields = [
      'user_role_label'    => trim($_POST['user_role_label']    ?? ''),
      'leitung_role_label' => trim($_POST['leitung_role_label'] ?? ''),
      'app_name'           => trim($_POST['app_name']           ?? ''),
      'org_name'           => trim($_POST['org_name']           ?? ''),
    ];
    foreach ($fields as $k => $v) {
      sv_setting_set($k, $v);
    }

    foreach (['color_primary', 'color_secondary'] as $cf) {
      $val = trim($_POST[$cf] ?? '');
      if (preg_match('/^#[0-9a-fA-F]{6}$/', $val)) sv_setting_set($cf, $val);
    }

    $logoWidth = (int)($_POST['logo_login_width'] ?? 0);
    if ($logoWidth >= 60 && $logoWidth <= 400) sv_setting_set('logo_login_width', (string)$logoWidth);

    // Logo-Upload (neues Logo in Galerie)
    $file = $_FILES['logo_upload'] ?? null;
    if ($file && $file['error'] === UPLOAD_ERR_OK) {
      $allowed = ['image/svg+xml', 'image/png', 'image/jpeg'];
      $extMap  = ['image/svg+xml' => 'svg', 'image/png' => 'png', 'image/jpeg' => 'jpg'];
      $mime    = mime_content_type($file['tmp_name']);
      if (!in_array($mime, $allowed)) {
        sv_flash_set('error', 'Ungültiges Dateiformat. Erlaubt: SVG, PNG, JPG.');
      } elseif ($file['size'] > 2 * 1024 * 1024) {
        sv_flash_set('error', 'Datei zu groß (max. 2 MB).');
      } else {
        $ext  = $extMap[$mime];
        $name = pathinfo($file['name'], PATHINFO_FILENAME);
        $name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
        $dest = $logosDir . '/' . $name . '.' . $ext;
        $i = 1;
        while (is_file($dest)) { $dest = $logosDir . '/' . $name . '_' . $i . '.' . $ext; $i++; }
        move_uploaded_file($file['tmp_name'], $dest);
        $relPath = 'assets/logos/' . basename($dest);
        sv_setting_set('logo_path', $relPath);
        sv_log($user['id'], 'settings_logo_upload', "file=" . basename($dest));
      }
    }

    // Logo-Auswahl (aus Galerie)
    $selectedLogo = trim($_POST['selected_logo'] ?? '');
    if ($selectedLogo === '__none__') {
      sv_setting_set('logo_path', '__none__');
    } elseif ($selectedLogo !== '' && !($file && $file['error'] === UPLOAD_ERR_OK)) {
      if (is_file(__DIR__ . '/../' . $selectedLogo)) {
        sv_setting_set('logo_path', $selectedLogo);
      }
    }

    sv_log($user['id'], 'settings_save', null);
    sv_flash_set('success', 'Einstellungen gespeichert.');

  } elseif ($action === 'delete_logo') {
    $delFile = trim($_POST['logo_file'] ?? '');
    if ($delFile && str_starts_with($delFile, 'assets/logos/')) {
      $fullPath = __DIR__ . '/../' . $delFile;
      if (is_file($fullPath)) {
        @unlink($fullPath);
        if (sv_setting_get('logo_path') === $delFile) sv_setting_delete('logo_path');
        sv_flash_set('success', 'Logo gelöscht.');
      }
    }

  } elseif ($action === 'save_impressum') {
    sv_setting_set('impressum_html', $_POST['impressum_html'] ?? '');
    sv_log($user['id'], 'settings_impressum', null);
    sv_flash_set('success', 'Impressum gespeichert.');

  } elseif ($action === 'save_datenschutz') {
    sv_setting_set('datenschutz_html', $_POST['datenschutz_html'] ?? '');
    sv_log($user['id'], 'settings_datenschutz', null);
    sv_flash_set('success', 'Datenschutzerklärung gespeichert.');

  }

  header('Location: ' . $base . '/admin/einstellungen.php');
  exit;
}

// ── Aktuelle Werte laden ─────────────────────────────────────────────────────
$userLabel     = sv_setting_get('user_role_label',    'O-Rat');
$leitungLabel  = sv_setting_get('leitung_role_label', 'Leitung');
$appName       = sv_setting_get('app_name',           $brand['app_name']  ?? 'KlangVotum');
$orgName       = sv_setting_get('org_name',           $brand['org_name']  ?? 'Musikschule Hildesheim');
$colorPrimary   = sv_setting_get('color_primary',   '#c1090f');
$colorSecondary = sv_setting_get('color_secondary', '#7a8c0a');
$logoPath      = sv_setting_get('logo_path', '');
$noLogo        = ($logoPath === '__none__');

// Logo-Galerie: assets/logos/ + Legacy-Logos aus assets/
$logosDir = __DIR__ . '/../assets/logos';
if (!is_dir($logosDir)) @mkdir($logosDir, 0755, true);
$galleryLogos = [];
// Logos im Galerie-Ordner
foreach (glob($logosDir . '/*.{svg,png,jpg,jpeg}', GLOB_BRACE) as $f) {
  $rel = 'assets/logos/' . basename($f);
  $galleryLogos[] = ['path' => $rel, 'url' => $base . '/' . $rel, 'name' => basename($f)];
}
// Legacy: assets/logo.svg, assets/logo_custom.*
$assetsDir = __DIR__ . '/../assets';
foreach (glob($assetsDir . '/logo{,_custom}.{svg,png,jpg,jpeg}', GLOB_BRACE) as $f) {
  $rel = 'assets/' . basename($f);
  $already = array_column($galleryLogos, 'path');
  if (!in_array($rel, $already)) {
    $galleryLogos[] = ['path' => $rel, 'url' => $base . '/' . $rel, 'name' => basename($f)];
  }
}

$defaultImpressum = '<h3>Verantwortlich</h3>
<p><strong>Tobias Kropp</strong><br>
Wanneweg 2<br>
31162 Bad Salzdetfurth<br>
Deutschland</p>
<p><strong>E-Mail:</strong> <a href="mailto:kropp.tobias@gmail.com">kropp.tobias@gmail.com</a></p>

<h3 style="margin-top:20px">Haftungsausschluss</h3>
<p>Diese Webanwendung dient ausschließlich der internen Abstimmung und ist nicht für die Öffentlichkeit bestimmt.
Der Zugang ist auf registrierte Nutzer beschränkt.</p>
<p>Trotz sorgfältiger Kontrolle übernehmen wir keine Haftung für die Inhalte externer Links.
Für den Inhalt der verlinkten Seiten sind ausschließlich deren Betreiber verantwortlich.</p>

<h3 style="margin-top:20px">Urheberrecht</h3>
<p>Die durch den Seitenbetreiber erstellten Inhalte und Werke auf dieser Seite unterliegen dem deutschen Urheberrecht.</p>';

$defaultDatenschutz = '<h3>1. Verantwortlicher</h3>
<p><strong>Tobias Kropp</strong><br>
E-Mail: <a href="mailto:kropp.tobias@gmail.com">kropp.tobias@gmail.com</a></p>

<h3 style="margin-top:20px">2. Zweck der Anwendung</h3>
<p>KlangVotum ist ein privates Tool für die interne Abstimmung über Musikstücke zur Vorbereitung von Konzerten.
Die Nutzung ist auf registrierte und autorisierte Personen beschränkt.</p>

<h3 style="margin-top:20px">3. Gespeicherte Daten</h3>
<p>Benutzername, Anzeigename, Passwort (bcrypt-Hash), Stimmen und Notizen, IP-Adresse, letzte Aktivität sowie Login-Protokolle.
Rechtsgrundlage: Art. 6 Abs. 1 lit. b und f DSGVO.</p>

<h3 style="margin-top:20px">4. Keine Weitergabe an Dritte</h3>
<p>Deine Daten werden nicht an Dritte weitergegeben, verkauft oder für Werbezwecke genutzt.</p>

<h3 style="margin-top:20px">5. Speicherdauer</h3>
<p>Deine Daten werden für die Dauer der Nutzung gespeichert.
Login-Protokolle werden nach spätestens 2 Tagen automatisch gelöscht.</p>

<h3 style="margin-top:20px">6. Deine Rechte</h3>
<p>Du hast gemäß DSGVO das Recht auf Auskunft (Art. 15), Berichtigung (Art. 16), Löschung (Art. 17),
Einschränkung (Art. 18), Widerspruch (Art. 21) sowie das Recht auf Beschwerde bei der zuständigen Datenschutzbehörde.</p>

<h3 style="margin-top:20px">7. Cookies und Sessions</h3>
<p>Diese Anwendung verwendet ausschließlich einen technisch notwendigen Session-Cookie.
Es werden keine Tracking- oder Werbe-Cookies eingesetzt.</p>';

$impressumHtml   = sv_setting_get('impressum_html',   $defaultImpressum);
$datenschutzHtml = sv_setting_get('datenschutz_html', $defaultDatenschutz);

sv_header('Software-Einstellungen', $user);
?>

<div class="page-header">
  <div>
    <h2>Software-Einstellungen</h2>
    <div class="muted">Branding, Farben und Bezeichnungen anpassen</div>
  </div>
  <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
</div>

<!-- ═══ ALLGEMEIN (1 Formular) ═══ -->
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
  <input type="hidden" name="action" value="save_settings">

  <div class="card" style="margin-bottom:12px">
    <!-- Anwendung -->
    <h3 style="margin-bottom:14px">Anwendung</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px">
      <label>
        App-Name
        <div class="small muted" style="margin-bottom:6px">Wird im Titel, Header und Footer angezeigt</div>
        <input class="input" name="app_name" value="<?=h($appName)?>" placeholder="KlangVotum" style="width:100%">
      </label>
      <label>
        Organisations-Name
        <div class="small muted" style="margin-bottom:6px">Unter dem App-Namen im Header</div>
        <input class="input" name="org_name" value="<?=h($orgName)?>" placeholder="Musikschule Hildesheim" style="width:100%">
      </label>
    </div>

    <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

    <!-- Rollenbezeichnungen -->
    <h3 style="margin-bottom:14px">Rollenbezeichnungen</h3>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px">
      <label>
        Bezeichnung für Mitglieder
        <div class="small muted" style="margin-bottom:6px">z.B. O-Rat, Vorstand, Mitglied</div>
        <input class="input" name="user_role_label" value="<?=h($userLabel)?>" placeholder="O-Rat" style="width:100%">
      </label>
      <label>
        Bezeichnung für Leitung
        <div class="small muted" style="margin-bottom:6px">z.B. Leitung, Dirigent</div>
        <input class="input" name="leitung_role_label" value="<?=h($leitungLabel)?>" placeholder="Leitung" style="width:100%">
      </label>
    </div>

    <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

    <!-- Vereinsfarben -->
    <h3 style="margin-bottom:14px">Vereinsfarben</h3>
    <div class="small muted" style="margin-bottom:14px">Hover-Farben und helle Varianten werden automatisch berechnet, damit alles lesbar bleibt. Lösch-Buttons und Fehlermeldungen bleiben immer rot.</div>
    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px">
      <div>
        <label style="display:block;margin-bottom:6px">
          Primärfarbe
          <div class="small muted">Buttons, Links, aktive Elemente, Topbar-Akzente</div>
        </label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" name="color_primary" id="color_primary" value="<?=h($colorPrimary)?>"
                 style="width:48px;height:36px;border:1px solid var(--border);border-radius:6px;cursor:pointer;padding:2px"
                 oninput="document.getElementById('color_primary_hex').value=this.value;updatePreview()">
          <input class="input" type="text" id="color_primary_hex" value="<?=h($colorPrimary)?>" maxlength="7"
                 style="width:90px;font-family:monospace"
                 oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value)){document.getElementById('color_primary').value=this.value;updatePreview()}">
        </div>
      </div>
      <div>
        <label style="display:block;margin-bottom:6px">
          Sekundärfarbe
          <div class="small muted">Navigation, Bestätigungen, Checkboxen, Fokus-Rahmen</div>
        </label>
        <div style="display:flex;gap:8px;align-items:center">
          <input type="color" name="color_secondary" id="color_secondary" value="<?=h($colorSecondary)?>"
                 style="width:48px;height:36px;border:1px solid var(--border);border-radius:6px;cursor:pointer;padding:2px"
                 oninput="document.getElementById('color_secondary_hex').value=this.value;updatePreview()">
          <input class="input" type="text" id="color_secondary_hex" value="<?=h($colorSecondary)?>" maxlength="7"
                 style="width:90px;font-family:monospace"
                 oninput="if(/^#[0-9a-fA-F]{6}$/.test(this.value)){document.getElementById('color_secondary').value=this.value;updatePreview()}">
        </div>
      </div>
    </div>

    <!-- Vorschau -->
    <div id="color-preview" style="margin-top:18px;padding:14px;border:1px solid var(--border);border-radius:8px;background:#fafafa">
      <div class="small muted" style="margin-bottom:10px">Vorschau — zeigt auch die automatisch berechneten Varianten</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button type="button" id="prev-btn-primary" style="padding:7px 16px;border:none;border-radius:6px;color:#fff;font-weight:600;cursor:default">Button</button>
        <button type="button" id="prev-btn-hover" style="padding:7px 16px;border:none;border-radius:6px;color:#fff;font-weight:600;cursor:default">Hover</button>
        <span id="prev-nav-active" style="padding:5px 14px;border-radius:20px;font-size:13px;font-weight:700;color:#fff;border:2px solid transparent">Aktiv</span>
        <span id="prev-nav-inactive" style="padding:5px 14px;border-radius:20px;font-size:13px;font-weight:700;background:#fff;border:2px solid">Navigation</span>
        <span id="prev-badge" style="padding:3px 9px;border-radius:20px;font-size:12px;font-weight:600;border:1px solid">Badge</span>
        <span style="padding:3px 9px;border-radius:20px;font-size:12px;font-weight:600;background:#fdecea;color:#C1090F;border:1px solid rgba(193,9,15,.3)">Löschen (immer rot)</span>
      </div>
    </div>

    <hr style="border:none;border-top:1px solid var(--border);margin:20px 0">

    <!-- Logo -->
    <h3 style="margin-bottom:14px">Logo</h3>

    <!-- Logo-Galerie -->
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
      <!-- Kein Logo -->
      <label style="cursor:pointer">
        <input type="radio" name="selected_logo" value="__none__" <?= $noLogo ? 'checked' : '' ?> style="display:none" onchange="document.querySelectorAll('.logo-tile').forEach(t=>t.style.outline='');this.closest('label').querySelector('.logo-tile').style.outline='2.5px solid var(--accent)'">
        <div class="logo-tile" style="width:100px;height:70px;border:1px solid var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;background:#fff;padding:6px;<?= $noLogo ? 'outline:2.5px solid var(--accent)' : '' ?>">
          <span style="font-size:11px;color:var(--muted);text-align:center">Kein Logo</span>
        </div>
      </label>
      <?php foreach ($galleryLogos as $gl): ?>
      <div style="position:relative">
        <label style="cursor:pointer">
          <input type="radio" name="selected_logo" value="<?=h($gl['path'])?>" <?= $logoPath === $gl['path'] ? 'checked' : '' ?> style="display:none" onchange="document.querySelectorAll('.logo-tile').forEach(t=>t.style.outline='');this.closest('label').querySelector('.logo-tile').style.outline='2.5px solid var(--accent)'">
          <div class="logo-tile" style="width:100px;height:70px;border:1px solid var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;background:#fff;padding:6px;<?= $logoPath === $gl['path'] ? 'outline:2.5px solid var(--accent)' : '' ?>">
            <img src="<?=h($gl['url'])?>" alt="<?=h($gl['name'])?>" style="max-width:100%;max-height:100%;object-fit:contain">
          </div>
        </label>
        <div style="display:flex;gap:6px;justify-content:center;margin-top:5px">
          <a href="<?=h($gl['url'])?>" download title="Download" class="btn" style="font-size:11px;padding:2px 8px;color:var(--muted)">⬇ Download</a>
          <?php if (str_starts_with($gl['path'], 'assets/logos/')): ?>
          <button type="button" title="Löschen" class="btn" style="font-size:11px;padding:2px 8px;color:var(--red)" onclick="if(confirm('Logo-Datei endgültig löschen?')){var f=document.createElement('form');f.method='post';f.innerHTML='<input type=hidden name=csrf value=<?=h(sv_csrf_token())?>><input type=hidden name=action value=delete_logo><input type=hidden name=logo_file value=<?=h($gl['path'])?>>';document.body.appendChild(f);f.submit()}">✕</button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">
      <div>
        <label style="display:block;margin-bottom:6px">
          Neues Logo hochladen
          <div class="small muted" style="margin-bottom:6px">SVG, PNG oder JPG · max. 2 MB</div>
        </label>
        <input type="file" name="logo_upload" accept=".svg,.png,.jpg,.jpeg" style="font-size:13px">
      </div>
      <div>
        <label style="display:block">
          Logo-Breite (Login)
          <div class="small muted" style="margin-bottom:6px">Breite in Pixel (60–400)</div>
          <input class="input" type="number" name="logo_login_width" value="<?=h(sv_setting_get('logo_login_width', '120'))?>" min="60" max="400" step="10" style="width:120px">
        </label>
      </div>
    </div>
  </div>

  <div style="margin-top:12px;margin-bottom:20px">
    <button class="btn primary" type="submit">Einstellungen speichern</button>
  </div>
</form>

<!-- ═══ IMPRESSUM ═══ -->
<form method="post">
  <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
  <input type="hidden" name="action" value="save_impressum">
  <div class="card" style="margin-bottom:12px">
    <h3 style="margin-bottom:8px">Impressum</h3>
    <div class="small muted" style="margin-bottom:10px">HTML-Inhalt der Impressum-Seite. Leer lassen für den Standard-Text.</div>
    <textarea name="impressum_html" rows="10" style="width:100%;font-family:monospace;font-size:13px;padding:10px;border:1.5px solid var(--border);border-radius:10px;background:#faf9f7;resize:vertical"><?=h($impressumHtml)?></textarea>
    <button class="btn primary" type="submit" style="margin-top:10px">Impressum speichern</button>
  </div>
</form>

<!-- ═══ DATENSCHUTZ ═══ -->
<form method="post">
  <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
  <input type="hidden" name="action" value="save_datenschutz">
  <div class="card" style="margin-bottom:12px">
    <h3 style="margin-bottom:8px">Datenschutzerklärung</h3>
    <div class="small muted" style="margin-bottom:10px">HTML-Inhalt der Datenschutz-Seite. Leer lassen für den Standard-Text.</div>
    <textarea name="datenschutz_html" rows="10" style="width:100%;font-family:monospace;font-size:13px;padding:10px;border:1.5px solid var(--border);border-radius:10px;background:#faf9f7;resize:vertical"><?=h($datenschutzHtml)?></textarea>
    <button class="btn primary" type="submit" style="margin-top:10px">Datenschutz speichern</button>
  </div>
</form>

<script>
function hexToRgb(hex) {
  hex = hex.replace('#','');
  return [parseInt(hex.substring(0,2),16), parseInt(hex.substring(2,4),16), parseInt(hex.substring(4,6),16)];
}
function rgbToHex(r,g,b) {
  return '#' + [r,g,b].map(function(c){ return Math.max(0,Math.min(255,Math.round(c))).toString(16).padStart(2,'0'); }).join('');
}
function darken(hex, factor) {
  var rgb = hexToRgb(hex);
  return rgbToHex(rgb[0]*(1-factor), rgb[1]*(1-factor), rgb[2]*(1-factor));
}
function mixWhite(hex, amount) {
  var rgb = hexToRgb(hex);
  return rgbToHex(rgb[0]*amount+255*(1-amount), rgb[1]*amount+255*(1-amount), rgb[2]*amount+255*(1-amount));
}
function updatePreview() {
  var primary   = document.getElementById('color_primary').value;
  var secondary = document.getElementById('color_secondary').value;
  var hover     = darken(primary, 0.15);
  var secLight  = mixWhite(secondary, 0.10);
  var secMid    = mixWhite(secondary, 0.35);

  document.getElementById('prev-btn-primary').style.background  = primary;
  document.getElementById('prev-btn-hover').style.background    = hover;
  document.getElementById('prev-nav-active').style.background   = secondary;
  document.getElementById('prev-nav-active').style.borderColor  = secondary;
  document.getElementById('prev-nav-inactive').style.borderColor = secMid;
  document.getElementById('prev-nav-inactive').style.color      = '#1a1a18';
  document.getElementById('prev-badge').style.background        = secLight;
  document.getElementById('prev-badge').style.color             = secondary;
  document.getElementById('prev-badge').style.borderColor       = secMid;
}
updatePreview();
</script>

<?php sv_footer(); ?>
