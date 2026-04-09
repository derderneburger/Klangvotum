<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$admin = sv_require_admin();
$pdo = sv_pdo();

$user_id = (int)($_GET['user_id'] ?? 0);
$song_id = (int)($_GET['song_id'] ?? 0);

$params = [];
$where = "WHERE s.is_active = 1 AND s.deleted_at IS NULL AND u.is_active = 1
          AND (
            v.vote IS NOT NULL
            OR TRIM(COALESCE(vn.note, '')) <> ''
          )";
if ($user_id > 0) { $where .= " AND u.id = ?"; $params[] = $user_id; }
if ($song_id > 0) { $where .= " AND s.id = ?"; $params[] = $song_id; }

$sql = "
  SELECT
    u.id AS user_id,
    s.id AS song_id,
    u.display_name,
    u.username,
    s.title,
    s.youtube_url,
    v.vote,
    v.updated_at,
    vn.note
  FROM users u
  CROSS JOIN songs s
  LEFT JOIN votes v
    ON v.user_id = u.id AND v.song_id = s.id
  LEFT JOIN vote_notes vn
    ON vn.user_id = u.id AND vn.song_id = s.id
  $where
  ORDER BY s.title ASC, u.display_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="songvote_stimmen.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Teilnehmer','Username','Titel','YouTube','Vote','Notiz','Updated']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['display_name'],
      $r['username'],
      $r['title'],
      $r['youtube_url'],
      $r['vote'] ?? '',
      str_replace(["\r\n", "\r", "\n"], ' ', $r['note'] ?? ''),
      $r['updated_at'] ?? ''
    ]);
  }
  fclose($out);
  exit;
}

$users = $pdo->query("SELECT id, display_name, username FROM users WHERE is_active=1 ORDER BY display_name ASC")->fetchAll();
$songs = $pdo->query("SELECT id, title FROM songs WHERE is_active=1 AND deleted_at IS NULL ORDER BY title ASC")->fetchAll();

sv_header('Admin – Stimmen', $admin);
$base = sv_base_url();
?>
<div class="page-header">
  <div>
    <h2>Stimmen</h2>
    <div class="small">Wer hat für welches Stück abgestimmt oder eine Notiz hinterlegt (nur aktive Nutzer + aktive Titel).</div>
  </div>
  <div class="row">
    <a class="btn" href="<?=h($base)?>/admin/votes.php?<?=http_build_query(array_merge($_GET, ['export'=>'csv']))?>">CSV Export</a>
    <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
  </div>
</div>

<div class="card" style="margin-top:12px">
  <form method="get" class="row" style="gap:10px;align-items:end">
    <label>Teilnehmer<br>
      <select name="user_id">
        <option value="0">Alle</option>
        <?php foreach ($users as $u): ?>
          <option value="<?=h($u['id'])?>" <?=($user_id==(int)$u['id']?'selected':'')?>><?=h($u['display_name'])?> (<?=h($u['username'])?>)</option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Stück<br>
      <select name="song_id">
        <option value="0">Alle</option>
        <?php foreach ($songs as $s): ?>
          <option value="<?=h($s['id'])?>" <?=($song_id==(int)$s['id']?'selected':'')?>><?=h($s['title'])?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn primary" type="submit">Filtern</button>
    <a class="btn" href="<?=h($base)?>/admin/votes.php">Zurücksetzen</a>
  </form>
</div>

<div class="card" style="margin-top:12px">
  <div class="table-scroll wide">
  <table>
    <thead>
      <tr>
        <th>Teilnehmer</th>
        <th>Titel</th>
        <th>Vote</th>
        <th>Notiz</th>
        <th>Updated</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?=h($r['display_name'])?> <span class="small">(<?=h($r['username'])?>)</span></td>
          <td>
            <div class="song-title"><?=h($r['title'])?></div>
            <div class="small"><a class="song-link" href="<?=h($r['youtube_url'])?>" target="_blank" rel="noopener">▶ YouTube öffnen</a></div>
          </td>
          <td>
            <?php if (!empty($r['vote'])): ?>
              <span class="badge"><?=h($r['vote'])?></span>
            <?php else: ?>
              <span class="small">—</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= nl2br(h($r['note'] ?? '')) ?></td>
          <td class="small"><?= !empty($r['updated_at']) ? h($r['updated_at']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="5" class="small">Keine Stimmen oder Notizen (oder Filter zu eng).</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>

<?php sv_footer(); ?>
