<?php
/**
 * admin.php — User management backend for freedoms4.org
 * Hyzen-only. All actions require an active hyzen session.
 *
 * Actions:
 *   GET  ?action=list_users          — list all users
 *   POST { action: "block_user",   user_id }  — block a user
 *   POST { action: "unblock_user", user_id }  — unblock a user
 *   POST { action: "delete_user",  user_id }  — permanently delete a user
 */

// ── Credentials from env file ──
$env_file = '/etc/freedoms4/auth.env';
if (!is_readable($env_file)) {
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

define('DB_HOST', $env['DB_HOST'] ?? '127.0.0.1');
define('DB_PORT', $env['DB_PORT'] ?? '5432');
define('DB_NAME', $env['DB_NAME'] ?? 'freedoms4');
define('DB_USER', $env['DB_USER'] ?? 'freedoms4_user');
define('DB_PASS', $env['DB_PASS'] ?? '');
define('PROSODY_DB_NAME', $env['PROSODY_DB_NAME'] ?? 'prosody');
define('PROSODY_DB_USER', $env['PROSODY_DB_USER'] ?? 'prosody');
define('PROSODY_DB_PASS', $env['PROSODY_DB_PASS'] ?? '');
define('PROSODY_HOST',    $env['PROSODY_HOST']    ?? 'freedoms4.org');

define('SESSION_NAME',     'f4_session');
define('SESSION_SECURE',   true);
define('SESSION_SAMESITE', 'None');
define('MAX_BODY_BYTES',   4096);
define('ADMIN_USER',       'hyzen');

// ── CORS ──
$origin          = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed_origins = ['https://freedoms4.org', 'https://www.freedoms4.org'];

if (!$origin || !in_array($origin, $allowed_origins, true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Forbidden.']);
    exit;
}
if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
        error_log('admin.php DB error: ' . $e->getMessage());
        json_out(['success' => false, 'message' => 'Database unavailable.'], 503);
    }
    return $pdo;
}

function prosody_db_connect(): ?PDO {
    static $pdo = null;
    static $failed = false;
    if ($pdo !== null) return $pdo;
    if ($failed) return null;
    try {
        $dsn = sprintf('pgsql:host=127.0.0.1;port=5432;dbname=%s', PROSODY_DB_NAME);
        $pdo = new PDO($dsn, PROSODY_DB_USER, PROSODY_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log('admin.php Prosody DB error: ' . $e->getMessage());
        $failed = true;
        return null;
    }
}

// Remove a user's XMPP account (all stores: accounts, roster, vcard, etc.)
// so it doesn't keep working indefinitely after the user is deleted.
function delete_xmpp_account(string $username): void {
    $pdo = prosody_db_connect();
    if (!$pdo) return; // Prosody DB unreachable — already logged, don't block user deletion on it
    try {
        $pdo->prepare('DELETE FROM prosody WHERE host = :h AND "user" = :u')
            ->execute([':h' => PROSODY_HOST, ':u' => strtolower($username)]);
    } catch (Exception $e) {
        error_log("delete_xmpp_account failed for {$username}: " . $e->getMessage());
    }
}

function ensure_xmpp_backup_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS xmpp_account_backups (
            username   VARCHAR(32)  PRIMARY KEY,
            rows       JSONB        NOT NULL,
            blocked_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
        )"
    );
}

// Back up a user's Prosody rows (all stores) into xmpp_account_backups,
// then remove them from the live prosody table — mirroring how
// email-block.sh moves the Dovecot passwd-file entry into a backup file.
function xmpp_backup_and_remove(string $username): void {
    $prosody = prosody_db_connect();
    if (!$prosody) return; // logged already — don't block the block/delete action on it

    try {
        $stmt = $prosody->prepare('SELECT host, "user", store, key, type, value FROM prosody WHERE host = :h AND "user" = :u');
        $stmt->execute([':h' => PROSODY_HOST, ':u' => strtolower($username)]);
        $rows = $stmt->fetchAll();
        if (!$rows) return; // nothing to back up (e.g. never had an XMPP account)

        $pdo = db_connect();
        ensure_xmpp_backup_table($pdo);
        $pdo->prepare(
            'INSERT INTO xmpp_account_backups (username, rows, blocked_at)
             VALUES (:u, :rows, NOW())
             ON CONFLICT (username) DO UPDATE SET rows = :rows2, blocked_at = NOW()'
        )->execute([
            ':u'     => strtolower($username),
            ':rows'  => json_encode($rows),
            ':rows2' => json_encode($rows),
        ]);

        $prosody->prepare('DELETE FROM prosody WHERE host = :h AND "user" = :u')
            ->execute([':h' => PROSODY_HOST, ':u' => strtolower($username)]);
    } catch (Exception $e) {
        error_log("xmpp_backup_and_remove failed for {$username}: " . $e->getMessage());
    }
}

