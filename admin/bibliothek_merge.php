<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$admin     = sv_require_admin();
$pdo       = sv_pdo();
$base      = sv_base_url();
$accentRed = sv_setting_get('color_primary', '#c1090f');

// ── Merge ausführen ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $keepId   = (int)($_POST['keep_id']   ?? 0);
  $deleteId = (int)($_POST['delete_id'] ?? 0);

  if ($keepId <= 0 || $deleteId <= 0 || $keepId === $deleteId) {
    sv_flash_set('error', 'Ungültige Auswahl.');
    header('Location: ' . $base . '/admin/bibliothek_merge.php');
    exit;
  }

  // Felder aus dem zu löschenden Stück übernehmen wenn Keep-Felder leer sind
  $fields = ['youtube_url','composer','arranger','publisher','duration','genre',
             'difficulty','owner','has_scan','has_score_scan','has_original_score',
             'binder','shop_url','shop_price','info'];

  // Welche Felder soll der User übernehmen (checkboxes)?
  $overwrite = $_POST['overwrite'] ?? [];

  $keep   = $pdo->prepare("SELECT * FROM pieces WHERE id=?"); $keep->execute([$keepId]);   $keep   = $keep->fetch();
  $del    = $pdo->prepare("SELECT * FROM pieces WHERE id=?"); $del->execute([$deleteId]);  $del    = $del->fetch();

  if (!$keep || !$del) {
    sv_flash_set('error', 'Stück nicht gefunden.');
    header('Location: ' . $base . '/admin/bibliothek_merge.php');
    exit;
  }

  try {
    $pdo->beginTransaction();

    // 1. Zuerst: vote_history vom zu löschenden Stück auf Keep umhängen
    // Vote-History zusammenführen: für jeden Nutzer aus del-Eintrag, neuere Stimme gewinnt
    $delVotesH = $pdo->prepare("SELECT * FROM vote_history WHERE piece_id=?");
    $delVotesH->execute([$deleteId]);
    foreach ($delVotesH->fetchAll() as $dv) {
      $pdo->prepare("
        INSERT INTO vote_history (user_id, piece_id, vote, note, archived_at)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          vote        = IF(VALUES(archived_at) >= archived_at, VALUES(vote),        vote),
          note        = IF(VALUES(archived_at) >= archived_at, VALUES(note),        note),
          archived_at = IF(VALUES(archived_at) >= archived_at, VALUES(archived_at), archived_at)
      ")->execute([$dv['user_id'], $keepId, $dv['vote'], $dv['note'], $dv['archived_at']]);
    }

    // 2. Aktive Songs umhängen
    $pdo->prepare("UPDATE songs SET piece_id=? WHERE piece_id=?")->execute([$keepId, $deleteId]);

    // 3. Zu löschendes Stück JETZT löschen (vor dem Update, damit Unique-Key frei wird)
    $pdo->prepare("DELETE FROM pieces WHERE id=?")->execute([$deleteId]);

    // 4. Jetzt Keep-Eintrag updaten — nur was der User explizit übernehmen will
    $sets = [];
    $vals = [];
    foreach ($overwrite as $field) {
      if (in_array($field, $fields)) {
        $sets[] = "$field = ?";
        $vals[] = $del[$field];
      }
    }
    if ($sets) {
      $vals[] = $keepId;
      $pdo->prepare("UPDATE pieces SET " . implode(', ', $sets) . " WHERE id=?")->execute($vals);
    }
    // Sync: alle verknüpften Songs mit den finalen Keep-Daten aktualisieren
    $finalPiece = $pdo->prepare("SELECT * FROM pieces WHERE id=?"); $finalPiece->execute([$keepId]); $fp = $finalPiece->fetch();
    if ($fp) {
      $linkedSongs = $pdo->prepare("SELECT id FROM songs WHERE piece_id=?"); $linkedSongs->execute([$keepId]);
      foreach ($linkedSongs->fetchAll() as $ls) {
        $pdo->prepare("UPDATE songs SET title=?,youtube_url=?,composer=?,arranger=?,publisher=?,duration=?,genre=?,difficulty=?,shop_url=?,shop_price=?,info=? WHERE id=?")
          ->execute([$fp['title'],$fp['youtube_url'],$fp['composer'],$fp['arranger'],$fp['publisher'],$fp['duration'],$fp['genre'],$fp['difficulty'],$fp['shop_url'],$fp['shop_price'],$fp['info'],$ls['id']]);
      }
    }

    // (Song-Sync bereits oben erledigt)

    $pdo->commit();
    sv_log($admin['id'], 'pieces_merge', "keep=$keepId delete=$deleteId");
    sv_flash_set('success', '„'.$del['title'].'" wurde mit „'.$keep['title'].'" zusammengeführt.');
    header('Location: ' . $base . '/admin/bibliothek.php');
    exit;

  } catch (Throwable $e) {
    $pdo->rollBack();
    sv_flash_set('error', 'Fehler: ' . $e->getMessage());
    header('Location: ' . $base . '/admin/bibliothek_merge.php');
    exit;
  }
}

