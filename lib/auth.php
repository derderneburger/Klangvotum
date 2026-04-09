<?php


// Dauer normalisieren: "03:30", "3:30", "3'30" → "3'30"
function sv_normalize_duration(string $d): string {
  $d = trim($d);
  if ($d === '') return '';
  // Format mit Apostroph: 4'30 oder 4'
  if (preg_match("/^(\d+)'(\d*)$/", $d, $m)) {
    $sec = $m[2] !== '' ? str_pad($m[2], 2, '0', STR_PAD_LEFT) : '00';
    return ((int)$m[1]) . ':' . $sec;
  }
  // Format mit Doppelpunkt: 03:30 oder 3:30 → normalisieren (keine führende 0 bei Minuten)
  if (preg_match('/^(\d{1,2}):(\d{2})$/', $d, $m)) {
    return ((int)$m[1]) . ':' . $m[2];
  }
  // Nur Minuten: "6" → "6:00"
  if (preg_match('/^\d+$/', $d)) {
    return ((int)$d) . ':00';
  }
  return $d; // unbekannt, unverändert lassen
}

// PHP 7.4 polyfills
if (!function_exists('sv_str_contains'))    { function sv_str_contains($h,$n)    { return $n===''||strpos($h,$n)!==false; } }
if (!function_exists('sv_str_starts_with')) { function sv_str_starts_with($h,$n) { return strncmp($h,$n,strlen($n))===0; } }
if (!function_exists('sv_str_ends_with'))   { function sv_str_ends_with($h,$n)   { return $n===''||substr($h,-strlen($n))===$n; } }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/backup_helper.php';

