<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = sv_require_login();
if (!sv_is_admin($user) && !sv_can_edit_noten($user)) {
  http_response_code(403);
  exit('Forbidden');
}
$pdo  = sv_pdo();
$base = sv_base_url();

// ── Feld-Labels ──────────────────────────────────────────────────────────────
$fieldLabels = [
  'title' => 'Titel', 'youtube_url' => 'YouTube URL', 'composer' => 'Komponist',
  'arranger' => 'Arrangeur', 'publisher' => 'Verlag', 'duration' => 'Länge',
  'genre' => 'Genre', 'difficulty' => 'Schwierigkeitsgrad', 'owner' => 'Eigentümer',
  'shop_url' => 'Händler-Link', 'shop_price' => 'Preis',
  'info' => 'Info-Text', 'querverweis' => 'Querverweis',
  'has_scan' => 'Stimmen eingescannt',
  'has_score_scan' => 'Partitur eingescannt', 'has_original_score' => 'Originalpartitur',
  'binder' => 'Mappe',
];

// ── POST-Aktionen ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $action = $_POST['action'] ?? '';
  $sid    = (int)($_POST['sid'] ?? 0);

  if ($sid > 0) {
    $sugg = $pdo->prepare("SELECT * FROM piece_suggestions WHERE id=?");
    $sugg->execute([$sid]);
    $s = $sugg->fetch();

    if ($s && $s['status'] === 'pending') {
      if ($action === 'accept_suggestion') {
        $changes = json_decode($s['changes'], true);
        $acceptFields = $_POST['accept_fields'] ?? [];
        if ($changes && is_array($changes) && !empty($acceptFields)) {
          $setClauses = [];
          $setValues  = [];
          $accepted   = [];
          foreach ($changes as $field => $vals) {
            if (!in_array($field, $acceptFields)) continue;
            $newVal = $vals['new'];
            if (in_array($field, ['has_scan','has_score_scan','has_original_score'])) {
              $setClauses[] = "$field = ?";
              $setValues[]  = (int)$newVal;
            } elseif ($field === 'binder') {
              $setClauses[] = "$field = ?";
              $setValues[]  = $newVal ?: null;
            } elseif ($field === 'difficulty') {
              $setClauses[] = "$field = ?";
              $setValues[]  = $newVal !== '' ? (float)$newVal : null;
            } elseif ($field === 'shop_price') {
              $setClauses[] = "$field = ?";
              $setValues[]  = $newVal !== '' ? (float)$newVal : null;
            } else {
              $setClauses[] = "$field = ?";
              $setValues[]  = $newVal !== '' ? $newVal : null;
            }
            $accepted[] = $field;
          }
          if ($setClauses) {
            $setValues[] = $s['piece_id'];
            $pdo->prepare("UPDATE pieces SET " . implode(', ', $setClauses) . " WHERE id=?")
                ->execute($setValues);
          }
          $countAccepted = count($accepted);
          $countTotal    = count($changes);
          $pdo->prepare("UPDATE piece_suggestions SET status='accepted', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
              ->execute([$user['id'], $sid]);
          sv_log($user['id'], 'suggestion_accept', "sid=$sid pid={$s['piece_id']} fields=" . implode(',', $accepted));
          sv_flash_set('success', "$countAccepted von $countTotal Feldern übernommen.");
        } else {
          sv_flash_set('warning', 'Kein Feld zum Übernehmen ausgewählt.');
        }

      } elseif ($action === 'reject_suggestion') {
        $pdo->prepare("UPDATE piece_suggestions SET status='rejected', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$user['id'], $sid]);
        sv_log($user['id'], 'suggestion_reject', "sid=$sid pid={$s['piece_id']}");
        sv_flash_set('success', 'Vorschlag abgelehnt.');
      }
    }
  }

  if ($action === 'delete_closed' && sv_is_admin($user)) {
    $n = $pdo->exec("DELETE FROM piece_suggestions WHERE status != 'pending'");
    sv_log($user['id'], 'suggestions_delete_closed', "count=$n");
    sv_flash_set('success', "$n erledigte Vorschläge gelöscht.");
  }

  header('Location: ' . $base . '/admin/vorschlaege.php');
  exit;
}

// ── Daten laden ──────────────────────────────────────────────────────────────
$rows = $pdo->query("
  SELECT ps.*,
         p.title AS piece_title, p.composer AS piece_composer, p.arranger AS piece_arranger,
         u1.display_name AS suggested_by_name,
         u2.display_name AS reviewed_by_name
  FROM piece_suggestions ps
  JOIN pieces p ON p.id = ps.piece_id
  JOIN users u1 ON u1.id = ps.user_id
  LEFT JOIN users u2 ON u2.id = ps.reviewed_by
  ORDER BY ps.status = 'pending' DESC, ps.created_at DESC
")->fetchAll();

$pendingCount = 0;
$closedCount  = 0;
foreach ($rows as $r) {
  if ($r['status'] === 'pending') $pendingCount++;
  else $closedCount++;
}

sv_header('Änderungsvorschläge', $user);
?>

<div class="page-header">
  <div>
    <h2>Änderungsvorschläge</h2>
    <div class="muted"><?=count($rows)?> Einträge<?php if($pendingCount): ?> · <strong style="color:#e65100"><?=$pendingCount?> offen</strong><?php endif; ?></div>
  </div>
  <div class="row">
    <?php if (sv_is_admin($user) && $closedCount > 0): ?>
    <form method="post" onsubmit="return confirm('<?=$closedCount?> erledigte Vorschläge wirklich löschen?')">
      <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
      <input type="hidden" name="action" value="delete_closed">
      <button class="btn" type="submit" style="color:var(--red)">🗑 <?=$closedCount?> erledigte löschen</button>
    </form>
    <?php endif; ?>
    <a class="btn" href="<?=h($base)?>/admin/bibliothek.php">📚 Bibliothek</a>
    <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
  </div>
</div>

<!-- Suche & Filter -->
<div class="card" style="margin-bottom:12px">
  <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap" onsubmit="return false">
    <label style="flex:1;min-width:200px">Suche<br>
      <input class="input" type="text" id="sugg-q" placeholder="Titel, vorgeschlagen von…" style="width:100%;margin-top:5px" autocomplete="off">
    </label>
    <span class="small" id="sugg-count" style="color:var(--muted);white-space:nowrap;margin-top:22px"><?=count($rows)?> Einträge</span>
    <button class="btn" type="button" id="btn-pending-only" onclick="togglePendingOnly()" style="margin-top:16px;white-space:nowrap">📝 Nur offene</button>
  </form>
</div>

<?php if (!$rows): ?>
<div class="card">
  <div class="small" style="color:var(--muted);padding:20px;text-align:center">Noch keine Änderungsvorschläge eingegangen.</div>
</div>
<?php else: ?>

<?php foreach ($rows as $r):
  $changes = json_decode($r['changes'], true) ?: [];
  $isPending  = $r['status'] === 'pending';
  $isAccepted = $r['status'] === 'accepted';
  $isRejected = $r['status'] === 'rejected';
?>
<div class="card sugg-card" data-search="<?=h(mb_strtolower($r['piece_title'] . ' ' . $r['suggested_by_name']))?>" data-status="<?=h($r['status'])?>"
     style="margin-bottom:10px;<?php if($isPending): ?>border-left:4px solid #e65100;<?php elseif($isRejected): ?>opacity:.6;<?php endif; ?>">

  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
    <div>
      <div style="font-weight:700;font-size:15px">
        <a href="<?=h($base)?>/admin/bibliothek.php?q=<?=urlencode($r['piece_title'])?>" style="color:inherit;text-decoration:none" onmouseover="this.style.color='var(--red)'" onmouseout="this.style.color='inherit'"><?=h($r['piece_title'])?></a>
      </div>
      <?php if ($r['piece_composer'] || $r['piece_arranger']): ?>
        <div class="small" style="color:var(--muted)"><?=h(implode(' · ', array_filter([$r['piece_composer'], $r['piece_arranger'] ? 'Arr. ' . $r['piece_arranger'] : ''])))?></div>
      <?php endif; ?>
      <div class="small" style="margin-top:4px">
        Vorgeschlagen von <strong><?=h($r['suggested_by_name'])?></strong> am <?=date('d.m.Y H:i', strtotime($r['created_at']))?>
      </div>
    </div>
    <div>
      <?php if ($isPending): ?>
        <span class="badge" style="background:#e65100;color:#fff">Offen</span>
      <?php elseif ($isAccepted): ?>
        <span class="badge" style="background:var(--green-light);color:var(--green);border-color:var(--green-mid)">Angenommen</span>
      <?php else: ?>
        <span class="badge" style="background:var(--red-soft);color:var(--red);border-color:rgba(193,9,15,.3)">Abgelehnt</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Diff-Tabelle -->
  <?php if ($isPending): ?><form method="post"><?php endif; ?>
  <div style="margin-top:10px;border:1px solid var(--border);border-radius:8px;overflow:hidden">
    <table style="width:100%;font-size:13px;border-collapse:collapse">
      <thead>
        <tr style="background:#f5f2ee">
          <?php if ($isPending): ?><th style="padding:6px 10px;width:50px;text-align:center;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Übern.</th><?php endif; ?>
          <th style="padding:6px 10px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Feld</th>
          <th style="padding:6px 10px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Aktuell</th>
          <th style="padding:6px 10px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Vorschlag</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($changes as $field => $vals):
        $label = $fieldLabels[$field] ?? $field;
        $oldDisplay = $vals['old'];
        $newDisplay = $vals['new'];
        // Checkboxen lesbar machen
        $isBool = in_array($field, ['has_scan','has_score_scan','has_original_score','binder']);
        if ($isBool) {
          $oldDisplay = ($oldDisplay === '1' || $oldDisplay === 'ja') ? '✓ Ja' : '✗ Nein';
          $newDisplay = ($newDisplay === '1' || $newDisplay === 'ja') ? '✓ Ja' : '✗ Nein';
        }
        // Default: Wenn Feld vorher leer → Übernahme an, sonst aus
        $defaultOn = trim($vals['old']) === '';
      ?>
        <tr style="border-top:1px solid var(--border)">
          <?php if ($isPending): ?>
          <td style="padding:6px 10px;text-align:center">
            <input type="checkbox" name="accept_fields[]" value="<?=h($field)?>" <?= $defaultOn ? 'checked' : '' ?> style="cursor:pointer;width:18px;height:18px">
          </td>
          <?php endif; ?>
          <td style="padding:6px 10px;font-weight:600;white-space:nowrap"><?=h($label)?></td>
          <td style="padding:6px 10px;<?= $oldDisplay && $oldDisplay !== '–' && $oldDisplay !== '✗ Nein' ? 'color:var(--red);text-decoration:line-through' : 'color:var(--muted)' ?>"><?=h($oldDisplay ?: '–')?></td>
          <td style="padding:6px 10px;color:var(--green);font-weight:600"><?=h($newDisplay ?: '–')?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($isPending): ?>
  <div style="display:flex;gap:8px;margin-top:12px;align-items:center">
      <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
      <input type="hidden" name="action" value="accept_suggestion">
      <input type="hidden" name="sid" value="<?=h($r['id'])?>">
      <button class="btn primary" type="submit">✓ Ausgewählte übernehmen</button>
  </form>
    <form method="post" onsubmit="return confirm('Vorschlag wirklich ablehnen?')">
      <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
      <input type="hidden" name="action" value="reject_suggestion">
      <input type="hidden" name="sid" value="<?=h($r['id'])?>">
      <button class="btn" type="submit" style="color:var(--red)">✗ Ablehnen</button>
    </form>
    <span class="small" style="color:var(--muted);margin-left:8px">Felder mit Häkchen werden übernommen</span>
  </div>
  <?php elseif ($r['reviewed_by_name']): ?>
  <div class="small" style="margin-top:8px;color:var(--muted)">
    <?= $isAccepted ? 'Angenommen' : 'Abgelehnt' ?> von <?=h($r['reviewed_by_name'])?> am <?=date('d.m.Y H:i', strtotime($r['reviewed_at']))?>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
var _pendingOnly = false;

function togglePendingOnly() {
  _pendingOnly = !_pendingOnly;
  var btn = document.getElementById('btn-pending-only');
  btn.classList.toggle('primary', _pendingOnly);
  btn.textContent = _pendingOnly ? '📝 Nur offene ✓' : '📝 Nur offene';
  applySuggFilter();
}

function applySuggFilter() {
  var q = document.getElementById('sugg-q');
  var countEl = document.getElementById('sugg-count');
  var query = (q ? q.value : '').toLowerCase().trim();
  var visible = 0;
  document.querySelectorAll('.sugg-card').forEach(function(card) {
    var search = card.dataset.search || '';
    var matchText = !query || search.indexOf(query) !== -1;
    var matchPending = !_pendingOnly || card.dataset.status === 'pending';
    var ok = matchText && matchPending;
    card.style.display = ok ? '' : 'none';
    if (ok) visible++;
  });
  if (countEl) countEl.textContent = visible + ' Einträge';
}

document.getElementById('sugg-q').addEventListener('input', applySuggFilter);
</script>

<?php sv_footer(); ?>
