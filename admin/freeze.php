<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$admin = sv_require_admin();
$base  = sv_base_url();

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
  } elseif ($action === 'save_deadline') {
    $enabled  = isset($_POST['deadline_active']) ? '1' : '0';
    $deadline = trim($_POST['vote_deadline'] ?? '');
    sv_setting_set('vote_deadline_active', $enabled);
    sv_setting_set('vote_deadline', $deadline);
    sv_flash_set('success', 'Deadline gespeichert.');
  }

  header('Location: ' . $base . '/admin/freeze.php');
  exit;
}

$frozen       = sv_is_frozen();
$reason       = sv_frozen_reason();
$dlActive     = sv_setting_get('vote_deadline_active', '0') === '1';
$dlValue      = sv_setting_get('vote_deadline', '');
// Für datetime-local Input: "YYYY-MM-DDTHH:MM"
$dlInput      = $dlValue ? date('Y-m-d\TH:i', strtotime($dlValue)) : '';

sv_header('Einfrieren', $admin);
?>

<div class="page-header">
  <div>
    <h2>Abstimmung einfrieren</h2>
    <div class="muted">Manuell sperren oder automatisch per Deadline.</div>
  </div>
  <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
</div>

<!-- Status -->
<div class="card" style="margin-bottom:12px">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div>
      <div class="small" style="margin-bottom:6px;text-transform:uppercase;font-weight:700;letter-spacing:.05em">Aktueller Status</div>
      <?php if ($frozen): ?>
        <span class="badge" style="background:var(--red-soft);border-color:rgba(193,9,15,.3);color:var(--red);font-size:14px;padding:6px 16px">
          🔒 Eingefroren<?= $reason === 'deadline' ? ' (Deadline erreicht)' : '' ?>
        </span>
      <?php else: ?>
        <span class="badge" style="background:var(--green-light);border-color:var(--green-mid);color:var(--green);font-size:14px;padding:6px 16px">✅ Offen</span>
      <?php endif; ?>
    </div>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap">
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
    <?php if ($reason === 'deadline'): ?>
      <br><strong>Hinweis:</strong> Die Abstimmung wurde automatisch durch die Deadline gesperrt. Ein Klick auf „Auftauen" überschreibt die Deadline dauerhaft.
    <?php endif; ?>
  </div>
</div>

<!-- Deadline -->
<div class="card">
  <h3 style="margin-bottom:4px">⏰ Automatische Deadline</h3>
  <div class="small" style="margin-bottom:16px">Wenn aktiviert, wird die Abstimmung zum eingestellten Zeitpunkt automatisch eingefroren. Du kannst sie danach jederzeit manuell wieder auftauen.</div>

  <form method="post">
    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
    <input type="hidden" name="action" value="save_deadline">

    <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <label class="label-checkbox" style="text-transform:none;font-size:14px;font-weight:600;color:var(--text);letter-spacing:0;margin-top:10px">
        <input type="checkbox" name="deadline_active" value="1" id="dlToggle"
               <?= $dlActive ? 'checked' : '' ?>
               onchange="document.getElementById('dlFields').style.display=this.checked?'':'none'">
        Deadline aktivieren
      </label>
      <div id="dlFields" style="flex:1;min-width:240px;<?= $dlActive ? '' : 'display:none' ?>">
        <label style="display:block;margin-bottom:6px">Zeitpunkt</label>
        <input type="datetime-local" name="vote_deadline" class="input"
               value="<?=h($dlInput)?>"
               style="width:100%;max-width:320px">
        <?php if ($dlActive && $dlValue): ?>
          <div class="small" style="margin-top:6px">
            <?php if (strtotime($dlValue) > time()): ?>
              ⏳ Friert ein in <?= sv_human_diff(strtotime($dlValue) - time()) ?>
              (<?= date('d.m.Y H:i', strtotime($dlValue)) ?> Uhr)
            <?php else: ?>
              ⚠️ Deadline bereits erreicht — Abstimmung ist eingefroren.
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div style="margin-top:16px">
      <button type="submit" class="btn primary">Deadline speichern</button>
    </div>
  </form>
</div>

<?php sv_footer(); ?>
