<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$u   = sv_require_admin();
$pdo = sv_pdo();
$base = sv_base_url();
$cfg  = sv_config();

$dbVersion = '';
try { $dbVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn(); }
catch (Throwable $e) { $dbVersion = 'unbekannt'; }

$cntUsers  = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$cntSongs  = (int)$pdo->query('SELECT COUNT(*) FROM songs')->fetchColumn();
$cntActive = (int)$pdo->query('SELECT COUNT(*) FROM songs WHERE is_active=1')->fetchColumn();
$cntVotes  = (int)$pdo->query('SELECT COUNT(*) FROM votes')->fetchColumn();

$php    = PHP_VERSION;
$sapi   = PHP_SAPI;
$server = $_SERVER['SERVER_SOFTWARE'] ?? '–';
$https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'Ja ✅' : 'Nein ⚠️';
$time   = date('d.m.Y H:i:s');

$sessTimeout  = (int)($cfg['session_timeout_seconds'] ?? 3600);
$lockMax      = (int)($cfg['login_lock_max_attempts'] ?? 5);
$lockMinutes  = (int)($cfg['login_lock_minutes'] ?? 10);
$frozen = sv_is_frozen();

sv_header('Admin – Systeminfo', $u);
?>

<div class="page-header">
  <div>
    <h2>Systeminfo</h2>
    <div class="small">Status, Versionen und Kennzahlen.</div>
  </div>
  <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
</div>

<?php if ($frozen): ?>
<div class="freeze-banner" style="margin-bottom:16px">🔒 <span>Abstimmung ist aktuell eingefroren.</span></div>
<?php endif; ?>

<div class="grid admin-sections">
  <div class="card grid">
    <h3>System</h3>
    <table>
      <tbody>
        <tr><th style="text-transform:none;font-size:13px;color:var(--muted);width:40%">Uhrzeit</th><td><?=h($time)?></td></tr>
        <tr><th style="text-transform:none;font-size:13px;color:var(--muted)">HTTPS</th><td><?=h($https)?></td></tr>
        <tr><th style="text-transform:none;font-size:13px;color:var(--muted)">PHP</th><td><?=h($php)?> <span class="small">(<?=h($sapi)?>)</span></td></tr>
        <tr><th style="text-transform:none;font-size:13px;color:var(--muted)">Server</th><td class="small"><?=h($server)?></td></tr>
      </tbody>
    </table>
  </div>

  <div class="card grid">
    <h3>Datenbank</h3>
    <table>
      <tbody>
        <tr><th style="text-transform:none;font-size:13px;color:var(--muted);width:40%">Version</th><td><?=h($dbVersion)?></td></tr>
        <tr><th style="text-transform:none;font-size:13px;color:var(--muted)">Benutzer</th><td><?=h($cntUsers)?></td></tr>
        <tr><th style="text-transform:none;font-size:13px;color:var(--muted)">Titel</th><td><?=h($cntSongs)?> <span class="small">(<?=h($cntActive)?> aktiv)</span></td></tr>
        <tr><th style="text-transform:none;font-size:13px;color:var(--muted)">Stimmen</th><td><?=h($cntVotes)?></td></tr>
      </tbody>
    </table>
  </div>

  <div class="card grid" style="grid-column:1/-1">
    <h3>Sicherheitseinstellungen</h3>
    <table>
      <tbody>
        <tr><th style="text-transform:none;font-size:13px;color:var(--muted);width:30%">Auto-Logout</th><td><?=h((int)round($sessTimeout/60))?> Minuten Inaktivität</td></tr>
        <tr><th style="text-transform:none;font-size:13px;color:var(--muted)">Login-Limit</th><td><?=h($lockMax)?> Fehlversuche → <?=h($lockMinutes)?> Minuten Sperre</td></tr>
        <tr><th style="text-transform:none;font-size:13px;color:var(--muted)">Freeze</th><td><?= $frozen ? '<strong>aktiv</strong>' : 'aus' ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<?php sv_footer(); ?>
