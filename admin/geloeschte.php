<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = sv_require_admin();
$pdo  = sv_pdo();
$base = sv_base_url();

// ── POST-Aktionen ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $action = $_POST['action'] ?? '';

  // ── Bibliothek ──
  if ($action === 'restore_piece') {
    $pid = (int)($_POST['pid'] ?? 0);
    if ($pid > 0) {
      $pdo->prepare("UPDATE pieces SET deleted_at=NULL, deleted_by=NULL, delete_reason=NULL WHERE id=?")->execute([$pid]);
      sv_log($user['id'], 'piece_restore', "pid=$pid");
      sv_flash_set('success', 'Stück wiederhergestellt.');
    }
  } elseif ($action === 'delete_piece') {
    $pid = (int)($_POST['pid'] ?? 0);
    if ($pid > 0) {
      $pdo->prepare("DELETE FROM pieces WHERE id=?")->execute([$pid]);
      sv_log($user['id'], 'piece_permanent_delete', "pid=$pid");
      sv_flash_set('success', 'Stück endgültig gelöscht.');
    }

  // ── Abstimmungstitel ──
  } elseif ($action === 'restore_song') {
    $sid = (int)($_POST['sid'] ?? 0);
    if ($sid > 0) {
      $pdo->prepare("UPDATE songs SET deleted_at=NULL, deleted_by=NULL, delete_reason=NULL WHERE id=?")->execute([$sid]);
      sv_log($user['id'], 'song_restore', "song_id=$sid");
      sv_flash_set('success', 'Abstimmungstitel wiederhergestellt.');
    }
  } elseif ($action === 'delete_song') {
    $sid = (int)($_POST['sid'] ?? 0);
    if ($sid > 0) {
      // Vote-Archivierung bei piece_id
      $songCheck = $pdo->prepare("SELECT piece_id FROM songs WHERE id=?");
      $songCheck->execute([$sid]);
      $songRow = $songCheck->fetch();
      if ($songRow && $songRow['piece_id']) {
        $pdo->prepare("
          INSERT INTO vote_history (user_id, piece_id, vote, note, archived_at)
          SELECT v.user_id, ?, v.vote, vn.note, NOW()
          FROM votes v
          LEFT JOIN vote_notes vn ON vn.song_id = v.song_id AND vn.user_id = v.user_id
          WHERE v.song_id = ?
          ON DUPLICATE KEY UPDATE vote=VALUES(vote), note=VALUES(note), archived_at=NOW()
        ")->execute([$songRow['piece_id'], $sid]);
      }
      $pdo->prepare("DELETE FROM songs WHERE id=?")->execute([$sid]);
      sv_log($user['id'], 'song_permanent_delete', "song_id=$sid");
      sv_flash_set('success', 'Abstimmungstitel endgültig gelöscht.');
    }

  // ── Chronik ──
  } elseif ($action === 'restore_concert') {
    $cid = (int)($_POST['cid'] ?? 0);
    if ($cid > 0) {
      $pdo->prepare("UPDATE concerts SET deleted_at=NULL, deleted_by=NULL, delete_reason=NULL WHERE id=?")->execute([$cid]);
      sv_log($user['id'], 'concert_restore', "concert_id=$cid");
      sv_flash_set('success', 'Auftritt wiederhergestellt.');
    }
  } elseif ($action === 'delete_concert') {
    $cid = (int)($_POST['cid'] ?? 0);
    if ($cid > 0) {
      $pdo->prepare("DELETE FROM concerts WHERE id=?")->execute([$cid]);
      sv_log($user['id'], 'concert_permanent_delete', "concert_id=$cid");
      sv_flash_set('success', 'Auftritt endgültig gelöscht.');
    }
  }

  header('Location: ' . $base . '/admin/geloeschte.php');
  exit;
}

// ── Daten laden ──────────────────────────────────────────────────────────────

