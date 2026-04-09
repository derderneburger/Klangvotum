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

$isAdmin = sv_is_admin($user);

// ── POST-Aktionen ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  if ($isAdmin && ($_POST['action'] ?? '') === 'delete_loan') {
    $lid = (int)($_POST['lid'] ?? 0);
    if ($lid > 0) {
      $pdo->prepare("DELETE FROM piece_loans WHERE id=?")->execute([$lid]);
      sv_log($user['id'], 'loan_delete', "lid=$lid");
      sv_flash_set('success', 'Ausleih-Eintrag gelöscht.');
    }
  }
  header('Location: ' . $base . '/admin/ausleihen.php' . ($search !== '' ? '?q=' . urlencode($search) : ''));
  exit;
}

// ── Daten laden ──────────────────────────────────────────────────────────────
$search = trim($_GET['q'] ?? '');

$where  = '';
$params = [];
if ($search !== '') {
  $where  = "WHERE (p.title LIKE ? OR pl.loaned_to LIKE ?)";
  $like   = '%' . $search . '%';
  $params = [$like, $like];
}

$rows = $pdo->prepare("
  SELECT pl.*,
         p.title, p.composer, p.arranger,
         u1.display_name AS loaned_by_name,
         u2.display_name AS returned_by_name
  FROM piece_loans pl
  JOIN pieces p ON p.id = pl.piece_id
  JOIN users u1 ON u1.id = pl.loaned_by
  LEFT JOIN users u2 ON u2.id = pl.returned_by
  $where
  ORDER BY pl.returned_at IS NOT NULL ASC, pl.created_at DESC
");
$rows->execute($params);
$loans = $rows->fetchAll();

$openCount = 0;
foreach ($loans as $l) { if ($l['returned_at'] === null) $openCount++; }

sv_header('Ausleih-Verlauf', $user);
?>

<div class="page-header">
  <div>
    <h2>Ausleih-Verlauf</h2>
    <div class="muted"><?=count($loans)?> Einträge<?php if($openCount): ?> · <strong style="color:#e65100"><?=$openCount?> offen</strong><?php endif; ?></div>
  </div>
  <div class="row">
    <a class="btn" href="<?=h($base)?>/admin/bibliothek.php">📚 Bibliothek</a>
    <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
  </div>
</div>

<!-- Suche -->
<div class="card" style="margin-bottom:12px">
  <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <label style="flex:1;min-width:200px">Suche<br>
      <input class="input" type="text" name="q" id="loan-q" value="<?=h($search)?>"
             placeholder="Titel, ausgeliehen an…" style="width:100%;margin-top:5px" autocomplete="off">
    </label>
    <span class="small" id="loan-count" style="color:var(--muted);white-space:nowrap;margin-top:22px"><?=count($loans)?> Einträge</span>
    <button class="btn" type="button" id="btn-open-only" onclick="toggleOpenOnly()" style="margin-top:16px;white-space:nowrap">📦 Nur offene</button>
  </form>
</div>

<div class="card">
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <th style="min-width:160px">Stück</th>
          <th>Ausgeliehen an</th>
          <th>Vermerk</th>
          <th style="white-space:nowrap">Verliehen am</th>
          <th style="white-space:nowrap">Eingetragen von</th>
          <th style="white-space:nowrap">Rückgabe</th>
          <th style="white-space:nowrap">Rückgabe von</th>
          <?php if ($isAdmin): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody id="loan-tbody">
      <?php if (!$loans): ?>
        <tr><td colspan="<?=$isAdmin?8:7?>" class="small" style="color:var(--muted)">Noch keine Ausleihen vermerkt.</td></tr>
      <?php endif; ?>
      <?php foreach ($loans as $l):
        $open = $l['returned_at'] === null;
      ?>
        <tr data-search="<?=h(mb_strtolower($l['title'] . ' ' . $l['loaned_to']))?>" data-open="<?=$open?'1':'0'?>"<?php if($open): ?> style="background:#fff3e0"<?php endif; ?>>
          <td>
            <a href="<?=h($base)?>/admin/bibliothek.php?q=<?=urlencode($l['title'])?>" style="font-weight:700;color:inherit;text-decoration:none" onmouseover="this.style.color='var(--red)'" onmouseout="this.style.color='inherit'"><?=h($l['title'])?></a>
            <?php if ($l['composer'] || $l['arranger']): ?>
              <div class="small" style="color:var(--muted)"><?=h(implode(' · ', array_filter([$l['composer'], $l['arranger'] ? 'Arr. ' . $l['arranger'] : ''])))?></div>
            <?php endif; ?>
          </td>
          <td>
            <strong><?=h($l['loaned_to'])?></strong>
            <?php if ($open): ?>
              <div><span class="badge" style="background:#e65100;color:#fff;font-size:10px;margin-top:3px">Noch ausgeliehen</span></div>
            <?php endif; ?>
          </td>
          <td class="small"><?=h($l['loaned_note'] ?? '–')?></td>
          <td class="small" style="white-space:nowrap"><?=date('d.m.Y', strtotime($l['loaned_at']))?></td>
          <td class="small" style="white-space:nowrap"><?=h($l['loaned_by_name'])?></td>
          <td class="small" style="white-space:nowrap">
            <?php if ($l['returned_at']): ?>
              <?=date('d.m.Y', strtotime($l['returned_at']))?>
            <?php else: ?>
              <span style="color:var(--muted)">–</span>
            <?php endif; ?>
          </td>
          <td class="small" style="white-space:nowrap">
            <?php if ($l['returned_by_name']): ?>
              <?=h($l['returned_by_name'])?>
            <?php else: ?>
              <span style="color:var(--muted)">–</span>
            <?php endif; ?>
          </td>
          <?php if ($isAdmin): ?>
          <td style="white-space:nowrap">
            <form method="post" onsubmit="return confirm('Eintrag wirklich löschen?')">
              <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
              <input type="hidden" name="action" value="delete_loan">
              <input type="hidden" name="lid" value="<?=(int)$l['id']?>">
              <button class="btn" type="submit" style="color:var(--red);padding:3px 8px;font-size:12px" title="Eintrag löschen">🗑</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
var _openOnly = false;

function toggleOpenOnly() {
  _openOnly = !_openOnly;
  var btn = document.getElementById('btn-open-only');
  btn.classList.toggle('primary', _openOnly);
  btn.textContent = _openOnly ? '📦 Nur offene ✓' : '📦 Nur offene';
  applyLoanFilter();
}

function applyLoanFilter() {
  var q = document.getElementById('loan-q');
  var tbody = document.getElementById('loan-tbody');
  var countEl = document.getElementById('loan-count');
  if (!tbody) return;
  var query = (q ? q.value : '').toLowerCase().trim();
  var visible = 0;
  Array.from(tbody.querySelectorAll('tr')).forEach(function(tr) {
    var search = tr.dataset.search || '';
    var matchText = !query || search.indexOf(query) !== -1;
    var matchOpen = !_openOnly || tr.dataset.open === '1';
    var ok = matchText && matchOpen;
    tr.style.display = ok ? '' : 'none';
    if (ok) visible++;
  });
  if (countEl) countEl.textContent = visible + ' Einträge';
}

(function(){
  var q = document.getElementById('loan-q');
  if (!q) return;

  q.addEventListener('input', function() {
    applyLoanFilter();
  });

  // Formular-Submit verhindern (Live-Suche reicht)
  q.closest('form').addEventListener('submit', function(e) { e.preventDefault(); });
})();
</script>

<?php sv_footer(); ?>