function sv_start_session(): void {
  $cfg = sv_config();
  if (session_status() === PHP_SESSION_ACTIVE) return;
  session_name($cfg['session_name'] ?? 'songvote_session');
  $params = session_get_cookie_params();
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => $params['path'] ?? '/',
    'domain' => $params['domain'] ?? '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}


function sv_client_ip(): string {
  return trim($_SERVER['REMOTE_ADDR'] ?? '');
}

function sv_touch_activity(int $user_id): void {
  sv_start_session();
  $_SESSION['LAST_ACTIVITY'] = time();
  try {
    $pdo = sv_pdo();
    $stmt = $pdo->prepare("INSERT INTO user_activity (user_id, last_activity, last_ip, last_user_agent)
      VALUES (?, NOW(), ?, ?)
      ON DUPLICATE KEY UPDATE last_activity=VALUES(last_activity), last_ip=VALUES(last_ip), last_user_agent=VALUES(last_user_agent)");
    $stmt->execute([$user_id, sv_client_ip(), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
  } catch (Throwable $e) {}
}

function sv_session_timeout_seconds(): int {
  $cfg = sv_config();
  // default: 60 minutes
  return (int)($cfg['session_timeout_seconds'] ?? 3600);
}

function sv_session_is_expired(): bool {
  sv_start_session();
  if (empty($_SESSION['uid'])) return false;
  $last = (int)($_SESSION['LAST_ACTIVITY'] ?? 0);
  if ($last <= 0) return false;
  return (time() - $last) > sv_session_timeout_seconds();
}

function sv_login_is_locked(string $username): bool {
  $u = trim(mb_strtolower($username));
  if ($u === '') return false;
  try {
    $pdo = sv_pdo();
    // cleanup old rows (older than 2 days)
    $pdo->exec("DELETE FROM login_attempts WHERE first_attempt < (NOW() - INTERVAL 2 DAY)");
    $stmt = $pdo->prepare("SELECT locked_until FROM login_attempts WHERE username = ? AND ip = ?");
    $stmt->execute([$u, sv_client_ip()]);
    $row = $stmt->fetch();
    if (!$row || empty($row['locked_until'])) return false;
    return strtotime($row['locked_until']) > time();
  } catch (Throwable $e) { return false; }
}

function sv_register_login_attempt(string $username, bool $success): void {
  $u = trim(mb_strtolower($username));
  if ($u === '') return;
  try {
    $pdo = sv_pdo();
    $ip = sv_client_ip();
    if ($success) {
      $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE username=? AND ip=?");
      $stmt->execute([$u, $ip]);
      return;
    }
    // failure: increment attempts (rolling 15-min window)
    $stmt = $pdo->prepare("SELECT attempts, first_attempt, locked_until FROM login_attempts WHERE username=? AND ip=?");
    $stmt->execute([$u, $ip]);
    $row = $stmt->fetch();
    if (!$row) {
      $stmt = $pdo->prepare("INSERT INTO login_attempts (username, ip, attempts, first_attempt, locked_until) VALUES (?, ?, 1, NOW(), NULL)");
      $stmt->execute([$u, $ip]);
      return;
    }

    $first = strtotime($row['first_attempt']) ?: time();
    $attempts = (int)$row['attempts'];
    if ((time() - $first) > 15*60) {
      // reset window
      $attempts = 0;
      $first = time();
      $stmt = $pdo->prepare("UPDATE login_attempts SET attempts=0, first_attempt=NOW(), locked_until=NULL WHERE username=? AND ip=?");
      $stmt->execute([$u, $ip]);
    }

    $attempts++;
    $lockedUntil = null;
    if ($attempts >= 5) {
      $lockedUntil = date('Y-m-d H:i:s', time() + 10*60); // 10 min lock
    }
    $stmt = $pdo->prepare("UPDATE login_attempts SET attempts=?, locked_until=? WHERE username=? AND ip=?");
    $stmt->execute([$attempts, $lockedUntil, $u, $ip]);
  } catch (Throwable $e) {}
}


function sv_csrf_token(): string {
  sv_start_session();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf'];
}

function sv_csrf_check(): void {
  sv_start_session();
  $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if (!$token || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
    http_response_code(403);
    exit('CSRF token invalid');
  }
}

function sv_current_user(): ?array {
  sv_start_session();
  if (sv_session_is_expired()) { sv_logout(); return null; }
  if (empty($_SESSION['uid'])) return null;

  $pdo = sv_pdo();
  $stmt = $pdo->prepare("SELECT id, username, display_name, is_admin, role, has_chronik, has_noten, is_active FROM users WHERE id = ?");
  $stmt->execute([$_SESSION['uid']]);
  $u = $stmt->fetch();
  if (!$u || (int)$u['is_active'] !== 1) { sv_logout(); return null; }

  // Fallback: falls role noch nicht migriert (leer), aus is_admin ableiten
  if (empty($u['role'])) {
    $u['role'] = (int)$u['is_admin'] === 1 ? 'admin' : 'user';
  }

  sv_touch_activity((int)$u['id']);
  return $u;
}

// Gibt zurück ob der User Admin ist
function sv_is_admin(array $user): bool {
  return ($user['role'] ?? '') === 'admin';
}

// Gibt zurück ob der User Leitung oder Admin ist
function sv_is_leitung(array $user): bool {
  return in_array($user['role'] ?? '', ['leitung', 'admin'], true);
}

// Gibt zurück ob der User die Chronik sehen darf (alle eingeloggten User)
function sv_can_view_chronik(array $user): bool {
  return true;
}

// Gibt zurück ob der User die Chronik bearbeiten darf (Admin oder has_chronik Flag)
function sv_can_edit_chronik(array $user): bool {
  if (sv_is_admin($user)) return true;
  return (int)($user['has_chronik'] ?? 0) === 1;
}

// Gibt zurück ob der User Noten/Bibliothek bearbeiten darf (Admin oder has_noten Flag)
function sv_can_edit_noten(array $user): bool {
  if (sv_is_admin($user)) return true;
  return (int)($user['has_noten'] ?? 0) === 1;
}

function sv_require_login(): array {
  $u = sv_current_user();
  if (!$u) {
    header('Location: ' . sv_base_url() . '/login.php');
    exit;
  }
  return $u;
}

function sv_require_admin(): array {
  $u = sv_require_login();
  if (!sv_is_admin($u)) { http_response_code(403); exit('Forbidden'); }
  return $u;
}

function sv_require_leitung(): array {
  $u = sv_require_login();
  if (!sv_is_leitung($u)) { http_response_code(403); exit('Forbidden'); }
  return $u;
}

function sv_require_chronik(): array {
  $u = sv_require_login();
  if (!sv_can_view_chronik($u)) { http_response_code(403); exit('Forbidden'); }
  return $u;
}

function sv_require_results(): array {
  return sv_require_login(); // Alle eingeloggten User dürfen Ergebnisse sehen
}

function sv_login(string $username, string $password): bool {
  sv_start_session();

  // Rate limit / lockout
  if (sv_login_is_locked($username)) {
    sv_log(null, 'login_locked', 'username=' . $username . ' ip=' . sv_client_ip());
    return false;
  }

  $pdo = sv_pdo();
  $u = trim($username);
  $stmt = $pdo->prepare("SELECT id, username, password_hash, is_active FROM users WHERE username = ?");
  $stmt->execute([$u]);
  $row = $stmt->fetch();

  if (!$row || (int)$row['is_active'] !== 1) {
    sv_register_login_attempt($u, false);
    sv_log(null, 'login_fail', 'username=' . $u . ' ip=' . sv_client_ip());
    return false;
  }

  if (!password_verify($password, $row['password_hash'])) {
    sv_register_login_attempt($u, false);
    sv_log((int)$row['id'], 'login_fail', 'username=' . $u . ' ip=' . sv_client_ip());
    return false;
  }

  // success
  sv_register_login_attempt($u, true);
  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$row['id'];
  $_SESSION['LAST_ACTIVITY'] = time();
  sv_touch_activity((int)$row['id']);
  sv_log((int)$row['id'], 'login', null);
  try { sv_run_daily_login_backup($pdo, (int)$row['id']); } catch (Throwable $e) {}
  return true;
}


function sv_logout(): void {
  sv_start_session();
  $uid = $_SESSION['uid'] ?? null;
  $_SESSION = [];
  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
  }
  session_destroy();
  if ($uid) sv_log((int)$uid, 'logout', null);
}


function sv_flash_set(string $type, string $message): void {
  sv_start_session();
  $_SESSION['flash_' . $type] = $message;
}

function sv_flash_get(string $type): ?string {
  sv_start_session();
  $key = 'flash_' . $type;
  if (!isset($_SESSION[$key]) || $_SESSION[$key] === '') return null;
  $msg = (string)$_SESSION[$key];
  unset($_SESSION[$key]);
  return $msg;
}

function sv_role_badge(string $role): string {
  $labels = [
    'user'    => sv_setting_get('user_role_label',    'O-Rat'),
    'leitung' => sv_setting_get('leitung_role_label', 'Leitung'),
    'admin'   => 'Admin',
  ];
  $colors = [
    'admin'   => 'background:var(--red-soft);color:var(--red);border-color:rgba(193,9,15,.3)',
    'leitung' => 'background:#f0f4ff;color:#3a5cc5;border-color:rgba(58,92,197,.3)',
    'user'    => 'background:var(--green-light);color:var(--green);border-color:var(--green-mid)',
  ];
  $label = $labels[$role] ?? ucfirst($role);
  $color = $colors[$role] ?? '';
  return '<span class="badge" style="'.$color.'">'.htmlspecialchars($label,ENT_QUOTES).'</span>';
}

function sv_chronik_badge(): string {
  return '<span class="badge" style="background:#fff7ed;color:#c2620a;border-color:rgba(194,98,10,.3)">Chronik</span>';
}

function sv_noten_badge(): string {
  return '<span class="badge" style="background:#f5f0ff;color:#7c3aed;border-color:rgba(124,58,237,.3)">Noten</span>';
}
