<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

$user   = sv_require_login();
$pdo    = sv_pdo();
$frozen = sv_is_frozen();

// Anzeigeeinstellungen laden
$displayFieldsRaw = '';
try {
  $s = $pdo->query("SELECT value FROM settings WHERE `key` = 'vote_display_fields'");
  $r = $s->fetch();
  $displayFieldsRaw = $r ? $r['value'] : '';
} catch (Throwable $e) {}
$displayFields = array_filter(explode(',', $displayFieldsRaw));

$stmt = $pdo->prepare("
  SELECT s.id, s.title, s.youtube_url, s.composer, s.arranger, s.duration,
         s.difficulty, s.genre, s.info, s.shop_url, s.piece_id,
         v.vote AS my_vote,
         vn.note AS my_note
  FROM songs s
  LEFT JOIN votes v   ON v.song_id = s.id  AND v.user_id  = ?
  LEFT JOIN vote_notes vn ON vn.song_id = s.id AND vn.user_id = ?
  WHERE s.is_active = 1 AND s.deleted_at IS NULL
  ORDER BY s.title COLLATE utf8mb4_german2_ci ASC
");
$stmt->execute([$user['id'], $user['id']]);
$songs = $stmt->fetchAll();

// Historische Stimmen: für Stücke mit piece_id die noch keine aktuelle Stimme haben
$oldVotesMap = [];
$songPieceIds = array_filter(array_column($songs, 'piece_id'));
if ($songPieceIds) {
  // Hole historische Stimmen aus vote_history
  $placeholders = implode(',', array_fill(0, count($songPieceIds), '?'));
  $oldStmt = $pdo->prepare("
    SELECT piece_id, vote AS old_vote, note AS old_note
    FROM vote_history
    WHERE user_id = ?
      AND piece_id IN ($placeholders)
    ORDER BY archived_at DESC
  ");
  $oldStmt->execute(array_merge([$user['id']], $songPieceIds));
  foreach ($oldStmt->fetchAll() as $row) {
    if (!isset($oldVotesMap[$row['piece_id']])) {
      $oldVotesMap[$row['piece_id']] = $row;
    }
  }
}

$total   = count($songs);
$done    = 0;
foreach ($songs as $s) { if (!empty($s['my_vote'])) $done++; }
$percent = $total ? round(($done / $total) * 100) : 0;

sv_header('Abstimmen', $user);
?>

<script>
  if(<?= $frozen ? 'true' : 'false' ?>){ document.body.classList.add('frozen'); }
</script>

<div class="page-header">
  <div>
    <h2>Abstimmung</h2>
    <div class="muted">Alles wird sofort gespeichert. Nur Notizen manuell speichern.</div>
  </div>
  <div class="done-badge" id="doneBadge"><?=h($done)?> / <?=h($total)?> bewertet</div>
</div>

<!-- Progress -->
<div class="progress-card">
  <div class="progress-header">
    <span class="progress-label">Fortschritt</span>
    <span class="progress-pct" id="progressPct"><?=h($percent)?>%</span>
  </div>
  <div class="progress"><div id="progressBar" style="width:<?=$percent?>%"></div></div>
</div>

<div id="toast" class="notice success" style="display:none"></div>

<?php if($frozen): ?>
<div class="freeze-banner" style="margin-bottom:16px">
  🔒 <span><strong>Abstimmung ist geschlossen.</strong> Du kannst deine Stimmen sehen, aber nichts mehr ändern.</span>
</div>
<?php endif; ?>

<!-- Filter -->
<div class="card" style="margin-bottom:4px">
  <div class="filterbar">
    <strong>Filter:</strong>
    <button class="filterbtn active" data-filter="all">Alle</button>
    <button class="filterbtn" data-filter="open">Offen</button>
    <button class="filterbtn" data-filter="ja">✓ Ja</button>
    <button class="filterbtn" data-filter="nein">✗ Nein</button>
    <button class="filterbtn" data-filter="neutral">– Neutral</button>
    <span class="small" id="voteStats" style="margin-left:auto"></span>
  </div>
</div>

<!-- Song Cards -->
<div class="song-list" id="songList">
<?php foreach ($songs as $song):
  $vote = $song['my_vote'] ?? '';
  $cardClass = $vote === 'ja' ? 'voted-ja' : ($vote === 'nein' ? 'voted-nein' : ($vote === 'neutral' ? 'voted-neutral' : ''));
?>
  <div class="song-card <?=h($cardClass)?>" data-song-id="<?=h($song['id'])?>" data-vote="<?=h($vote)?>">
    <div>
      <div class="song-title"><?=h($song['title'])?></div>
      <?php if(in_array('composer',$displayFields)||in_array('arranger',$displayFields)):
        $parts=array_filter([
          in_array('composer',$displayFields)&&!empty($song['composer']) ? h($song['composer']) : '',
          in_array('arranger',$displayFields)&&!empty($song['arranger']) ? 'Arr. '.h($song['arranger']) : '',
        ]);
        if($parts): ?><div class="small" style="color:var(--muted);margin-bottom:3px"><?=implode(' · ',$parts)?></div><?php endif;
      endif; ?>
      <?php if(!empty($song['youtube_url'])): ?><a class="song-link" href="<?=h($song['youtube_url'])?>" target="_blank" rel="noopener">▶ YouTube öffnen</a><?php endif; ?>
      <?php
        $extraBadges=[];
        if(in_array('difficulty',$displayFields)&&!empty($song['difficulty'])){
          $d=(float)$song['difficulty'];
          $ds=$d<=2?'background:var(--green-light);color:var(--green);border-color:var(--green-mid)':($d<=4?'background:#fff8e1;color:#b8860b;border-color:rgba(184,134,11,.3)':'background:var(--red-soft);color:var(--red);border-color:rgba(193,9,15,.3)');
          $extraBadges[]='<span class="badge" style="'.$ds.'">'.number_format($d,1).'</span>';
        }
        if(in_array('duration',$displayFields)&&!empty($song['duration']))
          $extraBadges[]='<span class="badge">⏱ '.h($song['duration']).'</span>';
        if(in_array('genre',$displayFields)&&!empty($song['genre']))
          $extraBadges[]='<span class="badge">'.h($song['genre']).'</span>';
        if(in_array('shop_url',$displayFields)&&!empty($song['shop_url']))
          $extraBadges[]='<a href="'.h($song['shop_url']).'" target="_blank" rel="noopener" class="badge" style="text-decoration:none" title="Noten ansehen">🛒 Noten</a>';
        if($extraBadges):
      ?><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:5px"><?=implode('',$extraBadges)?></div><?php endif; ?>
      <?php
        $hasInfo = in_array('info',$displayFields)&&!empty($song['info']);
        if($hasInfo):
      ?>
        <div class="small" style="color:var(--muted);margin-top:6px">ℹ <?=h($song['info'])?></div>
      <?php endif; ?>
      <?php
        if (empty($vote) && !empty($song['piece_id']) && isset($oldVotesMap[$song['piece_id']])):
          $old = $oldVotesMap[$song['piece_id']];
          $voteLabels = ['ja' => '✓ Ja', 'nein' => '✗ Nein', 'neutral' => '– Neutral'];
          $voteLabel  = $voteLabels[$old['old_vote']] ?? $old['old_vote'];
          $voteColor  = $old['old_vote']==='ja' ? 'var(--green)' : ($old['old_vote']==='nein' ? 'var(--red)' : 'var(--muted)');
      ?>
        <div style="margin-top:8px;padding:8px 10px;background:#f5f2ee;border-radius:8px;border-left:3px solid <?=$voteColor?>;font-size:13px">
          💬 <strong>Letzte Abstimmung:</strong>
          <span style="color:<?=$voteColor?>;font-weight:700"><?=h($voteLabel)?></span>
          <?php if(!empty($old['old_note'])): ?>
            — <em style="color:var(--muted)"><?=h(mb_strimwidth($old['old_note'], 0, 80, '…'))?></em>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="vote-buttons">
        <button class="vote-btn ja<?= $vote==='ja' ? ' selected' : '' ?>" data-val="ja" <?=$frozen?'disabled':''?>>✓ Ja</button>
        <button class="vote-btn neutral<?= $vote==='neutral' ? ' selected' : '' ?>" data-val="neutral" <?=$frozen?'disabled':''?>>– Neutral</button>
        <button class="vote-btn nein<?= $vote==='nein' ? ' selected' : '' ?>" data-val="nein" <?=$frozen?'disabled':''?>>✗ Nein</button>
        <button class="vote-btn reset-btn" data-clear="1" title="Zurücksetzen" <?=$frozen?'disabled':''?>>✕</button>
    </div>
    <div class="vote-note">
      <textarea rows="2" placeholder="Optionale Notiz…" <?=$frozen?'disabled':''?>><?=h($song['my_note'] ?? '')?></textarea>
      <div style="display:flex;align-items:center;gap:10px;margin-top:6px">
        <button class="btn-save-note" style="display:none" <?=$frozen?'disabled':''?>>Notiz speichern</button>
        <span class="small" style="color:var(--success)" data-note-status></span>
      </div>
    </div>
  </div>
<?php endforeach; ?>
<?php if(!$songs): ?>
  <div class="card"><div class="small">Noch keine aktiven Titel. Admin → Titel importieren.</div></div>
<?php endif; ?>
</div>

<script>
const csrf   = <?= json_encode(sv_csrf_token()) ?>;
const frozen = <?= $frozen ? 'true' : 'false' ?>;
let currentFilter = 'all';

/* ── Toast ─────────────────────────────────── */
function toast(msg, ok=true){
  const el = document.getElementById('toast');
  el.className = 'notice ' + (ok ? 'success' : 'error');
  el.textContent = msg;
  el.style.display = 'block';
  requestAnimationFrame(() => el.classList.add('show'));
  clearTimeout(window.__tt);
  window.__tt = setTimeout(() => {
    el.classList.remove('show');
    setTimeout(() => { el.style.display='none'; }, 160);
  }, 1800);
}

/* ── Progress ───────────────────────────────── */
function updateProgress(p){
  if(!p) return;
  document.getElementById('doneBadge').textContent = p.done + ' / ' + p.total + ' bewertet';
  document.getElementById('progressBar').style.width = p.percent + '%';
  document.getElementById('progressPct').textContent = p.percent + '%';
}

/* ── Stats ──────────────────────────────────── */
function updateStats(){
  const s = {ja:0, nein:0, neutral:0, open:0};
  document.querySelectorAll('.song-card').forEach(c => {
    const v = c.dataset.vote || '';
    if(v === 'ja') s.ja++;
    else if(v === 'nein') s.nein++;
    else if(v === 'neutral') s.neutral++;
    else s.open++;
  });
  const el = document.getElementById('voteStats');
  if(el) el.textContent = `Offen: ${s.open} | Ja: ${s.ja} | Nein: ${s.nein} | Neutral: ${s.neutral}`;
}

/* ── Filter ─────────────────────────────────── */
function applyFilter(f){
  currentFilter = f;
  document.querySelectorAll('.filterbtn').forEach(b => b.classList.toggle('active', b.dataset.filter === f));
  document.querySelectorAll('.song-card').forEach(c => {
    const v = c.dataset.vote || '';
    let show = f === 'all' ? true
      : f === 'open' ? (v === '')
      : (v === f);
    c.style.display = show ? '' : 'none';
  });
}

/* ── Mark card ──────────────────────────────── */
function markCard(card, val){
  card.classList.remove('voted-ja','voted-nein','voted-neutral');
  if(val === 'ja')      card.classList.add('voted-ja');
  if(val === 'nein')    card.classList.add('voted-nein');
  if(val === 'neutral') card.classList.add('voted-neutral');
  card.querySelectorAll('.vote-btn[data-val]').forEach(b => {
    b.classList.toggle('selected', b.dataset.val === val);
  });
  card.dataset.vote = val;
}

/* ── API ────────────────────────────────────── */
async function apiVote(songId, vote){
  const r = await fetch('api/vote.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
    body: JSON.stringify({song_id: songId, vote})
  });
  if(!r.ok) throw new Error(await r.text());
  return r.json();
}
async function apiNote(songId, note){
  const r = await fetch('api/note.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
    body: JSON.stringify({song_id: songId, note})
  });
  if(!r.ok) throw new Error(await r.text());
  return r.json();
}

