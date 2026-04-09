<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$u = sv_require_login();
$pdo = sv_pdo();
$base = sv_base_url();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $current = trim((string)($_POST['current_password'] ?? ''));
  $new1    = trim((string)($_POST['new_password'] ?? ''));
  $new2    = trim((string)($_POST['new_password2'] ?? ''));

  if ($current === '') sv_flash_set('error', 'Bitte aktuelles Passwort eingeben.');
  elseif (strlen($new1) < 10) sv_flash_set('error', 'Neues Passwort: mindestens 10 Zeichen.');
  elseif ($new1 !== $new2) sv_flash_set('error', 'Die neuen Passwörter stimmen nicht überein.');
  else {
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([(int)$u['id']]);
    $row = $stmt->fetch();
    if (!$row) {
      sv_flash_set('error', 'Benutzer nicht gefunden.');
    } elseif (!password_verify($current, $row['password_hash'])) {
      sv_flash_set('error', 'Aktuelles Passwort ist falsch.');
    } else {
      $hash = password_hash($new1, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
      $stmt->execute([$hash, (int)$u['id']]);
      sv_log((int)$u['id'], 'password_change_self', null);
      sv_flash_set('success', 'Passwort erfolgreich geändert.');
    }
  }
}

sv_header('Mein Konto', $u);
?>

<div class="page-header">
  <div>
    <h2>Mein Konto</h2>
    <div class="small">Eingeloggt als <strong><?=h($u['display_name'])?></strong></div>
  </div>
  <a class="btn" href="<?=h($base)?>/index.php">← Abstimmung</a>
</div>

<div class="card">
  <h3>Passwort ändern</h3>
  <form method="post" class="grid account-form-grid" style="grid-template-columns:1fr 1fr;gap:12px" id="account-pw-form">
    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
    <label style="display:block;grid-column:1/-1">
      Aktuelles Passwort
      <input type="password" name="current_password" required style="width:100%;margin-top:5px" autocomplete="off">
    </label>
    <div style="grid-column:1/-1" class="small" style="color:var(--muted)">⚠ Das neue Passwort muss mindestens 10 Zeichen haben.</div>
    <label style="display:block">
      Neues Passwort
      <input type="password" name="new_password" required minlength="10" style="width:100%;margin-top:5px"
             autocomplete="new-password"
             oninvalid="this.setCustomValidity('Mindestens 10 Zeichen')"
             oninput="this.setCustomValidity('')">
    </label>
    <label style="display:block">
      Neues Passwort wiederholen
      <input type="password" name="new_password2" required minlength="10" style="width:100%;margin-top:5px"
             autocomplete="new-password" id="account-pw2">
    </label>
    <div style="grid-column:1/-1">
      <button class="btn primary" type="submit">Speichern</button>
    </div>
  </form>
  <script>
  (function(){
    var f = document.getElementById('account-pw-form');
    if(!f) return;
    f.addEventListener('submit', function(e){
      var p1 = f.querySelector('[name="new_password"]').value;
      var p2 = f.querySelector('[name="new_password2"]').value;
      if(p1 !== p2){
        e.preventDefault();
        var el = f.querySelector('[name="new_password2"]');
        el.setCustomValidity('Passwörter stimmen nicht überein');
        el.reportValidity();
      } else {
        f.querySelector('[name="new_password2"]').setCustomValidity('');
      }
    });
    f.querySelector('[name="new_password2"]').addEventListener('input', function(){
      this.setCustomValidity('');
    });
  })();
  </script>
</div>

<?php sv_footer(); ?>
