<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$u   = sv_require_login();
$pdo = sv_pdo();
$base = sv_base_url();
$isAdmin   = sv_is_admin($u);
$isLeitung = sv_is_leitung($u);
$canNoten  = sv_can_edit_noten($u);
$canChronikEdit = sv_can_edit_chronik($u);

$top5 = $pdo->query("
  SELECT s.id, s.title, s.youtube_url,
         SUM(v.vote='ja') AS ja,
         SUM(v.vote='nein') AS nein,
         SUM(v.vote='neutral') AS neutral,
         (SUM(v.vote='ja') - SUM(v.vote='nein')) AS score
  FROM songs s
  LEFT JOIN votes v ON v.song_id = s.id
  WHERE s.is_active=1 AND s.deleted_at IS NULL
  GROUP BY s.id
  ORDER BY score DESC, ja DESC, s.id ASC
  LIMIT 5
")->fetchAll();

$stats = $pdo->query("
  SELECT
    (SELECT COUNT(*) FROM users WHERE is_active=1)   AS users_active,
    (SELECT COUNT(*) FROM users WHERE is_admin=1 AND is_active=1) AS admins_active,
    (SELECT COUNT(*) FROM songs WHERE is_active=1 AND deleted_at IS NULL)   AS songs_active,
    (SELECT COUNT(*) FROM songs WHERE is_active=0 AND deleted_at IS NULL)   AS songs_inactive,
    (SELECT COUNT(*) FROM votes v
      JOIN users u ON u.id=v.user_id AND u.is_active=1
      JOIN songs s ON s.id=v.song_id AND s.is_active=1 AND s.deleted_at IS NULL
    ) AS votes_count
")->fetch();

$activeUsers = [];
$onlineUsers = [];
if ($isAdmin) {
  $activeUsers = $pdo->query("
    SELECT u.id, u.username, u.display_name, u.is_admin, u.role, ua.last_activity
    FROM users u
    JOIN user_activity ua ON ua.user_id=u.id
    WHERE u.is_active=1 AND ua.last_activity >= (NOW() - INTERVAL 5 MINUTE)
    ORDER BY ua.last_activity DESC, u.display_name ASC
  ")->fetchAll();

  $onlineUsers = $pdo->query("
    SELECT u.id, u.username, u.display_name, u.is_admin, u.role, ua.last_activity
    FROM users u
    JOIN user_activity ua ON ua.user_id=u.id
    WHERE u.is_active=1
      AND ua.last_activity >= (NOW() - INTERVAL 20 MINUTE)
      AND ua.last_activity < (NOW() - INTERVAL 5 MINUTE)
    ORDER BY ua.last_activity DESC, u.display_name ASC
  ")->fetchAll();
}

$usersActive  = (int)($stats['users_active']  ?? 0);
$adminsActive = (int)($stats['admins_active'] ?? 0);
$songsActive  = (int)($stats['songs_active']  ?? 0);
$songsInactive= (int)($stats['songs_inactive']?? 0);
$votesCount   = (int)($stats['votes_count']   ?? 0);
$votesPossible= $usersActive * $songsActive;
$progressPct  = $votesPossible > 0 ? (int)round(($votesCount/$votesPossible)*100) : 0;

$maxAbs = 1;
foreach ($top5 as $r) $maxAbs = max($maxAbs, abs((int)$r['score']));
$frozen = sv_is_frozen();
$pendingSuggestions = 0;
if ($canNoten) {
  try { $pendingSuggestions = (int)$pdo->query("SELECT COUNT(*) FROM piece_suggestions WHERE status='pending'")->fetchColumn(); } catch (Throwable $e) {}
}
$deletedPieces = 0; $deletedSongs = 0; $deletedConcerts = 0;
if ($isAdmin) {
  try {
    $deletedPieces   = (int)$pdo->query("SELECT COUNT(*) FROM pieces WHERE deleted_at IS NOT NULL")->fetchColumn();
    $deletedSongs    = (int)$pdo->query("SELECT COUNT(*) FROM songs WHERE deleted_at IS NOT NULL")->fetchColumn();
    $deletedConcerts = (int)$pdo->query("SELECT COUNT(*) FROM concerts WHERE deleted_at IS NOT NULL")->fetchColumn();
  } catch (Throwable $e) {}
}
$deletedTotal = $deletedPieces + $deletedSongs + $deletedConcerts;

function sv_time_ago(?string $dt): string {
  if(!$dt) return 'unbekannt';
  $ts = strtotime($dt);
  if(!$ts) return 'unbekannt';
  $d = time() - $ts;
  if($d < 60) return 'gerade eben';
  if($d < 3600) return (int)floor($d/60).' Min.';
  if($d < 86400) return (int)floor($d/3600).' Std.';
  return (int)floor($d/86400).' Tg.';
}

sv_header($isAdmin ? 'Admin' : 'Verwaltung', $u);
?>

<?php if($frozen): ?>
<div class="freeze-banner" style="margin-bottom:16px">🔒 <span><strong>Abstimmung ist eingefroren.</strong> Teilnehmer können keine Stimmen ändern.</span></div>
<?php endif; ?>

<?php if($pendingSuggestions > 0): ?>
<a href="<?=h($base)?>/admin/vorschlaege.php" style="display:block;text-decoration:none;margin-bottom:16px;padding:12px 16px;background:#fff3e0;border:2px solid #e65100;border-radius:12px;color:#e65100;font-weight:600">
  📝 <strong><?=$pendingSuggestions?> Änderungsvorschlag<?= $pendingSuggestions !== 1 ? 'e' : '' ?></strong> <?= $pendingSuggestions === 1 ? 'wartet' : 'warten' ?> auf Prüfung → <span style="text-decoration:underline">Vorschläge ansehen</span>
</a>
<?php endif; ?>

<?php if($deletedTotal > 0): ?>
<a href="<?=h($base)?>/admin/geloeschte.php" style="display:block;text-decoration:none;margin-bottom:16px;padding:12px 16px;background:var(--red-soft);border:2px solid var(--red);border-radius:12px;color:var(--red);font-weight:600">
  🗑 <strong><?=$deletedTotal?> Eintrag<?= $deletedTotal !== 1 ? 'e' : '' ?></strong> zur Löschung vorgemerkt:
  <?php
    $parts = [];
    if ($deletedPieces)   $parts[] = $deletedPieces.' Stück'.($deletedPieces!==1?'e':'');
    if ($deletedSongs)    $parts[] = $deletedSongs.' Abstimmungstitel';
    if ($deletedConcerts) $parts[] = $deletedConcerts.' Auftritt'.($deletedConcerts!==1?'e':'');
    echo implode(' · ', $parts);
  ?> → <span style="text-decoration:underline">Prüfen</span>
</a>
<?php endif; ?>

<div class="page-header" style="margin-bottom:16px">
  <div><h2><?= $isAdmin ? 'Admin Dashboard' : 'Verwaltung' ?></h2></div>
</div>

<!-- Stat Cards -->
<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-label">👥 Nutzer</div>
    <div class="stat-value"><?=h($usersActive)?></div>
    <div class="stat-sub">aktiv<?php if($adminsActive): ?> · <?=h($adminsActive)?> Admin<?= $adminsActive!==1?'s':'' ?><?php endif; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">🗳️ Stimmen</div>
    <div class="stat-value"><?=h($votesCount)?></div>
    <div class="stat-sub">von <?=h($votesPossible)?> möglich</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">🎵 Titel</div>
    <div class="stat-value"><?=h($songsActive)?></div>
    <div class="stat-sub">aktiv<?php if($songsInactive): ?> · <?=h($songsInactive)?> inaktiv<?php endif; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">📊 Fortschritt</div>
    <div class="stat-value"><?=h($progressPct)?>%</div>
    <div class="stat-sub" style="margin-bottom:8px">gesamt</div>
    <div class="progress"><div style="width:<?=$progressPct?>%"></div></div>
  </div>
</div>

<!-- Top 5 -->
<div class="card" style="margin-bottom:16px">
  <h3>🔥 Top 5 aktuell</h3>
  <div class="small" style="margin-bottom:14px">Score = Ja − Nein. Neutral zählt nicht.</div>
  <div style="overflow-x:auto">
    <table>
      <thead>
        <tr><th>#</th><th>Titel</th><th>Ja</th><th>Neutral</th><th>Nein</th><th>Score</th><th style="min-width:90px">Trend</th></tr>
      </thead>
      <tbody>
      <?php $i=1; foreach($top5 as $r):
        $score = (int)$r['score'];
        $w     = (int)round($maxAbs ? abs($score)/$maxAbs*100 : 0);
        $pillClass = $score > 0 ? 'pos' : ($score < 0 ? 'neg' : 'zero');
        $barClass  = $score >= 0 ? 'heat-pos' : 'heat-neg';
      ?>
        <tr>
          <td><span class="rank-num"><?=h($i++)?></span></td>
          <td>
            <strong><?=h($r['title'])?></strong>
            <?php if(!empty($r['youtube_url'])): ?>
              <div><a class="song-link" href="<?=h($r['youtube_url'])?>" target="_blank" rel="noopener">▶ YouTube öffnen</a></div>
            <?php endif; ?>
          </td>
          <td><?=h((int)$r['ja'])?></td>
          <td><?=h((int)$r['neutral'])?></td>
          <td><?=h((int)$r['nein'])?></td>
          <td>
            <?php
              $scBg     = $score>0?'var(--score-light)':($score<0?'var(--red-soft)':'#f5f2ee');
              $scColor  = $score>0?'var(--score)':($score<0?'var(--red)':'var(--muted)');
              $scBorder = $score>0?'var(--score-mid)':($score<0?'rgba(193,9,15,.3)':'#ddd');
            ?>
            <div style="display:inline-flex;flex-direction:column;align-items:center;background:<?=$scBg?>;color:<?=$scColor?>;border:1.5px solid <?=$scBorder?>;border-radius:10px;padding:5px 12px;min-width:44px;text-align:center">
              <span style="font-family:'Fraunces',serif;font-size:1.3rem;font-weight:700;line-height:1"><?= $score>0?'+':'' ?><?=h($score)?></span>
              <span style="font-size:9px;font-weight:700;letter-spacing:.06em;opacity:.7;margin-top:1px">SCORE</span>
            </div>
          </td>
          <td><div class="heat-bar"><div class="heat-fill <?=h($barClass)?>" style="width:<?=$w?>%"></div></div></td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$top5): ?><tr><td colspan="7" class="small">Noch keine Titel oder Stimmen.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($isAdmin): ?>
<!-- Online Users -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
  <div class="card">
    <h3 style="margin-bottom:10px"><span class="online-dot dot-green"></span>Aktiv <span class="badge" style="vertical-align:middle"><?=count($activeUsers)?></span></h3>
    <div class="small" style="margin-bottom:10px">Letzte 5 Minuten</div>
    <?php if($activeUsers): foreach($activeUsers as $ou): ?>
      <div class="user-row">
        <div style="font-weight:700"><?=h($ou['display_name'] ?: $ou['username'])?><?php if((int)$ou['is_admin']): ?> <?= sv_role_badge($ou['role'] ?? 'admin') ?><?php endif; ?></div>
        <span class="small">vor <?=h(sv_time_ago($ou['last_activity']))?></span>
      </div>
    <?php endforeach; else: ?>
      <div class="small">Niemand gerade aktiv.</div>
    <?php endif; ?>
  </div>
  <div class="card">
    <h3 style="margin-bottom:10px"><span class="online-dot dot-yellow"></span>Online <span class="badge" style="vertical-align:middle"><?=count($onlineUsers)?></span></h3>
    <div class="small" style="margin-bottom:10px">Letzte 20 Minuten (ohne Aktive)</div>
    <?php if($onlineUsers): foreach($onlineUsers as $ou): ?>
      <div class="user-row">
        <div style="font-weight:700"><?=h($ou['display_name'] ?: $ou['username'])?></div>
        <span class="small">vor <?=h(sv_time_ago($ou['last_activity']))?></span>
      </div>
    <?php endforeach; else: ?>
      <div class="small">Keine weiteren Nutzer online.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Verwaltung Grid -->
<div class="admin-grid">

  <?php if ($canNoten || $isAdmin): ?>
  <div class="admin-section-card">
    <h3>Abstimmung</h3>
    <div class="admin-btn-row">
      <a class="btn" href="<?=h($base)?>/admin/abstimmungstitel.php">🎵 Abstimmungstitel</a>
      <?php if ($isAdmin): ?>
      <a class="btn" href="<?=h($base)?>/admin/freeze.php">❄️ Einfrieren</a>
      <a class="btn" href="<?=h($base)?>/admin/kartenanzeige.php">🎨 Anzeige</a>
      <?php endif; ?>
    </div>
    <div class="small"><?= $isAdmin ? 'Titel zur Abstimmung stellen und Abstimmung sperren.' : 'Titel zur Abstimmung verwalten.' ?></div>
  </div>
  <?php endif; ?>

  <?php if ($isLeitung): ?>
  <div class="admin-section-card">
    <h3>Auswertung</h3>
    <div class="admin-btn-row">
      <a class="btn" href="<?=h($base)?>/admin/progress.php">📈 Fortschritt</a>
      <a class="btn" href="<?=h($base)?>/admin/results.php">📊 Ergebnisse</a>
      <?php if ($isAdmin): ?>
      <a class="btn" href="<?=h($base)?>/admin/votes.php">🗳️ Stimmen</a>
      <?php endif; ?>
    </div>
    <div class="small">Wer hat abgestimmt, aggregierte Ergebnisse und Detailansicht.</div>
  </div>
  <?php endif; ?>

  <div class="admin-section-card">
    <h3>Notenbibliothek</h3>
    <div class="admin-btn-row">
      <a class="btn" href="<?=h($base)?>/admin/bibliothek.php">📚 Bibliothek</a>
      <?php if ($canNoten): ?>
      <a class="btn" href="<?=h($base)?>/admin/ausleihen.php">📦 Ausleihen</a>
      <a class="btn" href="<?=h($base)?>/admin/vorschlaege.php">📝 Vorschläge<?php if($pendingSuggestions): ?> <span style="background:#e65100;color:#fff;border-radius:50%;padding:1px 6px;font-size:11px;font-weight:700;margin-left:4px"><?=$pendingSuggestions?></span><?php endif; ?></a>
      <a class="btn" href="<?=h($base)?>/admin/bibliothek_inhaltsverzeichnis.php" target="_blank">📄 Inhaltsverzeichnis</a>
      <?php endif; ?>
      <?php if ($isAdmin && $deletedTotal > 0): ?>
      <a class="btn" href="<?=h($base)?>/admin/geloeschte.php">🗑 Gelöschte<?php if($deletedTotal): ?> <span style="background:var(--red);color:#fff;border-radius:50%;padding:1px 6px;font-size:11px;font-weight:700;margin-left:4px"><?=$deletedTotal?></span><?php endif; ?></a>
      <?php endif; ?>
    </div>
    <div class="small">Alle Stücke die das Orchester besitzt oder je gespielt hat.</div>
  </div>

  <div class="admin-section-card">
    <h3>Auftrittchronik</h3>
    <div class="admin-btn-row">
      <a class="btn" href="<?=h($base)?>/admin/concerts.php">🎼 Chronik</a>
    </div>
    <div class="small"><?= $canChronikEdit ? 'Konzerte dokumentieren und Stücke zuordnen.' : 'Konzerthistorie und gespielte Stücke einsehen.' ?></div>
  </div>

  <?php if ($isAdmin): ?>
  <div class="admin-section-card">
    <h3>Teilnehmer</h3>
    <div class="admin-btn-row">
      <a class="btn" href="<?=h($base)?>/admin/users.php">👥 Benutzer</a>
    </div>
    <div class="small">Zugänge anlegen, deaktivieren oder Passwörter zurücksetzen.</div>
  </div>
  <div class="admin-section-card">
    <h3>Software-Einstellungen</h3>
    <div class="admin-btn-row">
      <a class="btn" href="<?=h($base)?>/admin/einstellungen.php">⚙ Einstellungen</a>
    </div>
    <div class="small">Logo, Akzentfarben und Rollenbezeichnungen anpassen.</div>
  </div>
  <div class="admin-section-card" style="grid-column:1/-1">
    <h3>Wartung</h3>
    <div class="admin-btn-row">
      <a class="btn" href="<?=h($base)?>/admin/backup.php">💾 Backup</a>
      <a class="btn" href="<?=h($base)?>/admin/logininfo.php">🔐 Login-Infos</a>
      <a class="btn" href="<?=h($base)?>/admin/sysinfo.php">🖥️ Systeminfo</a>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($isAdmin): ?>
  <div class="admin-section-card" style="grid-column:1/-1;border:1.5px dashed var(--border);background:#faf8f5">
    <h3>🧪 Experimentell</h3>
    <div class="admin-btn-row">
      <a class="btn" href="<?=h($base)?>/admin/planer.php">🎼 Konzertplaner</a>
    </div>
    <div class="small">Kombinierte Ansicht aus Bibliothek und Abstimmungstiteln mit Zeitkalkulation.</div>
  </div>
  <?php endif; ?>

</div>

<?php sv_footer(); ?>
