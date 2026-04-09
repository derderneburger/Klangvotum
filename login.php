<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

sv_start_session();
$user = sv_current_user();
if ($user) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $username = trim($_POST['username'] ?? '');
  $password = (string)($_POST['password'] ?? '');
  if (sv_login($username, $password)) { header('Location: index.php'); exit; }
  $error = 'Login fehlgeschlagen – Benutzername oder Passwort falsch.';
}

sv_header('Login');

$cfg = sv_config();
$brand = $cfg['branding'] ?? [];
$logoSetting = sv_setting_get('logo_path', '');
$logoPath = ($logoSetting !== '' && $logoSetting !== '__none__') ? $logoSetting : ($logoSetting === '__none__' ? '' : ($brand['logo_path'] ?? 'assets/logo.svg'));
$base = sv_base_url();
$logoUrl = ($logoPath !== '' && is_file(__DIR__ . '/' . ltrim($logoPath, '/'))) ? ($base . '/' . ltrim($logoPath, '/')) : null;
$appName = sv_setting_get('app_name', $brand['app_name'] ?? 'KlangVotum');
$orgName = sv_setting_get('org_name', $brand['org_name'] ?? 'Musikschule Hildesheim');
?>

<div class="auth-page">
  <div class="auth-card card grid">
    <?php if ($logoUrl): ?>
      <div class="auth-logo-wrap">
        <img class="auth-logo" src="<?=h($logoUrl)?>" alt="Logo" style="width:<?=h(sv_setting_get('logo_login_width', '120'))?>px">
      </div>
    <?php else: ?>
      <div style="text-align:center;margin-bottom:8px">
        <div style="font-size:1.4rem;font-weight:900;color:var(--accent)"><?=h($appName)?></div>
        <?php if ($orgName): ?><div class="small muted"><?=h($orgName)?></div><?php endif; ?>
      </div>
    <?php endif; ?>

    <h2 class="auth-title">Willkommen</h2>
    <p class="small" style="text-align:center;margin-top:-6px">Melde dich an, um abzustimmen</p>

    <?php if ($error): ?>
      <div class="notice error"><?=h($error)?></div>
    <?php endif; ?>

    <form method="post" class="grid" autocomplete="on" style="margin-top:4px">
      <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
      <label style="display:block">
        Benutzername
        <input name="username" required style="width:100%;margin-top:5px" autocomplete="username">
      </label>
      <label style="display:block">
        Passwort
        <input name="password" type="password" required style="width:100%;margin-top:5px" autocomplete="current-password">
      </label>
      <button class="btn primary" type="submit" style="width:100%;justify-content:center;padding:11px">Einloggen</button>
    </form>
  </div>
</div>

<?php sv_footer(); ?>
