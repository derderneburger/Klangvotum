<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$admin = sv_require_login();
$canSeeNotes = sv_is_leitung($admin);
$pdo = sv_pdo();

$rows = $pdo->query("
  SELECT
    s.id,
    s.title,
    s.youtube_url,
    s.duration,
    s.composer,
    s.arranger,
    s.genre,
    s.difficulty,
    SUM(CASE WHEN v.vote='ja' THEN 1 ELSE 0 END) AS ja_count,
    SUM(CASE WHEN v.vote='nein' THEN 1 ELSE 0 END) AS nein_count,
    SUM(CASE WHEN v.vote='neutral' THEN 1 ELSE 0 END) AS neutral_count,
    COUNT(v.vote) AS total_votes
  FROM songs s
  LEFT JOIN votes v ON v.song_id = s.id
  WHERE s.is_active = 1 AND s.deleted_at IS NULL
  GROUP BY s.id
  ORDER BY (SUM(CASE WHEN v.vote='ja' THEN 1 ELSE 0 END) - SUM(CASE WHEN v.vote='nein' THEN 1 ELSE 0 END)) DESC,
           s.title ASC
")->fetchAll();

$noteRows = $canSeeNotes ? $pdo->query("
  SELECT
    vn.song_id,
    u.display_name,
    vn.note
  FROM vote_notes vn
  JOIN users u ON u.id = vn.user_id
  JOIN songs s ON s.id = vn.song_id
  WHERE s.is_active = 1
    AND u.is_active = 1
    AND TRIM(COALESCE(vn.note, '')) <> ''
  ORDER BY s.title ASC, u.display_name ASC
")->fetchAll() : [];

$notesBySong = [];
foreach ($noteRows as $n) {
  $notesBySong[(int)$n['song_id']][] = [
    'display_name' => $n['display_name'],
    'note' => $n['note'],
  ];
}

if (($_GET['export'] ?? '') === 'csv') {
  if (!sv_is_admin($admin)) { http_response_code(403); exit('Forbidden'); }
  $cols = array_filter(explode(',', $_GET['cols'] ?? ''));
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="songvote_ergebnisse_' . date('Y-m-d') . '.csv"');
  fprintf(fopen('php://output','w'), chr(0xEF).chr(0xBB).chr(0xBF));
  $out = fopen('php://output', 'w');
  $header = ['Titel'];
  if (in_array('duration',    $cols)) $header[] = 'Dauer';
  if (in_array('composer',    $cols)) $header[] = 'Komponist';
  if (in_array('arranger',    $cols)) $header[] = 'Arrangeur';
  if (in_array('difficulty',  $cols)) $header[] = 'Grad';
  array_push($header, 'Ja','Nein','Neutral','Summe','Score','Notizen');
  fputcsv($out, $header, ';');
  foreach ($rows as $r) {
    $score = (int)$r['ja_count'] - (int)$r['nein_count'];
    $noteText = '';
    if (!empty($notesBySong[(int)$r['id']])) {
      $parts = [];
      foreach ($notesBySong[(int)$r['id']] as $noteRow) {
        $parts[] = $noteRow['display_name'] . ': ' . str_replace(["\r\n", "\r", "\n"], ' ', $noteRow['note']);
      }
      $noteText = implode(" | ", $parts);
    }
    $row = [$r['title']];
    if (in_array('duration',   $cols)) $row[] = $r['duration'] ?? '';
    if (in_array('composer',   $cols)) $row[] = $r['composer'] ?? '';
    if (in_array('arranger',   $cols)) $row[] = $r['arranger'] ?? '';
    if (in_array('difficulty', $cols)) $row[] = $r['difficulty'] !== null ? number_format((float)$r['difficulty'],1) : '';
    array_push($row, $r['ja_count'], $r['nein_count'], $r['neutral_count'], $r['total_votes'], $score, $noteText);
    fputcsv($out, $row, ';');
  }
  fclose($out);
  exit;
}

sv_header('Admin – Ergebnisse', $admin);
$base = sv_base_url();
?>
<div class="page-header">
  <div>
    <h2>Ergebnisse</h2>
    <div class="muted">Sortiert nach Score (Ja − Nein). Nur aktive Titel.</div>
  </div>
  <div class="row">
    <button class="btn" id="viewToggleBtn" onclick="toggleView()">☰ Kartenansicht</button>
    <?php if(sv_is_admin($admin)): ?><a class="btn" id="csvExportBtn" href="<?=h($base)?>/admin/results.php?export=csv">CSV Export</a><?php endif; ?>
    <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
  </div>
</div>

<div class="card" style="margin-top:12px">
  <div style="display:flex;gap:10px;align-items:center">
    <label style="flex:1;min-width:200px">Suche<br>
      <input id="q" class="input" type="search" placeholder="Titel suchen…" style="width:100%;margin-top:5px">
    </label>
    <span class="small" id="visibleCount" style="padding-top:22px"></span>
  </div>
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
    <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Spalten</span>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="chk-composer" onchange="toggleCol('composer',this.checked);toggleCol('arranger',this.checked)"> Komponist / Arrangeur</label>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="chk-genre"      onchange="toggleCol('genre',this.checked)"> Genre</label>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="chk-difficulty" onchange="toggleCol('difficulty',this.checked)"> Grad</label>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="chk-duration"   onchange="toggleCol('duration',this.checked)"> Länge</label>
    <?php if($canSeeNotes): ?><label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="chk-notes"      onchange="toggleCol('notes',this.checked)" checked> Notizen</label><?php endif; ?>
  </div>
</div>

<div id="dur-bar" style="display:none;position:sticky;top:0;z-index:100;background:#fff;border:1.5px solid var(--green-mid);border-radius:12px;padding:12px 16px;margin-top:12px;margin-bottom:10px;box-shadow:0 2px 12px rgba(0,0,0,.08)">
  <div style="display:flex;align-items:center;gap:16px;margin-bottom:8px">
    <div style="font-size:13px;font-weight:700;color:var(--muted)">Ausgewählte Stücke:</div>
    <div style="font-family:'Fraunces',serif;font-size:1.6rem;font-weight:700;color:var(--green)" id="dur-total">0:00</div>
    <div class="small" style="color:var(--muted)" id="dur-count">0 Stücke</div>
    <button class="btn" style="margin-left:auto;font-size:12px" onclick="durReset()">✕ Auswahl leeren</button>
  </div>
  <div id="dur-titles" style="display:flex;flex-wrap:wrap;gap:5px"></div>
</div>
<div class="card" style="margin-top:12px" id="tableView">
  <div class="table-scroll">
  <table id="resultsTable">
    <thead>
      <tr>
        <th style="min-width:180px;cursor:pointer;user-select:none" onclick="sortResults('title')"><span class="sort-label" data-col="title">Titel</span></th>
        <th class="col-composer col-arranger" style="display:none;white-space:nowrap;cursor:pointer;user-select:none" onclick="sortResults('composer')"><span class="sort-label" data-col="composer">Komponist</span><br><span style="font-size:11px;color:var(--muted);font-weight:400;cursor:pointer" onclick="event.stopPropagation();sortResults('arranger')"><span class="sort-label" data-col="arranger">Arrangeur</span></span></th>
        <th class="col-genre" style="display:none;cursor:pointer;user-select:none" onclick="sortResults('genre')"><span class="sort-label" data-col="genre">Genre</span></th>
        <th class="col-difficulty" style="display:none;cursor:pointer;user-select:none" onclick="sortResults('difficulty')"><span class="sort-label" data-col="difficulty">Grad</span></th>
        <th class="col-duration" style="display:none;cursor:pointer;user-select:none" onclick="sortResults('duration')"><span class="sort-label" data-col="duration">Länge</span></th>
        <th style="text-align:center;cursor:pointer;user-select:none" onclick="sortResults('score')"><span class="sort-label sort-active" data-col="score">Score ↓</span></th>
        <?php if($canSeeNotes): ?><th class="col-notes">Notizen</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php $rank=0; foreach ($rows as $r):
        $score = (int)$r['ja_count'] - (int)$r['nein_count'];
        $rank++;
      ?>
        <?php $notesJson = htmlspecialchars(json_encode($notesBySong[(int)$r['id']] ?? []), ENT_QUOTES); ?>
        <tr data-title="<?=h(mb_strtolower($r['title']))?>" data-composer="<?=h(mb_strtolower($r['composer']??''))?>" data-arranger="<?=h(mb_strtolower($r['arranger']??''))?>" data-genre="<?=h(mb_strtolower($r['genre']??''))?>" data-difficulty="<?=h($r['difficulty']??'')?>" data-duration="<?=h($r['duration']??'')?>" data-ja="<?=h($r['ja_count'])?>" data-nein="<?=h($r['nein_count'])?>" data-neutral="<?=h($r['neutral_count'])?>" data-total="<?=h($r['total_votes'])?>" data-score="<?=h($score)?>" data-notes="<?=$notesJson?>">
          <td style="min-width:180px">
            <div style="display:flex;align-items:baseline;gap:8px">
              <input type="checkbox" class="dur-check" data-dur="<?=h($r['duration']??'')?>" data-title="<?=h($r['title'])?>" style="flex-shrink:0;margin-top:2px;cursor:pointer" title="Zur Zeitberechnung hinzufügen">
              <span style="font-family:'Fraunces',serif;font-size:1.1rem;font-weight:700;color:var(--muted);min-width:20px"><?=$rank?>.</span>
              <button class="song-title" onclick="openResultDetail(this)"
                style="background:none;border:none;padding:0;cursor:pointer;text-align:left;font-family:inherit;line-height:inherit"
                data-sid="<?=h($r['id'])?>"
                data-title="<?=h($r['title'])?>"
                data-composer="<?=h($r['composer']??'')?>"
                data-arranger="<?=h($r['arranger']??'')?>"
                data-genre="<?=h($r['genre']??'')?>"
                data-duration="<?=h($r['duration']??'')?>"
                data-difficulty="<?=h($r['difficulty']??'')?>"
                data-youtube="<?=h($r['youtube_url']??'')?>"
                data-ja="<?=h($r['ja_count'])?>"
                data-nein="<?=h($r['nein_count'])?>"
                data-neutral="<?=h($r['neutral_count'])?>"
                data-score="<?=h($score)?>"
                ><?=h($r['title'])?></button>
            </div>
            <div class="small"><a class="song-link" href="<?=h($r['youtube_url'])?>" target="_blank" rel="noopener">▶ YouTube öffnen</a></div>
          </td>
          <td class="col-composer col-arranger small" style="display:none;min-width:80px;max-width:130px">
            <?=h($r['composer']??'–')?>
            <?php if(!empty($r['arranger'])): ?><div style="color:var(--muted);font-size:12px">Arr. <?=h($r['arranger'])?></div><?php endif; ?>
          </td>
          <td class="col-genre small" style="display:none"><?=h($r['genre']??'–')?></td>
          <td class="col-difficulty small" style="display:none"><?=sv_diff_pill($r['difficulty'])?></td>
          <td class="col-duration small" style="display:none;white-space:nowrap"><?=h($r['duration']??'–')?></td>

          <?php
            $sc = $score; $sp = $sc>0?'+':'';
            $scColor = $sc>0?'var(--score)':($sc<0?'var(--red)':'var(--muted)');
            $scBg    = $sc>0?'var(--score-light)':($sc<0?'var(--red-soft)':'#f5f2ee');
            $scBorder= $sc>0?'var(--score-mid)':($sc<0?'rgba(193,9,15,.3)':'#ddd');
          ?>
          <td style="white-space:nowrap;text-align:center">
            <div class="score-wrap" style="position:relative;display:inline-block">
              <div class="score-block" style="display:inline-flex;flex-direction:column;align-items:center;background:<?=$scBg?>;color:<?=$scColor?>;border:1.5px solid <?=$scBorder?>;border-radius:10px;padding:5px 12px;min-width:44px;cursor:default">
                <span style="font-family:'Fraunces',serif;font-size:1.3rem;font-weight:700;line-height:1"><?=$sp.h($sc)?></span>
                <span style="font-size:9px;font-weight:700;letter-spacing:.06em;opacity:.7;margin-top:1px">SCORE</span>
              </div>
              <div class="score-tooltip" style="display:none;position:fixed;background:#fff;border:1.5px solid var(--border);border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.1);padding:10px 14px;white-space:nowrap;z-index:9999;font-size:13px;min-width:140px">
                <div style="display:grid;grid-template-columns:1fr auto;gap:3px 12px;align-items:center">
                  <span style="color:var(--green);font-weight:600">✓ Ja</span><span style="font-weight:700;text-align:right"><?=h($r['ja_count'])?></span>
                  <span style="color:var(--red);font-weight:600">✗ Nein</span><span style="font-weight:700;text-align:right"><?=h($r['nein_count'])?></span>
                  <span style="color:var(--muted);font-weight:600">○ Neutral</span><span style="font-weight:700;text-align:right"><?=h($r['neutral_count'])?></span>
                  <span style="color:var(--muted);border-top:1px solid var(--border);padding-top:4px;margin-top:2px">Summe</span><span style="font-weight:700;text-align:right;border-top:1px solid var(--border);padding-top:4px;margin-top:2px"><?=h($r['total_votes'])?></span>
                </div>
              </div>
            </div>
          </td>
          <?php if($canSeeNotes): ?>
          <td class="col-notes small" style="max-width:260px">
            <?php if (!empty($notesBySong[(int)$r['id']])): ?>
              <?php $noteCount = count($notesBySong[(int)$r['id']]); ?>
              <details>
                <summary style="cursor:pointer;list-style:none;display:inline-flex;align-items:center;gap:6px">
                  <span class="badge" style="background:var(--green-light);color:var(--green);border-color:var(--green-mid)"><?=$noteCount?> Notiz<?=$noteCount>1?'en':''?></span>
                </summary>
                <div style="margin-top:6px">
                <?php foreach ($notesBySong[(int)$r['id']] as $noteRow): ?>
                  <div style="margin-bottom:6px;padding:6px 8px;background:#faf8f5;border-radius:6px">
                    <strong><?=h($noteRow['display_name'])?>:</strong>
                    <?= nl2br(h($noteRow['note'])) ?>
                  </div>
                <?php endforeach; ?>
                </div>
              </details>
            <?php else: ?>
              <span style="color:#ccc">—</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="small">Keine aktiven Titel.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Kartenansicht -->
<div id="cardView" style="display:none;margin-top:12px">
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">
  <?php $cardRank=0; foreach ($rows as $r):
    $score = (int)$r['ja_count'] - (int)$r['nein_count'];
    $cardRank++;
    $scoreColor = $score > 0 ? 'var(--score)' : ($score < 0 ? 'var(--red)' : 'var(--muted)');
    $scoreBg    = $score > 0 ? 'var(--score-light)' : ($score < 0 ? 'var(--red-soft)' : '#f5f2ee');
  ?>
    <div class="card result-card" data-title="<?=h(mb_strtolower($r['title']))?>" data-ja="<?=h($r['ja_count'])?>" data-nein="<?=h($r['nein_count'])?>" data-neutral="<?=h($r['neutral_count'])?>" data-total="<?=h($r['total_votes'])?>" data-score="<?=h($score)?>" style="padding:16px">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:10px">
        <div>
          <div style="display:flex;align-items:baseline;gap:6px"><span style="font-family:'Fraunces',serif;font-size:1rem;font-weight:700;color:var(--muted)"><?=$cardRank?>.</span><div class="song-title" style="font-size:.95rem"><?=h($r['title'])?></div></div>
          <?php if(!empty($r['youtube_url'])): ?><a class="song-link" href="<?=h($r['youtube_url'])?>" target="_blank" rel="noopener">▶ YouTube öffnen</a><?php endif; ?>
        </div>
        <div style="text-align:center;background:<?=$scoreBg?>;color:<?=$scoreColor?>;border-radius:10px;padding:6px 12px;font-family:'Fraunces',serif;font-size:1.4rem;font-weight:700;line-height:1;flex-shrink:0">
          <?= $score > 0 ? '+' : '' ?><?=h($score)?><div style="font-family:'DM Sans',sans-serif;font-size:10px;font-weight:600;margin-top:2px">Score</div>
        </div>
      </div>
      <div class="card-extra" style="display:none;border-top:1px solid var(--border);padding-top:8px;margin-top:2px;font-size:13px">
        <div class="card-extra-genre" style="display:none;color:var(--muted)"><?=h($r['genre']??'–')?></div>
        <div class="card-extra-duration" style="display:none;color:var(--muted)">⏱ <?=h($r['duration']??'–')?></div>
        <div class="card-extra-composer" style="display:none;color:var(--muted)"><?=h($r['composer']??'–')?></div>
        <div class="card-extra-arranger" style="display:none;color:var(--muted)">Arr. <?=h($r['arranger']??'–')?></div>
        <div class="card-extra-difficulty" style="display:none"><?=sv_diff_pill($r['difficulty'])?></div>
      </div>
      <div class="col-votes" style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
        <span class="badge" style="background:var(--green-light);color:var(--green);border-color:var(--green-mid)">✓ <?=h($r['ja_count'])?></span>
        <span class="badge" style="background:var(--red-soft);color:var(--red);border-color:rgba(193,9,15,.3)">✗ <?=h($r['nein_count'])?></span>
        <span class="badge">– <?=h($r['neutral_count'])?></span>
      </div>
      <?php if (!empty($notesBySong[(int)$r['id']])): ?>
        <div class="col-notes" style="margin-top:10px;border-top:1px solid var(--border);padding-top:8px">
          <?php foreach ($notesBySong[(int)$r['id']] as $noteRow): ?>
            <div style="font-size:12px;margin-bottom:4px"><strong><?=h($noteRow['display_name'])?>:</strong> <?=h(mb_strimwidth($noteRow['note'],0,80,'…'))?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
</div>

<style>
.sort-label { color:inherit; }
.sort-active { color:var(--red) !important; }
</style>
<script>
(function(){
  const q = document.getElementById('q');
  const countEl = document.getElementById('visibleCount');
  const tbody = document.querySelector('table tbody');
  if(!q || !tbody) return;

  function normalize(s){ return (s||'').toString().toLowerCase().trim(); }

  function applyResultsFilter(){
    const query = normalize(q.value);
    let visible = 0;
    Array.from(tbody.querySelectorAll('tr')).forEach(function(tr){
      const title = normalize(tr.dataset.title || tr.querySelector('td').innerText);
      const ok = !query || title.includes(query);
      tr.style.display = ok ? '' : 'none';
      if(ok) visible++;
    });
    if(countEl) countEl.textContent = visible + ' angezeigt';
  }

  q.addEventListener('input', applyResultsFilter);
  applyResultsFilter();
})();

var _sortCol = 'score';
var _sortDir = 'desc';

function durToSec(s) {
  if (!s) return 0;
  var m = s.match(/^(\d+)[:']\s*(\d{1,2})/);
  if (m) return parseInt(m[1],10)*60 + parseInt(m[2],10);
  var n = parseFloat(s);
  return isNaN(n) ? 0 : n * 60;
}

function sortResults(col) {
  if (_sortCol === col) {
    _sortDir = _sortDir === 'asc' ? 'desc' : 'asc';
  } else {
    _sortCol = col;
    // Numerische Spalten default desc, Text-Spalten default asc
    _sortDir = (col === 'score' || col === 'difficulty') ? 'desc' : 'asc';
  }
  var tbody = document.querySelector('#resultsTable tbody');
  var rows = Array.from(tbody.querySelectorAll('tr'));
  rows.sort(function(a, b) {
    var va, vb;
    if (col === 'score') {
      va = parseInt(a.dataset.score || '0', 10);
      vb = parseInt(b.dataset.score || '0', 10);
    } else if (col === 'difficulty') {
      va = parseFloat(a.dataset.difficulty || '0');
      vb = parseFloat(b.dataset.difficulty || '0');
    } else if (col === 'duration') {
      va = durToSec(a.dataset.duration);
      vb = durToSec(b.dataset.duration);
    } else {
      va = (a.dataset[col] || '').toLowerCase();
      vb = (b.dataset[col] || '').toLowerCase();
      var cmp = va.localeCompare(vb, 'de');
      return _sortDir === 'asc' ? cmp : -cmp;
    }
    var diff = va - vb;
    return _sortDir === 'asc' ? diff : -diff;
  });
  rows.forEach(function(tr) { tbody.appendChild(tr); });
  // Labels aktualisieren
  document.querySelectorAll('.sort-label').forEach(function(el) {
    var c = el.dataset.col;
    el.classList.toggle('sort-active', c === col);
    var base = el.textContent.replace(/ [↑↓]$/, '');
    el.textContent = c === col ? base + (_sortDir === 'asc' ? ' ↑' : ' ↓') : base;
  });
  // Card-View synchronisieren
  if (typeof syncCardsToFilter === 'function') syncCardsToFilter();
}
</script>

<script>

function toggleCol(name, show) {
  document.querySelectorAll('.col-' + name).forEach(function(el){
    el.style.display = show ? '' : 'none';
  });
  // Update badge count
  var active = ['composer','arranger','genre','difficulty','duration'].filter(function(n){
    var chk = document.getElementById('chk-'+n);
    return chk && chk.checked;
  }).length;

  // CSV-Link aktualisieren
  var csvBtn = document.getElementById('csvExportBtn');
  if (csvBtn) {
    var activeCols = ['composer','arranger','genre','difficulty','duration'].filter(function(n){
      var chk = document.getElementById('chk-'+n);
      return chk && chk.checked;
    });
    var base = csvBtn.href.split('?')[0];
    csvBtn.href = base + '?export=csv' + (activeCols.length ? '&cols=' + activeCols.join(',') : '');
  }
}
// View toggle
var currentView = 'table';
function toggleView() {
  currentView = currentView === 'table' ? 'card' : 'table';
  document.getElementById('tableView').style.display  = currentView === 'table' ? '' : 'none';
  document.getElementById('cardView').style.display   = currentView === 'card'  ? '' : 'none';
  document.getElementById('viewToggleBtn').textContent = currentView === 'card' ? '☷ Tabellenansicht' : '☰ Kartenansicht';
  if (currentView === 'card') syncCardsToFilter();
}

// Sync card visibility with table filter
function syncCardsToFilter() {
  var tableRows = document.querySelectorAll('#resultsTable tbody tr');
  var cards     = document.querySelectorAll('.result-card');
  tableRows.forEach(function(tr, i) {
    if (cards[i]) cards[i].style.display = tr.style.display === 'none' ? 'none' : '';
  });
}

// Sync extra fields in cards when column toggles change
function syncCardExtras(name, show) {
  document.querySelectorAll('.card-extra-' + name).forEach(function(el){ el.style.display = show ? '' : 'none'; });
  // For votes: toggle col-votes elements in cards too
  if (name === 'votes') {
    document.querySelectorAll('#cardView .col-votes').forEach(function(el){ el.style.display = show ? '' : 'none'; });
  }
  if (name === 'notes') {
    document.querySelectorAll('#cardView .col-notes').forEach(function(el){ el.style.display = show ? '' : 'none'; });
  }
  document.querySelectorAll('.card-extra').forEach(function(el){
    var kids = el.children;
    var vis = false;
    for(var i=0;i<kids.length;i++){ if(kids[i].style.display !== 'none') vis=true; }
    el.style.display = vis ? '' : 'none';
  });
}

// Patch toggleCol to also sync cards
var _origToggleCol = toggleCol;
toggleCol = function(name, checked) {
  _origToggleCol(name, checked);
  syncCardExtras(name, checked);
};


</script>

<style>
.score-wrap .score-tooltip { display:none; }
</style>
<script>
document.querySelectorAll('.score-wrap').forEach(function(wrap) {
  var tip = wrap.querySelector('.score-tooltip');
  if (!tip) return;
  wrap.addEventListener('mouseenter', function() {
    var r = wrap.getBoundingClientRect();
    tip.style.display = 'block';
    var tw = tip.offsetWidth;
    var th = tip.offsetHeight;
    // Position below or above depending on space
    var top = (window.innerHeight - r.bottom > th + 10) ? r.bottom + 6 : r.top - th - 6;
    var left = r.left + r.width/2 - tw/2;
    // Keep within viewport
    left = Math.max(8, Math.min(left, window.innerWidth - tw - 8));
    tip.style.top  = top + 'px';
    tip.style.left = left + 'px';
  });
  wrap.addEventListener('mouseleave', function() {
    tip.style.display = 'none';
  });
});
</script>

<!-- Titel-Detail Dialog -->
<dialog id="result-detail-dialog" class="sv-dialog" style="width:min(580px,calc(100vw - 24px))">
  <div class="sv-dialog__panel" tabindex="-1">
    <div class="sv-dialog__head">
      <div>
        <div class="sv-dialog__title" id="rd-title">—</div>
        <div class="sv-dialog__sub" id="rd-sub"></div>
      </div>
      <button class="sv-dialog__close" type="button" data-close-dialog>✕</button>
    </div>
    <div class="sv-dialog__section">
      <!-- Score Aufschlüsselung -->
      <div id="rd-score-row" style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap">
        <div style="flex:1;min-width:80px;text-align:center;padding:10px;background:var(--green-light);border-radius:10px;border:1.5px solid var(--green-mid)">
          <div style="font-family:'Fraunces',serif;font-size:1.8rem;font-weight:700;color:var(--green);line-height:1" id="rd-ja">0</div>
          <div style="font-size:11px;font-weight:700;color:var(--green);margin-top:2px">✓ JA</div>
        </div>
        <div style="flex:1;min-width:80px;text-align:center;padding:10px;background:var(--red-soft);border-radius:10px;border:1.5px solid rgba(193,9,15,.3)">
          <div style="font-family:'Fraunces',serif;font-size:1.8rem;font-weight:700;color:var(--red);line-height:1" id="rd-nein">0</div>
          <div style="font-size:11px;font-weight:700;color:var(--red);margin-top:2px">✗ NEIN</div>
        </div>
        <div style="flex:1;min-width:80px;text-align:center;padding:10px;background:#f5f2ee;border-radius:10px;border:1.5px solid #ddd">
          <div style="font-family:'Fraunces',serif;font-size:1.8rem;font-weight:700;color:var(--muted);line-height:1" id="rd-neutral">0</div>
          <div style="font-size:11px;font-weight:700;color:var(--muted);margin-top:2px">○ NEUTRAL</div>
        </div>
        <div style="flex:1;min-width:80px;text-align:center;padding:10px;border-radius:10px;border:1.5px solid var(--border)">
          <div style="font-family:'Fraunces',serif;font-size:1.8rem;font-weight:700;line-height:1" id="rd-score">0</div>
          <div style="font-size:11px;font-weight:700;color:var(--muted);margin-top:2px">SCORE</div>
        </div>
      </div>
      <!-- Infos -->
      <div id="rd-info" style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;font-size:13px"></div>
      <!-- YouTube -->
      <div id="rd-yt" style="margin-top:10px"></div>
    </div>
    <!-- Notizen -->
    <div class="sv-dialog__section" id="rd-notes-section" style="display:none">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);margin-bottom:10px">Notizen</div>
      <div id="rd-notes"></div>
    </div>
  </div>
</dialog>

<script>
document.addEventListener('click', function(e) {
  var closeBtn = e.target.closest('[data-close-dialog]');
  if (closeBtn) { var d = closeBtn.closest('dialog'); if (d) d.close(); }
});

function openResultDetail(btn) {
  var tr   = btn.closest('tr');
  var dlg  = document.getElementById('result-detail-dialog');
  var sc   = parseInt(btn.dataset.score||'0',10);
  var scEl = document.getElementById('rd-score');

  // Title & sub
  document.getElementById('rd-title').textContent = btn.dataset.title;
  var sub = [];
  if (btn.dataset.composer) sub.push(btn.dataset.composer);
  if (btn.dataset.arranger) sub.push('Arr. ' + btn.dataset.arranger);
  document.getElementById('rd-sub').textContent = sub.join(' · ');

  // Score boxes
  document.getElementById('rd-ja').textContent      = btn.dataset.ja;
  document.getElementById('rd-nein').textContent    = btn.dataset.nein;
  document.getElementById('rd-neutral').textContent = btn.dataset.neutral;
  scEl.textContent = (sc>0?'+':'') + sc;
  scEl.style.color = sc>0?'var(--score)':sc<0?'var(--red)':'var(--muted)';

  // Info grid
  var info = document.getElementById('rd-info');
  info.innerHTML = '';
  var fields = [
    ['Genre',        btn.dataset.genre],
    ['Länge',        btn.dataset.duration],
    ['Grad',         btn.dataset.difficulty ? parseFloat(btn.dataset.difficulty).toFixed(1) : ''],
  ];
  fields.forEach(function(f) {
    if (!f[1]) return;
    info.innerHTML += '<div><span style="color:var(--muted)">'+f[0]+':</span> <strong>'+escH(f[1])+'</strong></div>';
  });

  // YouTube
  var yt = document.getElementById('rd-yt');
  yt.innerHTML = btn.dataset.youtube
    ? '<a class="song-link" href="'+escH(btn.dataset.youtube)+'" target="_blank" rel="noopener">▶ YouTube öffnen</a>'
    : '';

  // Notes from TR data attribute
  var notes = [];
  try { notes = JSON.parse(tr ? tr.dataset.notes || '[]' : '[]'); } catch(e){}
  var ns = document.getElementById('rd-notes-section');
  var nd = document.getElementById('rd-notes');
  if (notes.length) {
    nd.innerHTML = notes.map(function(n){
      return '<div style="margin-bottom:8px;padding:8px 10px;background:#faf8f5;border-radius:8px;font-size:13px">'
        + '<strong>'+escH(n.display_name)+':</strong> '+escH(n.note)+'</div>';
    }).join('');
    ns.style.display = '';
  } else {
    ns.style.display = 'none';
  }

  dlg.showModal();
}

function escH(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }
</script>

<script>
function durParseSec(s) {
  if (!s) return 0;
  s = s.replace(/'+$/, '').trim(); // trailing ' oder '' entfernen (13', 5'40'')
  // M:SS or M'SS
  var m = s.match(/^(\d+)[:'](\d{1,2})$/);
  if (m) return parseInt(m[1],10)*60 + parseInt(m[2],10);
  // just minutes
  m = s.match(/^(\d+)$/);
  if (m) return parseInt(m[1],10)*60;
  return 0;
}
function durUpdate() {
  var checks = document.querySelectorAll('.dur-check:checked');
  var total  = 0;
  checks.forEach(function(cb){ total += durParseSec(cb.dataset.dur); });
  var bar = document.getElementById('dur-bar');
  if (checks.length === 0) {
    bar.style.display = 'none';
    return;
  }
  bar.style.display = 'block';
  var m = Math.floor(total/60), s = total%60;
  document.getElementById('dur-total').textContent = m + ':' + String(s).padStart(2,'0');
  document.getElementById('dur-count').textContent = checks.length + ' Stück' + (checks.length!==1?'e':'');
  // Titel anzeigen
  var titlesEl = document.getElementById('dur-titles');
  titlesEl.innerHTML = '';
  checks.forEach(function(cb) {
    var span = document.createElement('span');
    span.className = 'badge';
    span.style.cssText = 'background:var(--green-light);color:var(--green);border-color:var(--green-mid)';
    span.textContent = cb.dataset.title || '–';
    titlesEl.appendChild(span);
  });
}
function durReset() {
  document.querySelectorAll('.dur-check').forEach(function(cb){ cb.checked=false; });
  document.getElementById('dur-bar').style.display='none';
}
document.addEventListener('change', function(e){
  if (e.target.classList.contains('dur-check')) durUpdate();
});
</script>
<?php sv_footer(); ?>