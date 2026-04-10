<?php
require_once __DIR__ . '/../lib/auth.php';

$user = sv_require_login();
if (!sv_can_edit_noten($user)) {
  http_response_code(403);
  echo json_encode(['error' => 'Keine Berechtigung.']);
  exit;
}

header('Content-Type: application/json; charset=utf-8');
sv_csrf_check();

$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';
$tagId  = (int)($body['tag_id'] ?? 0);

$pdo = sv_pdo();

if ($action === 'delete' && $tagId > 0) {
  // Prüfen ob Genre noch vergeben ist
  try {
    $stmtP = $pdo->prepare("SELECT COUNT(*) FROM piece_tags WHERE tag_id=?");
    $stmtP->execute([$tagId]);
    $countP = (int)$stmtP->fetchColumn();

    $stmtS = $pdo->prepare("SELECT COUNT(*) FROM song_tags WHERE tag_id=?");
    $stmtS->execute([$tagId]);
    $countS = (int)$stmtS->fetchColumn();

    $total = $countP + $countS;
    if ($total > 0) {
      echo json_encode(['error' => "Genre ist noch $total× vergeben (Bibliothek: $countP, Abstimmung: $countS). Bitte erst überall entfernen."]);
      exit;
    }

    // Genre löschen
    $pdo->prepare("DELETE FROM tags WHERE id=?")->execute([$tagId]);
    sv_log($user['id'], 'tag_delete', "tag_id=$tagId");
    echo json_encode(['ok' => true]);
  } catch (Throwable $e) {
    echo json_encode(['error' => 'Fehler: ' . $e->getMessage()]);
  }
} else {
  http_response_code(400);
  echo json_encode(['error' => 'Ungültige Aktion.']);
}