/* ── Bind events ────────────────────────────── */
document.querySelectorAll('.filterbtn').forEach(b => {
  b.addEventListener('click', () => applyFilter(b.dataset.filter));
});

document.querySelectorAll('.song-card').forEach(card => {
  const songId = card.dataset.songId;

  /* Vote buttons */
  card.querySelectorAll('.vote-btn[data-val]').forEach(btn => {
    btn.addEventListener('click', async () => {
      try {
        const out = await apiVote(songId, btn.dataset.val);
        markCard(card, btn.dataset.val);
        updateStats();
        applyFilter(currentFilter);
        updateProgress(out && out.progress);
        toast('Gespeichert ✓');
      } catch(e) {
        toast(String(e).includes('423') ? 'Abstimmung ist eingefroren' : 'Fehler beim Speichern', false);
      }
    });
  });

  /* Reset button */
  card.querySelector('[data-clear]')?.addEventListener('click', async () => {
    try {
      const out = await apiVote(songId, null);
      markCard(card, '');
      updateStats();
      applyFilter(currentFilter);
      updateProgress(out && out.progress);
      toast('Zurückgesetzt');
    } catch(e) {
      toast('Fehler beim Zurücksetzen', false);
    }
  });

  /* Notes */
  const ta  = card.querySelector('textarea');
  const btn = card.querySelector('.btn-save-note');
  const st  = card.querySelector('[data-note-status]');
  let savedNote = ta ? ta.value : '';

  ta?.addEventListener('input', () => {
    if(btn) btn.style.display = (ta.value !== savedNote) ? 'inline-block' : 'none';
    if(st) st.textContent = '';
  });

  btn?.addEventListener('click', async () => {
    try {
      await apiNote(songId, ta.value);
      savedNote = ta.value;
      btn.style.display = 'none';
      if(st){ st.textContent = 'Gespeichert ✓'; setTimeout(()=>{ st.textContent=''; }, 2000); }
      toast('Notiz gespeichert ✓');
    } catch(e) {
      toast('Fehler beim Speichern der Notiz', false);
    }
  });
});

updateStats();
applyFilter('all');
</script>

<?php sv_footer(); ?>
