<?php
require_once __DIR__ . '/../lib/auth.php';

$user = sv_require_login();

if (sv_is_frozen()) {
  http_response_code(423);
  exit('Abstimmung ist eingefroren');
}

sv_csrf_check();

$body = json_decode(file_get_contents('php://input'), true);
$song_id = (int)($body['song_id'] ?? 0);
$vote = $body['vote'] ?? null;

if ($song_id <= 0) { http_response_code(400); exit('song_id missing'); }
if (!is_null($vote) && !in_array($vote, ['ja','nein','neutral'], true)) { http_response_code(400); exit('vote invalid'); }

$pdo = sv_pdo();

$stmt = $pdo->prepare("SELECT id FROM songs WHERE id = ? AND is_active = 1 AND deleted_at IS NULL");
$stmt->execute([$song_id]);
if (!$stmt->fetch()) { http_response_code(404); exit('song not found'); }

if (is_null($vote)) {
  $stmt = $pdo->prepare("DELETE FROM votes WHERE user_id = ? AND song_id = ?");
  $stmt->execute([$user['id'], $song_id]);
  sv_log($user['id'], 'vote_clear', "song_id=$song_id");
} else {
  $stmt = $pdo->prepare("INSERT INTO votes (user_id, song_id, vote) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote)");
  $stmt->execute([$user['id'], $song_id, $vote]);
  sv_log($user['id'], 'vote_set', "song_id=$song_id vote=$vote");
}

$stmt = $pdo->query("SELECT COUNT(*) AS total FROM songs WHERE is_active=1 AND deleted_at IS NULL");
$total = (int)$stmt->fetch()['total'];
$stmt = $pdo->prepare("SELECT COUNT(*) AS done FROM votes v JOIN songs s ON s.id=v.song_id WHERE v.user_id = ? AND s.is_active=1 AND s.deleted_at IS NULL");
$stmt->execute([$user['id']]);
$done = (int)$stmt->fetch()['done'];
$percent = $total ? (int)round(($done/$total)*100) : 0;

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'progress'=>['done'=>$done,'total'=>$total,'percent'=>$percent]]);
