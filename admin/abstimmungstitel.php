<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user  = sv_require_login();
if (!sv_can_edit_noten($user)) { http_response_code(403); exit('Forbidden'); }
$pdo   = sv_pdo();
$base  = sv_base_url();
$isAdmin = sv_is_admin($user);
$canEdit = true; // Wer hier reinkommt, darf bearbeiten

function songFields(): array {
  return [
    'title'      => trim($_POST['title']      ?? ''),
    'youtube_url'=> trim($_POST['youtube_url']?? ''),
    'composer'   => trim($_POST['composer']   ?? ''),
    'arranger'   => trim($_POST['arranger']   ?? ''),
    'publisher'  => trim($_POST['publisher']  ?? ''),
    'duration'   => sv_normalize_duration(trim($_POST['duration'] ?? '')),
    'difficulty' => (isset($_POST['difficulty']) && $_POST['difficulty'] !== '') ? (float)str_replace(',','.',$_POST['difficulty']) : null,
    'shop_url'   => trim($_POST['shop_url']   ?? ''),
    'shop_price' => (isset($_POST['shop_price']) && $_POST['shop_price'] !== '') ? (float)str_replace(',','.',$_POST['shop_price']) : null,
    'info'       => trim($_POST['info']       ?? ''),
    '_tags'      => array_filter(array_map('trim', $_POST['tags'] ?? [])),
  ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $f = songFields();
    $ytOptional = !empty($_POST['yt_optional']);
    // Duplikatprüfung: gleicher Titel+Arrangeur in songs oder pieces?
    $dupSong  = $pdo->prepare("SELECT id FROM songs  WHERE title=? AND LOWER(TRIM(COALESCE(arranger,'')))=LOWER(TRIM(?))");
    $dupSong->execute([$f['title'], $f['arranger']]); $dupInSongs = $dupSong->fetch();
    $dupPiece = $pdo->prepare("SELECT id FROM pieces WHERE title=? AND LOWER(TRIM(COALESCE(arranger,'')))=LOWER(TRIM(?))");
    $dupPiece->execute([$f['title'], $f['arranger']]); $dupInPieces = $dupPiece->fetch();
    if ($f['title'] === '') { sv_flash_set('error', 'Titel ist Pflichtfeld.'); }
    elseif ($dupInSongs || $dupInPieces) { sv_flash_set('error', 'Ein Titel mit diesem Namen und Arrangeur existiert bereits' . ($dupInPieces ? ' in der Bibliothek.' : ' in der Abstimmung.')); }
    elseif (!$ytOptional && $f['youtube_url'] === '') { sv_flash_set('error', 'YouTube URL ist Pflichtfeld.'); }
    else {
      $stmt = $pdo->prepare("INSERT INTO songs (title,youtube_url,composer,arranger,publisher,duration,difficulty,shop_url,shop_price,info) VALUES (?,?,?,?,?,?,?,?,?,?)");
      $stmt->execute([$f['title'],$f['youtube_url'],$f['composer'],$f['arranger'],$f['publisher'],$f['duration'],$f['difficulty'],$f['shop_url'],$f['shop_price'],$f['info']]);
      $newSid = (int)$pdo->lastInsertId();
      sv_sync_tags('song', $newSid, $f['_tags']);
      sv_log($user['id'], 'song_create', $f['title']);
      sv_flash_set('success', 'Titel angelegt.');
    }

  } elseif ($action === 'update') {
    $sid = (int)($_POST['sid'] ?? 0);
    $f   = songFields();
    $ytOptional = !empty($_POST['yt_optional']);
    // Duplikatprüfung (eigene ID + verknüpftes Piece ausschließen)
    $dupSong  = $pdo->prepare("SELECT id FROM songs  WHERE title=? AND LOWER(TRIM(COALESCE(arranger,'')))=LOWER(TRIM(?)) AND id!=?");
    $dupSong->execute([$f['title'], $f['arranger'], $sid]); $dupInSongs = $dupSong->fetch();
    $lpStmt = $pdo->prepare("SELECT piece_id FROM songs WHERE id=?"); $lpStmt->execute([$sid]);
    $linkedPieceId = (int)($lpStmt->fetchColumn() ?: 0);
    $dupPiece = $pdo->prepare("SELECT id FROM pieces WHERE title=? AND LOWER(TRIM(COALESCE(arranger,'')))=LOWER(TRIM(?)) AND id!=?");
    $dupPiece->execute([$f['title'], $f['arranger'], $linkedPieceId ?: 0]); $dupInPieces = $dupPiece->fetch();
    if ($sid <= 0 || $f['title'] === '') { sv_flash_set('error', 'Titel ist Pflichtfeld.'); }
    elseif ($dupInSongs || $dupInPieces) { sv_flash_set('error', 'Ein Titel mit diesem Namen und Arrangeur existiert bereits' . ($dupInPieces ? ' in der Bibliothek.' : ' in der Abstimmung.')); }
    elseif (!$ytOptional && $f['youtube_url'] === '') { sv_flash_set('error', 'YouTube URL ist Pflichtfeld.'); }
    else {
      $stmt = $pdo->prepare("UPDATE songs SET title=?,youtube_url=?,composer=?,arranger=?,publisher=?,duration=?,difficulty=?,shop_url=?,shop_price=?,info=? WHERE id=?");
      $stmt->execute([$f['title'],$f['youtube_url'],$f['composer'],$f['arranger'],$f['publisher'],$f['duration'],$f['difficulty'],$f['shop_url'],$f['shop_price'],$f['info'],$sid]);
      sv_sync_tags('song', $sid, $f['_tags']);
      // Sync zu verknuepftem Archiveintrag
      $linkedP = $pdo->prepare("SELECT piece_id FROM songs WHERE id=?"); $linkedP->execute([$sid]);
      $linkedPid = (int)($linkedP->fetchColumn() ?: 0);
      if ($linkedPid) {
        $pdo->prepare("UPDATE pieces SET title=?,youtube_url=?,composer=?,arranger=?,publisher=?,duration=?,difficulty=?,shop_url=?,shop_price=?,info=? WHERE id=?")
          ->execute([$f['title'],$f['youtube_url'],$f['composer'],$f['arranger'],$f['publisher'],$f['duration'],$f['difficulty'],$f['shop_url'],$f['shop_price'],$f['info'],$linkedPid]);
        sv_sync_tags('piece', $linkedPid, $f['_tags']);
      }
      sv_log($user['id'], 'song_update', "song_id=$sid".($linkedPid?" sync_piece=$linkedPid":''));
      sv_flash_set('success', 'Titel aktualisiert.'.($linkedPid?' (Archiv synchronisiert)':''));
    }

  } elseif ($action === 'toggle_active') {
    $sid = (int)($_POST['sid'] ?? 0);
    $pdo->prepare("UPDATE songs SET is_active = 1 - is_active WHERE id=?")->execute([$sid]);
    sv_log($user['id'], 'song_toggle', "song_id=$sid");
    sv_flash_set('success', 'Status geändert.');

  } elseif ($action === 'to_archive') {
    // Reset orphaned piece_id if piece was deleted
    if (isset($_POST['reset_piece_id'])) {
      $sid = (int)($_POST['sid'] ?? 0);
      if ($sid) $pdo->prepare("UPDATE songs SET piece_id=NULL WHERE id=?")->execute([$sid]);
    }
    $sid       = (int)($_POST['sid'] ?? 0);
    $forceLink = !empty($_POST['force_link']);
    $linkPid   = (int)($_POST['link_piece_id'] ?? 0); // Variante C: User wählt konkreten Archiveintrag
    $mergeFields = !empty($_POST['merge_fields']); // Felder aus Song übernehmen?
    if ($sid > 0) {
      $s = $pdo->prepare("SELECT * FROM songs WHERE id=?");
      $s->execute([$sid]);
      $s = $s->fetch();
      if ($s && $s['piece_id']) {
        sv_flash_set('error', '„'.$s['title'].'" ist bereits mit einem Archiveintrag verknüpft.');
      } elseif ($s) {
        // Alle Archiveinträge mit gleichem Titel suchen (für Typo-Erkennung)
        $allSameTitleStmt = $pdo->prepare("SELECT id, composer, arranger, duration, publisher FROM pieces WHERE title=?");
        $allSameTitleStmt->execute([$s['title']]);
        $allSameTitle = $allSameTitleStmt->fetchAll();

        // Exakter Match: Titel + Arrangeur
        $existingPiece = null;
        foreach ($allSameTitle as $p) {
          if (($p['arranger'] ?? '') === ($s['arranger'] ?? '')) {
            $existingPiece = $p;
            break;
          }
        }

        if (($existingPiece || count($allSameTitle) > 0) && !$forceLink && !$linkPid) {
          // Session: Song-Daten für den Vergleichs-Dialog speichern
          $_SESSION['archive_conflict'] = [
            'sid'         => $sid,
            'song'        => $s,
            'matches'     => $allSameTitle,
            'exact_match' => $existingPiece,
          ];
          sv_flash_set('error', '__archive_conflict__');
        } elseif ($linkPid) {
          // User hat explizit einen Archiveintrag gewählt
          $existingPiece = $pdo->prepare("SELECT * FROM pieces WHERE id=?")->execute([$linkPid]) ? null : null;
          $pStmt = $pdo->prepare("SELECT * FROM pieces WHERE id=?");
          $pStmt->execute([$linkPid]);
          $existingPiece = $pStmt->fetch();
          if ($existingPiece) {
            $pieceId = $linkPid;
            // Felder aus Song übernehmen wenn gewünscht
            if ($mergeFields) {
              $pdo->prepare("UPDATE pieces SET
                youtube_url=COALESCE(NULLIF(?,  ''), youtube_url),
                composer   =COALESCE(NULLIF(?,  ''), composer),
                arranger   =COALESCE(NULLIF(?,  ''), arranger),
                publisher  =COALESCE(NULLIF(?,  ''), publisher),
                duration   =COALESCE(NULLIF(?,  ''), duration),
                difficulty =COALESCE(?,              difficulty),
                shop_url   =COALESCE(NULLIF(?,  ''), shop_url),
                shop_price =COALESCE(?,              shop_price),
                info       =COALESCE(NULLIF(?,  ''), info)
                WHERE id=?")->execute([
                  $s['youtube_url'],$s['composer'],$s['arranger'],
                  $s['publisher'],$s['duration'],
                  $s['difficulty'],$s['shop_url'],$s['shop_price'],$s['info'],
                  $pieceId
              ]);
              // Tags vom Song zum Piece übertragen (nur wenn Piece keine Tags hat)
              $existingPieceTags = sv_tags_for_piece($pieceId);
              if (empty($existingPieceTags)) {
                $songTags = sv_tags_for_song($sid);
                if ($songTags) sv_sync_tags('piece', $pieceId, $songTags);
              }
            }
            try {
              $pdo->prepare("INSERT INTO vote_history (user_id, piece_id, vote, note, archived_at)
                SELECT v.user_id, ?, v.vote, vn.note, NOW()
                FROM votes v
                LEFT JOIN vote_notes vn ON vn.song_id = v.song_id AND vn.user_id = v.user_id
                WHERE v.song_id = ?
                ON DUPLICATE KEY UPDATE vote=VALUES(vote), note=VALUES(note), archived_at=NOW()
              ")->execute([$pieceId, $sid]);
              $pdo->prepare("DELETE FROM songs WHERE id=?")->execute([$sid]);
              sv_log($user['id'], 'song_to_archive', "song_id=$sid piece_id=$pieceId linked");
              sv_flash_set('success', '„'.$s['title'].'" mit Archiv verknüpft und aus Abstimmung entfernt.');
            } catch (Throwable $e) {
              sv_flash_set('error', 'Fehler: '.$e->getMessage());
            }
          }
        } else {
          try {
            if ($existingPiece) {
              $pieceId = (int)$existingPiece['id'];
            } else {
              $stmt = $pdo->prepare("INSERT INTO pieces (title,youtube_url,composer,arranger,publisher,duration,difficulty,shop_url,shop_price,info) VALUES (?,?,?,?,?,?,?,?,?,?)");
              $stmt->execute([$s['title'],$s['youtube_url'],$s['composer'],$s['arranger'],$s['publisher'],$s['duration'],$s['difficulty'],$s['shop_url'],$s['shop_price'],$s['info']]);
              $pieceId = (int)$pdo->lastInsertId();
              // Tags vom Song zum neuen Piece kopieren
              $songTags = sv_tags_for_song($sid);
              if ($songTags) sv_sync_tags('piece', $pieceId, $songTags);
            }
            if ($pieceId) {
              // Votes + Notizen in vote_history sichern (historischer Hinweis)
              $pdo->prepare("
                INSERT INTO vote_history (user_id, piece_id, vote, note, archived_at)
                SELECT v.user_id, ?, v.vote, vn.note, NOW()
                FROM votes v
                LEFT JOIN vote_notes vn ON vn.song_id = v.song_id AND vn.user_id = v.user_id
                WHERE v.song_id = ?
                ON DUPLICATE KEY UPDATE vote=VALUES(vote), note=VALUES(note), archived_at=NOW()
              ")->execute([$pieceId, $sid]);
              // Song löschen (votes/notes werden durch DB-Cascade oder einfach obsolet)
              $pdo->prepare("DELETE FROM songs WHERE id=?")->execute([$sid]);
              sv_log($user['id'], 'song_to_archive', "song_id=$sid piece_id=$pieceId");
              $verb = $existingPiece ? 'mit Archiv verknüpft' : 'ins Archiv übertragen';
              sv_flash_set('success', '„'.$s['title'].'" '.$verb.' und aus Abstimmung entfernt.');
            }
          } catch (Throwable $e) {
            sv_flash_set('error', 'Fehler: '.$e->getMessage());
          }
        }
      }
    }

  } elseif ($action === 'delete') {
    $sid = (int)($_POST['sid'] ?? 0);
    if ($isAdmin) {
      // Admin: Hard-Delete mit Vote-Archivierung
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
      sv_log($user['id'], 'song_delete', "song_id=$sid");
      sv_flash_set('success', 'Titel endgültig gelöscht.');
    } else {
      // Noten-Rolle: Soft-Delete mit Grund
      $reason = trim($_POST['delete_reason'] ?? '');
      if ($reason === '') {
        sv_flash_set('error', 'Bitte einen Grund für die Löschung angeben.');
      } else {
        $pdo->prepare("UPDATE songs SET deleted_at=NOW(), deleted_by=?, delete_reason=? WHERE id=?")->execute([$user['id'], $reason, $sid]);
        sv_log($user['id'], 'song_soft_delete', "song_id=$sid reason=$reason");
        sv_flash_set('success', 'Titel zur Löschung vorgemerkt — Admin prüft.');
      }
    }

  }

  $redirectParams = array_filter([
    'q'    => $_POST['_q']    ?? '',
    'sort' => $_POST['_sort'] ?? '',
    'dir'  => $_POST['_dir']  ?? '',
  ]);
  $redirectSuffix = $redirectParams ? '?' . http_build_query($redirectParams) : '';
  header('Location: '.$base.'/admin/abstimmungstitel.php'.$redirectSuffix);
  exit;
}

// ── Filter & Sortierung ───────────────────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$fActive = $_GET['active']      ?? '';
$sortBy  = in_array($_GET['sort']??'', ['title','composer','arranger','tags','difficulty','duration','status']) ? ($_GET['sort']??'title') : 'title';
$sortDir = ($_GET['dir']  ?? '') === 'desc' ? 'DESC' : 'ASC';

$conds  = [];
$params = [];
if (!$isAdmin) {
  $conds[] = "s.deleted_at IS NULL";
}
if ($search !== '') {
  $conds[] = "(s.title LIKE ? OR s.composer LIKE ? OR s.arranger LIKE ? OR EXISTS (SELECT 1 FROM song_tags st JOIN tags t ON t.id=st.tag_id WHERE st.song_id=s.id AND t.name LIKE ?))";
  $params  = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
}
if ($fActive === '1') { $conds[] = "s.is_active = 1"; }
if ($fActive === '0') { $conds[] = "s.is_active = 0"; }

$where   = $conds ? "WHERE ".implode(" AND ", $conds) : "";
if ($sortBy==='difficulty') { $orderBy="s.difficulty IS NULL, s.difficulty $sortDir, s.title ASC"; }
elseif ($sortBy==='composer') { $orderBy="s.composer IS NULL OR s.composer='', s.composer $sortDir, s.title ASC"; }
elseif ($sortBy==='arranger') { $orderBy="s.arranger IS NULL OR s.arranger='', s.arranger $sortDir, s.title ASC"; }
elseif ($sortBy==='tags')     { $orderBy="(SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM song_tags st JOIN tags t ON t.id=st.tag_id WHERE st.song_id=s.id) IS NULL, (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM song_tags st JOIN tags t ON t.id=st.tag_id WHERE st.song_id=s.id) $sortDir, s.title ASC"; }
elseif ($sortBy==='duration') { $orderBy="(CASE WHEN s.duration IS NULL OR s.duration='' THEN 99999 WHEN s.duration REGEXP '^[0-9]+[:][0-9]+' THEN CAST(SUBSTRING_INDEX(s.duration,':',1) AS UNSIGNED)*60+CAST(SUBSTRING_INDEX(s.duration,':',-1) AS UNSIGNED) ELSE CAST(s.duration AS UNSIGNED)*60 END) $sortDir, s.title ASC"; }
elseif ($sortBy==='status')   { $orderBy="s.is_active $sortDir, s.title ASC"; }
else { $orderBy="s.title $sortDir"; }

$stmt = $pdo->prepare("SELECT s.*, p.title AS piece_title FROM songs s LEFT JOIN pieces p ON p.id=s.piece_id $where ORDER BY $orderBy");
// Hinweis: s.genre ist noch in der DB, wird aber nicht mehr genutzt — Tags kommen aus song_tags
$stmt->execute($params);
$songs = $stmt->fetchAll();

// Tags vorladen
$songIds = array_column($songs, 'id');
$tagsBySong = sv_tags_for_songs($songIds);
$allTagsForForm = sv_all_tags();

sv_header('Abstimmungstitel', $user);

function diffPill(mixed $d): string { return sv_diff_pill($d); }

function songFormFields(array $s, array $selectedTags = []): string {
  global $allTagsForForm;
  $v = fn(string $k) => htmlspecialchars((string)($s[$k]??''), ENT_QUOTES, 'UTF-8');
  ob_start(); ?>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <label style="grid-column:1/-1">Titel *<br><input name="title" required value="<?=$v('title')?>" style="width:100%;margin-top:5px" class="dup-title"></label>
    <div class="dup-hint" style="grid-column:1/-1;display:none;background:#fff8e1;border:1px solid rgba(184,134,11,.3);border-radius:8px;padding:8px 12px;font-size:12px;color:#b8860b"></div>
    <div style="grid-column:1/-1;display:flex;gap:8px">
      <button type="button" class="btn" style="flex:1;justify-content:center" onclick="openSearchWindow(this, 'google')">🌐 Google-Suche</button>
      <button type="button" class="btn" style="flex:1;justify-content:center" onclick="openSearchWindow(this, 'youtube')">▶ YouTube-Suche</button>
    </div>
    <label style="grid-column:1/-1">
      <span style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px">
        <span class="yt-label">YouTube URL *</span>
        <button type="button" class="btn" style="font-size:11px;padding:2px 8px" onclick="toggleYtRequired(this)">Pflicht aufheben</button>
      </span>
      <input class="yt-input" name="youtube_url" required value="<?=$v('youtube_url')?>" placeholder="https://youtu.be/…" style="width:100%">
    </label>
    <label>Komponist<br><input name="composer" value="<?=$v('composer')?>" style="width:100%;margin-top:5px"></label>
    <label>Arrangeur<br><input name="arranger" value="<?=$v('arranger')?>" style="width:100%;margin-top:5px" class="dup-arranger"></label>
    <label style="grid-column:1/-1">Verlag<br><input name="publisher" value="<?=$v('publisher')?>" style="width:100%;margin-top:5px"></label>
    <label>Länge<br><input name="duration" value="<?=$v('duration')?>" placeholder="z.B. 6:30" style="width:100%;margin-top:5px"></label>
    <label>Grad (1.0–6.0)<br><input name="difficulty" type="number" step="0.1" min="1" max="6" value="<?=$v('difficulty')?>" style="width:100%;margin-top:5px"></label>
    <div style="grid-column:1/-1"><?= sv_tag_widget($allTagsForForm, $selectedTags) ?></div>
    <label>Preis (€)<br><input name="shop_price" type="number" step="0.01" min="0" value="<?=$v('shop_price')?>" style="width:100%;margin-top:5px"></label>
    <label style="grid-column:1/-1">Link zum Händler<br><input name="shop_url" value="<?=$v('shop_url')?>" placeholder="https://…" style="width:100%;margin-top:5px"></label>
    <label style="grid-column:1/-1">Info-Text<br><textarea name="info" rows="2" style="width:100%;margin-top:5px"><?=$v('info')?></textarea></label>
  </div>
  <?php return ob_get_clean();
}
?>

<div class="page-header">
  <div>
    <h2>Titel</h2>
    <div class="muted">Aktive Titel erscheinen in der Abstimmung.</div>
  </div>
  <div class="row">
    <a class="btn primary" href="#" data-open-dialog="dialog-new-song">+ Neuer Titel</a>
    <?php if ($isAdmin): ?>
    <a class="btn" href="<?=h($base)?>/admin/abstimmungstitel_import.php">📥 Import / Export</a>
    <?php endif; ?>
    <a class="btn" href="<?=h($base)?>/admin/bibliothek.php">📚 Bibliothek</a>
    <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
  </div>
</div>

<?php
$flashError      = sv_flash_get('error');
$archiveConflict = $_SESSION['archive_conflict'] ?? null;
unset($_SESSION['archive_conflict']);
if ($flashError && $flashError !== '__archive_conflict__') {
  echo '<div class="notice error" style="margin-bottom:12px">'.h($flashError).'</div>';
}
?>

<?php if ($archiveConflict):
  $acSong = $archiveConflict['song'];
  $acSid  = $archiveConflict['sid'];
  $acCompareFields = [
    'arranger'  => 'Arrangeur',
    'composer'  => 'Komponist',
    'duration'  => 'Dauer',
    'publisher' => 'Verlag',
    'difficulty'=> 'Grad',
  ];
  $acExactMatch = false;
  foreach ($archiveConflict['matches'] as $m) {
    if (strtolower(trim($m['arranger'] ?? '')) === strtolower(trim($acSong['arranger'] ?? ''))) {
      $acExactMatch = true; break;
    }
  }
?>
<dialog id="dialog-archive-conflict" class="sv-dialog" style="max-width:800px;width:95vw">
  <div class="sv-dialog__panel" style="max-height:85vh;display:flex;flex-direction:column">
    <div class="sv-dialog__head">
      <div>
        <div class="sv-dialog__title">Archiv-Konflikt: „<?=h($acSong['title'])?>"</div>
        <div class="sv-dialog__sub"><?=count($archiveConflict['matches'])?> bestehende<?=count($archiveConflict['matches'])>1?'r Einträge':'r Eintrag'?> im Archiv mit diesem Titel</div>
      </div>
      <button class="sv-dialog__close" type="button" data-close-dialog>✕</button>
    </div>
    <div class="sv-dialog__section" style="overflow-y:auto;flex:1">

      <p class="small" style="margin-bottom:14px">
        Vergleiche den Abstimmungstitel mit den bestehenden Archiveinträgen.
        Bei abweichenden Feldern kannst du entscheiden, ob die Abstimmungsdaten übernommen werden sollen.
      </p>

      <?php foreach ($archiveConflict['matches'] as $mi => $m):
        $isExact = strtolower(trim($m['arranger'] ?? '')) === strtolower(trim($acSong['arranger'] ?? ''));
      ?>
      <form method="post" style="margin-bottom:16px">
        <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
        <input type="hidden" name="action" value="to_archive">
        <input type="hidden" name="sid" value="<?=h($acSid)?>">
        <input type="hidden" name="link_piece_id" value="<?=h($m['id'])?>">

        <?php if ($isExact): ?>
        <div style="background:var(--green-light);border:1px solid var(--green-mid);border-radius:8px;padding:8px 12px;margin-bottom:10px;font-size:12px;color:var(--green);font-weight:600">
          Exakter Treffer — Titel und Arrangeur stimmen überein
        </div>
        <?php endif; ?>

        <div class="table-scroll" style="margin-bottom:10px">
          <table>
            <thead>
              <tr>
                <th style="width:100px">Feld</th>
                <th style="background:var(--green-light);color:var(--green)">Archiv (bleibt)</th>
                <th style="width:44px;text-align:center"></th>
                <th>Abstimmungstitel</th>
                <th style="width:140px">Übernehmen?</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($acCompareFields as $field => $label):
              $aVal = $m[$field] ?? '';
              $sVal = $acSong[$field] ?? '';
              $differs = (string)$aVal !== (string)$sVal;
              $aEmpty = empty($aVal);
              $sEmpty = empty($sVal);
              if ($aEmpty && $sEmpty) continue;
            ?>
              <tr style="<?= ($differs && !$aEmpty && !$sEmpty) ? 'background:#fffdf5' : '' ?>">
                <td class="small" style="font-weight:600"><?=h($label)?></td>
                <td class="small"><?=h($aVal ?: '–')?></td>
                <td style="text-align:center">
                  <?php if ($differs && !$sEmpty): ?>
                    <span style="font-size:22px;font-weight:900;color:<?= $aEmpty ? 'var(--red)' : '#ddd' ?>">←</span>
                  <?php else: ?>
                    <span style="color:#ddd">·</span>
                  <?php endif; ?>
                </td>
                <td class="small">
                  <?=h($sVal ?: '–')?>
                  <?php if ($differs && !$sEmpty && !$aEmpty): ?>
                    <span class="badge" style="background:#fff8e1;color:#b8860b;border-color:rgba(184,134,11,.3);font-size:10px">abweichend</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($differs && !$sEmpty && $field !== 'title'): ?>
                    <label class="toggle-wrap" style="margin:0">
                      <input type="checkbox" name="overwrite_fields[]" value="<?=h($field)?>"
                             <?= $aEmpty ? 'checked' : '' ?>>
                      <span class="toggle-track"></span>
                      <span class="toggle-label" style="font-size:11px"><?= $aEmpty ? 'Ja' : 'Nein' ?></span>
                    </label>
                  <?php elseif ($aEmpty && !$sEmpty): ?>
                    <span class="small" style="color:var(--green)">auto</span>
                  <?php else: ?>
                    <span class="small" style="color:var(--muted)">–</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div style="display:flex;align-items:center;gap:10px;justify-content:flex-end">
          <button class="btn primary" type="submit">Mit diesem Eintrag verknüpfen</button>
        </div>
      </form>
      <?php endforeach; ?>

      <?php if (!$acExactMatch): ?>
      <div style="border-top:1px solid var(--border);padding-top:14px;margin-top:4px">
        <div class="small" style="margin-bottom:8px;color:var(--muted)">Keiner passt — als neuen Archiveintrag anlegen:</div>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
          <input type="hidden" name="action" value="to_archive">
          <input type="hidden" name="sid" value="<?=h($acSid)?>">
          <input type="hidden" name="force_link" value="1">
          <button class="btn" type="submit">+ Neu ins Archiv</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
</dialog>
<script>document.addEventListener('DOMContentLoaded',function(){document.getElementById('dialog-archive-conflict').showModal();});</script>
<?php endif; ?>

<!-- Dialog: Neuen Titel hinzufügen -->
<dialog id="dialog-new-song" class="sv-dialog">
  <div class="sv-dialog__panel" tabindex="-1">
    <div class="sv-dialog__head">
      <div class="sv-dialog__title">Neuen Titel hinzufügen</div>
      <button class="sv-dialog__close" type="button" data-close-dialog aria-label="Schließen">✕</button>
    </div>
    <div class="sv-dialog__section">
      <form method="post" class="grid" style="gap:12px">
        <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
        <input type="hidden" name="action" value="create">
        <?= songFormFields([], []) ?>
        <div class="row" style="gap:10px">
          <button class="btn primary" type="submit">Hinzufügen</button>
          <button class="btn" type="button" data-close-dialog>Abbrechen</button>
        </div>
      </form>
    </div>
  </div>
</dialog>

<!-- Suche & Filter -->
<div class="card" style="margin-bottom:12px">
  <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <label style="flex:1;min-width:200px">Suche<br>
      <input class="input" type="text" name="q" id="song-live-q" value="<?=h($search)?>"
             placeholder="Titel, Komponist, Arrangeur, Genre…" autocomplete="off" style="width:100%;margin-top:5px">
    </label>
    <span class="small" id="song-count" style="color:var(--muted);white-space:nowrap;margin-top:22px"><?=count($songs)?> Titel</span>
  </form>
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
    <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Spalten</span>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="s-col-composer" onchange="sCol('composer',this.checked);sCol('arranger',this.checked)" checked> Komponist / Arrangeur</label>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="s-col-genre"      onchange="sCol('genre',this.checked)"      checked> Genre</label>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="s-col-difficulty" onchange="sCol('difficulty',this.checked)" checked> Grad</label>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="s-col-duration"   onchange="sCol('duration',this.checked)"   checked> Länge</label>
  </div>
</div>

<!-- Liste -->
<div class="card">
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <?php
          function sSortTh(string $col, string $label, string $cur, string $dir, string $cls=''): string {
            $active=$cur===$col; $nd=($active&&$dir==='ASC')?'desc':'asc';
            $icon=$active?($dir==='ASC'?' ↑':' ↓'):'';
            $qs=http_build_query(array_filter(['q'=>$_GET['q']??'','sort'=>$col,'dir'=>$nd]));
            $st=($active?'color:var(--red);':'').'cursor:pointer;white-space:nowrap;user-select:none'.($col==='title'?';min-width:180px':'');
            $cc=$cls?' class="'.$cls.'"':'';
            return '<th'.$cc.' style="'.$st.'"><a href="?'.$qs.'" style="text-decoration:none;color:inherit">'.$label.$icon.'</a></th>';
          }
          echo sSortTh('title',      'Titel',         $sortBy,$sortDir);
          // Kombinierter Kopf
          $icK2 = $sortBy==='composer' ? ($sortDir==='ASC'?' ↑':' ↓') : '';
          $icA2 = $sortBy==='arranger' ? ($sortDir==='ASC'?' ↑':' ↓') : '';
          $ndK2 = ($sortBy==='composer'&&$sortDir==='ASC')?'desc':'asc';
          $ndA2 = ($sortBy==='arranger'&&$sortDir==='ASC')?'desc':'asc';
          $qK2  = http_build_query(array_filter(['q'=>$_GET['q']??'','sort'=>'composer','dir'=>$ndK2]));
          $qA2  = http_build_query(array_filter(['q'=>$_GET['q']??'','sort'=>'arranger','dir'=>$ndA2]));
          $stK2 = ($sortBy==='composer'?'color:var(--red)':'color:inherit').';cursor:pointer;user-select:none;display:block';
          $stA2 = ($sortBy==='arranger'?'color:var(--red)':'color:var(--muted)').';cursor:pointer;user-select:none;display:block;font-size:12px';
          echo '<th class="s-col s-col-composer s-col-arranger" style="white-space:nowrap"><a href="?'.$qK2.'" style="text-decoration:none;'.$stK2.'">Komponist'.$icK2.'</a><a href="?'.$qA2.'" style="text-decoration:none;'.$stA2.'">Arrangeur'.$icA2.'</a></th>';
          echo sSortTh('tags',       'Genre',          $sortBy,$sortDir,'s-col s-col-genre');
          echo sSortTh('difficulty', 'Grad', $sortBy,$sortDir,'s-col s-col-difficulty');
          echo sSortTh('duration',   'Länge',         $sortBy,$sortDir,'s-col s-col-duration');
          echo sSortTh('status',     'Status',        $sortBy,$sortDir);
          ?>
          <th style="width:44px"></th>
        </tr>
      </thead>
      <tbody id="song-tbody">
      <?php foreach ($songs as $s): ?>
        <?php $sIsDeleted = !empty($s['deleted_at']); ?>
        <tr data-search="<?=h(strtolower($s['title'].' '.($s['composer']??'').' '.($s['arranger']??'').' '.implode(' ', $tagsBySong[(int)$s['id']] ?? [])))?>"<?php if($sIsDeleted): ?> style="border-left:3px solid var(--red)"<?php endif; ?>>
          <td style="min-width:180px"><strong><?=h($s['title'])?></strong>
            <?php if(!empty($s['youtube_url'])): ?><div><a class="song-link" href="<?=h($s['youtube_url'])?>" target="_blank" rel="noopener">▶ YouTube öffnen</a></div>
            <?php else: ?><div class="small" style="color:#ccc">Kein YouTube-Link</div><?php endif; ?>
            <?php if($s['piece_id'] && !empty($s['piece_title'])): ?><div><span class="badge" style="background:var(--green-light);color:var(--green);border-color:var(--green-mid);font-size:11px">🔗 Archiv</span></div><?php elseif($s['piece_id'] && empty($s['piece_title'])): ?><div><span class="badge" style="background:#fff8e1;color:#b8860b;border-color:rgba(184,134,11,.3);font-size:11px">⚠ Archiv gelöscht</span></div><?php endif; ?>
            <?php if($sIsDeleted): ?><div style="margin-top:4px"><a href="<?=h($base)?>/admin/geloeschte.php" class="badge" style="background:var(--red-soft);color:var(--red);border-color:rgba(193,9,15,.3);font-size:11px;text-decoration:none">🗑 Löschung vorgemerkt</a></div><?php endif; ?>
          </td>
          <td class="small s-col s-col-composer s-col-arranger" style="min-width:80px;max-width:130px">
            <?=h($s['composer'] ?: '–')?>
            <?php if(!empty($s['arranger'])): ?><div style="color:var(--muted);font-size:12px">Arr. <?=h($s['arranger'])?></div><?php endif; ?>
          </td>
          <td class="small s-col s-col-genre" style="white-space:nowrap"><?= sv_tag_badges($tagsBySong[(int)$s['id']] ?? []) ?></td>
          <td class="s-col s-col-difficulty" style="white-space:nowrap"><?=diffPill($s['difficulty'])?></td>
          <td class="small s-col s-col-duration" style="white-space:nowrap"><?=h($s['duration'] ?: '–')?></td>
          <td style="white-space:nowrap"><?= (int)$s['is_active']===1
            ? '<span class="badge" style="background:var(--green-light);color:var(--green);border-color:var(--green-mid)">aktiv</span>'
            : '<span class="badge">inaktiv</span>' ?>
          </td>
          <td style="white-space:nowrap;text-align:center">
            <div style="position:relative;display:inline-block">
              <button class="btn" style="padding:4px 10px;font-size:16px;line-height:1" onclick="songToggleMenu(this)">⋯</button>
              <div class="song-menu" style="display:none;position:fixed;background:#fff;border:1.5px solid var(--border);border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.1);z-index:9999;min-width:190px;overflow:hidden">
                <button class="song-menu-item" type="button" data-open-dialog="edit-song-<?=h($s['id'])?>">✏️ Bearbeiten</button>
                <?php if(!$s['piece_id'] || empty($s['piece_title'])): ?>
                <form method="post"><input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>"><input type="hidden" name="sid" value="<?=h($s['id'])?>">
                  <?php if($s['piece_id'] && empty($s['piece_title'])): ?><input type="hidden" name="reset_piece_id" value="1"><?php endif; ?>
                  <button class="song-menu-item" name="action" value="to_archive" type="submit">📚 → Archiv</button>
                </form>
                <?php else: ?><div class="song-menu-item" style="opacity:.5;cursor:default">📚 Im Archiv</div><?php endif; ?>
                <form method="post"><input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>"><input type="hidden" name="sid" value="<?=h($s['id'])?>">
                  <button class="song-menu-item" name="action" value="toggle_active" type="submit"><?= $s['is_active'] ? '⏸ Deaktivieren' : '▶ Aktivieren' ?></button>
                </form>
                <?php if ($isAdmin): ?>
                <form method="post" onsubmit="return confirm('<?= $s['piece_id'] ? 'Titel löschen? Stimmen bleiben im Archiv.' : 'Titel löschen? Stimmen gehen verloren.' ?>')">
                  <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>"><input type="hidden" name="sid" value="<?=h($s['id'])?>">
                  <button class="song-menu-item" name="action" value="delete" type="submit" style="color:var(--red)">🗑 Löschen</button>
                </form>
                <?php else: ?>
                <button class="song-menu-item" type="button" data-open-dialog="softdel-song-<?=h($s['id'])?>" onclick="songCloseMenus()">🗑 Löschen</button>
                <?php endif; ?>
              </div>
            </div>

            <dialog id="edit-song-<?=h($s['id'])?>" class="sv-dialog">
              <div class="sv-dialog__panel" tabindex="-1">
                <div class="sv-dialog__head">
                  <div>
                    <div class="sv-dialog__title">Titel bearbeiten</div>
                    <div class="sv-dialog__sub">Stimmen bleiben erhalten.</div>
                  </div>
                  <button class="sv-dialog__close" type="button" data-close-dialog aria-label="Schließen">✕</button>
                </div>
                <div class="sv-dialog__section">
                  <form method="post" class="grid" style="gap:12px">
                    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
                    <input type="hidden" name="sid"  value="<?=h($s['id'])?>">
                    <input type="hidden" name="action" value="update">
    <input type="hidden" name="_q"    value="<?=h($_GET['q']    ?? '')?>">
    <input type="hidden" name="_sort" value="<?=h($_GET['sort'] ?? '')?>">
    <input type="hidden" name="_dir"  value="<?=h($_GET['dir']  ?? '')?>">
                    <?= songFormFields($s, $tagsBySong[(int)$s['id']] ?? []) ?>
                    <?php if($s['piece_id']): ?>
                      <div class="small" style="color:var(--muted)">🔗 Verknüpft mit Archiv: <strong><?=h($s['piece_title']??'')?></strong></div>
                    <?php endif; ?>
                    <div class="row" style="gap:10px">
                      <button class="btn primary" type="submit">Speichern</button>
                      <button class="btn" type="button" data-close-dialog>Abbrechen</button>
                    </div>
                  </form>
                </div>
              </div>
            </dialog>

            <?php if (!$isAdmin): ?>
            <dialog id="softdel-song-<?=h($s['id'])?>" class="sv-dialog">
              <div class="sv-dialog__panel" tabindex="-1" style="max-width:440px">
                <div class="sv-dialog__head">
                  <div>
                    <div class="sv-dialog__title">Titel löschen</div>
                    <div class="sv-dialog__sub"><?=h($s['title'])?></div>
                  </div>
                  <button class="sv-dialog__close" type="button" data-close-dialog aria-label="Schließen">✕</button>
                </div>
                <div class="sv-dialog__section">
                  <div class="small" style="background:var(--red-soft);border:1px solid rgba(193,9,15,.3);border-radius:8px;padding:8px 12px;margin-bottom:12px;color:var(--red)">
                    Dieser Titel wird zur Prüfung als gelöscht markiert. Der Admin kann ihn wiederherstellen oder endgültig löschen.
                  </div>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="sid" value="<?=h($s['id'])?>">
                    <input type="hidden" name="_q"    value="<?=h($_GET['q']    ?? '')?>">
                    <input type="hidden" name="_sort" value="<?=h($_GET['sort'] ?? '')?>">
                    <input type="hidden" name="_dir"  value="<?=h($_GET['dir']  ?? '')?>">
                    <label style="display:block;margin-bottom:16px">Grund <span style="color:var(--red)">*</span><br>
                      <input class="input" type="text" name="delete_reason" required placeholder="z.B. Doppelter Eintrag, nicht mehr relevant" style="width:100%;margin-top:5px">
                    </label>
                    <div style="display:flex;gap:8px;justify-content:flex-end">
                      <button class="btn" type="submit" style="color:var(--red)">🗑 Als gelöscht markieren</button>
                      <button class="btn" type="button" data-close-dialog>Abbrechen</button>
                    </div>
                  </form>
                </div>
              </div>
            </dialog>
            <?php endif; ?>

          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$songs): ?>
        <tr><td colspan="8" class="small"><?= $search !== '' ? 'Keine Treffer für „'.h($search).'".' : 'Noch keine Titel.' ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(function(){
  document.addEventListener('click', function(e){
    var o = e.target.closest('[data-open-dialog]');
    if(o){ var d = document.getElementById(o.getAttribute('data-open-dialog')); if(d){ d.showModal(); var p=d.querySelector('.sv-dialog__panel'); if(p) p.focus(); } return; }
    var c = e.target.closest('[data-close-dialog]');
    if(c){ var d = c.closest('dialog'); if(d){
      d.querySelectorAll('input:not([type=hidden]), textarea, select').forEach(function(el){
        el.value = el.defaultValue;
        if(el.type==='checkbox') el.checked = el.defaultChecked;
      });
      d.close();
    }}
  });
  document.querySelectorAll('dialog.sv-dialog').forEach(function(d){
    // backdrop click intentionally disabled
  });
})();
</script>


