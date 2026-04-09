<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$admin = sv_require_admin();
$pdo   = sv_pdo();
$base  = sv_base_url();

$users = $pdo->query("
  SELECT u.id, u.username, u.display_name, u.is_admin, u.is_active,
         ua.last_activity, ua.last_ip,
         (SELECT created_at FROM audit_log al WHERE al.user_id=u.id AND al.action='login' ORDER BY al.id DESC LIMIT 1) AS last_login
  FROM users u
  LEFT JOIN user_activity ua ON ua.user_id=u.id
  ORDER BY u.is_admin DESC, u.display_name ASC
")->fetchAll();

$events = $pdo->query("
  SELECT al.created_at, al.user_id, u.display_name, u.username, al.action, al.details
  FROM audit_log al
  LEFT JOIN users u ON u.id=al.user_id
  WHERE al.action IN ('login','login_fail','login_locked','logout','password_change','password_reset','role_change')
  ORDER BY al.id DESC LIMIT 150
")->fetchAll();

sv_header('Admin – Login-Info', $admin);
?>

<div class="page-header">
  <div>
    <h2>Login-Informationen</h2>
    <div class="small">Protokolle und Übersicht der Nutzeraktivitäten.</div>
  </div>
  <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
</div>

<div class="card" style="margin-bottom:12px">
  <h3>Letzte Aktivität je Nutzer</h3>
  <div class="table-scroll wide">
  <table>
    <thead>
      <tr><th>Nutzer</th><th>Letzter Login</th><th>Letzte Aktivität</th><th>Letzte IP</th><th>Status</th></tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td>
          <strong><?=h($u['display_name'])?></strong>
          <div class="small"><?=h($u['username'])?><?= (int)$u['is_admin']===1 ? ' · Admin' : '' ?></div>
        </td>
        <td class="small"><?= $u['last_login'] ? h($u['last_login']) : '–' ?></td>
        <td class="small"><?= $u['last_activity'] ? h($u['last_activity']) : '–' ?></td>
        <td class="small"><?= $u['last_ip'] ? h($u['last_ip']) : '–' ?></td>
        <td><?= (int)$u['is_active']===1 ? '<span class="badge" style="color:var(--green);border-color:var(--green-mid);background:var(--green-light)">aktiv</span>' : '<span class="badge">inaktiv</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="card">
  <h3>Sicherheitsprotokoll</h3>
  <div class="small" style="margin-bottom:12px">Erfolgreiche Logins, Fehlversuche und Sperren – neueste zuerst.</div>
  <div class="table-scroll wide">
  <table>
    <thead>
      <tr><th>Zeit</th><th>Nutzer</th><th>Aktion</th><th>Details</th></tr>
    </thead>
    <tbody>
      <?php foreach ($events as $e): ?>
      <tr>
        <td class="small"><?=h($e['created_at'])?></td>
        <td class="small"><?= $e['display_name'] ? h($e['display_name']) : '–' ?></td>
        <td><span class="badge"><?=h($e['action'])?></span></td>
        <td class="small"><?=h($e['details'] ?? '')?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php sv_footer(); ?>
