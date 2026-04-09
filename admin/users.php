<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$admin = sv_require_admin();
$pdo = sv_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $action = $_POST['action'] ?? '';
  if ($action === 'create') {
    $username = trim($_POST['username'] ?? '');
    $display = trim($_POST['display_name'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $role = in_array($_POST['role'] ?? '', ['user','leitung','admin'], true) ? $_POST['role'] : 'user';
    $is_admin = ($role === 'admin') ? 1 : 0;
    $has_chronik = !empty($_POST['has_chronik']) ? 1 : 0;
    $has_noten = !empty($_POST['has_noten']) ? 1 : 0;

    if (!preg_match('/^[a-zA-Z0-9_\-\.]{3,50}$/', $username)) sv_flash_set('error', 'Benutzername: 3-50 Zeichen (a-z, 0-9, _-.).');
    elseif (strlen($password) < 8) sv_flash_set('error', 'Passwort mindestens 8 Zeichen.');
    else {
      try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, display_name, password_hash, is_admin, role, has_chronik, has_noten) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $display ?: $username, $hash, $is_admin, $role, $has_chronik, $has_noten]);
        sv_log($admin['id'], 'user_create', "$username role=$role");
        sv_flash_set('success', 'Benutzer angelegt.');
      } catch (Throwable $e) { sv_flash_set('error', 'Konnte Benutzer nicht anlegen (Name schon vergeben?).'); }
    }
  
  } elseif ($action === 'set_password') {
    $uid = (int)($_POST['uid'] ?? 0);
    $pw1 = (string)($_POST['new_password'] ?? '');
    $pw2 = (string)($_POST['new_password2'] ?? '');
    if (!$uid) sv_flash_set('error', 'Ungültiger Benutzer (uid fehlt).');
    elseif (strlen($pw1) < 10) sv_flash_set('error', 'Passwort mindestens 10 Zeichen (erhalten: ' . strlen($pw1) . ').');
    elseif ($pw1 !== $pw2) sv_flash_set('error', 'Passwörter stimmen nicht überein.');
    else {
      try {
        $hash = password_hash($pw1, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $uid]);
        $rows = $stmt->rowCount();
        sv_log($admin['id'], 'user_password_set', "uid=$uid");
        sv_flash_set('success', 'Passwort geändert (' . $rows . ' Zeile aktualisiert).');
      } catch (Throwable $e) {
        sv_flash_set('error', 'DB-Fehler: ' . $e->getMessage());
      }
    }
    header('Location: ' . sv_base_url() . '/admin/users.php');
    exit;
  } elseif ($action === 'set_role') {
    $uid  = (int)($_POST['uid'] ?? 0);
    $role = in_array($_POST['role'] ?? '', ['user','leitung','admin'], true) ? $_POST['role'] : 'user';
    $has_chronik = !empty($_POST['has_chronik']) ? 1 : 0;
    $has_noten = !empty($_POST['has_noten']) ? 1 : 0;
    if (!$uid) sv_flash_set('error', 'Ungültiger Benutzer.');
    elseif ($uid === (int)$admin['id'] && $role !== 'admin') {
      sv_flash_set('error', 'Du kannst deine eigene Admin-Rolle nicht entfernen.');
    } else {
      $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
      $stmt->execute([$uid]);
      $cur = $stmt->fetch();
      if (!$cur) { sv_flash_set('error', 'Benutzer nicht gefunden.'); }
      else {
        // Sicherstellen dass mindestens 1 Admin bleibt
        if ($cur['role'] === 'admin' && $role !== 'admin') {
          $adminsCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1")->fetchColumn();
          if ($adminsCount <= 1) {
            sv_flash_set('error', 'Es muss mindestens ein aktiver Admin existieren.');
            header('Location: ' . sv_base_url() . '/admin/users.php');
            exit;
          }
        }
        $is_admin = ($role === 'admin') ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE users SET role = ?, is_admin = ?, has_chronik = ?, has_noten = ? WHERE id = ?");
        $stmt->execute([$role, $is_admin, $has_chronik, $has_noten, $uid]);
        sv_log($admin['id'], 'user_role_set', "uid=$uid role=$role has_chronik=$has_chronik has_noten=$has_noten");
        sv_flash_set('success', 'Rolle geändert.');
      }
    }
    header('Location: ' . sv_base_url() . '/admin/users.php');
    exit;
  } elseif ($action === 'toggle_active') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid && $uid !== (int)$admin['id']) {
      $stmt = $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE id = ?");
      $stmt->execute([$uid]);
      sv_log($admin['id'], 'user_toggle', "uid=$uid");
      sv_flash_set('success', 'Benutzerstatus geändert.');
    } else sv_flash_set('error', 'Du kannst dich nicht selbst deaktivieren.');
    header('Location: ' . sv_base_url() . '/admin/users.php');
    exit;
  } elseif ($action === 'delete') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid && $uid !== (int)$admin['id']) {
      $pdo->beginTransaction();
      // Alles vom Nutzer löschen
      $pdo->prepare("DELETE FROM votes       WHERE user_id = ?")->execute([$uid]);
      $pdo->prepare("DELETE FROM vote_notes  WHERE user_id = ?")->execute([$uid]);
      $pdo->prepare("DELETE FROM vote_history WHERE user_id = ?")->execute([$uid]);
      $pdo->prepare("DELETE FROM users        WHERE id = ?")->execute([$uid]);
      $pdo->commit();
      sv_log($admin['id'], 'user_delete', "uid=$uid incl. votes");
      sv_flash_set('success', 'Benutzer und alle zugehörigen Stimmen gelöscht.');
    } else sv_flash_set('error', 'Du kannst dich nicht selbst löschen.');
    header('Location: ' . sv_base_url() . '/admin/users.php');
    exit;
  } else {
    header('Location: ' . sv_base_url() . '/admin/users.php');
    exit;
  }
}

