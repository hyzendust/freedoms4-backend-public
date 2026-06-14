<?php
/**
 * auth.php — Login, Sign-Up, OTP/Welcome emails, Prosody XMPP account backend for freedoms4.org
 *
 * Sign-up flow:
 *   1. POST { action: "send_otp",  email }
 *   2. POST { action: "signup",    username, email, password, otp }
 */

// ── Credentials from env file ─────────────────────────
$env_file = '/etc/freedoms4/auth.env';
if (!is_readable($env_file)) {
    error_log('auth.php: env file not readable: ' . $env_file);
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Server configuration error.']);
    exit;
}
$env = [];
foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}

define('DB_HOST',         $env['DB_HOST']         ?? '127.0.0.1');
define('DB_PORT',         $env['DB_PORT']         ?? '5432');
define('DB_NAME',         $env['DB_NAME']         ?? 'freedoms4');
define('DB_USER',         $env['DB_USER']         ?? 'freedoms4_user');
define('DB_PASS',         $env['DB_PASS']         ?? '');
define('PROSODY_DB_NAME', $env['PROSODY_DB_NAME'] ?? 'prosody');
define('PROSODY_DB_USER', $env['PROSODY_DB_USER'] ?? 'prosody');
define('PROSODY_DB_PASS', $env['PROSODY_DB_PASS'] ?? '');
define('PROSODY_HOST',    $env['PROSODY_HOST']    ?? 'freedoms4.org');

// ── Constants ──
define('SESSION_NAME',     'f4_session');
define('SESSION_SECURE',   true);
define('SESSION_SAMESITE', 'None');
define('SESSION_TTL',      86400);   // 24 hours

define('OTP_FROM',        'no-reply@freedoms4.org');
define('OTP_TTL',         600);      // 10 minutes
define('OTP_MAX_DAY',     5);        // max OTPs per email per 24 h
define('OTP_MAX_FAILS',   10);       // max failed OTP attempts per IP before lockout
define('MAX_BODY_BYTES',  4096);

// ── CORS ──
$origin          = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = ['https://freedoms4.org', 'https://www.freedoms4.org'];

if ($origin && !in_array($origin, $allowed_origins, true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}

if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Helpers ──
function json_out(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => SESSION_SECURE,
            'httponly' => true,
            'samesite' => SESSION_SAMESITE,
        ]);
        session_start();
    }
}

function db_connect(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        json_out(['success' => false, 'message' => 'Database unavailable.'], 503);
    }
    return $pdo;
}