<script>
function buildSearchQuery(form, type) {
  var title    = ((form.querySelector('[name="title"]')    || {}).value || '').trim();
  var composer = ((form.querySelector('[name="composer"]') || {}).value || '').trim();
  var arranger = ((form.querySelector('[name="arranger"]') || {}).value || '').trim();
  if (!title) return '';
  var parts = [title];
  if (arranger) parts.push(arranger);
  else if (composer) parts.push(composer);
  if (type === 'google') parts.push('Blasorchester OR "concert band"');
  return parts.join(' ');
}
function openSearchWindow(btn, type) {
  var form = btn.closest('form');
  if (!form) return;
  var query = buildSearchQuery(form, type);
  if (!query) { alert('Bitte zuerst einen Titel eingeben.'); return; }
  var url = type === 'youtube'
    ? 'https://www.youtube.com/results?search_query=' + encodeURIComponent(query)
    : 'https://www.google.com/search?q=' + encodeURIComponent(query);
  var win = window.open(url, 'klangvotum_search_window_' + type, 'popup=yes,width=1280,height=900,left=80,top=60,resizable=yes,scrollbars=yes');
  if (!win) alert('Das Suchfenster wurde vom Browser blockiert. Bitte Popups für diese Seite erlauben.');
  else { try { win.focus(); } catch(e) {} }
}
</script>