$users = $pdo->query("SELECT id, username, display_name, is_admin, role, has_chronik, has_noten, is_active, created_at FROM users ORDER BY role ASC, username ASC")->fetchAll();

sv_header('Admin – Benutzer', $admin);
$base         = sv_base_url();
$userLabel    = sv_setting_get('user_role_label',    'O-Rat');
$leitungLabel = sv_setting_get('leitung_role_label', 'Leitung');
?>
<div class="page-header">
  <div>
    <h2>Benutzer</h2>
    <div class="muted">Musiker-Logins verwalten.</div>
  </div>
  <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
</div>

<div class="card" style="margin-top:12px">
  <h3>Neuen Benutzer anlegen</h3>
  <form method="post" class="grid form-grid-2" style="grid-template-columns:repeat(2,1fr);gap:10px">
    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
    <input type="hidden" name="action" value="create">
    <label>Benutzername<br><input name="username" required></label>
    <label>Anzeigename<br><input name="display_name"></label>
    <label>Passwort<br><input name="password" type="password" required></label>
    <label>Rolle<br>
      <div class="select-wrap" style="margin-top:5px">
        <select name="role">
          <option value="user"><?=h($userLabel)?></option>
          <option value="leitung"><?=h($leitungLabel)?></option>
          <option value="admin">Admin</option>
        </select>
      </div>
    </label>
    <div style="grid-column:1/-1;display:flex;gap:18px;flex-wrap:wrap">
      <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" name="has_chronik" value="1"> Chronik-Zugriff
      </label>
      <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" name="has_noten" value="1"> Noten-Zugriff
      </label>
    </div>
    <div style="grid-column:1/-1"><button class="btn primary" type="submit">Anlegen</button></div>
  </form>
</div>

