<?php
require_once __DIR__ . '/../lib/auth.php';

$user = sv_require_login();

if (sv_is_frozen()) {
  http_response_code(423);
  exit('Abstimmung ist eingefroren');
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!$csrfHeader || !hash_equals(sv_csrf_token(), $csrfHeader)) {
  http_response_code(403);
  exit('Ungültiges CSRF-Token');
}

$body = json_decode(file_get_contents('php://input'), true);
$song_id = (int)($body['song_id'] ?? 0);
$note = trim((string)($body['note'] ?? ''));

if ($song_id <= 0) {
  http_response_code(400);
  exit('song_id missing');
}

$pdo = sv_pdo();

$stmt = $pdo->prepare("SELECT id FROM songs WHERE id = ? AND is_active = 1 AND deleted_at IS NULL");
$stmt->execute([$song_id]);
if (!$stmt->fetch()) {
  http_response_code(404);
  exit('song not found');
}

if ($note === '') {
  $stmt = $pdo->prepare("DELETE FROM vote_notes WHERE user_id = ? AND song_id = ?");
  $stmt->execute([$user['id'], $song_id]);
  sv_log($user['id'], 'note_clear', "song_id=$song_id");
} else {
  $stmt = $pdo->prepare("
    INSERT INTO vote_notes (user_id, song_id, note)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE note = VALUES(note)
  ");
  $stmt->execute([$user['id'], $song_id, $note]);
  sv_log($user['id'], 'note_set', "song_id=$song_id");
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true]);