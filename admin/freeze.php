<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$admin = sv_require_admin();

sv_flash_set('success', ''); sv_flash_set('error', '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $action = $_POST['action'] ?? '';
  if ($action === 'freeze_on') {
    sv_set_frozen((int)$admin['id'], true);
    sv_flash_set('success', 'Abstimmung wurde eingefroren.');
  } elseif ($action === 'freeze_off') {
    sv_set_frozen((int)$admin['id'], false);
    sv_flash_set('success', 'Abstimmung wurde wieder freigegeben.');
  }
}

$frozen = sv_is_frozen();

sv_header('Admin – Einfrieren', $admin);
$base = sv_base_url();
?>

<div class="page-header">
  <div>
    <h2>Einfrieren</h2>
    <div class="small">Wenn eingefroren, können Teilnehmer keine Stimmen ändern.</div>
  </div>
  <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
</div>

<div class="card">
  <div class="row" style="justify-content:space-between;align-items:center">
    <div>
      <div class="small" style="margin-bottom:4px">Aktueller Status</div>
      <?php if ($frozen): ?>
        <span class="badge" style="background:var(--red-soft);border-color:rgba(193,9,15,.3);color:var(--red);font-size:14px;padding:5px 14px">🔒 Eingefroren</span>
      <?php else: ?>
        <span class="badge" style="background:var(--green-light);border-color:var(--green-mid);color:var(--green);font-size:14px;padding:5px 14px">✅ Offen</span>
      <?php endif; ?>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
      <?php if (!$frozen): ?>
        <button class="btn danger" type="submit" name="action" value="freeze_on"
          onclick="return confirm('Abstimmung wirklich einfrieren?')">🔒 Jetzt einfrieren</button>
      <?php else: ?>
        <button class="btn primary" type="submit" name="action" value="freeze_off">✅ Auftauen</button>
      <?php endif; ?>
    </form>
  </div>
  <div class="small" style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border)">
    Beim Einfrieren können Teilnehmer ihre bisherigen Stimmen sehen, aber keine mehr abgeben oder ändern.
  </div>
</div>

<?php sv_footer(); ?>