<div class="card" style="margin-top:12px">
  <h3>Vorhandene Benutzer</h3>
  <div class="table-scroll">
  <table>
    <thead><tr><th>Name</th><th>Rolle</th><th>Status</th><th>Aktion</th></tr></thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?=h($u['display_name'])?> <span class="small">(<?=h($u['username'])?>)</span></td>
          <td><?= sv_role_badge($u['role'] ?? ($u['is_admin'] ? 'admin' : 'user')) ?><?php if ((int)($u['has_chronik'] ?? 0)): ?> <?= sv_chronik_badge() ?><?php endif; ?><?php if ((int)($u['has_noten'] ?? 0)): ?> <?= sv_noten_badge() ?><?php endif; ?></td>
          <td><?= ((int)$u['is_active']===1) ? '<span class="badge">aktiv</span>' : '<span class="badge">inaktiv</span>' ?></td>
          <td>
            <button class="btn" type="button" data-open-dialog="edit-user-<?=h($u['id'])?>">Bearbeiten</button>

            <dialog id="edit-user-<?=h($u['id'])?>" class="sv-dialog">
              <div class="sv-dialog__panel">

                <!-- Header -->
                <div class="sv-dialog__head">
                  <div>
                    <div class="sv-dialog__title">Benutzer bearbeiten</div>
                    <div class="sv-dialog__sub"><?=h($u['display_name'] ?: $u['username'])?></div>
                  </div>
                  <button class="sv-dialog__close" type="button" data-close-dialog aria-label="Schließen">✕</button>
                </div>

                <!-- Rolle -->
                <div class="sv-dialog__section">
                  <div class="sv-dialog__section-label">Rolle</div>
                  <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
                    <input type="hidden" name="uid" value="<?=h($u['id'])?>">
                    <input type="hidden" name="action" value="set_role">
                    <div class="select-wrap" style="flex:1;min-width:120px;max-width:200px">
                      <select name="role">
                        <option value="user"    <?=($u['role']??'')==='user'    ?'selected':''?>><?=h($userLabel)?></option>
                        <option value="leitung" <?=($u['role']??'')==='leitung' ?'selected':''?>><?=h($leitungLabel)?></option>
                        <option value="admin"   <?=($u['role']??'')==='admin'   ?'selected':''?>>Admin</option>
                      </select>
                    </div>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                      <input type="checkbox" name="has_chronik" value="1" <?= (int)($u['has_chronik'] ?? 0) ? 'checked' : '' ?>> Chronik
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer">
                      <input type="checkbox" name="has_noten" value="1" <?= (int)($u['has_noten'] ?? 0) ? 'checked' : '' ?>> Noten
                    </label>
                    <button class="btn primary" type="submit">Speichern</button>
                  </form>
                </div>

                <!-- Passwort -->
                <div class="sv-dialog__section">
                  <div class="sv-dialog__section-label">Passwort ändern</div>
                  <form method="post" class="grid form-grid-2" style="grid-template-columns:1fr 1fr;gap:10px" id="pw-form-<?=h($u['id'])?>">
                    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
                    <input type="hidden" name="uid" value="<?=h($u['id'])?>">
                    <input type="hidden" name="action" value="set_password">
                    <label>Neues Passwort<br>
                      <input type="password" name="new_password" required minlength="10"
                             style="width:100%;margin-top:5px" autocomplete="new-password"
                             oninvalid="this.setCustomValidity('Mindestens 10 Zeichen')"
                             oninput="this.setCustomValidity('')">
                    </label>
                    <label>Wiederholen<br>
                      <input type="password" name="new_password2" required minlength="10"
                             style="width:100%;margin-top:5px" autocomplete="new-password"
                             id="pw2-<?=h($u['id'])?>">
                    </label>
                    <div style="grid-column:1/-1">
                      <div class="small" style="margin-bottom:8px;color:var(--muted)">⚠ Mindestens 10 Zeichen.</div>
                      <button class="btn" type="submit">Passwort setzen</button>
                    </div>
                  </form>
                  <script>
                  (function(){
                    var f = document.getElementById('pw-form-<?=h($u['id'])?>');
                    if(!f) return;
                    f.addEventListener('submit', function(e){
                      var p1 = f.querySelector('[name="new_password"]').value;
                      var p2 = f.querySelector('[name="new_password2"]').value;
                      if(p1 !== p2){
                        e.preventDefault();
                        f.querySelector('[name="new_password2"]').setCustomValidity('Passwörter stimmen nicht überein');
                        f.querySelector('[name="new_password2"]').reportValidity();
                      } else {
                        f.querySelector('[name="new_password2"]').setCustomValidity('');
                      }
                    });
                    f.querySelector('[name="new_password2"]').addEventListener('input', function(){
                      this.setCustomValidity('');
                    });
                  })();
                  </script>
                  <?php if ((int)$u['id'] === (int)$admin['id']): ?>
                    <div class="small" style="margin-top:8px">Eigenes Passwort auch unter <a href="<?=h($base)?>/account.php">Mein Konto</a> änderbar.</div>
                  <?php endif; ?>
                </div>

                <!-- Gefahrenzone -->
                <div class="sv-dialog__section sv-dialog__section--danger">
                  <div class="sv-dialog__section-label">Konto</div>
                  <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <form method="post">
                      <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
                      <input type="hidden" name="uid" value="<?=h($u['id'])?>">
                      <button class="btn" name="action" value="toggle_active" type="submit">
                        <?= (int)$u['is_active']===1 ? '⏸ Deaktivieren' : '▶ Aktivieren' ?>
                      </button>
                    </form>
                    <form method="post" onsubmit="return confirm('Benutzer wirklich löschen?');">
                      <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
                      <input type="hidden" name="uid" value="<?=h($u['id'])?>">
                      <button class="btn danger" name="action" value="delete" type="submit">🗑 Löschen</button>
                    </form>
                  </div>
                </div>

              </div>
            </dialog>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="4" class="small">Noch keine Benutzer.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<script>
(function svDialogInit(){
  function closestDialog(el){
    while(el && el.tagName !== 'DIALOG'){ el = el.parentElement; }
    return el;
  }
  document.querySelectorAll('[data-open-dialog]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var id = btn.getAttribute('data-open-dialog');
      var dlg = document.getElementById(id);
      if(dlg && typeof dlg.showModal === 'function') dlg.showModal();
    });
  });
  document.addEventListener('click', function(e){
    var closeBtn = e.target.closest('[data-close-dialog]');
    if(closeBtn){
      var dlg = closestDialog(closeBtn);
      if(dlg) dlg.close();
    }
  });
  // backdrop click intentionally disabled
})();
</script>

<?php sv_footer(); ?>