<style>
.col-hidden { display:none!important; }
.song-menu-item { display:block;width:100%;text-align:left;padding:9px 14px;font-size:13px;font-weight:600;background:none;border:none;cursor:pointer;color:var(--text);border-bottom:1px solid var(--border); }
.song-menu-item:last-child { border-bottom:none; }
.song-menu-item:hover { background:#faf8f5; }
.song-menu form { margin:0; }
</style>
<script>
function sCol(name, show) {
  document.querySelectorAll('.s-col-'+name).forEach(function(el){ el.classList.toggle('col-hidden',!show); });
  var p=JSON.parse(localStorage.getItem('song_cols')||'{}'); p[name]=show; localStorage.setItem('song_cols',JSON.stringify(p));
}
document.addEventListener('DOMContentLoaded', function(){
  var p=JSON.parse(localStorage.getItem('song_cols')||'{}');
  ['composer','arranger','genre','difficulty','duration'].forEach(function(col){
    if(p[col]===false){ sCol(col,false); var c=document.getElementById('s-col-'+col); if(c) c.checked=false; }
  });
});
function songToggleMenu(btn) {
  var menu=btn.nextElementSibling; var isOpen=menu.style.display!=='none';
  songCloseMenus();
  if(!isOpen){
    menu.style.display='block';
    var r=btn.getBoundingClientRect(); var mh=menu.offsetHeight||120;
    menu.style.top=(window.innerHeight-r.bottom<mh+8)?(r.top-mh-4)+'px':(r.bottom+4)+'px';
    menu.style.right=(window.innerWidth-r.right)+'px';
    setTimeout(function(){ document.addEventListener('click',songCloseOnOutside); },0);
  }
}
function songCloseMenus() {
  document.querySelectorAll('.song-menu').forEach(function(m){m.style.display='none';});
  document.removeEventListener('click',songCloseOnOutside);
}
function songCloseOnOutside(e) {
  if(!e.target.closest('.song-menu')&&!e.target.closest('[onclick*="songToggleMenu"]')) songCloseMenus();
}
</script>

<script>
function toggleYtRequired(btn) {
  var wrap = btn.closest('label');
  var form = btn.closest('form');
  var inp  = wrap.querySelector('.yt-input');
  var lbl  = wrap.querySelector('.yt-label');
  if (!inp) return;
  // Hidden field für serverseitige Prüfung
  var hidden = form.querySelector('input[name="yt_optional"]');
  if (!hidden) {
    hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'yt_optional';
    hidden.value = '';
    form.appendChild(hidden);
  }
  if (inp.hasAttribute('required')) {
    inp.removeAttribute('required');
    inp.placeholder = 'https://youtu.be/… (optional)';
    btn.textContent = 'Pflicht wiederherstellen';
    btn.style.cssText = 'font-size:11px;padding:2px 8px;background:var(--red-soft);border-color:rgba(193,9,15,.3);color:var(--red)';
    if (lbl) lbl.textContent = 'YouTube URL (optional)';
    hidden.value = '1';
  } else {
    inp.setAttribute('required','');
    inp.placeholder = 'https://youtu.be/…';
    btn.textContent = 'Pflicht aufheben';
    btn.style.cssText = 'font-size:11px;padding:2px 8px';
    if (lbl) lbl.textContent = 'YouTube URL *';
    hidden.value = '';
  }
}
</script>
<script>
function songFilter() {
  var inp   = document.getElementById('song-live-q');
  var tbody = document.getElementById('song-tbody');
  if (!tbody) return;
  var q = inp ? inp.value.toLowerCase().trim() : '';
  var rows = tbody.querySelectorAll('tr[data-search]');
  var vis = 0;
  rows.forEach(function(tr) {
    var ok = !q || tr.dataset.search.indexOf(q) !== -1;
    tr.style.display = ok ? '' : 'none';
    if (ok) vis++;
  });
  var ct = document.getElementById('song-count');
  if (ct) ct.textContent = vis + ' Titel';
}
(function(){
  var inp = document.getElementById('song-live-q');
  if (!inp) return;
  inp.addEventListener('input', songFilter);
  inp.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); songFilter(); }
  });
  songFilter();
})();
</script>
<script>
// ── Live-Duplikatprüfung ─────────────────────────────────────────────────────
(function() {
  var timer = null;
  var base  = '<?=h($base)?>';

  function checkDuplicate(form) {
    var titleInp = form.querySelector('.dup-title');
    var arrInp   = form.querySelector('.dup-arranger');
    var hint     = form.querySelector('.dup-hint');
    if (!titleInp || !hint) return;

    var title    = titleInp.value.trim();
    var arranger = arrInp ? arrInp.value.trim() : '';
    var submitBtn = form.querySelector('button[type="submit"]');
    if (title.length < 2) { hint.style.display = 'none'; if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = ''; submitBtn.style.pointerEvents = ''; } return; }

    // Beim Bearbeiten: eigene ID ausschließen
    var sidInput = form.querySelector('input[name="sid"]');
    var excludeId = sidInput ? sidInput.value : '0';

    var url = base + '/api/check_duplicate.php?title=' + encodeURIComponent(title)
            + '&arranger=' + encodeURIComponent(arranger)
            + '&exclude_table=songs&exclude_id=' + excludeId;

    var submitBtn = form.querySelector('button[type="submit"]');

    fetch(url).then(function(r) { return r.json(); }).then(function(data) {
      if (!data.matches || data.matches.length === 0) {
        hint.style.display = 'none';
        if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = ''; submitBtn.style.pointerEvents = ''; }
        return;
      }
      var hasExact = false;
      var lines = [];
      data.matches.forEach(function(m) {
        var where = m.source;
        var arr = m.arranger ? ' (Arr. ' + m.arranger + ')' : '';
        var comp = m.composer ? ' — ' + m.composer : '';
        if (m.exact) {
          hasExact = true;
          lines.push('<strong style="color:var(--red)">Exakt gleicher Eintrag in ' + where + ':</strong> ' + m.title + arr + comp);
        } else {
          lines.push('Ähnlicher Eintrag in ' + where + ': ' + m.title + arr + comp);
        }
      });
      if (hasExact) {
        lines.push('<div style="margin-top:4px;font-weight:700">Speichern nicht möglich — Titel+Arrangeur bereits vergeben.</div>');
      }
      hint.innerHTML = lines.join('<br>');
      hint.style.display = 'block';
      if (hasExact) {
        hint.style.background = 'var(--red-soft)';
        hint.style.borderColor = 'rgba(193,9,15,.3)';
        hint.style.color = 'var(--red)';
        if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '.4'; submitBtn.style.pointerEvents = 'none'; }
      } else {
        hint.style.background = '#fff8e1';
        hint.style.borderColor = 'rgba(184,134,11,.3)';
        hint.style.color = '#b8860b';
        if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = ''; submitBtn.style.pointerEvents = ''; }
      }
    });
  }

  document.addEventListener('input', function(e) {
    if (!e.target.classList.contains('dup-title') && !e.target.classList.contains('dup-arranger')) return;
    var form = e.target.closest('form');
    if (!form) return;
    clearTimeout(timer);
    timer = setTimeout(function() { checkDuplicate(form); }, 400);
  });

  // Reset beim Öffnen eines Dialogs
  document.querySelectorAll('dialog').forEach(function(dlg) {
    dlg.addEventListener('close', function() {
      var hint = dlg.querySelector('.dup-hint');
      if (hint) hint.style.display = 'none';
      var btn = dlg.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = false; btn.style.opacity = ''; btn.style.pointerEvents = ''; }
    });
  });
})();
</script>
<script>
function svGenreAdd(sel) {
  var name = sel.value; if (!name) return;
  var wrap = sel.closest('.genre-widget');
  var chips = wrap.querySelector('.genre-chips');
  var existing = chips.querySelectorAll('input[type="hidden"]');
  for (var i = 0; i < existing.length; i++) {
    if (existing[i].value.toLowerCase() === name.toLowerCase()) { sel.value = ''; return; }
  }
  _svAddChip(chips, name);
  sel.querySelector('option[value="'+CSS.escape(name)+'"]').disabled = true;
  sel.value = '';
}
function svGenreRefresh(el) {
  var wrap = el.closest('.genre-widget');
  if (!wrap) return;
  var sel = wrap.querySelector('.genre-dropdown');
  var active = {};
  wrap.querySelectorAll('.genre-chips input[type="hidden"]').forEach(function(h){ active[h.value] = true; });
  sel.querySelectorAll('option').forEach(function(o){ if(o.value) o.disabled = !!active[o.value]; });
}
function svAddNewTag(btn) {
  var wrap = btn.closest('.genre-widget');
  var inp = wrap.querySelector('.tag-new-input');
  var name = (inp.value || '').trim();
  if (!name) return;
  var chips = wrap.querySelector('.genre-chips');
  var existing = chips.querySelectorAll('input[type="hidden"]');
  for (var i = 0; i < existing.length; i++) {
    if (existing[i].value.toLowerCase() === name.toLowerCase()) { inp.value = ''; return; }
  }
  _svAddChip(chips, name);
  var sel = wrap.querySelector('.genre-dropdown');
  var opt = sel.querySelector('option[value="'+CSS.escape(name)+'"]');
  if (opt) opt.disabled = true;
  inp.value = '';
}
function _svAddChip(container, name) {
  var chip = document.createElement('span');
  chip.className = 'genre-chip badge';
  chip.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:3px 8px;font-size:12px';
  var esc = name.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;');
  chip.innerHTML = esc + '<input type="hidden" name="tags[]" value="'+esc+'">'
    + '<span onclick="this.parentElement.remove();svGenreRefresh(this)" style="cursor:pointer;font-weight:700;line-height:1;opacity:.6">&times;</span>';
  container.appendChild(chip);
}
function svDeleteTagConfirm(btn) {
  var wrap = btn.closest('.genre-widget');
  var delSel = wrap.querySelector('.genre-delete-dropdown');
  var tagId = delSel.value;
  if (!tagId) { alert('Erst ein Genre zum Löschen auswählen.'); return; }
  var tagName = delSel.options[delSel.selectedIndex].textContent;
  if (!confirm('Genre „' + tagName + '" global löschen?\n\nFunktioniert nur wenn es nirgends mehr vergeben ist.')) return;
  var csrf = document.querySelector('input[name="csrf"]');
  fetch('<?=h($base)?>/api/tag.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json','X-CSRF-Token': csrf ? csrf.value : ''},
    body: JSON.stringify({action:'delete', tag_id: parseInt(tagId)})
  }).then(function(r){ return r.json(); }).then(function(d){
    if (d.error) { alert(d.error); return; }
    document.querySelectorAll('.genre-dropdown option[data-tag-id="'+tagId+'"]').forEach(function(o){ o.remove(); });
    document.querySelectorAll('.genre-delete-dropdown option[value="'+tagId+'"]').forEach(function(o){ o.remove(); });
    document.querySelectorAll('.genre-chips input[type="hidden"]').forEach(function(h){
      if (h.value === tagName) h.parentElement.remove();
    });
    delSel.value = '';
  }).catch(function(e){ alert('Fehler: ' + e.message); });
}
</script>
<?php sv_footer(); ?>