$deletedPieces = $pdo->query("
  SELECT p.id, p.title, p.composer, p.arranger, p.deleted_at, p.deleted_by, p.delete_reason,
         u.display_name AS deleted_by_name
  FROM pieces p
  LEFT JOIN users u ON u.id = p.deleted_by
  WHERE p.deleted_at IS NOT NULL
  ORDER BY p.deleted_at DESC
")->fetchAll();

$deletedSongs = $pdo->query("
  SELECT s.id, s.title, s.composer, s.arranger, s.youtube_url, s.piece_id,
         s.deleted_at, s.deleted_by, s.delete_reason,
         u.display_name AS deleted_by_name,
         p.title AS piece_title
  FROM songs s
  LEFT JOIN users u ON u.id = s.deleted_by
  LEFT JOIN pieces p ON p.id = s.piece_id
  WHERE s.deleted_at IS NOT NULL
  ORDER BY s.deleted_at DESC
")->fetchAll();

$deletedConcerts = $pdo->query("
  SELECT c.id, c.name, c.year, c.date, c.location,
         c.deleted_at, c.deleted_by, c.delete_reason,
         u.display_name AS deleted_by_name
  FROM concerts c
  LEFT JOIN users u ON u.id = c.deleted_by
  WHERE c.deleted_at IS NOT NULL
  ORDER BY c.deleted_at DESC
")->fetchAll();

$totalDeleted = count($deletedPieces) + count($deletedSongs) + count($deletedConcerts);

sv_header('Admin – Gelöschte Einträge', $user);
?>

<div class="page-header">
  <div>
    <h2>Gelöschte Einträge</h2>
    <div class="small"><?= $totalDeleted ?> Eintrag<?= $totalDeleted !== 1 ? 'e' : '' ?> zur Prüfung vorgemerkt</div>
  </div>
  <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
</div>

<?php if ($totalDeleted === 0): ?>
<div class="card"><div class="small">Keine gelöschten Einträge vorhanden.</div></div>
<?php else: ?>

<!-- Bibliothek -->
<?php if ($deletedPieces): ?>
<div class="card" style="margin-bottom:16px">
  <h3 style="margin-bottom:12px">📚 Bibliothek <span class="badge"><?= count($deletedPieces) ?></span></h3>
  <?php foreach ($deletedPieces as $p): ?>
  <div style="padding:12px;margin-bottom:8px;border:1.5px solid rgba(193,9,15,.2);border-radius:10px;background:var(--red-soft)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
      <div style="flex:1;min-width:200px">
        <a href="<?=h($base)?>/admin/bibliothek.php?q=<?=urlencode($p['title'])?>" style="color:inherit;text-decoration:none"><strong><?=h($p['title'])?></strong></a>
        <?php
          $meta = array_filter([
            !empty($p['composer']) ? h($p['composer']) : '',
            !empty($p['arranger']) ? 'Arr. ' . h($p['arranger']) : '',
          ]);
          if ($meta): ?><div class="small"><?= implode(' · ', $meta) ?></div><?php endif;
        ?>
        <div class="small" style="margin-top:4px;color:var(--red)">
          🗑 Gelöscht von <strong><?=h($p['deleted_by_name'] ?? 'Unbekannt')?></strong>
          am <?=h(date('d.m.Y H:i', strtotime($p['deleted_at'])))?>
        </div>
        <?php if (!empty($p['delete_reason'])): ?>
          <div class="small" style="margin-top:2px;color:var(--muted)">Grund: <?=h($p['delete_reason'])?></div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0">
        <form method="post"><input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>"><input type="hidden" name="action" value="restore_piece"><input type="hidden" name="pid" value="<?=h($p['id'])?>">
          <button class="btn" type="submit" style="color:var(--green)">♻️ Wiederherstellen</button>
        </form>
        <form method="post" onsubmit="return confirm('Stück endgültig löschen? Das kann nicht rückgängig gemacht werden.')"><input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>"><input type="hidden" name="action" value="delete_piece"><input type="hidden" name="pid" value="<?=h($p['id'])?>">
          <button class="btn" type="submit" style="color:var(--red)">🗑 Endgültig löschen</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Abstimmungstitel -->
<?php if ($deletedSongs): ?>
<div class="card" style="margin-bottom:16px">
  <h3 style="margin-bottom:12px">🎵 Abstimmungstitel <span class="badge"><?= count($deletedSongs) ?></span></h3>
  <?php foreach ($deletedSongs as $s): ?>
  <div style="padding:12px;margin-bottom:8px;border:1.5px solid rgba(193,9,15,.2);border-radius:10px;background:var(--red-soft)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
      <div style="flex:1;min-width:200px">
        <a href="<?=h($base)?>/admin/abstimmungstitel.php?q=<?=urlencode($s['title'])?>" style="color:inherit;text-decoration:none"><strong><?=h($s['title'])?></strong></a>
        <?php
          $meta = array_filter([
            !empty($s['composer']) ? h($s['composer']) : '',
            !empty($s['arranger']) ? 'Arr. ' . h($s['arranger']) : '',
          ]);
          if ($meta): ?><div class="small"><?= implode(' · ', $meta) ?></div><?php endif;
        ?>
        <?php if ($s['piece_id'] && !empty($s['piece_title'])): ?>
          <div><a href="<?=h($base)?>/admin/bibliothek.php?q=<?=urlencode($s['piece_title'])?>" style="text-decoration:none"><span class="badge" style="background:var(--green-light);color:var(--green);border-color:var(--green-mid);font-size:11px">🔗 Archiv: <?=h($s['piece_title'])?></span></a></div>
        <?php elseif ($s['piece_id'] && empty($s['piece_title'])): ?>
          <div><span class="badge" style="background:#fff8e1;color:#b8860b;border-color:rgba(184,134,11,.3);font-size:11px">⚠ Archiv-Eintrag gelöscht</span></div>
        <?php endif; ?>
        <?php if (!empty($s['youtube_url'])): ?><div><a class="song-link" href="<?=h($s['youtube_url'])?>" target="_blank" rel="noopener">▶ YouTube öffnen</a></div><?php endif; ?>
        <div class="small" style="margin-top:4px;color:var(--red)">
          🗑 Gelöscht von <strong><?=h($s['deleted_by_name'] ?? 'Unbekannt')?></strong>
          am <?=h(date('d.m.Y H:i', strtotime($s['deleted_at'])))?>
        </div>
        <?php if (!empty($s['delete_reason'])): ?>
          <div class="small" style="margin-top:2px;color:var(--muted)">Grund: <?=h($s['delete_reason'])?></div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0">
        <form method="post"><input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>"><input type="hidden" name="action" value="restore_song"><input type="hidden" name="sid" value="<?=h($s['id'])?>">
          <button class="btn" type="submit" style="color:var(--green)">♻️ Wiederherstellen</button>
        </form>
        <form method="post" onsubmit="return confirm('<?= $s['piece_id'] ? 'Titel löschen? Stimmen bleiben im Archiv erhalten.' : 'Titel löschen? Stimmen gehen unwiderruflich verloren!' ?>')"><input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>"><input type="hidden" name="action" value="delete_song"><input type="hidden" name="sid" value="<?=h($s['id'])?>">
          <button class="btn" type="submit" style="color:var(--red)">🗑 Endgültig löschen</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Auftritte -->
<?php if ($deletedConcerts): ?>
<div class="card" style="margin-bottom:16px">
  <h3 style="margin-bottom:12px">🎼 Auftritte <span class="badge"><?= count($deletedConcerts) ?></span></h3>
  <?php foreach ($deletedConcerts as $c): ?>
  <div style="padding:12px;margin-bottom:8px;border:1.5px solid rgba(193,9,15,.2);border-radius:10px;background:var(--red-soft)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
      <div style="flex:1;min-width:200px">
        <a href="<?=h($base)?>/admin/concerts.php?q=<?=urlencode($c['name'])?>" style="color:inherit;text-decoration:none"><strong><?=h($c['name'])?></strong></a>
        <?php
          $meta = array_filter([
            !empty($c['year']) ? h($c['year']) : '',
            !empty($c['date']) ? h(date('d.m.Y', strtotime($c['date']))) : '',
            !empty($c['location']) ? h($c['location']) : '',
          ]);
          if ($meta): ?><div class="small"><?= implode(' · ', $meta) ?></div><?php endif;
        ?>
        <div class="small" style="margin-top:4px;color:var(--red)">
          🗑 Gelöscht von <strong><?=h($c['deleted_by_name'] ?? 'Unbekannt')?></strong>
          am <?=h(date('d.m.Y H:i', strtotime($c['deleted_at'])))?>
        </div>
        <?php if (!empty($c['delete_reason'])): ?>
          <div class="small" style="margin-top:2px;color:var(--muted)">Grund: <?=h($c['delete_reason'])?></div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:6px;flex-shrink:0">
        <form method="post"><input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>"><input type="hidden" name="action" value="restore_concert"><input type="hidden" name="cid" value="<?=h($c['id'])?>">
          <button class="btn" type="submit" style="color:var(--green)">♻️ Wiederherstellen</button>
        </form>
        <form method="post" onsubmit="return confirm('Auftritt endgültig löschen? Das kann nicht rückgängig gemacht werden.')"><input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>"><input type="hidden" name="action" value="delete_concert"><input type="hidden" name="cid" value="<?=h($c['id'])?>">
          <button class="btn" type="submit" style="color:var(--red)">🗑 Endgültig löschen</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php sv_footer(); ?>