// Restore a previously backed-up XMPP account (on unblock) and remove the
// backup row once restored.
function xmpp_restore_from_backup(string $username): void {
    try {
        $pdo = db_connect();
        ensure_xmpp_backup_table($pdo);
        $stmt = $pdo->prepare('SELECT rows FROM xmpp_account_backups WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => strtolower($username)]);
        $row = $stmt->fetch();
        if (!$row) return; // nothing backed up — nothing to restore

        $prosody = prosody_db_connect();
        if (!$prosody) return; // can't restore right now — backup row stays put, safe to retry later

        $entries = json_decode($row['rows'], true) ?: [];
        $insert  = $prosody->prepare(
            'INSERT INTO prosody (host, "user", store, key, type, value) VALUES (:host, :user, :store, :key, :type, :value)'
        );
        foreach ($entries as $entry) {
            try {
                $insert->execute([
                    ':host'  => $entry['host'],
                    ':user'  => $entry['user'],
                    ':store' => $entry['store'],
                    ':key'   => $entry['key'],
                    ':type'  => $entry['type'],
                    ':value' => $entry['value'],
                ]);
            } catch (Exception $e) {
                // Row may already exist (e.g. partial prior restore) — skip and continue
                error_log("xmpp_restore_from_backup: skipped one row for {$username}: " . $e->getMessage());
            }
        }

        $pdo->prepare('DELETE FROM xmpp_account_backups WHERE username = :u')
            ->execute([':u' => strtolower($username)]);
    } catch (Exception $e) {
        error_log("xmpp_restore_from_backup failed for {$username}: " . $e->getMessage());
    }
}

// Remove any leftover XMPP backup row for a user (used on permanent
// deletion, in case they were blocked at some point before being deleted).
function xmpp_delete_backup(string $username): void {
    try {
        $pdo = db_connect();
        ensure_xmpp_backup_table($pdo);
        $pdo->prepare('DELETE FROM xmpp_account_backups WHERE username = :u')
            ->execute([':u' => strtolower($username)]);
    } catch (Exception $e) {
        error_log("xmpp_delete_backup failed for {$username}: " . $e->getMessage());
    }
}

// ── Session + admin check ──
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

if (empty($_SESSION['username']) || $_SESSION['username'] !== ADMIN_USER) {
    json_out(['success' => false, 'message' => 'Unauthorized.'], 403);
}

// ════════════════════════════════════════════════════════════════════════════
// GET: list users
// ════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pdo  = db_connect();
    $stmt = $pdo->prepare(
        "SELECT id, username, email, blocked, created_at
         FROM users
         ORDER BY CASE WHEN username = :admin THEN 0 ELSE 1 END,
                  LOWER(username) ASC,
                  username ASC"
    );
    $stmt->execute([':admin' => ADMIN_USER]);
    json_out(['success' => true, 'users' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($content_length > MAX_BODY_BYTES) {
    json_out(['success' => false, 'message' => 'Request too large.'], 413);
}
$body = json_decode(fread(fopen('php://input', 'r'), MAX_BODY_BYTES), true);
if (!is_array($body)) {
    json_out(['success' => false, 'message' => 'Invalid request body.'], 400);
}

$action  = $body['action']  ?? '';
$user_id = (int)($body['user_id'] ?? 0);

if ($user_id === 0) {
    json_out(['success' => false, 'message' => 'user_id is required.']);
}

$pdo = db_connect();

// Prevent admin from acting on themselves
$stmt = $pdo->prepare('SELECT username, email FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $user_id]);
$target = $stmt->fetch();
if (!$target) {
    json_out(['success' => false, 'message' => 'User not found.'], 404);
}
if ($target['username'] === ADMIN_USER) {
    json_out(['success' => false, 'message' => 'Cannot modify the admin account.'], 403);
}

// ════════════════════════════════════════════════════════════════════════════
// Block user
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'block_user') {
    $pdo->prepare('UPDATE users SET blocked = TRUE WHERE id = :id')
        ->execute([':id' => $user_id]);

    // Backup virtual mail entry so user can no longer receive mail
    $safe_user = escapeshellarg($target['username']);
    shell_exec("sudo /usr/local/bin/email-block block {$safe_user} 2>&1");

    // Backup and remove their XMPP/Prosody account so it stops working
    // while blocked, restorable on unblock.
    xmpp_backup_and_remove($target['username']);

    json_out(['success' => true]);
}

// ════════════════════════════════════════════════════════════════════════════
// Unblock user
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'unblock_user') {
    $pdo->prepare('UPDATE users SET blocked = FALSE WHERE id = :id')
        ->execute([':id' => $user_id]);

    // Restore virtual mail entry
    $safe_user = escapeshellarg($target['username']);
    shell_exec("sudo /usr/local/bin/email-block unblock {$safe_user} 2>&1");

    // Restore their XMPP/Prosody account from backup, if one exists.
    xmpp_restore_from_backup($target['username']);

    json_out(['success' => true]);
}

// ════════════════════════════════════════════════════════════════════════════
// Delete user
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'delete_user') {
    $safe_user = escapeshellarg($target['username']);

    // Run the same message cleanup as the block action before removing the
    // mailbox and account configuration permanently.
    $block_output = [];
    $block_status = 0;
    exec("sudo /usr/local/bin/email-block block {$safe_user} 2>&1", $block_output, $block_status);
    if ($block_status !== 0) {
        error_log("email-block failed during delete for {$target['username']}: " . implode("\n", $block_output));
        json_out(['success' => false, 'message' => 'Failed to clear the user email history.'], 500);
    }

    $delete_output = [];
    $delete_status = 0;
    exec("sudo /usr/local/bin/email-delete {$safe_user} 2>&1", $delete_output, $delete_status);
    if ($delete_status !== 0) {
        error_log("email-delete failed for {$target['username']}: " . implode("\n", $delete_output));
    }

    $pdo->prepare('DELETE FROM users WHERE id = :id')
        ->execute([':id' => $user_id]);

    // Remove the user's XMPP/Prosody account so it stops working once their
    // freedoms4 account is gone, instead of remaining usable indefinitely.
    delete_xmpp_account($target['username']);
    // ...and remove any leftover backup row too, in case they were blocked
    // at some point before being deleted outright.
    xmpp_delete_backup($target['username']);

    // Clear OTP history for this email so re-signing-up doesn't hit the
    // daily OTP request limit because of OTPs sent before deletion.
    $pdo->prepare('DELETE FROM email_otps WHERE email = :e')
        ->execute([':e' => $target['email']]);

    json_out(['success' => true]);
}

json_out(['success' => false, 'message' => 'Unknown action.'], 400);
