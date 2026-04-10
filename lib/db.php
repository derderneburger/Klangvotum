<?php
function sv_config(): array {
  static $cfg = null;
  if ($cfg === null) {
    $base = __DIR__ . '/..';
    if (file_exists($base . '/configv2.php')) {
      $cfg = require $base . '/configv2.php';
    } elseif (file_exists($base . '/configlive.php')) {
      $cfg = require $base . '/configlive.php';
    } else {
      throw new RuntimeException('Keine Konfigurationsdatei gefunden (configv2.php oder configlive.php).');
    }
  }
  return $cfg;
}

function sv_pdo(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $c = sv_config()['db'];
  $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $c['host'], $c['name'], $c['charset']);
  $pdo = new PDO($dsn, $c['user'], $c['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
  sv_ensure_schema($pdo);
  return $pdo;
}


function sv_ensure_schema(PDO $pdo): void {

  // ── Bestehende Hilfstabellen ──────────────────────────────────────────────
  $pdo->exec("CREATE TABLE IF NOT EXISTS user_activity (
      user_id INT NOT NULL,
      last_activity DATETIME NOT NULL,
      last_ip VARCHAR(45) NULL,
      last_user_agent VARCHAR(255) NULL,
      PRIMARY KEY (user_id),
      KEY idx_last_activity (last_activity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
      username VARCHAR(190) NOT NULL,
      ip VARCHAR(45) NOT NULL,
      attempts INT NOT NULL DEFAULT 0,
      first_attempt DATETIME NOT NULL,
      locked_until DATETIME NULL,
      PRIMARY KEY (username, ip),
      KEY idx_locked_until (locked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // ── v2: users.role ────────────────────────────────────────────────────────
  // Spalte role hinzufügen falls noch nicht vorhanden
  $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetchAll();
  if (empty($cols)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('user','leitung','admin') NOT NULL DEFAULT 'user' AFTER is_admin");
    // Migration: is_admin=1 → role=admin, sonst role=user
    $pdo->exec("UPDATE users SET role = CASE WHEN is_admin = 1 THEN 'admin' ELSE 'user' END");
  } else {
    // chroniker aus ENUM entfernen falls noch drin (Umbau auf has_chronik Flag)
    $colDef = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if ($colDef && strpos($colDef['Type'], 'chroniker') !== false) {
      // Eventuell vorhandene chroniker-User auf user zurücksetzen
      $pdo->exec("UPDATE users SET role = 'user' WHERE role = 'chroniker'");
      $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('user','leitung','admin') NOT NULL DEFAULT 'user'");
    }
  }

  // ── v2: users.has_chronik — Zusatzrecht Chronik-Bearbeitung ───────────────
  $userCols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(), 'Field');
  if (!in_array('has_chronik', $userCols)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN has_chronik TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
  }

  // ── v2: users.has_noten — Zusatzrecht Noten/Bibliothek-Bearbeitung ────────
  if (!in_array('has_noten', $userCols)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN has_noten TINYINT(1) NOT NULL DEFAULT 0 AFTER has_chronik");
  }

  // ── v2: songs — neue Felder ───────────────────────────────────────────────
  $songCols = array_column($pdo->query("SHOW COLUMNS FROM songs")->fetchAll(), 'Field');
  if (!in_array('piece_id', $songCols)) {
    $pdo->exec("ALTER TABLE songs ADD COLUMN piece_id INT NULL AFTER is_active");
  }
  if (!in_array('composer', $songCols)) {
    $pdo->exec("ALTER TABLE songs ADD COLUMN composer VARCHAR(255) NULL AFTER piece_id");
  }
  if (!in_array('arranger', $songCols)) {
    $pdo->exec("ALTER TABLE songs ADD COLUMN arranger VARCHAR(255) NULL AFTER composer");
  }
  if (!in_array('publisher', $songCols)) {
    $pdo->exec("ALTER TABLE songs ADD COLUMN publisher VARCHAR(255) NULL AFTER arranger");
  }
  if (!in_array('duration', $songCols)) {
    $pdo->exec("ALTER TABLE songs ADD COLUMN duration VARCHAR(20) NULL AFTER publisher");
  }
  if (!in_array('genre', $songCols)) {
    $pdo->exec("ALTER TABLE songs ADD COLUMN genre VARCHAR(100) NULL AFTER duration");
  }
  if (!in_array('difficulty', $songCols)) {
    $pdo->exec("ALTER TABLE songs ADD COLUMN difficulty DECIMAL(3,1) NULL AFTER publisher");
  }
  if (!in_array('shop_url', $songCols)) {
    $pdo->exec("ALTER TABLE songs ADD COLUMN shop_url VARCHAR(512) NULL AFTER difficulty");
  }
  if (!in_array('shop_price', $songCols)) {
    $pdo->exec("ALTER TABLE songs ADD COLUMN shop_price DECIMAL(8,2) NULL AFTER shop_url");
  }
  if (!in_array('info', $songCols)) {
    $pdo->exec("ALTER TABLE songs ADD COLUMN info TEXT NULL AFTER shop_price");
  }
  // Soft-Delete-Spalten
  if (!in_array('deleted_at',    $songCols)) $pdo->exec("ALTER TABLE songs ADD COLUMN deleted_at    DATETIME     NULL");
  if (!in_array('deleted_by',    $songCols)) $pdo->exec("ALTER TABLE songs ADD COLUMN deleted_by    INT          NULL");
  if (!in_array('delete_reason', $songCols)) $pdo->exec("ALTER TABLE songs ADD COLUMN delete_reason VARCHAR(255) NULL");

  // ── v2: pieces — Notenbibliothek ──────────────────────────────────────────
  $pdo->exec("CREATE TABLE IF NOT EXISTS pieces (
      id               INT NOT NULL AUTO_INCREMENT,
      title            VARCHAR(255) NOT NULL,
      youtube_url      VARCHAR(512) NULL,
      composer         VARCHAR(255) NULL,
      arranger         VARCHAR(255) NULL,
      publisher        VARCHAR(255) NULL,
      duration         VARCHAR(20)  NULL,
      difficulty       DECIMAL(3,1) NULL,
      genre            VARCHAR(100) NULL,
      owner            VARCHAR(100) NULL,
      has_scan         TINYINT(1)   NOT NULL DEFAULT 0,
      has_score_scan   TINYINT(1)   NOT NULL DEFAULT 0,
      has_original_score TINYINT(1) NOT NULL DEFAULT 0,
      folder_number    INT          NULL,
      binder           VARCHAR(10)  NULL,
      shop_url         VARCHAR(512) NULL,
      shop_price       DECIMAL(8,2) NULL,
      info             TEXT         NULL,
      notes            TEXT         NULL,
      created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_pieces_title_arr (title, arranger(100)),
      KEY idx_pieces_composer (composer(50))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // ── v2: concerts — Auftrittskronik ────────────────────────────────────────
  $pdo->exec("CREATE TABLE IF NOT EXISTS concerts (
      id         INT NOT NULL AUTO_INCREMENT,
      name       VARCHAR(255) NOT NULL,
      date       DATE         NULL,
      location   VARCHAR(255) NULL,
      notes      TEXT         NULL,
      created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_concerts_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  // Add year column if missing
  $concertCols = array_column($pdo->query("SHOW COLUMNS FROM concerts")->fetchAll(), 'Field');
  if (!in_array('year',       $concertCols)) $pdo->exec("ALTER TABLE concerts ADD COLUMN year       SMALLINT NULL AFTER name");
  if (!in_array('sort_order', $concertCols)) $pdo->exec("ALTER TABLE concerts ADD COLUMN sort_order INT NULL AFTER year");
  // Soft-Delete-Spalten
  if (!in_array('deleted_at',    $concertCols)) $pdo->exec("ALTER TABLE concerts ADD COLUMN deleted_at    DATETIME     NULL");
  if (!in_array('deleted_by',    $concertCols)) $pdo->exec("ALTER TABLE concerts ADD COLUMN deleted_by    INT          NULL");
  if (!in_array('delete_reason', $concertCols)) $pdo->exec("ALTER TABLE concerts ADD COLUMN delete_reason VARCHAR(255) NULL");

  // ── v2: pieces — UNIQUE KEY auf title+arranger ändern ──────────────────────
  try {
    // Alten title-only unique key entfernen falls vorhanden
    $idxList = $pdo->query("SHOW INDEX FROM pieces WHERE Key_name = 'uq_pieces_title'")->fetchAll();
    if ($idxList) $pdo->exec("ALTER TABLE pieces DROP INDEX uq_pieces_title");
    // Neuen title+arranger unique key hinzufügen falls nicht vorhanden
    $newIdx = $pdo->query("SHOW INDEX FROM pieces WHERE Key_name = 'uq_pieces_title_arr'")->fetchAll();
    if (!$newIdx) $pdo->exec("ALTER TABLE pieces ADD UNIQUE KEY uq_pieces_title_arr (title, arranger(100))");
  } catch (Throwable $e) {}

  // ── v2: pieces — neue Felder für bestehende Installationen ──────────────────
  $pieceCols = array_column($pdo->query("SHOW COLUMNS FROM pieces")->fetchAll(), 'Field');
  if (!in_array('youtube_url',$pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN youtube_url VARCHAR(512) NULL AFTER title");
  if (!in_array('duration',   $pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN duration   VARCHAR(20)  NULL AFTER publisher");
  if (!in_array('genre',      $pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN genre      VARCHAR(100) NULL AFTER duration");
  if (!in_array('shop_url',   $pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN shop_url   VARCHAR(512) NULL AFTER binder");
  if (!in_array('shop_price', $pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN shop_price DECIMAL(8,2) NULL AFTER shop_url");
  if (!in_array('info',        $pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN info        TEXT         NULL AFTER shop_price");
  if (!in_array('querverweis', $pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN querverweis VARCHAR(255) NULL AFTER info");
  if (!in_array('loaned_to',   $pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN loaned_to   VARCHAR(255) NULL AFTER notes");
  if (!in_array('loaned_at',   $pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN loaned_at   DATE         NULL AFTER loaned_to");
  if (!in_array('loaned_note', $pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN loaned_note VARCHAR(255) NULL AFTER loaned_at");
  // Soft-Delete-Spalten
  if (!in_array('deleted_at',    $pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN deleted_at    DATETIME     NULL");
  if (!in_array('deleted_by',    $pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN deleted_by    INT          NULL");
  if (!in_array('delete_reason', $pieceCols)) $pdo->exec("ALTER TABLE pieces ADD COLUMN delete_reason VARCHAR(255) NULL");

  // ── v2: piece_loans — Ausleih-Verlaufslog ─────────────────────────────────
  $pdo->exec("CREATE TABLE IF NOT EXISTS piece_loans (
      id          INT NOT NULL AUTO_INCREMENT,
      piece_id    INT NOT NULL,
      loaned_to   VARCHAR(255) NOT NULL,
      loaned_at   DATE NOT NULL,
      loaned_note VARCHAR(255) NULL,
      loaned_by   INT NOT NULL,
      returned_at DATE NULL,
      returned_by INT NULL,
      created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_ploans_piece (piece_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // ── v2: piece_suggestions — Änderungsvorschläge für Bibliothek ─────────────
  $pdo->exec("CREATE TABLE IF NOT EXISTS piece_suggestions (
      id          INT NOT NULL AUTO_INCREMENT,
      piece_id    INT NOT NULL,
      user_id     INT NOT NULL,
      changes     TEXT NOT NULL,
      status      ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
      reviewed_by INT NULL,
      reviewed_at DATETIME NULL,
      created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_psugg_piece (piece_id),
      KEY idx_psugg_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


  // ── v2: vote_history — historische Stimmen nach Archivierung ────────────────
  $pdo->exec("CREATE TABLE IF NOT EXISTS vote_history (
      id          INT NOT NULL AUTO_INCREMENT,
      user_id     INT NOT NULL,
      piece_id    INT NOT NULL,
      vote        ENUM('ja','nein','neutral') NOT NULL,
      note        TEXT NULL,
      archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      UNIQUE KEY uq_vh_user_piece (user_id, piece_id),
      KEY idx_vh_piece (piece_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // ── v2: concert_pieces — Verknüpfung Auftritt ↔ Stück ────────────────────
  $pdo->exec("CREATE TABLE IF NOT EXISTS concert_pieces (
      id         INT NOT NULL AUTO_INCREMENT,
      concert_id INT NOT NULL,
      piece_id   INT NOT NULL,
      position   INT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_concert_piece (concert_id, piece_id),
      KEY idx_cp_concert (concert_id),
      KEY idx_cp_piece   (piece_id),
      CONSTRAINT fk_cp_concert FOREIGN KEY (concert_id) REFERENCES concerts (id) ON DELETE CASCADE,
      CONSTRAINT fk_cp_piece   FOREIGN KEY (piece_id)   REFERENCES pieces   (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // ── v2: concert_plans — Gespeicherte Konzertplaene ──────────────────────────
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS concert_plans (
        id          INT NOT NULL AUTO_INCREMENT,
        name        VARCHAR(255) NOT NULL,
        variant     VARCHAR(100) NOT NULL DEFAULT 'A',
        user_id     INT NOT NULL,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_cplans_user (user_id),
        KEY idx_cplans_name (name)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── v2: concert_plan_items — Eintraege in einem Konzertplan ─────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS concert_plan_items (
        id                INT NOT NULL AUTO_INCREMENT,
        plan_id           INT NOT NULL,
        position          INT NOT NULL,
        item_type         ENUM('piece','block','halftime') NOT NULL DEFAULT 'piece',
        piece_id          INT NULL,
        source            ENUM('song','piece') NULL,
        label             VARCHAR(255) NULL,
        duration_override VARCHAR(20) NULL,
        PRIMARY KEY (id),
        KEY idx_cpitems_plan (plan_id),
        CONSTRAINT fk_cpitems_plan FOREIGN KEY (plan_id) REFERENCES concert_plans (id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Throwable $e) {}

  // ── App-Einstellungen ────────────────────────────────────────────────────
  $pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
      setting_key   VARCHAR(100) NOT NULL,
      setting_value TEXT,
      PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // ── v2: Tags — ersetzt Genre-Textfeld ──────────────────────────────────
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tags (
        id   INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_tags_name (name)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // piece_tags: erst ohne FK versuchen falls Engine/Typ-Mismatch
    $pdo->exec("CREATE TABLE IF NOT EXISTS piece_tags (
        piece_id INT NOT NULL,
        tag_id   INT NOT NULL,
        PRIMARY KEY (piece_id, tag_id),
        KEY idx_pt_tag (tag_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS song_tags (
        song_id INT NOT NULL,
        tag_id  INT NOT NULL,
        PRIMARY KEY (song_id, tag_id),
        KEY idx_st_tag (tag_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // FK-Constraints nachträglich hinzufügen (ignoriert Fehler bei Engine/Typ-Mismatch)
    try { $pdo->exec("ALTER TABLE piece_tags ADD CONSTRAINT fk_pt_piece FOREIGN KEY (piece_id) REFERENCES pieces (id) ON DELETE CASCADE"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE piece_tags ADD CONSTRAINT fk_pt_tag   FOREIGN KEY (tag_id)   REFERENCES tags   (id) ON DELETE CASCADE"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE song_tags  ADD CONSTRAINT fk_st_song  FOREIGN KEY (song_id)  REFERENCES songs  (id) ON DELETE CASCADE"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE song_tags  ADD CONSTRAINT fk_st_tag   FOREIGN KEY (tag_id)   REFERENCES tags   (id) ON DELETE CASCADE"); } catch (Throwable $e) {}
  } catch (Throwable $e) {}

  // Migration: bestehende Genre-Texte in Tags umwandeln
  try {
    $migrated = $pdo->query("SELECT COUNT(*) FROM tags")->fetchColumn();
    if ((int)$migrated === 0) {
      // Alle Genre-Werte aus pieces und songs sammeln
      $genres = [];
      foreach ($pdo->query("SELECT id, genre FROM pieces WHERE genre IS NOT NULL AND genre != ''")->fetchAll() as $r) {
        $genres['piece'][$r['id']] = $r['genre'];
      }
      foreach ($pdo->query("SELECT id, genre FROM songs WHERE genre IS NOT NULL AND genre != ''")->fetchAll() as $r) {
        $genres['song'][$r['id']] = $r['genre'];
      }
      // Tags anlegen und zuordnen
      $tagCache = [];
      $insertTag = $pdo->prepare("INSERT IGNORE INTO tags (name) VALUES (?)");
      $insertPT  = $pdo->prepare("INSERT IGNORE INTO piece_tags (piece_id, tag_id) VALUES (?, ?)");
      $insertST  = $pdo->prepare("INSERT IGNORE INTO song_tags (song_id, tag_id) VALUES (?, ?)");
      $findTag   = $pdo->prepare("SELECT id FROM tags WHERE name = ?");
      foreach (['piece', 'song'] as $type) {
        foreach ($genres[$type] ?? [] as $entityId => $genreStr) {
          $parts = preg_split('/\s*\/\s*/', trim($genreStr));
          foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if (!isset($tagCache[$part])) {
              $insertTag->execute([$part]);
              $findTag->execute([$part]);
              $tagCache[$part] = (int)$findTag->fetchColumn();
            }
            if ($type === 'piece') {
              $insertPT->execute([$entityId, $tagCache[$part]]);
            } else {
              $insertST->execute([$entityId, $tagCache[$part]]);
            }
          }
        }
      }
    }
  } catch (Throwable $e) {}
}

function sv_base_url(): string {
  $cfg = sv_config();
  if (!empty($cfg['base_url'])) return rtrim($cfg['base_url'], '/');
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
  $scheme = $https ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $path = rtrim(str_replace(basename($script), '', $script), '/');
  return $scheme . '://' . $host . $path;
}

function sv_log(?int $user_id, string $action, ?string $details = null): void {
  try {
    $pdo = sv_pdo();
    $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $action, $details]);
  } catch (Throwable $e) {}
}

function sv_is_frozen(): bool {
  try {
    $pdo = sv_pdo();
    $stmt = $pdo->query("SELECT action FROM audit_log WHERE action IN ('freeze_on','freeze_off') ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    if (!$row) return false;
    return $row['action'] === 'freeze_on';
  } catch (Throwable $e) {
    return false;
  }
}

function sv_set_frozen(int $admin_user_id, bool $on): void {
  sv_log($admin_user_id, $on ? 'freeze_on' : 'freeze_off', null);
}

// ── App-Einstellungen ────────────────────────────────────────────────────────

function sv_setting_get(string $key, $default = null) {
  static $cache = null;
  if ($cache === null) {
    try {
      $rows = sv_pdo()->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll();
      $cache = [];
      foreach ($rows as $r) { $cache[$r['setting_key']] = $r['setting_value']; }
    } catch (Throwable $e) { $cache = []; }
  }
  return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function sv_setting_set(string $key, string $value): void {
  sv_pdo()->prepare(
    "INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
  )->execute([$key, $value]);
}

function sv_setting_delete(string $key): void {
  sv_pdo()->prepare("DELETE FROM app_settings WHERE setting_key = ?")->execute([$key]);
}

function sv_settings_all(): array {
  try {
    $rows = sv_pdo()->query("SELECT setting_key, setting_value FROM app_settings")->fetchAll();
    $out = [];
    foreach ($rows as $r) { $out[$r['setting_key']] = $r['setting_value']; }
    return $out;
  } catch (Throwable $e) { return []; }
}

/**
 * Berechnet --green-light und --green-mid aus einem Hex-Farbwert.
 * green-light: Farbe auf weißem Grund bei 14 % Deckkraft
 * green-mid:   rgba mit 0.28 Deckkraft
 */
function sv_color_variants(string $hex): array {
  $light = sv_color_mix($hex, '#ffffff', 0.10);
  $mid   = sv_color_mix($hex, '#ffffff', 0.35);
  return [$light, $mid];
}

function sv_color_mix(string $hex, string $base, float $amount): string {
  $hex = ltrim($hex, '#');
  if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
  $base = ltrim($base, '#');
  if (strlen($base) === 3) { $base = $base[0].$base[0].$base[1].$base[1].$base[2].$base[2]; }
  $r = (int)round(hexdec(substr($hex, 0, 2)) * $amount + hexdec(substr($base, 0, 2)) * (1 - $amount));
  $g = (int)round(hexdec(substr($hex, 2, 2)) * $amount + hexdec(substr($base, 2, 2)) * (1 - $amount));
  $b = (int)round(hexdec(substr($hex, 4, 2)) * $amount + hexdec(substr($base, 4, 2)) * (1 - $amount));
  return sprintf('#%02x%02x%02x', max(0,min(255,$r)), max(0,min(255,$g)), max(0,min(255,$b)));
}

function sv_color_darken(string $hex, float $factor = 0.15): string {
  $hex = ltrim($hex, '#');
  if (strlen($hex) === 3) {
    $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
  }
  $r = (int)round(hexdec(substr($hex, 0, 2)) * (1 - $factor));
  $g = (int)round(hexdec(substr($hex, 2, 2)) * (1 - $factor));
  $b = (int)round(hexdec(substr($hex, 4, 2)) * (1 - $factor));
  return sprintf('#%02x%02x%02x', max(0,$r), max(0,$g), max(0,$b));
}

function sv_color_contrast(string $hex): string {
  $hex = ltrim($hex, '#');
  if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
  $r = hexdec(substr($hex, 0, 2)) / 255;
  $g = hexdec(substr($hex, 2, 2)) / 255;
  $b = hexdec(substr($hex, 4, 2)) / 255;
  $r = $r <= 0.03928 ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
  $g = $g <= 0.03928 ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
  $b = $b <= 0.03928 ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);
  $lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
  return $lum > 0.35 ? '#1a1a18' : '#ffffff';
}

function sv_diff_style(float $d): string {
  $map = [
    '0.5' => ['bg' => '#F7F7F8', 'color' => '#707784', 'border' => '#D8DCE2'],
    '1.0' => ['bg' => '#F1F3F5', 'color' => '#5F6F80', 'border' => '#CDD5DE'],
    '1.5' => ['bg' => '#EEF7F0', 'color' => '#5A7E61', 'border' => '#C8E0CD'],
    '2.0' => ['bg' => '#DFF5E3', 'color' => '#31733F', 'border' => '#9FD5AA'],
    '2.5' => ['bg' => '#CBF0D2', 'color' => '#246A38', 'border' => '#77C68A'],
    '3.0' => ['bg' => '#C2ECBA', 'color' => '#1A6B28', 'border' => '#4DB860'],
    '3.5' => ['bg' => '#EDF8C9', 'color' => '#61750C', 'border' => '#B5D63C'],
    '4.0' => ['bg' => '#FFF1B8', 'color' => '#8A6900', 'border' => '#E5BF1F'],
    '4.5' => ['bg' => '#FFE0C2', 'color' => '#9B5315', 'border' => '#F39A49'],
    '5.0' => ['bg' => '#FFD9D2', 'color' => '#A93A2B', 'border' => '#EA6C5A'],
    '5.5' => ['bg' => '#F9CED7', 'color' => '#9C2F53', 'border' => '#DC5E7C'],
    '6.0' => ['bg' => '#F2C0CC', 'color' => '#8B1A3A', 'border' => '#CC4060'],
  ];
  $rounded = number_format(max(0.5, min(6.0, round($d * 2) / 2)), 1);
  $c = $map[$rounded] ?? $map['3.0'];
  return "background:{$c['bg']};color:{$c['color']};border-color:{$c['border']}";
}

function sv_diff_pill(mixed $d): string {
  if ($d === null || $d === '') return '<span class="small" style="color:#bbb">–</span>';
  $d = (float)$d;
  return '<span class="badge" style="' . sv_diff_style($d) . '">' . number_format($d, 1) . '</span>';
}

// ── Tag-Helpers ──────────────────────────────────────────────────────────────

function sv_all_tags(): array {
  try {
    return sv_pdo()->query("SELECT id, name FROM tags ORDER BY name ASC")->fetchAll();
  } catch (Throwable $e) { return []; }
}

function sv_tags_for_piece(int $pieceId): array {
  try {
    $stmt = sv_pdo()->prepare("SELECT t.name FROM tags t JOIN piece_tags pt ON pt.tag_id=t.id WHERE pt.piece_id=? ORDER BY t.name");
    $stmt->execute([$pieceId]);
    return array_column($stmt->fetchAll(), 'name');
  } catch (Throwable $e) { return []; }
}

function sv_tags_for_song(int $songId): array {
  try {
    $stmt = sv_pdo()->prepare("SELECT t.name FROM tags t JOIN song_tags st ON st.tag_id=t.id WHERE st.song_id=? ORDER BY t.name");
    $stmt->execute([$songId]);
    return array_column($stmt->fetchAll(), 'name');
  } catch (Throwable $e) { return []; }
}

function sv_tags_for_pieces(array $pieceIds): array {
  if (!$pieceIds) return [];
  try {
    $ph = implode(',', array_map('intval', $pieceIds));
    $rows = sv_pdo()->query("SELECT pt.piece_id, t.name FROM tags t JOIN piece_tags pt ON pt.tag_id=t.id WHERE pt.piece_id IN ($ph) ORDER BY t.name")->fetchAll();
    $map = [];
    foreach ($rows as $r) $map[(int)$r['piece_id']][] = $r['name'];
    return $map;
  } catch (Throwable $e) { return []; }
}

function sv_tags_for_songs(array $songIds): array {
  if (!$songIds) return [];
  try {
    $ph = implode(',', array_map('intval', $songIds));
    $rows = sv_pdo()->query("SELECT st.song_id, t.name FROM tags t JOIN song_tags st ON st.tag_id=t.id WHERE st.song_id IN ($ph) ORDER BY t.name")->fetchAll();
    $map = [];
    foreach ($rows as $r) $map[(int)$r['song_id']][] = $r['name'];
    return $map;
  } catch (Throwable $e) { return []; }
}

function sv_sync_tags(string $type, int $entityId, array $tagNames): void {
  try {
    $pdo = sv_pdo();
    $junction = $type === 'piece' ? 'piece_tags' : 'song_tags';
    $fk       = $type === 'piece' ? 'piece_id'   : 'song_id';

    // Bestehende Tags entfernen
    $pdo->prepare("DELETE FROM $junction WHERE $fk = ?")->execute([$entityId]);

    if (!$tagNames) return;

    $insertTag = $pdo->prepare("INSERT IGNORE INTO tags (name) VALUES (?)");
    $findTag   = $pdo->prepare("SELECT id FROM tags WHERE name = ?");
    $insertJunction = $pdo->prepare("INSERT IGNORE INTO $junction ($fk, tag_id) VALUES (?, ?)");

    foreach ($tagNames as $name) {
      $name = trim($name);
      if ($name === '') continue;
      $insertTag->execute([$name]);
      $findTag->execute([$name]);
      $tagId = (int)$findTag->fetchColumn();
      if ($tagId) $insertJunction->execute([$entityId, $tagId]);
    }
  } catch (Throwable $e) {}
}

function sv_tag_badges(array $tagNames): string {
  if (!$tagNames) return '<span class="small" style="color:#bbb">–</span>';
  return implode(' ', array_map(function($t) {
    return '<span class="badge">' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '</span>';
  }, $tagNames));
}

function sv_tag_widget(array $allTags, array $selectedTags, string $fieldName = 'tags'): string {
  $canDelete = false;
  try { $u = sv_current_user(); $canDelete = $u && sv_can_edit_noten($u); } catch (Throwable $e) {}
  $uid = 'tw' . mt_rand(1000,9999);
  ob_start();
  ?>
  <fieldset class="genre-widget" id="<?=$uid?>" style="border:1px solid var(--border);border-radius:8px;padding:10px;margin:0">
    <legend style="font-weight:600;font-size:14px;padding:0 6px">Genre</legend>
    <div class="genre-chips" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;min-height:24px">
      <?php foreach ($selectedTags as $t): ?>
      <span class="genre-chip badge" style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;font-size:12px">
        <?=htmlspecialchars($t, ENT_QUOTES, 'UTF-8')?>
        <input type="hidden" name="<?=htmlspecialchars($fieldName, ENT_QUOTES)?>[]" value="<?=htmlspecialchars($t, ENT_QUOTES, 'UTF-8')?>">
        <span onclick="this.parentElement.remove();svGenreRefresh(this)" style="cursor:pointer;font-weight:700;line-height:1;opacity:.6">&times;</span>
      </span>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
      <select class="genre-dropdown" onchange="svGenreAdd(this)" style="flex:1;padding:4px 8px;font-size:13px;border:1px solid var(--border);border-radius:6px;min-width:120px;background:#fff">
        <option value="">Genre wählen…</option>
        <?php foreach ($allTags as $tag): ?>
        <option value="<?=htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8')?>" data-tag-id="<?=(int)$tag['id']?>"<?=in_array($tag['name'], $selectedTags) ? ' disabled' : ''?>><?=htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8')?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" class="tag-new-input" placeholder="Neues Genre…" style="width:130px;padding:4px 8px;font-size:13px;border:1px solid var(--border);border-radius:6px">
      <button type="button" class="btn" style="padding:4px 10px;font-size:12px" onclick="svAddNewTag(this)">+</button>
      <?php if ($canDelete && $allTags): ?>
      <span style="border-left:1px solid var(--border);height:24px;margin:0 2px"></span>
      <select class="genre-delete-dropdown" style="width:auto;padding:4px 8px;font-size:12px;border:1px solid var(--border);border-radius:6px;background:#fff;color:var(--red)">
        <option value="">🗑 Löschen…</option>
        <?php foreach ($allTags as $tag): ?>
        <option value="<?=(int)$tag['id']?>"><?=htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8')?></option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="btn" style="padding:4px 10px;font-size:12px;color:var(--red)" onclick="svDeleteTagConfirm(this)" title="Ausgewähltes Genre global löschen">🗑</button>
      <?php endif; ?>
    </div>
  </fieldset>
  <?php
  return ob_get_clean();
}