// ── Suche & Auswahl ───────────────────────────────────────────────────────────
$q       = trim($_GET['q'] ?? '');
$keepId  = (int)($_GET['keep']   ?? 0);
$delId   = (int)($_GET['delete'] ?? 0);

$results = [];
if ($q !== '') {
  $stmt = $pdo->prepare("SELECT * FROM pieces WHERE title LIKE ? OR composer LIKE ? OR arranger LIKE ? ORDER BY title ASC LIMIT 20");
  $stmt->execute(["%$q%","%$q%","%$q%"]);
  $results = $stmt->fetchAll();
}

$keepPiece = $delPiece = null;
if ($keepId) { $s=$pdo->prepare("SELECT * FROM pieces WHERE id=?"); $s->execute([$keepId]); $keepPiece=$s->fetch(); }
if ($delId)  { $s=$pdo->prepare("SELECT * FROM pieces WHERE id=?"); $s->execute([$delId]);  $delPiece=$s->fetch(); }

// Votes aus vote_history UND aktive votes für beide Stücke
function loadVoteHistory(PDO $pdo, int $pid): array {
  // Archivierte Stimmen
  $stmt = $pdo->prepare("
    SELECT vh.user_id, vh.vote, vh.note, vh.archived_at, u.display_name, u.username
    FROM vote_history vh
    JOIN users u ON u.id = vh.user_id
    WHERE vh.piece_id = ?
    ORDER BY vh.archived_at DESC
  ");
  $stmt->execute([$pid]);
  $archived = $stmt->fetchAll();

  // Aktive Stimmen (via songs → votes)
  $stmt2 = $pdo->prepare("
    SELECT v.user_id, v.vote, vn.note, v.updated_at AS archived_at, u.display_name, u.username
    FROM votes v
    JOIN songs s ON s.id = v.song_id
    JOIN users u ON u.id = v.user_id
    LEFT JOIN vote_notes vn ON vn.song_id = v.song_id AND vn.user_id = v.user_id
    WHERE s.piece_id = ?
    ORDER BY v.updated_at DESC
  ");
  $stmt2->execute([$pid]);
  $active = $stmt2->fetchAll();

  // Zusammenführen — aktive Stimmen haben Vorrang bei gleichem Nutzer
  $merged = [];
  foreach ($active as $v) { $merged[$v['user_id']] = $v; }
  foreach ($archived as $v) { if (!isset($merged[$v['user_id']])) $merged[$v['user_id']] = $v; }
  return array_values($merged);
}

$keepVotes = $keepPiece ? loadVoteHistory($pdo, $keepId) : [];
$delVotes  = $delPiece  ? loadVoteHistory($pdo, $delId)  : [];

function diffPill(mixed $d): string { return sv_diff_pill($d); }

sv_header('Admin – Stücke zusammenführen', $admin);
?>

<div class="page-header">
  <div>
    <h2>Stücke zusammenführen</h2>
    <div class="muted">Zwei Archiveinträge zu einem zusammenführen — Stimmen und Notizen werden übernommen.</div>
  </div>
  <a class="btn" href="<?=h($base)?>/admin/bibliothek.php">← Bibliothek</a>
</div>

<!-- Schritt 1: Suche -->
<div class="card" style="margin-bottom:12px">
  <h3>Schritt 1 — Stücke suchen</h3>
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
    <label style="flex:1;min-width:200px">Suche<br>
      <input class="input" name="q" value="<?=h($q)?>" placeholder="Titel, Komponist, Arrangeur…" style="width:100%;margin-top:5px">
    </label>
    <?php if($keepId): ?><input type="hidden" name="keep" value="<?=h($keepId)?>"> <?php endif; ?>
    <?php if($delId):  ?><input type="hidden" name="delete" value="<?=h($delId)?>"> <?php endif; ?>
    <button class="btn primary" type="submit">Suchen</button>
    <?php if($keepId || $delId): ?><a class="btn" href="?q=<?=urlencode($q)?>">Auswahl zurücksetzen</a><?php endif; ?>
  </form>

  <?php if ($results): ?>
  <div class="table-scroll" style="margin-top:12px">
    <table>
      <thead><tr><th>Titel</th><th>Arrangeur</th><th>Komponist</th><th>Aktion</th></tr></thead>
      <tbody>
        <?php foreach ($results as $r): ?>
        <tr>
          <td><strong><?=h($r['title'])?></strong></td>
          <td class="small"><?=h($r['arranger'] ?: '–')?></td>
          <td class="small"><?=h($r['composer'] ?: '–')?></td>
          <td>
            <div class="btn-group">
              <?php if ($keepId !== (int)$r['id']): ?>
              <a class="btn<?= $keepId===(int)$r['id']?' primary':'' ?>"
                 href="?q=<?=urlencode($q)?>&keep=<?=h($r['id'])?>&delete=<?=h($delId)?>">
                <?= $keepId===(int)$r['id'] ? '✓ Behalten' : 'Als Behalten wählen' ?>
              </a>
              <?php else: ?>
              <span class="badge" style="background:var(--green-light);color:var(--green);border-color:var(--green-mid)">✓ Behalten</span>
              <?php endif; ?>
              <?php if ($delId !== (int)$r['id']): ?>
              <a class="btn<?= $delId===(int)$r['id']?' danger':'' ?>"
                 href="?q=<?=urlencode($q)?>&keep=<?=h($keepId)?>&delete=<?=h($r['id'])?>">
                <?= $delId===(int)$r['id'] ? '✓ Löschen' : 'Als Löschen wählen' ?>
              </a>
              <?php else: ?>
              <span class="badge" style="background:var(--red-soft);color:var(--red);border-color:rgba(193,9,15,.3)">✗ Löschen</span>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php elseif ($q !== ''): ?>
  <div class="small" style="margin-top:12px;color:var(--muted)">Keine Treffer für „<?=h($q)?>".</div>
  <?php endif; ?>
</div>

<?php if ($keepPiece && $delPiece): ?>
<!-- Schritt 2: Vergleich & Merge -->
<div class="card">
  <h3>Schritt 2 — Vergleichen & zusammenführen</h3>
  <p class="small" style="margin-bottom:16px">
    Der <strong>Behalten</strong>-Eintrag bleibt erhalten. Bei abweichenden Feldern entscheidest du was übernommen wird.
    Toggles die vorausgewählt sind kannst du jederzeit deaktivieren.
  </p>

  <form method="post">
    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
    <input type="hidden" name="keep_id"   value="<?=h($keepId)?>">
    <input type="hidden" name="delete_id" value="<?=h($delId)?>">

    <!-- Feld-Vergleich -->
    <div class="table-scroll" style="margin-bottom:20px">
      <table>
        <thead>
          <tr>
            <th style="width:140px">Feld</th>
            <th>✓ Behalten: <em><?=h($keepPiece['title'])?></em></th>
            <th style="width:44px;text-align:center"></th>
            <th>✗ Wird gelöscht: <em><?=h($delPiece['title'])?></em></th>
            <th style="width:160px">Übernehmen?</th>
          </tr>
        </thead>
        <tbody>
        <?php
        $compareFields = [
          'title'       => 'Titel',
          'composer'    => 'Komponist',
          'arranger'    => 'Arrangeur',
          'publisher'   => 'Verlag',
          'duration'    => 'Dauer',
          'genre'       => 'Genre',
          'difficulty'  => 'Grad',
          'owner'       => 'Eigentümer',
          'youtube_url' => 'YouTube',
          'shop_url'    => 'Händler-Link',
          'info'        => 'Info-Text',
        ];
        foreach ($compareFields as $field => $label):
          $kVal = $keepPiece[$field] ?? '';
          $dVal = $delPiece[$field]  ?? '';
          $differs = $kVal !== $dVal;
          $kEmpty  = empty($kVal);
          $dEmpty  = empty($dVal);
          if ($kEmpty && $dEmpty) continue; // beide leer — überspringen
        ?>
        <tr style="<?= $differs && !$kEmpty && !$dEmpty ? 'background:#fffdf5' : '' ?>">
          <td class="small" style="font-weight:600"><?=h($label)?></td>
          <td class="small">
            <?php if ($field === 'difficulty'): echo diffPill($kVal);
            elseif ($field === 'youtube_url' && $kVal): echo '<a href="'.h($kVal).'" target="_blank" rel="noopener" class="song-link">▶ YouTube öffnen</a>';
            elseif ($field === 'shop_url' && $kVal): echo '<a href="'.h($kVal).'" target="_blank" rel="noopener" class="song-link">🛒 Händler</a>';
            else: echo h($kVal ?: '–'); endif; ?>
          </td>
          <td class="merge-arrow-cell" style="text-align:center;width:44px">
            <?php if ($differs && !$dEmpty && $field !== 'title'): ?>
            <span class="merge-center-arrow" style="font-size:28px;font-weight:900;line-height:1;color:<?= $kEmpty ? $accentRed : '#ddd' ?>">&#8592;</span>
            <?php else: ?><span style="color:#ddd">&#183;</span><?php endif; ?>
          </td>
          <td class="small">
            <?php if ($field === 'difficulty'): echo diffPill($dVal);
            elseif ($field === 'youtube_url' && $dVal): echo '<a href="'.h($dVal).'" target="_blank" rel="noopener" class="song-link">▶ YouTube öffnen</a>';
            elseif ($field === 'shop_url' && $dVal): echo '<a href="'.h($dVal).'" target="_blank" rel="noopener" class="song-link">🛒 Händler</a>';
            else: echo h($dVal ?: '–'); endif; ?>
          </td>
          <td>
            <?php if ($differs && !$dEmpty && $field !== 'title'): ?>
            <label class="toggle-wrap" style="margin:0">
              <input type="checkbox" name="overwrite[]" value="<?=h($field)?>"
                     <?= $kEmpty ? 'checked' : '' ?>
                     onchange="updateMergeLabel(this, <?= $kEmpty ? 'true' : 'false' ?>); updateArrow(this)">
              <span class="toggle-track"></span>
              <span class="toggle-label merge-lbl" style="font-size:12px"><?= $kEmpty ? 'Wird übernommen' : 'Nicht übernehmen' ?></span>
            </label>
            <?php elseif ($kEmpty && !$dEmpty && $field !== 'title'): ?>
            <span class="small" style="color:var(--green)">↑ wird automatisch gefüllt</span>
            <?php else: ?>
            <span class="small" style="color:var(--muted)">–</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <!-- Boolean-Felder direkt in derselben Tabelle -->
        <?php
        $toggleFields = [
          'has_scan'          => 'Stimmen eingescannt',
          'has_score_scan'    => 'Partitur eingescannt',
          'has_original_score'=> 'Originalpartitur',
          'binder'            => 'Mappe',
        ];
        foreach ($toggleFields as $field => $label):
          $kVal = !empty($keepPiece[$field]);
          $dVal = !empty($delPiece[$field]);
          $differs = $kVal !== $dVal;
          $kStr = $kVal ? '<span style="color:var(--green)">✓ ja</span>' : '<span style="color:#ccc">✗ nein</span>';
          $dStr = $dVal ? '<span style="color:var(--green)">✓ ja</span>' : '<span style="color:#ccc">✗ nein</span>';
        ?>
        <tr style="<?= ($differs && !$kVal && $dVal) ? 'background:#fffdf5' : '' ?>">
          <td class="small" style="font-weight:600"><?=h($label)?></td>
          <td class="small"><?=$kStr?></td>
          <td style="text-align:center;font-size:20px;color:<?= ($differs&&!$kVal&&$dVal)?$accentRed:'#ddd' ?>;white-space:nowrap"><?= ($differs&&!$kVal&&$dVal)?'←':'·' ?></td>
          <td class="small"><?=$dStr?></td>
          <td>
            <?php if ($differs && !$kVal && $dVal): ?>
            <label class="toggle-wrap" style="margin:0">
              <input type="checkbox" name="overwrite[]" value="<?=h($field)?>" checked
                     onchange="updateMergeLabel(this, true)">
              <span class="toggle-track"></span>
              <span class="toggle-label merge-lbl" style="font-size:12px">Wird übernommen</span>
            </label>
            <?php else: ?>
            <span class="small" style="color:var(--muted)">–</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <!-- Abstimmungsverlauf-Zeile -->
        <tr>
          <td class="small" style="font-weight:600">Abstimmungsverlauf</td>
          <td class="small"><span style="color:var(--muted)"><?=count($keepVotes)?> Stimme<?=count($keepVotes)!==1?'n':''?></span></td>
          <td style="text-align:center;color:#ddd">&#183;</td>
          <td class="small"><span style="color:var(--muted)"><?=count($delVotes)?> Stimme<?=count($delVotes)!==1?'n':''?></span></td>
          <td colspan="2"><span class="small" style="color:var(--muted)">Wird zusammengeführt — bei gleichem Nutzer gewinnt die neuere Stimme.</span></td>
        </tr>
        </tbody>
      </table>
    </div>



    <div style="padding:12px 14px;background:var(--red-soft);border:1.5px solid rgba(193,9,15,.3);border-radius:10px;margin-bottom:16px">
      <div style="font-weight:700;color:var(--red);margin-bottom:4px">⚠️ Zusammenführen ist unwiderruflich</div>
      <div class="small" style="color:var(--red)">„<?=h($delPiece['title'])?>"<?php if($delPiece['arranger']): ?> (Arr. <?=h($delPiece['arranger'])?>)<?php endif; ?> wird gelöscht.
      Stimmen aus beiden Einträgen werden im Behalten-Eintrag zusammengeführt — bei gleichem Nutzer gewinnt die neuere Stimme.</div>
    </div>

    <button class="btn" type="submit" style="background:var(--red);color:#fff;border-color:var(--red);font-weight:700"
            onclick="return confirm('Wirklich zusammenführen? Das kann nicht rückgängig gemacht werden.')">
      🔀 Jetzt zusammenführen
    </button>
  </form>
</div>
<?php elseif ($keepPiece || $delPiece): ?>
<div class="card">
  <div class="small" style="color:var(--muted)">
    <?php if (!$keepPiece): ?>Bitte noch das <strong>Behalten</strong>-Stück auswählen.
    <?php else: ?>Bitte noch das <strong>zu löschende</strong> Stück auswählen.
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>


<script>
function updateArrow(cb) {
  var row = cb.closest('tr');
  if (!row) return;
  var arrow = row.querySelector('.merge-center-arrow');
  if (!arrow) return;
  arrow.style.color = cb.checked ? '<?=h($accentRed)?>' : '#ddd';
}
function updateBoolArrow(cb) { updateArrow(cb); }
function updateMergeLabel(cb, wasEmpty) {
  var lbl = cb.parentElement.querySelector('.merge-lbl');
  if (!lbl) return;
  lbl.textContent = cb.checked ? 'Wird übernommen' : 'Nicht übernehmen';
}
</script>
<?php sv_footer(); ?>
