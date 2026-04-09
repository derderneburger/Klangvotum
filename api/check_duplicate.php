<?php
// Live-Duplikatprüfung: Titel+Arrangeur gegen pieces UND songs prüfen
require_once __DIR__ . '/../lib/auth.php';

$user = sv_require_login();
$pdo  = sv_pdo();

$title    = trim($_GET['title'] ?? '');
$arranger = trim($_GET['arranger'] ?? '');
$excludeTable = $_GET['exclude_table'] ?? '';   // 'songs' oder 'pieces'
$excludeId    = (int)($_GET['exclude_id'] ?? 0); // eigene ID beim Bearbeiten ausschließen

if ($title === '') {
  header('Content-Type: application/json');
  echo '{"matches":[]}';
  exit;
}

$matches = [];

// In Bibliothek (pieces) suchen
$sql = "SELECT id, title, arranger, composer, 'pieces' AS source FROM pieces WHERE title = ?";
$params = [$title];
if ($excludeTable === 'pieces' && $excludeId) {
  $sql .= " AND id != ?";
  $params[] = $excludeId;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
foreach ($stmt->fetchAll() as $row) {
  $matches[] = [
    'source'   => 'Bibliothek',
    'title'    => $row['title'],
    'arranger' => $row['arranger'] ?: '',
    'composer' => $row['composer'] ?: '',
    'exact'    => (strtolower(trim($row['arranger'] ?? '')) === strtolower($arranger)),
  ];
}

// In Abstimmungstitel (songs) suchen
$sql = "SELECT id, title, arranger, composer, 'songs' AS source FROM songs WHERE title = ?";
$params = [$title];
if ($excludeTable === 'songs' && $excludeId) {
  $sql .= " AND id != ?";
  $params[] = $excludeId;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
foreach ($stmt->fetchAll() as $row) {
  $matches[] = [
    'source'   => 'Abstimmung',
    'title'    => $row['title'],
    'arranger' => $row['arranger'] ?: '',
    'composer' => $row['composer'] ?: '',
    'exact'    => (strtolower(trim($row['arranger'] ?? '')) === strtolower($arranger)),
  ];
}

header('Content-Type: application/json');
echo json_encode(['matches' => $matches], JSON_UNESCAPED_UNICODE);
