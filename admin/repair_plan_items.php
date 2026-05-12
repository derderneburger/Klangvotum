<?php
// Einmaliges Reparatur-Skript:
// Findet Konzertplaner-Items, die noch auf alte Song-IDs zeigen (source='song'),
// aber der Song existiert nicht mehr — und mappt sie über das Audit-Log auf
// die neue Piece-ID um (source='piece').

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user = sv_require_login();
if (!sv_is_admin($user)) { http_response_code(403); exit('Nur Admins'); }
$pdo = sv_pdo();

// ── Audit-Log auslesen: song_id → piece_id Mapping (neuester Eintrag gewinnt)
$logRows = $pdo->query("SELECT details FROM audit_log WHERE action='song_to_archive' ORDER BY id ASC")->fetchAll();
$mapping = []; // song_id => piece_id
foreach ($logRows as $r) {
  if (preg_match('/song_id=(\d+)\s+piece_id=(\d+)/', $r['details'] ?? '', $m)) {
    $mapping[(int)$m[1]] = (int)$m[2];
  }
}

// ── Aktuelle kaputte Plan-Items finden
$broken = $pdo->query("
  SELECT cpi.id, cpi.plan_id, cpi.piece_id AS old_song_id, cpi.label,
         cp.name AS plan_name
  FROM concert_plan_items cpi
  LEFT JOIN songs s ON s.id = cpi.piece_id
  LEFT JOIN concert_plans cp ON cp.id = cpi.plan_id
  WHERE cpi.source = 'song' AND s.id IS NULL
  ORDER BY cpi.plan_id, cpi.position
")->fetchAll();

// ── Reparierbare Items mit Ziel-Piece anreichern
$repairable = [];
$unrepairable = [];
foreach ($broken as $b) {
  $oldSongId = (int)$b['old_song_id'];
  if (!isset($mapping[$oldSongId])) {
    $unrepairable[] = $b + ['reason' => 'Kein Audit-Log-Eintrag für song_id='.$oldSongId];
    continue;
  }
  $newPieceId = $mapping[$oldSongId];
  $p = $pdo->prepare("SELECT id, title FROM pieces WHERE id=?");
  $p->execute([$newPieceId]);
  $piece = $p->fetch();
  if (!$piece) {
    $unrepairable[] = $b + ['reason' => 'Ziel-Piece ID '.$newPieceId.' existiert nicht (mehr)'];
    continue;
  }
  $repairable[] = $b + ['new_piece_id' => $newPieceId, 'new_title' => $piece['title']];
}

// ── POST: Reparatur ausführen
$repaired = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  if (($_POST['action'] ?? '') === 'repair') {
    $upd = $pdo->prepare("UPDATE concert_plan_items SET source='piece', piece_id=? WHERE id=? AND source='song' AND piece_id=?");
    foreach ($repairable as $r) {
      $upd->execute([$r['new_piece_id'], $r['id'], $r['old_song_id']]);
      $repaired += $upd->rowCount();
      sv_log($user['id'], 'repair_plan_item', "item_id={$r['id']} old_song_id={$r['old_song_id']} new_piece_id={$r['new_piece_id']}");
    }
    sv_flash_set('success', $repaired.' Plan-Items repariert.');
    header('Location: repair_plan_items.php'); exit;
  }
}

sv_header('Konzertplaner reparieren', $user);
?>
<div class="container" style="max-width:900px;margin:0 auto;padding:20px">
  <h1>Konzertplaner: Kaputte Items reparieren</h1>
  <p class="small" style="color:var(--muted)">
    Wenn ein Titel aus der Abstimmung in die Bibliothek verschoben wurde, zeigt der Konzertplaner ihn als „—".
    Dieses Skript mappt solche Items über das Audit-Log auf die neue Piece-ID um.
  </p>

  <?php
    $fs = sv_flash_get('success'); $fe = sv_flash_get('error');
    if ($fs) echo '<div class="notice success" style="margin-bottom:12px">'.h($fs).'</div>';
    if ($fe) echo '<div class="notice error"   style="margin-bottom:12px">'.h($fe).'</div>';
  ?>

  <?php if (empty($broken)): ?>
    <div class="card" style="padding:20px;background:#e8f5e9;border:1px solid #2e7d32;border-radius:8px;color:#1b5e20">
      ✓ Alles sauber. Keine kaputten Plan-Items gefunden.
    </div>
  <?php else: ?>

    <?php if (!empty($repairable)): ?>
      <h2>Reparierbar (<?=count($repairable)?>)</h2>
      <table class="data" style="width:100%;margin-bottom:20px">
        <thead><tr>
          <th>Plan</th><th>alte Song-ID</th><th>→ neue Piece-ID</th><th>Titel</th>
        </tr></thead>
        <tbody>
          <?php foreach ($repairable as $r): ?>
            <tr>
              <td><?=h($r['plan_name'] ?? '?')?></td>
              <td><?=$r['old_song_id']?></td>
              <td><?=$r['new_piece_id']?></td>
              <td><?=h($r['new_title'])?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" onsubmit="return confirm('Wirklich <?=count($repairable)?> Items reparieren?')">
        <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
        <input type="hidden" name="action" value="repair">
        <button type="submit" class="btn primary"><?=count($repairable)?> Items jetzt reparieren</button>
      </form>
    <?php endif; ?>

    <?php if (!empty($unrepairable)): ?>
      <h2 style="margin-top:30px">Nicht reparierbar (<?=count($unrepairable)?>)</h2>
      <table class="data" style="width:100%">
        <thead><tr>
          <th>Plan</th><th>alte Song-ID</th><th>Label</th><th>Grund</th>
        </tr></thead>
        <tbody>
          <?php foreach ($unrepairable as $r): ?>
            <tr>
              <td><?=h($r['plan_name'] ?? '?')?></td>
              <td><?=$r['old_song_id']?></td>
              <td><?=h($r['label'] ?? '—')?></td>
              <td style="color:var(--muted)"><?=h($r['reason'])?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="small" style="color:var(--muted);margin-top:10px">
        Diese Items müssen manuell behandelt werden — entweder löschen oder den richtigen Titel neu auswählen.
      </p>
    <?php endif; ?>

  <?php endif; ?>

  <p style="margin-top:30px"><a href="index.php" class="btn">← Zurück zur Admin-Übersicht</a></p>
</div>
<?php sv_footer(); ?>