function prosody_db_connect(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = sprintf('pgsql:host=127.0.0.1;port=5432;dbname=%s', PROSODY_DB_NAME);
    // Throws on failure — caller must catch and handle
    $pdo = new PDO($dsn, PROSODY_DB_USER, PROSODY_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

// Rate limiting via APCu (per-IP, persistent across requests within the window)
// Falls back to session-based if APCu is unavailable.
function rate_limit(string $ip, int $max, int $window): bool {
    $key = 'rl_' . hash('sha256', $ip);
    if (function_exists('apcu_fetch')) {
        $count = apcu_fetch($key, $ok);
        if (!$ok) {
            apcu_store($key, 1, $window);
            return true;
        }
        if ($count >= $max) return false;
        apcu_inc($key);
        return true;
    }
    // Session fallback
    $now = time();
    $rl  = $_SESSION[$key] ?? ['count' => 0, 'window_start' => $now];
    if ($now - $rl['window_start'] > $window) {
        $rl = ['count' => 0, 'window_start' => $now];
    }
    $rl['count']++;
    $_SESSION[$key] = $rl;
    return $rl['count'] <= $max;
}

// OTP failure tracking via APCu (per-IP lockout after OTP_MAX_FAILS attempts)
function otp_fail_count(string $ip): int {
    $key = 'otpfail_' . hash('sha256', $ip);
    if (function_exists('apcu_fetch')) {
        $count = apcu_fetch($key, $ok);
        return $ok ? (int)$count : 0;
    }
    return $_SESSION[$key] ?? 0;
}

function otp_fail_increment(string $ip): void {
    $key = 'otpfail_' . hash('sha256', $ip);
    if (function_exists('apcu_inc')) {
        if (!apcu_fetch($key)) {
            apcu_store($key, 1, 3600);
        } else {
            apcu_inc($key);
        }
        return;
    }
    $_SESSION[$key] = ($_SESSION[$key] ?? 0) + 1;
}

function otp_fail_reset(string $ip): void {
    $key = 'otpfail_' . hash('sha256', $ip);
    if (function_exists('apcu_delete')) {
        apcu_delete($key);
        return;
    }
    unset($_SESSION[$key]);
}

function create_xmpp_account(string $username, string $password): bool {
    try {
        $pdo  = prosody_db_connect();
        $host = PROSODY_HOST;
        $now  = time();

        // Never overwrite an existing account
        $stmt = $pdo->prepare(
            "SELECT 1 FROM prosody WHERE host = :h AND \"user\" = :u AND store = 'accounts' LIMIT 1"
        );
        $stmt->execute([':h' => $host, ':u' => $username]);
        if ($stmt->fetch()) {
            return true;
        }

        // Derive SCRAM-SHA-1 keys
        $salt = sprintf(
            '%08x-%04x-%04x-%04x-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0x4000, 0x4fff),
            random_int(0x8000, 0xbfff),
            random_int(0, 0xffffffffffff)
        );
        $iterations = 10000;
        $salted_pw  = hash_pbkdf2('sha1', $password, $salt, $iterations, 0, true);
        $client_key = hash_hmac('sha1', 'Client Key', $salted_pw, true);
        $stored_key = sha1($client_key);
        $server_key = hash_hmac('sha1', 'Server Key', $salted_pw, false);

        $insert = $pdo->prepare(
            "INSERT INTO prosody (host, \"user\", store, key, type, value)
             VALUES (:h, :u, 'accounts', :k, :t, :v)
             ON CONFLICT (host, \"user\", store, key) DO UPDATE SET type = EXCLUDED.type, value = EXCLUDED.value"
        );

        $rows = [
            ['salt',            'string', $salt],
            ['iteration_count', 'number', (string)$iterations],
            ['stored_key',      'string', $stored_key],
            ['server_key',      'string', $server_key],
            ['created',         'number', (string)$now],
            ['updated',         'number', (string)$now],
        ];

        $pdo->beginTransaction();
        foreach ($rows as [$key, $type, $value]) {
            $insert->execute([':h' => $host, ':u' => $username, ':k' => $key, ':t' => $type, ':v' => $value]);
        }
        $pdo->commit();

        return true;
    } catch (Exception $e) {
        error_log("create_xmpp_account failed for {$username}: " . $e->getMessage());
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

// ── Only accept POST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// ── Request body size cap ──
$content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($content_length > MAX_BODY_BYTES) {
    json_out(['success' => false, 'message' => 'Request too large.'], 413);
}

$raw  = fread(fopen('php://input', 'r'), MAX_BODY_BYTES + 1);
if (strlen($raw) > MAX_BODY_BYTES) {
    json_out(['success' => false, 'message' => 'Request too large.'], 413);
}

$body = json_decode($raw, true);
if (!is_array($body)) {
    json_out(['success' => false, 'message' => 'Invalid request body.'], 400);
}
$action = $body['action'] ?? '';

// ── Session + rate limiting ──
start_session();
$now = time();
$ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!rate_limit($ip, 20, 900)) {
    json_out(['success' => false, 'message' => 'Too many requests. Please wait a few minutes.'], 429);
}

// ── 24 h session expiry ──
if (!empty($_SESSION['user_id'])) {
    $last_seen = $_SESSION['last_seen'] ?? 0;
    if ($now - $last_seen > SESSION_TTL) {
        if (!empty($_SESSION['db_session_id'])) {
            try {
                db_connect()->prepare(
                    "UPDATE user_sessions SET logged_out_at = NOW() WHERE id = :sid AND logged_out_at IS NULL"
                )->execute([':sid' => $_SESSION['db_session_id']]);
            } catch (Exception $e) {}
        }
        session_destroy();
        start_session();
        json_out(['success' => false, 'message' => 'Session expired. Please log in again.'], 401);
    }
    if ($now - $last_seen > 60) {
        $_SESSION['last_seen'] = $now;
        if (!empty($_SESSION['db_session_id'])) {
            try {
                db_connect()->prepare(
                    "UPDATE user_sessions SET last_seen_at = NOW() WHERE id = :sid"
                )->execute([':sid' => $_SESSION['db_session_id']]);
            } catch (Exception $e) {}
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
// Send OTP
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'send_otp') {
    $username = trim($body['username'] ?? '');
    $email = trim($body['email'] ?? '');
    if ($username !== '' && !preg_match('/^[a-zA-Z0-9_\-]{3,32}$/', $username)) {
        json_out(['success' => false, 'message' => 'Username must be 3-32 characters: letters, numbers, _ or -.']);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['success' => false, 'message' => 'A valid email address is required.']);
    }

    $pdo = db_connect();

    $stmt = $pdo->prepare('SELECT blocked FROM users WHERE username = :u OR email = :e ORDER BY blocked DESC LIMIT 1');
    $stmt->execute([':u' => $username, ':e' => $email]);
    $user = $stmt->fetch();
    if ($user && ($user['blocked'] === true || $user['blocked'] === 't')) {
        json_out(['success' => false, 'message' => 'This account has been blocked.']);
    }
    if ($user) {
        json_out(['success' => false, 'message' => 'Username or email is already taken.']);
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM email_otps WHERE email = :e AND created_at > NOW() - INTERVAL '24 hours'"
    );
    $stmt->execute([':e' => $email]);
    if ((int)$stmt->fetchColumn() >= OTP_MAX_DAY) {
        json_out(['success' => false, 'message' => 'Too many OTP requests for this email. Please try again tomorrow.'], 429);
    }

    $pdo->prepare("DELETE FROM email_otps WHERE email = :e AND used = FALSE")->execute([':e' => $email]);

    $otp      = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otp_hash = password_hash($otp, PASSWORD_BCRYPT, ['cost' => 10]);

    $pdo->prepare(
        "INSERT INTO email_otps (email, otp_hash, expires_at, used)
         VALUES (:e, :h, NOW() + INTERVAL '10 minutes', FALSE)"
    )->execute([':e' => $email, ':h' => $otp_hash]);

    $subject = 'Freedoms4 sign up OTP';
    $message =
        "Hello,\n\n" .
        "Your OTP to create a freedoms4.org account is:\n\n" .
        "{$otp}\n\n" .
        "This code expires in 10 minutes. Do not share it with anyone.\n\n" .
        "If you did not request this, you can safely ignore this email.\n\n" .
        "freedoms4.org";

    $headers = implode("\r\n", [
        'From: freedoms4.org <' . OTP_FROM . '>',
        'Reply-To: ' . OTP_FROM,
        'Cc: hyzen@freedoms4.org',
        'X-Mailer: PHP/' . PHP_VERSION,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ]);

    if (!mail($email, $subject, $message, $headers)) {
        error_log("OTP mail() failed for: {$email}");
        json_out(['success' => false, 'message' => 'Failed to send OTP email. Please try again.'], 500);
    }

    json_out(['success' => true, 'message' => 'OTP sent. Please check your inbox (and spam folder).']);
}

// ════════════════════════════════════════════════════════════════════════════
// Login
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'login') {
    $username = trim($body['username'] ?? '');
    $password = $body['password'] ?? '';

    if ($username === '' || $password === '') {
        json_out(['success' => false, 'message' => 'Username and password are required.']);
    }

    $pdo  = db_connect();
    $stmt = $pdo->prepare('SELECT id, username, password_hash, blocked FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    $hash = $user['password_hash'] ?? '$2y$12$invalidhashpadding000000000000000000000000000000000000000';
    if (!$user || !password_verify($password, $hash)) {
        json_out(['success' => false, 'message' => 'Invalid username or password.']);
    }

    if ($user && ($user['blocked'] === true || $user['blocked'] === 't')) {
        json_out(['success' => false, 'message' => 'This account has been blocked.']);
    }


    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['last_seen'] = $now;

    $ua         = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);
    $session_hash = hash('sha256', session_id());
    $stmt = $pdo->prepare(
        "INSERT INTO user_sessions (user_id, session_id, logged_in_at, last_seen_at, ip_address, user_agent)
         VALUES (:uid, :sid, NOW(), NOW(), :ip, :ua)"
    );
    $stmt->execute([':uid' => $user['id'], ':sid' => $session_hash, ':ip' => $ip, ':ua' => $ua]);
    $_SESSION['db_session_id'] = $pdo->lastInsertId();

    json_out(['success' => true, 'redirect' => '/']);
}

// ════════════════════════════════════════════════════════════════════════════
// Signup + XMPP + Email accounts creation
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'signup') {
    $username = trim($body['username'] ?? '');
    $email    = trim($body['email']    ?? '');
    $password = $body['password']      ?? '';
    $otp          = trim($body['otp']      ?? '');
    $terms_agreed = !empty($body['terms_agreed']);

    if ($username === '') {
        json_out(['success' => false, 'message' => 'Username is required.']);
    }
    if (!preg_match('/^[a-zA-Z0-9_\-]{3,32}$/', $username)) {
        json_out(['success' => false, 'message' => 'Username must be 3-32 characters: letters, numbers, _ or -.']);
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_out(['success' => false, 'message' => 'A valid email address is required.']);
    }
    if (strlen($password) < 8) {
        json_out(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    }
    if ($otp === '' || !preg_match('/^\d{6}$/', $otp)) {
        json_out(['success' => false, 'message' => 'A 6-digit verification code is required.']);
    }
    if (!$terms_agreed) {
        json_out(['success' => false, 'message' => 'You must agree to the terms and conditions.']);
    }

    // OTP brute-force lockout
    if (otp_fail_count($ip) >= OTP_MAX_FAILS) {
        json_out(['success' => false, 'message' => 'Too many failed attempts. Please request a new code.'], 429);
    }

    $pdo = db_connect();

    $stmt = $pdo->prepare('SELECT blocked FROM users WHERE username = :u OR email = :e ORDER BY blocked DESC LIMIT 1');
    $stmt->execute([':u' => $username, ':e' => $email]);
    $user = $stmt->fetch();
    if ($user && ($user['blocked'] === true || $user['blocked'] === 't')) {
        json_out(['success' => false, 'message' => 'This account has been blocked.']);
    }
    if ($user) {
        json_out(['success' => false, 'message' => 'Username or email is already taken.']);
    }

    // Verify OTP
    $stmt = $pdo->prepare(
        "SELECT id, otp_hash FROM email_otps
         WHERE email = :e AND used = FALSE AND expires_at > NOW()
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute([':e' => $email]);
    $otp_row = $stmt->fetch();

    if (!$otp_row || !password_verify($otp, $otp_row['otp_hash'])) {
        otp_fail_increment($ip);
        json_out(['success' => false, 'message' => 'Invalid or expired verification code.']);
    }

    // Valid OTP — reset failure counter
    otp_fail_reset($ip);

    // Check uniqueness
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE username = :u OR email = :e LIMIT 1');
    $stmt->execute([':u' => $username, ':e' => $email]);
    if ($stmt->fetch()) {
        json_out(['success' => false, 'message' => 'Username or email is already taken.']);
    }

    // Create freedoms4 account + mark OTP used
    $pdo->beginTransaction();
    try {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, terms_agreed, created_at) VALUES (:u, :e, :h, :t, NOW())'
        )->execute([':u' => $username, ':e' => $email, ':h' => $hash, ':t' => $terms_agreed ? 'true' : 'false']);

        $pdo->prepare("UPDATE email_otps SET used = TRUE WHERE id = :id")
            ->execute([':id' => $otp_row['id']]);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log('signup transaction failed: ' . $e->getMessage());
        json_out(['success' => false, 'message' => 'Account creation failed. Please try again.'], 500);
    }

    // Regenerate session after successful signup (#5)
    session_regenerate_id(true);

    // Create XMPP account
    if (!create_xmpp_account($username, $password)) {
        error_log("XMPP account creation failed for new user: {$username}");
        // Don't fail the signup — user account exists, XMPP can be fixed manually
    }

    // ── Create Dovecot virtual email account ──
    // Sudoers: www-data ALL=(root) NOPASSWD: /usr/local/bin/email-account-create
    $safe_user = escapeshellarg($username);
    $safe_pw   = escapeshellarg($password);
    $email_out = shell_exec("sudo /usr/local/bin/email-account-create {$safe_user} {$safe_pw} 2>&1");
    if (!in_array(trim($email_out ?? ''), ['created', 'exists', 'system-user'])) {
        error_log("email-account-create failed for {$username}: " . ($email_out ?? 'null'));
        // Don't fail the signup — email can be fixed manually
    }

    // Welcome email
    $welcome_headers = implode("\r\n", [
        'From: hyzen <hyzen@freedoms4.org>',
        'Reply-To: hyzen@freedoms4.org',
        'Cc: hyzen@freedoms4.org',
        'X-Mailer: PHP/' . PHP_VERSION,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ]);

    $welcome_message =
        "Hi {$username},\n\n" .
        "Welcome aboard!\n\n" .
        "Thank you for signing up.\n\n" .
        "Your credentials:\n\n" .
        "XMPP JID: {$username}@freedoms4.org\n" .
        "Email ID: {$username}@freedoms4.org\n\n" .
        "Passwords: Use the same password that you used during registration.\n\n" .
        "If you have any questions, I'm here to help:\n" .
        "Email <mailto:hyzen@freedoms4.org> and XMPP <xmpp:hyzen@freedoms4.org>: hyzen@freedoms4.org\n" .
        "IRC/Liberachat: hyzen, #freedoms4\n\n" .
        "Best regards,\n" .
        "hyzen, freedoms4.org.";

    if (!mail($email, "Welcome to freedoms4.org", $welcome_message, $welcome_headers)) {
        error_log("Welcome mail() failed for: {$email}");
    }

    json_out(['success' => true]);
}

// ════════════════════════════════════════════════════════════════════════════
// Check session
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'check_session') {
    if (empty($_SESSION['user_id'])) {
        json_out(['valid' => false]);
    }

    // Verify user still exists in DB
    try {
        $pdo  = db_connect();
        $stmt = $pdo->prepare('SELECT 1 FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            // User deleted — destroy session
            $_SESSION = [];
            session_destroy();
            json_out(['valid' => false]);
        }
    } catch (Exception $e) {
        // DB unavailable — don't force logout, just report invalid so frontend can retry
        json_out(['valid' => false, 'db_error' => true]);
    }

    json_out(['valid' => true, 'username' => $_SESSION['username']]);
}

// ════════════════════════════════════════════════════════════════════════════
// Logout
// ════════════════════════════════════════════════════════════════════════════
// Logout
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'logout') {
    if (!empty($_SESSION['db_session_id'])) {
        try {
            db_connect()->prepare(
                "UPDATE user_sessions SET logged_out_at = NOW() WHERE id = :sid AND logged_out_at IS NULL"
            )->execute([':sid' => $_SESSION['db_session_id']]);
        } catch (Exception $e) {}
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    json_out(['success' => true, 'redirect' => '/']);
}

json_out(['success' => false, 'message' => 'Unknown action.'], 400);
