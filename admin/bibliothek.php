<?php
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

$user    = sv_require_login();
$pdo     = sv_pdo();
$base    = sv_base_url();
$isAdmin = sv_is_admin($user); // Admin: Import/Export/Zusammenführen/Löschen
$canEdit = sv_can_edit_noten($user); // Admin + Noten: Bearbeiten/Anlegen/Abstimmung


function pieceForm(?array $p, string $action): string {
  $v = function(string $k) use ($p): string {
    return htmlspecialchars((string)($p[$k] ?? ''), ENT_QUOTES, 'UTF-8');
  };
  $pid = $p ? (int)$p['id'] : 0;
  ob_start();
?>
<form method="post" class="grid" style="gap:12px">
  <input type="hidden" name="csrf" value="<?=htmlspecialchars(sv_csrf_token(),ENT_QUOTES)?>">
  <input type="hidden" name="action" value="<?=htmlspecialchars($action,ENT_QUOTES)?>">
  <?php if($pid): ?><input type="hidden" name="pid" value="<?=$pid?>"><?php endif; ?>
  <input type="hidden" name="_q"    value="<?=h($_GET['q']    ?? '')?>">
  <input type="hidden" name="_sort" value="<?=h($_GET['sort'] ?? '')?>">
  <input type="hidden" name="_dir"  value="<?=h($_GET['dir']  ?? '')?>">
  <input type="hidden" name="_page" value="<?=h($_GET['page'] ?? '')?>">

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px" class="form-grid-2-inner">
    <label style="grid-column:1/-1">Titel *<br><input name="title" required value="<?=$v('title')?>" style="width:100%;margin-top:5px" class="dup-title"></label>
    <div class="dup-hint" style="grid-column:1/-1;display:none;background:#fff8e1;border:1px solid rgba(184,134,11,.3);border-radius:8px;padding:8px 12px;font-size:12px;color:#b8860b"></div>
    <label style="grid-column:1/-1">YouTube URL<br><input name="youtube_url" value="<?=$v('youtube_url')?>" placeholder="https://youtu.be/…" style="width:100%;margin-top:5px"></label>
    <label>Komponist<br><input name="composer" value="<?=$v('composer')?>" style="width:100%;margin-top:5px"></label>
    <label>Arrangeur<br><input name="arranger" value="<?=$v('arranger')?>" style="width:100%;margin-top:5px" class="dup-arranger"></label>
    <div style="grid-column:1/-1;display:flex;gap:8px">
      <button type="button" class="btn" style="flex:1;justify-content:center" onclick="openSearchWindow(this, 'google')">🌐 Google-Suche</button>
      <button type="button" class="btn" style="flex:1;justify-content:center" onclick="openSearchWindow(this, 'youtube')">▶ YouTube-Suche</button>
    </div>
    <label>Verlag<br><input name="publisher" value="<?=$v('publisher')?>" style="width:100%;margin-top:5px"></label>
    <label>Länge<br><input name="duration" value="<?=$v('duration')?>" placeholder="z.B. 6:30" style="width:100%;margin-top:5px"></label>
    <label>Genre<br><input name="genre" value="<?=$v('genre')?>" placeholder="z.B. Konzertmarsch" style="width:100%;margin-top:5px"></label>
    <label>Gradsgrad (1.0–6.0)<br><input name="difficulty" type="number" step="0.1" min="1" max="6" value="<?=$v('difficulty')?>" style="width:100%;margin-top:5px"></label>
    <label>Eigentümer<br><input name="owner" value="<?=$v('owner')?>" placeholder="z.B. BPH, M.Müller" style="width:100%;margin-top:5px"></label>
    <label>Preis (€)<br><input name="shop_price" type="number" step="0.01" min="0" value="<?=$v('shop_price')?>" style="width:100%;margin-top:5px"></label>
    <label style="grid-column:1/-1">Link zum Händler / Notenversand<br><input name="shop_url" value="<?=$v('shop_url')?>" placeholder="https://…" style="width:100%;margin-top:5px"></label>
    <label style="grid-column:1/-1">Querverweis <span style="font-weight:400;color:var(--muted);font-size:12px">(optional – z.B. „The Happy Cyclist")</span><br><input name="querverweis" value="<?=$v('querverweis')?>" placeholder="Alternativer Titel der in derselben Mappe liegt" style="width:100%;margin-top:5px"></label>
    <label style="grid-column:1/-1">Info-Text<br><textarea name="info" rows="2" style="width:100%;margin-top:5px"><?=$v('info')?></textarea></label>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <label class="toggle-wrap">
      <input type="checkbox" name="has_scan" value="1" <?= !empty($p['has_scan']) ? 'checked' : '' ?>>
      <span class="toggle-track"></span>
      <span class="toggle-label">Stimmen eingescannt</span>
    </label>
    <label class="toggle-wrap">
      <input type="checkbox" name="has_score_scan" value="1" <?= !empty($p['has_score_scan']) ? 'checked' : '' ?>>
      <span class="toggle-track"></span>
      <span class="toggle-label">Partitur eingescannt</span>
    </label>
    <label class="toggle-wrap">
      <input type="checkbox" name="has_original_score" value="1" <?= !empty($p['has_original_score']) ? 'checked' : '' ?>>
      <span class="toggle-track"></span>
      <span class="toggle-label">Originalpartitur vorhanden</span>
    </label>
    <label class="toggle-wrap">
      <input type="checkbox" name="binder" value="1" <?= !empty($p['binder']) ? 'checked' : '' ?>>
      <span class="toggle-track"></span>
      <span class="toggle-label">Mappe</span>
    </label>
  </div>

  <div class="row" style="gap:10px">
    <button class="btn primary" type="submit"><?= $action==='create' ? 'Anlegen' : ($action==='suggest' ? 'Vorschlag einreichen' : 'Speichern') ?></button>
    <button class="btn" type="button" data-close-dialog>Abbrechen</button>
  </div>
</form>
<?php
  return ob_get_clean();
}


// ── POST-Aktionen ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sv_csrf_check();
  $action = $_POST['action'] ?? '';
  // create/update/to_song/loan/delete: canEdit (Admin + Noten), restore/permanent_delete: nur Admin, suggest: alle
  if ($action === 'delete') {
    if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
  } elseif ($action === 'suggest') {
    // Alle eingeloggten Nutzer dürfen Vorschläge machen
  } else {
    if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
  }

  if ($action === 'create' || $action === 'update') {
    $pid       = (int)($_POST['pid'] ?? 0);
    $title      = trim($_POST['title']       ?? '');
    $youtube_url = trim($_POST['youtube_url'] ?? '');
    $composer  = trim($_POST['composer'] ?? '');
    $arranger  = trim($_POST['arranger'] ?? '');
    $publisher = trim($_POST['publisher'] ?? '');
    $duration  = sv_normalize_duration(trim($_POST['duration'] ?? ''));
    $genre     = trim($_POST['genre']    ?? '');
    $diff      = $_POST['difficulty'] !== '' ? (float)str_replace(',','.',$_POST['difficulty']) : null;
    $owner     = trim($_POST['owner'] ?? '');
    $has_scan        = !empty($_POST['has_scan']) ? 1 : 0;
    $has_score_scan  = !empty($_POST['has_score_scan']) ? 1 : 0;
    $has_orig_score  = !empty($_POST['has_original_score']) ? 1 : 0;
    $folder    = $_POST['folder_number'] !== '' ? (int)$_POST['folder_number'] : null;
    $binder    = !empty($_POST['binder']) ? 'x' : '';
    $shop_url  = trim($_POST['shop_url']   ?? '');
    $shop_price= $_POST['shop_price'] !== '' ? (float)str_replace(',','.',$_POST['shop_price']) : null;
    $info        = trim($_POST['info']        ?? '');
    $notes       = trim($_POST['notes']       ?? '');
    $querverweis = trim($_POST['querverweis'] ?? '');

    // Duplikatprüfung: gleicher Titel+Arrangeur in pieces oder songs?
    $dupExclude = ($action === 'update' && $pid) ? $pid : 0;
    $dupPiece = $pdo->prepare("SELECT id FROM pieces WHERE title=? AND LOWER(TRIM(COALESCE(arranger,'')))=LOWER(TRIM(?)) AND id!=?");
    $dupPiece->execute([$title, $arranger, $dupExclude]); $dupInPieces = $dupPiece->fetch();
    $dupSong  = $pdo->prepare("SELECT id FROM songs  WHERE title=? AND LOWER(TRIM(COALESCE(arranger,'')))=LOWER(TRIM(?))");
    $dupSong->execute([$title, $arranger]); $dupInSongs = $dupSong->fetch();

    if ($title === '') {
      sv_flash_set('error', 'Titel ist Pflichtfeld.');
    } elseif ($dupInPieces || $dupInSongs) {
      sv_flash_set('error', 'Ein Titel mit diesem Namen und Arrangeur existiert bereits' . ($dupInSongs ? ' in der Abstimmung.' : ' in der Bibliothek.'));
    } elseif ($diff !== null && ($diff < 1.0 || $diff > 6.0)) {
      sv_flash_set('error', 'Gradsgrad muss zwischen 1.0 und 6.0 liegen.');
    } else {
      try {
        if ($action === 'create') {
          $stmt = $pdo->prepare("INSERT INTO pieces
            (title,youtube_url,composer,arranger,publisher,duration,genre,difficulty,owner,
             has_scan,has_score_scan,has_original_score,folder_number,binder,shop_url,shop_price,info,notes,querverweis)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
          $stmt->execute([$title,$youtube_url,$composer,$arranger,$publisher,$duration,$genre,$diff,$owner,
            $has_scan,$has_score_scan,$has_orig_score,$folder,$binder,$shop_url,$shop_price,$info,$notes,$querverweis]);
          sv_log($user['id'], 'piece_create', $title);
          sv_flash_set('success', 'Stück angelegt.');
        } else {
          $stmt = $pdo->prepare("UPDATE pieces SET
            title=?,youtube_url=?,composer=?,arranger=?,publisher=?,duration=?,genre=?,difficulty=?,owner=?,
            has_scan=?,has_score_scan=?,has_original_score=?,folder_number=?,binder=?,shop_url=?,shop_price=?,info=?,notes=?,querverweis=?
            WHERE id=?");
          $stmt->execute([$title,$youtube_url,$composer,$arranger,$publisher,$duration,$genre,$diff,$owner,
            $has_scan,$has_score_scan,$has_orig_score,$folder,$binder,$shop_url,$shop_price,$info,$notes,$querverweis,$pid]);
          // Sync zu verknuepften Abstimmungstiteln
          $linkedS = $pdo->prepare("SELECT id FROM songs WHERE piece_id=?"); $linkedS->execute([$pid]);
          $linkedIds = array_column($linkedS->fetchAll(), 'id');
          foreach ($linkedIds as $songId) {
            $pdo->prepare("UPDATE songs SET title=?,youtube_url=?,composer=?,arranger=?,publisher=?,duration=?,genre=?,difficulty=?,shop_url=?,shop_price=?,info=? WHERE id=?")
              ->execute([$title,$youtube_url,$composer,$arranger,$publisher,$duration,$genre,$diff,$shop_url,$shop_price,$info,$songId]);
          }
          sv_log($user['id'], 'piece_update', "pid=$pid".($linkedIds?" sync=".implode(',',$linkedIds):''));
          sv_flash_set('success', 'Stueck aktualisiert.'.($linkedIds?' ('.count($linkedIds).' Abstimmungstitel synchronisiert)':''));
        }
      } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (strpos($msg, '1062') !== false || strpos($msg, 'Duplicate') !== false) {
          sv_flash_set('error', 'Ein Stück mit diesem Titel und Arrangeur existiert bereits im Archiv. Bitte prüfe ob du es über die Suche findest.');
        } else {
          sv_flash_set('error', 'Fehler: ' . $msg);
        }
      }
    }

  } elseif ($action === 'delete') {
    $pid = (int)($_POST['pid'] ?? 0);
    if ($pid > 0) {
      if ($isAdmin) {
        // Admin: Hard-Delete
        $pdo->prepare("DELETE FROM pieces WHERE id=?")->execute([$pid]);
        sv_log($user['id'], 'piece_delete', "pid=$pid");
        sv_flash_set('success', 'Stück endgültig gelöscht.');
      } else {
        // Noten-Rolle: Soft-Delete mit Grund
        $reason = trim($_POST['delete_reason'] ?? '');
        if ($reason === '') {
          sv_flash_set('error', 'Bitte einen Grund für die Löschung angeben.');
        } else {
          $pdo->prepare("UPDATE pieces SET deleted_at=NOW(), deleted_by=?, delete_reason=? WHERE id=?")->execute([$user['id'], $reason, $pid]);
          sv_log($user['id'], 'piece_soft_delete', "pid=$pid reason=$reason");
          sv_flash_set('success', 'Stück zur Löschung vorgemerkt — Admin prüft.');
        }
      }
    }

  } elseif ($action === 'to_song') {
    $pid = (int)($_POST['pid'] ?? 0);
    if ($pid > 0) {
      $piece = $pdo->prepare("SELECT * FROM pieces WHERE id=?");
      $piece->execute([$pid]);
      $p = $piece->fetch();
      if ($p) {
        $existing = $pdo->prepare("SELECT id FROM songs WHERE piece_id=? AND is_active=1");
        $existing->execute([$pid]);
        if ($existing->fetch()) {
          sv_flash_set('error', 'Dieses Stück steht bereits aktiv zur Abstimmung.');
        } else {
          $stmt = $pdo->prepare("INSERT INTO songs
            (title, youtube_url, is_active, piece_id, composer, arranger, publisher, duration, genre, difficulty, shop_url, shop_price, info)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
          $stmt->execute([
            $p['title'], $p['youtube_url'] ?? '', $pid,
            $p['composer'], $p['arranger'], $p['publisher'],
            $p['duration'], $p['genre'], $p['difficulty'],
            $p['shop_url'], $p['shop_price'], $p['info'],
          ]);
          $newSongId = (int)$pdo->lastInsertId();
          $oldVotes = $pdo->prepare("SELECT COUNT(DISTINCT v.user_id) AS cnt FROM votes v JOIN songs s ON s.id=v.song_id WHERE s.piece_id=? AND s.id!=?");
          $oldVotes->execute([$pid, $newSongId]);
          $oldVoteCount = (int)$oldVotes->fetchColumn();
          sv_log($user['id'], 'piece_to_song', "pid=$pid title={$p['title']}");
          $youtubeHint = empty($p['youtube_url']) ? ' YouTube-URL bitte in Titel-Verwaltung ergänzen.' : '';
          $msg = '„'.$p['title'].'“ zur Abstimmung gestellt.'.$youtubeHint;
          if ($oldVoteCount > 0) $msg .= ' '.$oldVoteCount.' Musiker haben früher abgestimmt — sie sehen ihre alte Stimme als Hinweis.';
          sv_flash_set($youtubeHint ? 'warning' : 'success', $msg);
        }
      }
    }
  } elseif ($action === 'loan') {
    $pid = (int)($_POST['pid'] ?? 0);
    $loanedTo = trim($_POST['loaned_to'] ?? '');
    $loanedAt = trim($_POST['loaned_at'] ?? '');
    $loanedNote = trim($_POST['loaned_note'] ?? '');
    if ($pid > 0 && $loanedTo !== '') {
      if ($loanedAt === '') $loanedAt = date('Y-m-d');
      $pdo->prepare("UPDATE pieces SET loaned_to=?, loaned_at=?, loaned_note=? WHERE id=?")
          ->execute([$loanedTo, $loanedAt, $loanedNote ?: null, $pid]);
      $pdo->prepare("INSERT INTO piece_loans (piece_id, loaned_to, loaned_at, loaned_note, loaned_by) VALUES (?,?,?,?,?)")
          ->execute([$pid, $loanedTo, $loanedAt, $loanedNote ?: null, $user['id']]);
      sv_log($user['id'], 'piece_loan', "pid=$pid an=$loanedTo");
      sv_flash_set('success', 'Stück als verliehen markiert.');
    }

  } elseif ($action === 'loan_return') {
    $pid = (int)($_POST['pid'] ?? 0);
    if ($pid > 0) {
      $pdo->prepare("UPDATE pieces SET loaned_to=NULL, loaned_at=NULL, loaned_note=NULL WHERE id=?")
          ->execute([$pid]);
      // Offenen Log-Eintrag abschließen
      $openLoan = $pdo->prepare("SELECT id FROM piece_loans WHERE piece_id=? AND returned_at IS NULL ORDER BY id DESC LIMIT 1");
      $openLoan->execute([$pid]);
      $loanId = $openLoan->fetchColumn();
      if ($loanId) {
        $pdo->prepare("UPDATE piece_loans SET returned_at=CURDATE(), returned_by=? WHERE id=?")
            ->execute([$user['id'], $loanId]);
      }
      sv_log($user['id'], 'piece_loan_return', "pid=$pid");
      sv_flash_set('success', 'Rückgabe vermerkt — Stück wieder verfügbar.');
    }

  } elseif ($action === 'suggest') {
    $pid = (int)($_POST['pid'] ?? 0);
    if ($pid > 0) {
      $piece = $pdo->prepare("SELECT * FROM pieces WHERE id=?");
      $piece->execute([$pid]);
      $cur = $piece->fetch();
      if ($cur) {
        $fields = ['title','youtube_url','composer','arranger','publisher','duration',
                   'genre','difficulty','owner','shop_url','shop_price','info','querverweis'];
        $changes = [];
        foreach ($fields as $f) {
          $oldVal = trim((string)($cur[$f] ?? ''));
          $newVal = trim((string)($_POST[$f] ?? ''));
          if ($f === 'difficulty') {
            $oldVal = $oldVal !== '' ? number_format((float)$oldVal, 1, '.', '') : '';
            $newVal = $newVal !== '' ? number_format((float)$newVal, 1, '.', '') : '';
          }
          if ($oldVal !== $newVal) {
            $changes[$f] = ['old' => $oldVal, 'new' => $newVal];
          }
        }
        // Checkboxen
        foreach (['has_scan','has_score_scan','has_original_score'] as $cb) {
          $oldVal = (int)$cur[$cb];
          $newVal = isset($_POST[$cb]) ? 1 : 0;
          if ($oldVal !== $newVal) {
            $changes[$cb] = ['old' => (string)$oldVal, 'new' => (string)$newVal];
          }
        }
        // Mappe
        $oldBinder = trim((string)($cur['binder'] ?? ''));
        $newBinder = isset($_POST['binder']) ? 'ja' : '';
        if ($oldBinder !== $newBinder) {
          $changes['binder'] = ['old' => $oldBinder, 'new' => $newBinder];
        }

        if (empty($changes)) {
          sv_flash_set('warning', 'Keine Änderungen erkannt.');
        } else {
          $pdo->prepare("INSERT INTO piece_suggestions (piece_id, user_id, changes) VALUES (?,?,?)")
              ->execute([$pid, $user['id'], json_encode($changes, JSON_UNESCAPED_UNICODE)]);
          sv_log($user['id'], 'piece_suggest', "pid=$pid fields=" . implode(',', array_keys($changes)));
          sv_flash_set('success', 'Änderungsvorschlag eingereicht — wird geprüft.');
        }
      }
    }
  }

  $redirectParams = array_filter([
    'q'    => $_POST['_q']    ?? '',
    'sort' => $_POST['_sort'] ?? '',
    'dir'  => $_POST['_dir']  ?? '',
    'page' => $_POST['_page'] ?? '',
  ]);
  $redirectSuffix = $redirectParams ? '?' . http_build_query($redirectParams) : '';
  header('Location: ' . $base . '/admin/bibliothek.php' . $redirectSuffix);
  exit;
}

// ── Daten laden ───────────────────────────────────────────────────────────────
$search  = trim($_GET['q']      ?? '');
$sortBy  = in_array($_GET['sort'] ?? '', ['title','composer','arranger','genre','difficulty','duration','owner','scan','binder']) ? $_GET['sort'] : 'title';
$sortDir = ($_GET['dir']  ?? '') === 'desc' ? 'DESC' : 'ASC';
$perPage = 9999;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$conds  = [];
$params = [];
if (!$isAdmin) {
  $conds[] = "deleted_at IS NULL";
}
if ($search !== '') {
  $conds[] = "(title LIKE ? OR composer LIKE ? OR arranger LIKE ? OR genre LIKE ? OR owner LIKE ?)";
  $params  = array_merge($params, ["%$search%","%$search%","%$search%","%$search%","%$search%"]);
}

$where   = $conds ? "WHERE ".implode(" AND ", $conds) : "";
if ($sortBy === 'composer') {
  $orderBy = "composer IS NULL OR composer='', composer $sortDir, title ASC";
} elseif ($sortBy === 'arranger') {
  $orderBy = "arranger IS NULL OR arranger='', arranger $sortDir, title ASC";
} elseif ($sortBy === 'genre') {
  $orderBy = "genre IS NULL OR genre='', genre $sortDir, title ASC";
} elseif ($sortBy === 'owner') {
  $orderBy = "owner IS NULL OR owner='', owner $sortDir, title ASC";
} elseif ($sortBy === 'scan') {
  $orderBy = "(has_scan + has_score_scan) $sortDir, title ASC";
} elseif ($sortBy === 'binder') {
  $orderBy = $sortDir === 'DESC' ? "(binder IS NOT NULL AND binder != '') DESC, title ASC" : "(binder IS NULL OR binder = '') ASC, title ASC";
} elseif ($sortBy === 'difficulty') {
  $orderBy = "difficulty IS NULL, difficulty $sortDir, title ASC";
} elseif ($sortBy === 'duration') {
  // Dauer als Sekunden parsen: "6:30" oder "6:30" oder "6" → Minuten*60+Sekunden
  $orderBy = "(CASE WHEN duration IS NULL OR duration = '' THEN 99999
    WHEN duration REGEXP '^[0-9]+[\':][0-9]+' THEN
      CAST(SUBSTRING_INDEX(REPLACE(duration, '\'', ':'), ':', 1) AS UNSIGNED)*60 +
      CAST(SUBSTRING_INDEX(REPLACE(duration, '\'', ':'), ':', -1) AS UNSIGNED)
    ELSE CAST(duration AS UNSIGNED)*60 END) $sortDir, title ASC";
} else {
  $orderBy = "title $sortDir";
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM pieces $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare("SELECT * FROM pieces $where ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$pieces = $stmt->fetchAll();

$activeSongPieceIds = array_column(
  $pdo->query("SELECT piece_id FROM songs WHERE piece_id IS NOT NULL AND is_active=1 AND deleted_at IS NULL")->fetchAll(),
  'piece_id'
);

// Konzerte pro Stück vorladen (für aktuelle Seite)
$concertsByPiece = [];
if ($pieces) {
  $pids2 = implode(',', array_map(function($r){ return (int)$r['id']; }, $pieces));
  if ($pids2) {
    $cpRows = $pdo->query("SELECT cp.piece_id, c.name, c.year FROM concert_pieces cp JOIN concerts c ON c.id=cp.concert_id WHERE cp.piece_id IN ($pids2) ORDER BY c.year DESC, c.name ASC")->fetchAll();
    foreach ($cpRows as $row) $concertsByPiece[(int)$row['piece_id']][] = $row;
  }
}

// Eigene Vorschläge des aktuellen Users vorladen
$mySuggestions = [];
try {
  $myRows = $pdo->prepare("SELECT piece_id, status FROM piece_suggestions WHERE user_id=? ORDER BY created_at DESC");
  $myRows->execute([$user['id']]);
  foreach ($myRows->fetchAll() as $ms) {
    $pid = (int)$ms['piece_id'];
    // Nur den neuesten Status pro Stück merken
    if (!isset($mySuggestions[$pid])) $mySuggestions[$pid] = $ms['status'];
  }
} catch (Throwable $e) {}

sv_header('Notenbibliothek', $user);
?>
<style>
  .bib-row-deleted td:first-child { border-left: 3px solid var(--red); }
  .bib-row-loaned { background: #fff3e0 !important; }
  .bib-row-loaned td:first-child { border-left: 4px solid #e65100; }
  .bib-loan-badge { display:inline-flex;align-items:center;gap:4px;margin-top:4px;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:700;background:#e65100;color:#fff;letter-spacing:.02em; }
  .bib-sugg-badge { display:inline-flex;align-items:center;gap:4px;margin-top:4px;padding:3px 10px;border-radius:8px;font-size:11px;font-weight:700;letter-spacing:.02em; }

</style>
<?php
function diffPill(mixed $d): string {
  if ($d === null || $d === '') return '<span class="small" style="color:#bbb">–</span>';
  $d = (float)$d;
  if ($d <= 2)     $style = 'background:var(--green-light);color:var(--green);border-color:var(--green-mid)';
  elseif ($d <= 4) $style = 'background:#fff8e1;color:#b8860b;border-color:rgba(184,134,11,.3)';
  else             $style = 'background:var(--red-soft);color:var(--red);border-color:rgba(193,9,15,.3)';
  return '<span class="badge" style="'.$style.'">'.number_format($d,1).'</span>';
}
?>

<div class="page-header">
  <div>
    <h2>Notenbibliothek</h2>
    <div class="muted"><?=h($total)?> Stücke im Archiv</div>
  </div>
  <div class="row">
    <div class="btn-group">
      <button class="btn" id="btn-view-table"    onclick="setView('table')"    title="Tabelle">☰ Tabelle</button>
      <button class="btn" id="btn-view-detail"   onclick="setView('detail')"   title="Liste + Detail">⊟ Detail</button>
    </div>
    <?php if ($canEdit): ?>
    <a class="btn primary" href="#" data-open-dialog="dialog-new-piece">+ Neues Stück</a>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
    <a class="btn" href="<?=h($base)?>/admin/bibliothek_merge.php">🔀 Zusammenführen</a>
    <a class="btn" href="<?=h($base)?>/admin/bibliothek_import.php">📥 Import / Export</a>
    <?php endif; ?>
    <a class="btn" href="<?=h($base)?>/admin/">← Verwaltung</a>
  </div>
</div>

<!-- Suche & Filter -->
<div class="card" style="margin-bottom:12px">
  <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <label style="flex:1;min-width:200px">Suche<br>
      <input class="input" type="text" name="q" id="bib-live-q" value="<?=h($search)?>"
             placeholder="Titel, Komponist, Arrangeur, Genre, Eigentümer…" style="width:100%;margin-top:5px" autocomplete="off">
    </label>
    <span class="small" id="bib-count" style="color:var(--muted);white-space:nowrap;margin-top:22px"><?=h($total)?> Titel</span>
  </form>
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
    <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Spalten</span>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="bib-col-composer" onchange="bibCol('composer',this.checked);bibCol('arranger',this.checked)" checked> Komponist / Arrangeur</label>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="bib-col-genre"       onchange="bibCol('genre',this.checked)"       checked> Genre</label>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="bib-col-difficulty"  onchange="bibCol('difficulty',this.checked)"  checked> Grad</label>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="bib-col-duration"    onchange="bibCol('duration',this.checked)"    checked> Länge</label>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="bib-col-owner"       onchange="bibCol('owner',this.checked)"       checked> Eigentümer</label>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="bib-col-scan"        onchange="bibCol('scan',this.checked)"        checked> Scan</label>
    <label style="display:inline-flex;align-items:center;gap:5px;font-size:13px;font-weight:600;cursor:pointer"><input type="checkbox" id="bib-col-binder"      onchange="bibCol('binder',this.checked)"      checked> Mappe</label>
  </div>
</div>

<!-- TABELLE -->
<div id="view-table" class="view-panel">
<div class="card">
  <div class="table-scroll">
    <table>
      <thead>
        <tr>
          <?php
          function sortTh(string $col, string $label, string $cur, string $dir, string $colClass=''): string {
            $active = $cur===$col;
            $nd = ($active && $dir==='ASC') ? 'desc' : 'asc';
            $icon = $active ? ($dir==='ASC'?' ↑':' ↓') : '';
            $qs = http_build_query(array_filter(['q'=>$_GET['q']??'','sort'=>$col,'dir'=>$nd]));
            $style = ($active?'color:var(--red);':'').'cursor:pointer;white-space:nowrap;user-select:none';
            $cc = $colClass ? ' class="'.$colClass.'"' : '';
            return '<th'.$cc.' style="'.$style.'"><a href="?'.$qs.'" style="text-decoration:none;color:inherit">'.$label.$icon.'</a></th>';
          }
          echo sortTh('title',      'Titel',          $sortBy,$sortDir);
          // Kombinierter Komponist/Arrangeur-Kopf
          $icK = $sortBy==='composer' ? ($sortDir==='ASC'?' ↑':' ↓') : '';
          $icA = $sortBy==='arranger' ? ($sortDir==='ASC'?' ↑':' ↓') : '';
          $ndK = ($sortBy==='composer'&&$sortDir==='ASC')?'desc':'asc';
          $ndA = ($sortBy==='arranger'&&$sortDir==='ASC')?'desc':'asc';
          $qK  = http_build_query(array_filter(['q'=>$_GET['q']??'','sort'=>'composer','dir'=>$ndK]));
          $qA  = http_build_query(array_filter(['q'=>$_GET['q']??'','sort'=>'arranger','dir'=>$ndA]));
          $stK = ($sortBy==='composer'?'color:var(--red)':'color:inherit').';cursor:pointer;user-select:none;display:block';
          $stA = ($sortBy==='arranger'?'color:var(--red)':'color:var(--muted)').';cursor:pointer;user-select:none;display:block;font-size:12px';
          echo '<th class="bib-col bib-col-composer bib-col-arranger" style="white-space:nowrap"><a href="?'.$qK.'" style="text-decoration:none;'.$stK.'">Komponist'.$icK.'</a><a href="?'.$qA.'" style="text-decoration:none;font-size:12px;'.$stA.'">Arrangeur'.$icA.'</a></th>';
          echo sortTh('genre',      'Genre',          $sortBy,$sortDir,'bib-col bib-col-genre');
          echo sortTh('difficulty', 'Grad',  $sortBy,$sortDir,'bib-col bib-col-difficulty');
          echo sortTh('duration',   'Länge',          $sortBy,$sortDir,'bib-col bib-col-duration');
          echo sortTh('owner',      'Eigentümer',     $sortBy,$sortDir,'bib-col bib-col-owner');
          echo sortTh('scan',       'Scan',           $sortBy,$sortDir,'bib-col bib-col-scan');
          echo sortTh('binder',     'Mappe',          $sortBy,$sortDir,'bib-col bib-col-binder');
          ?>
          <th style="width:44px"></th>
        </tr>
      </thead>
      <tbody id="bib-tbody">
      <?php foreach ($pieces as $p):
        $pIsDeleted = !empty($p['deleted_at']);
        $rowClass = $pIsDeleted ? 'bib-row-deleted' : (!empty($p['loaned_to']) ? 'bib-row-loaned' : '');
      ?>
        <tr data-search="<?=h(strtolower($p['title'].' '.($p['composer']??'').' '.($p['arranger']??'').' '.($p['genre']??'').' '.($p['owner']??'')))?>"<?php if($rowClass): ?> class="<?=$rowClass?>"<?php endif; ?>>
          <td style="min-width:180px"><strong style="cursor:pointer" onclick="openDetailById('<?=h($p['id'])?>')"><?=h($p['title'])?></strong><?php if(!empty($p['querverweis'])): ?> <span class="small" style="color:var(--muted);font-weight:400">/ <?=h($p['querverweis'])?></span><?php endif; ?>
            <?php if(!empty($p['publisher'])): ?><div class="small"><?=h($p['publisher'])?></div><?php endif; ?>
            <?php if(!empty($p['youtube_url'])): ?><div><a class="song-link" href="<?=h($p['youtube_url'])?>" target="_blank" rel="noopener">▶ YouTube öffnen</a></div><?php endif; ?>
            <?php if(in_array((int)$p['id'],$activeSongPieceIds)): ?><div><span class="badge" style="background:var(--green-light);color:var(--green);border-color:var(--green-mid);font-size:11px">in Abstimmung</span></div><?php endif; ?>
            <?php if(!empty($concertsByPiece[(int)$p['id']])): ?>
              <div style="margin-top:3px;display:flex;flex-wrap:wrap;gap:3px">
              <?php foreach(array_slice($concertsByPiece[(int)$p['id']],0,3) as $cp): ?>
                <span class="badge" style="font-size:10px;padding:1px 6px"><?=h($cp['name'])?><?php if($cp['year']): ?> <?=(int)$cp['year']?><?php endif; ?></span>
              <?php endforeach; ?>
              <?php if(count($concertsByPiece[(int)$p['id']])>3): ?><span class="small" style="color:var(--muted)">+<?=count($concertsByPiece[(int)$p['id']])-3?></span><?php endif; ?>
              </div>
            <?php endif; ?>
            <?php if(!empty($p['loaned_to'])): ?>
              <div><span class="bib-loan-badge">📦 Verliehen an <?=h($p['loaned_to'])?> seit <?=date('d.m.Y', strtotime($p['loaned_at']))?></span></div>
            <?php endif; ?>
            <?php if(isset($mySuggestions[(int)$p['id']]) && $mySuggestions[(int)$p['id']] === 'pending'): ?>
              <div><span class="bib-sugg-badge" style="background:#fff8e1;color:#b8860b;border:1px solid rgba(184,134,11,.3)">📝 Vorschlag eingereicht</span></div>
            <?php endif; ?>
            <?php if($pIsDeleted): ?>
              <div><a href="<?=h($base)?>/admin/geloeschte.php" class="bib-loan-badge" style="background:var(--red);color:#fff;text-decoration:none">🗑 Löschung vorgemerkt</a></div>
            <?php endif; ?>
          </td>
          <td class="small bib-col bib-col-composer bib-col-arranger" style="min-width:80px;max-width:130px">
            <?=h($p['composer'] ?? '–')?>
            <?php if(!empty($p['arranger'])): ?><div style="color:var(--muted);font-size:12px">Arr. <?=h($p['arranger'])?></div><?php endif; ?>
          </td>
          <td class="small bib-col bib-col-genre" style="white-space:nowrap"><?=h($p['genre'] ?? '–')?></td>
          <td class="bib-col bib-col-difficulty" style="white-space:nowrap"><?=diffPill($p['difficulty'])?></td>
          <td class="small bib-col bib-col-duration" style="white-space:nowrap"><?=h($p['duration'] ?? '–')?></td>
          <td class="small bib-col bib-col-owner" style="white-space:nowrap">
            <?php if($p['owner']=== 'MS Hi'): ?><span class="badge" style="background:var(--green-light);color:var(--green);border-color:var(--green-mid);font-weight:700">MS Hi</span>
            <?php else: ?><?=h($p['owner'] ?? '–')?><?php endif; ?>
          </td>
          <td class="small bib-col bib-col-scan" style="white-space:nowrap">
            <div><?= $p['has_scan'] ? '<span style="color:var(--green)">✓ St.</span>' : '<span style="color:#ccc">✗ St.</span>' ?></div>
            <div><?= $p['has_score_scan'] ? '<span style="color:var(--green)">✓ Pa.</span>' : '<span style="color:#ccc">✗ Pa.</span>' ?></div>
          </td>
          <td class="bib-col bib-col-binder" style="white-space:nowrap;text-align:center"><?= !empty($p['binder']) ? '<span style="color:var(--green);font-size:16px">✓</span>' : '<span style="color:#ddd">–</span>' ?></td>
          <td style="white-space:nowrap;text-align:center">
            <div style="position:relative;display:inline-block">
              <button class="btn" style="padding:4px 10px;font-size:16px;line-height:1" onclick="toggleMenu(this)">⋯</button>
              <div class="piece-menu" style="display:none;position:fixed;background:#fff;border:1.5px solid var(--border);border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.1);z-index:9999;min-width:180px;overflow:hidden">
                <?php if ($canEdit): ?>
                <button class="piece-menu-item" type="button" data-open-dialog="dialog-edit-<?=h($p['id'])?>" onclick="closeMenus()">✏️ Bearbeiten</button>
                <?php if(!in_array((int)$p['id'],$activeSongPieceIds)): ?>
                <form method="post"><input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>"><input type="hidden" name="action" value="to_song"><input type="hidden" name="pid" value="<?=h($p['id'])?>">
                  <button class="piece-menu-item" type="submit">🎵 Zur Abstimmung</button>
                </form>
                <?php else: ?><div class="piece-menu-item" style="opacity:.5;cursor:default">🎵 Bereits aktiv</div><?php endif; ?>
                <?php if(empty($p['loaned_to'])): ?>
                <button class="piece-menu-item" type="button" data-open-dialog="dialog-loan-<?=h($p['id'])?>" onclick="closeMenus()">📦 Verleihen</button>
                <?php else: ?>
                <form method="post" onsubmit="return confirm('Rückgabe von „<?=h(addslashes($p['title']))?>" bestätigen?')">
                  <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>"><input type="hidden" name="action" value="loan_return"><input type="hidden" name="pid" value="<?=h($p['id'])?>">
                  <button class="piece-menu-item" type="submit" style="color:var(--green)">📦 Rückgabe</button>
                </form>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                <form method="post" onsubmit="return confirm('Stück wirklich löschen?')">
                  <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="pid" value="<?=h($p['id'])?>">
                  <button class="piece-menu-item" type="submit" style="color:var(--red)">🗑 Löschen</button>
                </form>
                <?php else: ?>
                <button class="piece-menu-item" type="button" data-open-dialog="dialog-softdel-<?=h($p['id'])?>" onclick="closeMenus()">🗑 Löschen</button>
                <?php endif; ?>
                <?php else: ?>
                <button class="piece-menu-item" type="button" data-open-dialog="dialog-suggest-<?=h($p['id'])?>" onclick="closeMenus()">✏️ Änderung vorschlagen</button>
                <?php endif; ?>
              </div>
            </div>
          </td>
        </tr>

        <?php if ($canEdit): ?>
        <dialog id="dialog-edit-<?=h($p['id'])?>" class="sv-dialog">
          <div class="sv-dialog__panel" tabindex="-1">
            <div class="sv-dialog__head">
              <div>
                <div class="sv-dialog__title">Stück bearbeiten</div>
                <div class="sv-dialog__sub"><?=h($p['title'])?></div>
              </div>
              <button class="sv-dialog__close" type="button" data-close-dialog aria-label="Schließen">✕</button>
            </div>
            <div class="sv-dialog__section">
              <?= pieceForm($p, 'update') ?>
            </div>
          </div>
        </dialog>

        <?php if(empty($p['loaned_to'])): ?>
        <dialog id="dialog-loan-<?=h($p['id'])?>" class="sv-dialog">
          <div class="sv-dialog__panel" tabindex="-1" style="max-width:440px">
            <div class="sv-dialog__head">
              <div>
                <div class="sv-dialog__title">Stück verleihen</div>
                <div class="sv-dialog__sub"><?=h($p['title'])?></div>
              </div>
              <button class="sv-dialog__close" type="button" data-close-dialog aria-label="Schließen">✕</button>
            </div>
            <div class="sv-dialog__section">
              <form method="post">
                <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
                <input type="hidden" name="action" value="loan">
                <input type="hidden" name="pid" value="<?=h($p['id'])?>">
                <label style="display:block;margin-bottom:12px">Ausgeliehen an <span style="color:var(--red)">*</span><br>
                  <input class="input" type="text" name="loaned_to" required placeholder="z.B. Musikverein Musterstadt" style="width:100%;margin-top:5px">
                </label>
                <label style="display:block;margin-bottom:12px">Datum<br>
                  <input class="input" type="date" name="loaned_at" value="<?=date('Y-m-d')?>" style="width:100%;margin-top:5px">
                </label>
                <label style="display:block;margin-bottom:16px">Vermerk <span class="small muted">(optional)</span><br>
                  <input class="input" type="text" name="loaned_note" placeholder="z.B. Rückgabe bis Juni" style="width:100%;margin-top:5px">
                </label>
                <div style="display:flex;gap:8px;justify-content:flex-end">
                  <button class="btn primary" type="submit">📦 Verleihen</button>
                  <button class="btn" type="button" data-close-dialog>Abbrechen</button>
                </div>
              </form>
            </div>
          </div>
        </dialog>
        <?php endif; ?>

        <?php if ($canEdit && !$isAdmin): ?>
        <dialog id="dialog-softdel-<?=h($p['id'])?>" class="sv-dialog">
          <div class="sv-dialog__panel" tabindex="-1" style="max-width:440px">
            <div class="sv-dialog__head">
              <div>
                <div class="sv-dialog__title">Stück löschen</div>
                <div class="sv-dialog__sub"><?=h($p['title'])?></div>
              </div>
              <button class="sv-dialog__close" type="button" data-close-dialog aria-label="Schließen">✕</button>
            </div>
            <div class="sv-dialog__section">
              <div class="small" style="background:var(--red-soft);border:1px solid rgba(193,9,15,.3);border-radius:8px;padding:8px 12px;margin-bottom:12px;color:var(--red)">
                Dieses Stück wird zur Prüfung als gelöscht markiert. Der Admin kann es wiederherstellen oder endgültig löschen.
              </div>
              <form method="post">
                <input type="hidden" name="csrf" value="<?=h(sv_csrf_token())?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="pid" value="<?=h($p['id'])?>">
                <input type="hidden" name="_q"    value="<?=h($_GET['q']    ?? '')?>">
                <input type="hidden" name="_sort" value="<?=h($_GET['sort'] ?? '')?>">
                <input type="hidden" name="_dir"  value="<?=h($_GET['dir']  ?? '')?>">
                <input type="hidden" name="_page" value="<?=h($_GET['page'] ?? '')?>">
                <label style="display:block;margin-bottom:16px">Grund <span style="color:var(--red)">*</span><br>
                  <input class="input" type="text" name="delete_reason" required placeholder="z.B. Doppelter Eintrag, Titel existiert nicht mehr" style="width:100%;margin-top:5px">
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

        <?php endif; ?>

        <?php if (!$canEdit): ?>
        <dialog id="dialog-suggest-<?=h($p['id'])?>" class="sv-dialog">
          <div class="sv-dialog__panel" tabindex="-1">
            <div class="sv-dialog__head">
              <div>
                <div class="sv-dialog__title">Änderung vorschlagen</div>
                <div class="sv-dialog__sub"><?=h($p['title'])?></div>
              </div>
              <button class="sv-dialog__close" type="button" data-close-dialog aria-label="Schließen">✕</button>
            </div>
            <div class="sv-dialog__section">
              <div class="small" style="background:#fff8e1;border:1px solid rgba(184,134,11,.3);border-radius:8px;padding:8px 12px;margin-bottom:12px;color:#b8860b">
                Ändere die Felder, die du korrigieren möchtest. Dein Vorschlag wird zur Prüfung an die Notenverwaltung gesendet.
              </div>
              <?= pieceForm($p, 'suggest') ?>
            </div>
          </div>
        </dialog>
        <?php endif; ?>

      <?php endforeach; ?>
      <?php if(!$pieces): ?>
        <tr><td colspan="8" class="small"><?= $search ? 'Keine Treffer für „'.h($search).'".' : 'Noch keine Stücke im Archiv. Stücke manuell anlegen oder per Import laden.' ?></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if($pages > 1): ?>
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:16px;align-items:center">
    <?php for($i=1; $i<=$pages; $i++): ?>
      <?php
        $qParams = array_filter([
          'q'        => $search,
          'sort'     => $sortBy !== 'title' ? $sortBy : '',
          'dir'      => $sortDir === 'DESC' ? 'desc' : '',
        ]);
        $qStr = $qParams ? '&' . http_build_query($qParams) : '';
      ?>
      <a class="btn<?= $i===$page?' primary':'' ?>" href="?page=<?=$i?><?=$qStr?>"><?=$i?></a>
    <?php endfor; ?>
    <span class="small">Seite <?=$page?> von <?=$pages?> (<?=$total?> Stücke)</span>
  </div>
  <?php endif; ?>
</div>
</div><!-- /view-table -->

<!-- DETAIL -->
<div id="view-detail" class="view-panel" style="display:none">
  <div style="display:grid;grid-template-columns:340px 1fr;gap:12px;align-items:start">
    <div class="card" style="padding:0;overflow:hidden">
      <?php foreach ($pieces as $p):
        $pDel = !empty($p['deleted_at']);
      ?>
      <div class="detail-list-row" data-id="<?=h($p['id'])?>" onclick="showDetail(this)"
           style="padding:10px 14px;border-bottom:1px solid var(--border);cursor:pointer<?php if($pDel): ?>;border-left:3px solid var(--red)<?php elseif(!empty($p['loaned_to'])): ?>;background:#fff3e0;border-left:4px solid #e65100<?php endif; ?>">
        <div style="font-weight:600;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php if($pDel): ?>🗑 <?php elseif(!empty($p['loaned_to'])): ?>📦 <?php endif; ?><?=h($p['title'])?></div>
        <div class="small" style="color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?= implode(' · ', array_filter([h($p['composer']??''), $p['arranger'] ? 'Arr. '.h($p['arranger']) : ''])) ?: '–' ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div id="detail-panel" class="card" style="min-height:300px">
      <div style="display:flex;align-items:center;justify-content:center;height:200px;color:var(--muted);font-size:14px">← Stück auswählen</div>
    </div>
  </div>
  <div id="detail-data" style="display:none">
  <?php foreach ($pieces as $p): ?>
    <div data-id="<?=h($p['id'])?>" data-title="<?=h($p['title'])?>"
         data-composer="<?=h($p['composer']??'')?>" data-arranger="<?=h($p['arranger']??'')?>"
         data-publisher="<?=h($p['publisher']??'')?>" data-duration="<?=h($p['duration']??'')?>"
         data-genre="<?=h($p['genre']??'')?>" data-difficulty="<?=h($p['difficulty']??'')?>"
         data-owner="<?=h($p['owner']??'')?>" data-youtube="<?=h($p['youtube_url']??'')?>"
         data-info="<?=h($p['info']??'')?>"
         data-scan="<?=$p['has_scan']?'1':'0'?>" data-scanscore="<?=$p['has_score_scan']?'1':'0'?>"
         data-original="<?=$p['has_original_score']?'1':'0'?>" data-binder="<?=!empty($p['binder'])?'1':'0'?>"
         data-active="<?=in_array((int)$p['id'],$activeSongPieceIds)?'1':'0'?>"
         data-loaned-to="<?=h($p['loaned_to']??'')?>" data-loaned-at="<?=h($p['loaned_at']??'')?>" data-loaned-note="<?=h($p['loaned_note']??'')?>"
         data-deleted="<?=!empty($p['deleted_at'])?'1':'0'?>"
         data-concerts="<?=h(json_encode(array_map(function($r){ return ['name'=>$r['name'],'year'=>$r['year']]; }, $concertsByPiece[(int)$p['id']] ?? []), JSON_UNESCAPED_UNICODE))?>"></div>
  <?php endforeach; ?>
  </div>
</div><!-- /view-detail -->


<?php if ($canEdit): ?>
<!-- Neues Stück Dialog -->
<dialog id="dialog-new-piece" class="sv-dialog">
  <div class="sv-dialog__panel" tabindex="-1">
    <div class="sv-dialog__head">
      <div class="sv-dialog__title">Neues Stück anlegen</div>
      <button class="sv-dialog__close" type="button" data-close-dialog aria-label="Schließen">✕</button>
    </div>
    <div class="sv-dialog__section">
      <?= pieceForm(null, 'create') ?>
    </div>
  </div>
</dialog>
<?php endif; ?>

<?php
?>

<script>
(function svDialogInit(){
  document.addEventListener('click', function(e){
    var openBtn = e.target.closest('[data-open-dialog]');
    if(openBtn){
      var id = openBtn.getAttribute('data-open-dialog');
      var dlg = document.getElementById(id);
      if(dlg){ dlg.showModal(); var p=dlg.querySelector('.sv-dialog__panel'); if(p) p.focus(); }
      return;
    }
    var closeBtn = e.target.closest('[data-close-dialog]');
    if(closeBtn){
      var dlg = closeBtn.closest('dialog');
      if(dlg){
        // Reset all inputs to original values before closing
        dlg.querySelectorAll('input:not([type=hidden]), textarea, select').forEach(function(el){
          el.value = el.defaultValue;
          if(el.type==='checkbox') el.checked = el.defaultChecked;
        });
        dlg.close();
      }
      return;
    }
  });
  document.querySelectorAll('dialog.sv-dialog').forEach(function(dlg){
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
  // Suche das nächste form oder dialog-panel
  var container = btn.closest('form') || btn.closest('.sv-dialog__section') || btn.closest('dialog');
  if (!container) return;
  var title    = ((container.querySelector('[name="title"]')    || {}).value || '').trim();
  var composer = ((container.querySelector('[name="composer"]') || {}).value || '').trim();
  var arranger = ((container.querySelector('[name="arranger"]') || {}).value || '').trim();
  if (!title) { alert('Bitte zuerst einen Titel eingeben.'); return; }
  var parts = [title];
  if (arranger) parts.push(arranger);
  else if (composer) parts.push(composer);
  if (type === 'google') parts.push('Blasorchester OR "concert band"');
  var query = parts.join(' ');
  var url = type === 'youtube'
    ? 'https://www.youtube.com/results?search_query=' + encodeURIComponent(query)
    : 'https://www.google.com/search?q=' + encodeURIComponent(query);
  var win = window.open(url, 'klangvotum_search_' + type, 'popup=yes,width=1280,height=900,left=80,top=60,resizable=yes,scrollbars=yes');
  if (!win) alert('Das Suchfenster wurde vom Browser blockiert. Bitte Popups für diese Seite erlauben.');
  else { try { win.focus(); } catch(e) {} }
}
</script>


<style>
.col-hidden { display:none!important; }
#btn-view-table.active,#btn-view-detail.active { background:var(--red);color:#fff;border-color:var(--red); }
.piece-menu-item { display:block;width:100%;text-align:left;padding:9px 14px;font-size:13px;font-weight:600;background:none;border:none;cursor:pointer;color:var(--text);border-bottom:1px solid var(--border); }
.piece-menu-item:last-child { border-bottom:none; }
.piece-menu-item:hover { background:#faf8f5; }
.piece-menu form { margin:0; }
.detail-list-row:hover { background:#faf8f5; }
.detail-list-row.active-row { background:#f0f5e8!important;border-left:3px solid var(--green); }
</style>
<script>
var currentView = localStorage.getItem('bib_view') || 'table';
if (currentView !== 'table' && currentView !== 'detail') currentView = 'table';

function setView(v) {
  currentView = v;
  localStorage.setItem('bib_view', v);
  ['table','detail'].forEach(function(n){
    var el = document.getElementById('view-'+n);
    if(el) el.style.display = n===v ? '' : 'none';
    var btn = document.getElementById('btn-view-'+n);
    if(btn) btn.classList.toggle('active', n===v);
  });
}

// Dropdown menu
function toggleMenu(btn) {
  var menu = btn.nextElementSibling;
  var isOpen = menu.style.display !== 'none';
  closeMenus();
  if (!isOpen) {
    menu.style.display = 'block';
    var r = btn.getBoundingClientRect();
    var mh = menu.offsetHeight || 120;
    menu.style.top  = (window.innerHeight - r.bottom < mh+8) ? (r.top-mh-4)+'px' : (r.bottom+4)+'px';
    menu.style.right = (window.innerWidth - r.right)+'px';
    setTimeout(function(){ document.addEventListener('click', closeOnOutside); }, 0);
  }
}
function closeMenus() {
  document.querySelectorAll('.piece-menu').forEach(function(m){ m.style.display='none'; });
  document.removeEventListener('click', closeOnOutside);
}
function closeOnOutside(e) {
  if (!e.target.closest('.piece-menu') && !e.target.closest('[onclick*="toggleMenu"]')) closeMenus();
}

// AJAX edit dialog
document.addEventListener('click', function(e){
  var openBtn = e.target.closest('[data-open-dialog]');
  if(openBtn){ var d=document.getElementById(openBtn.getAttribute('data-open-dialog')); if(d){ d.showModal(); var p=d.querySelector('.sv-dialog__panel'); if(p) p.focus(); } return; }

  var closeBtn = e.target.closest('[data-close-dialog]');
  if(closeBtn){ var d=closeBtn.closest('dialog'); if(d){ d.close(); document.body.style.overflow=''; } }
});

// Detail view
function showDetail(row) {
  document.querySelectorAll('.detail-list-row').forEach(function(r){ r.classList.remove('active-row'); });
  row.classList.add('active-row');
  var id = row.dataset.id;
  var d = document.querySelector('#detail-data [data-id="'+id+'"]');
  if(!d) return;
  var diff = d.dataset.difficulty;
  var diffHtml = '';
  if(diff){ var dv=parseFloat(diff),dc=dv<=2?'background:var(--green-light);color:var(--green);border-color:var(--green-mid)':dv<=4?'background:#fff8e1;color:#b8860b;border-color:rgba(184,134,11,.3)':'background:var(--red-soft);color:var(--red);border-color:rgba(193,9,15,.3)'; diffHtml='<span class="badge" style="'+dc+'">'+dv.toFixed(1)+'</span>'; }
  var fields=[['Komponist',d.dataset.composer],['Arrangeur',d.dataset.arranger],['Verlag',d.dataset.publisher],['Dauer',d.dataset.duration],['Genre',d.dataset.genre],['Eigentümer',d.dataset.owner],['Info',d.dataset.info]].filter(function(f){return f[1];});
  var toggles=[];
  if(d.dataset.scan==='1') toggles.push('✓ Stimmen');
  if(d.dataset.scanscore==='1') toggles.push('✓ Partitur');
  if(d.dataset.original==='1') toggles.push('✓ Original');
  if(d.dataset.binder==='1') toggles.push('📁 Mappe');
  var html='<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:14px"><div><div style="font-weight:700;font-size:16px">'+escH(d.dataset.title)+'</div>'+(d.dataset.composer||d.dataset.arranger?'<div class="small" style="color:var(--muted)">'+escH([d.dataset.composer,d.dataset.arranger?'Arr. '+d.dataset.arranger:''].filter(Boolean).join(' · '))+'</div>':'')+'</div>'+diffHtml+'</div>';
  if(fields.length){html+='<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 16px;margin-bottom:12px">';fields.forEach(function(f){html+='<div class="small"><span style="color:var(--muted)">'+f[0]+':</span> '+escH(f[1])+'</div>';});html+='</div>';}
  if(toggles.length) html+='<div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:10px">'+toggles.map(function(t){return'<span class="badge" style="background:var(--green-light);color:var(--green);border-color:var(--green-mid)">'+t+'</span>';}).join('')+'</div>';
  if(d.dataset.youtube) html+='<div style="margin-bottom:10px"><a class="song-link" href="'+escH(d.dataset.youtube)+'" target="_blank" rel="noopener">▶ YouTube öffnen</a></div>';
  // Konzerte anzeigen
  var concerts = [];
  try { concerts = JSON.parse(d.dataset.concerts||'[]'); } catch(e){}
  if (concerts.length) {
    html += '<div style="margin-bottom:12px"><div class="small" style="font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px">Gespielt bei</div>';
    html += '<div style="display:flex;flex-wrap:wrap;gap:5px">';
    concerts.forEach(function(con) {
      var label = con.name + (con.year ? ' ('+con.year+')' : '');
      html += '<span class="badge">' + escH(label) + '</span>';
    });
    html += '</div></div>';
  }
  if (d.dataset.loanedTo) {
    var loanDate = d.dataset.loanedAt ? new Date(d.dataset.loanedAt).toLocaleDateString('de-DE') : '';
    html += '<div style="margin-bottom:12px;padding:10px 14px;border:2px solid #e65100;border-radius:10px;background:#fff3e0">';
    html += '<div style="font-weight:700;color:#e65100;margin-bottom:4px">📦 Verliehen</div>';
    html += '<div class="small"><span style="color:var(--muted)">An:</span> <strong>' + escH(d.dataset.loanedTo) + '</strong></div>';
    if (loanDate) html += '<div class="small"><span style="color:var(--muted)">Seit:</span> ' + loanDate + '</div>';
    if (d.dataset.loanedNote) html += '<div class="small"><span style="color:var(--muted)">Vermerk:</span> ' + escH(d.dataset.loanedNote) + '</div>';
    html += '</div>';
  }
  if (d.dataset.deleted === '1') {
    html += '<div style="margin-bottom:12px;padding:10px 14px;border:2px solid var(--red);border-radius:10px;background:var(--red-soft)">';
    html += '<div style="font-weight:700;color:var(--red);font-size:13px">🗑 Löschung vorgemerkt</div>';
    html += '<div class="small" style="color:var(--muted);margin-top:2px">Verwalten unter <a href="<?=h($base)?>/admin/geloeschte.php" style="color:var(--red)">Gelöschte Einträge</a></div>';
    html += '</div>';
  }
  <?php if ($canEdit): ?>
  var btns = '<div style="display:flex;flex-wrap:wrap;gap:8px;padding-top:12px;border-top:1px solid var(--border)">';
  btns += '<button class="btn primary" type="button" data-open-dialog="dialog-edit-'+id+'">Bearbeiten</button>';
    if (d.dataset.active !== '1') {
      btns += '<form method="post" style="display:contents"><input type="hidden" name="csrf" value=""><input type="hidden" name="action" value="to_song"><input type="hidden" name="pid" value="'+id+'"><button class="btn" type="submit">→ Abstimmung</button></form>';
    } else {
      btns += '<button class="btn" type="button" disabled style="opacity:.5;cursor:default">🎵 Bereits aktiv</button>';
    }
    if (!d.dataset.loanedTo) {
      btns += '<button class="btn" type="button" data-open-dialog="dialog-loan-'+id+'">📦 Verleihen</button>';
    } else {
      btns += '<form method="post" style="display:contents" onsubmit="return confirm(\'Rückgabe bestätigen?\')"><input type="hidden" name="csrf" value=""><input type="hidden" name="action" value="loan_return"><input type="hidden" name="pid" value="'+id+'"><button class="btn" type="submit" style="color:var(--green)">📦 Rückgabe</button></form>';
    }
    <?php if ($isAdmin): ?>
    btns += '<form method="post" style="display:contents" onsubmit="return confirm(\'Stück wirklich löschen?\')"><input type="hidden" name="csrf" value=""><input type="hidden" name="action" value="delete"><input type="hidden" name="pid" value="'+id+'"><button class="btn" type="submit" style="color:var(--red)">🗑 Löschen</button></form>';
    <?php else: ?>
    btns += '<button class="btn" type="button" data-open-dialog="dialog-softdel-'+id+'" style="color:var(--red)">🗑 Löschen</button>';
    <?php endif; ?>
  btns += '</div>';
  html += btns;
  <?php else: ?>
  html += '<div style="display:flex;flex-wrap:wrap;gap:8px;padding-top:12px;border-top:1px solid var(--border)">';
  html += '<button class="btn primary" type="button" data-open-dialog="dialog-suggest-'+id+'">✏️ Änderung vorschlagen</button>';
  html += '</div>';
  <?php endif; ?>
  document.getElementById('detail-panel').innerHTML = html;
  // Re-fill CSRF
  var csrfMaster = document.querySelector('input[name="csrf"]');
  document.querySelectorAll('#detail-panel input[name="csrf"]').forEach(function(i){ if(csrfMaster) i.value=csrfMaster.value; });
}

function openDetailById(id) {
  // Switch to detail view if not already
  setView('detail');
  // Find and click the row
  var row = document.querySelector('.detail-list-row[data-id="'+id+'"]');
  if (row) { row.scrollIntoView({block:'nearest'}); showDetail(row); }
}

function escH(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

document.addEventListener('DOMContentLoaded', function(){ setView(currentView); });
</script>

<script>
function bibCol(name, show) {
  document.querySelectorAll('.bib-col-'+name).forEach(function(el){
    el.classList.toggle('col-hidden', !show);
  });
  var p = JSON.parse(localStorage.getItem('bib_cols')||'{}');
  p[name]=show; localStorage.setItem('bib_cols', JSON.stringify(p));
}
</script>
<script>
function bibFilter() {
  var inp   = document.getElementById('bib-live-q');
  var tbody = document.getElementById('bib-tbody');
  if (!tbody) return;
  var q = inp ? inp.value.toLowerCase().trim() : '';
  var rows = tbody.querySelectorAll('tr[data-search]');
  var vis = 0;
  rows.forEach(function(tr) {
    var ok = !q || tr.dataset.search.indexOf(q) !== -1;
    tr.style.display = ok ? '' : 'none';
    if (ok) vis++;
  });
  var ct = document.getElementById('bib-count');
  if (ct) ct.textContent = vis + ' Titel';
}
(function(){
  var inp = document.getElementById('bib-live-q');
  if (!inp) return;
  inp.addEventListener('input', bibFilter);
  inp.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); bibFilter(); }
  });
  bibFilter();
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
    var pidInput = form.querySelector('input[name="pid"]');
    var excludeId = pidInput ? pidInput.value : '0';

    var url = base + '/api/check_duplicate.php?title=' + encodeURIComponent(title)
            + '&arranger=' + encodeURIComponent(arranger)
            + '&exclude_table=pieces&exclude_id=' + excludeId;

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

  // Reset beim Schließen eines Dialogs
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
<?php sv_footer(); ?>
