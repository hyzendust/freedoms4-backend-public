<?php
/**
 * comments.php — Blog comment backend for freedoms4.org
 *
 * Actions:
 *   GET  ?action=get&post_id=...           — fetch comments for a post
 *   POST { action: "post",   post_id, body }         — add a top-level comment
 *   POST { action: "reply",  post_id, parent_id, body } — reply to a comment
 *   POST { action: "delete", comment_id }            — delete own comment
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

define('SESSION_NAME',     'f4_session');
define('SESSION_SECURE',   true);
define('SESSION_SAMESITE', 'None');
define('MAX_BODY_BYTES',   8192);
define('MAX_COMMENT_LEN',  2000);

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
        error_log('comments.php DB error: ' . $e->getMessage());
        json_out(['success' => false, 'message' => 'Database unavailable.'], 503);
    }
    return $pdo;
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

/**
 * Send comment notification emails.
 *
 * $type        'new_comment' | 'new_reply'
 * $actor       username of the person who wrote the comment/reply
 * $body        the comment/reply text
 * $post_id     the post slug/path (used as human-readable context)
 * $notify_user ['username' => ..., 'email' => ...] | null  — commenter being replied to
 */
function send_notification(string $type, string $actor, string $body, string $post_id, ?array $notify_user): void {
    $from    = 'no-reply@freedoms4.org';
    $headers = implode("\r\n", [
        'From: freedoms4.org <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . PHP_VERSION,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ]);

    $post_url = 'https://freedoms4.org' . trim($post_id, '"\'');

    if ($type === 'new_reply') {
        // ── Reply notification ──
        // Always notify hyzen (unless hyzen is the one replying)
        $reply_subject = "You have a new reply from {$actor}";
        $reply_body    =
            "You have a new reply from {$actor}:\n\n" .
            "{$body}\n\n" .
            "Post: {$post_url}\n\n" .
            "freedoms4.org";

        // Notify the commenter being replied to (their registered email + @freedoms4.org),
        // unless they are hyzen (handled separately below)
        if ($notify_user && $notify_user['username'] !== 'hyzen') {
            // Send to registered email
            if (!empty($notify_user['email'])) {
                @mail($notify_user['email'], $reply_subject, $reply_body, $headers);
            }
            // Send to their @freedoms4.org address
            $site_email = $notify_user['username'] . '@freedoms4.org';
            @mail($site_email, $reply_subject, $reply_body, $headers);
        }

        // Notify hyzen for all replies (unless hyzen is the replier)
        if ($actor !== 'hyzen') {
            // If hyzen is being replied to, use the reply subject; otherwise use new-comment subject
            if ($notify_user && $notify_user['username'] === 'hyzen') {
                @mail('hyzen@freedoms4.org', $reply_subject, $reply_body, $headers);
            } else {
                // hyzen gets a "new reply" notice even when it's not on their own comment
                $hyzen_subject = "A new comment from {$actor}";
                $hyzen_body    =
                    "A new comment from {$actor}:\n\n" .
                    "{$body}\n\n" .
                    "Post: {$post_url}\n\n" .
                    "freedoms4.org";
                @mail('hyzen@freedoms4.org', $hyzen_subject, $hyzen_body, $headers);
            }
        }

    } else {
        // ── New top-level comment notification (hyzen only) ──
        if ($actor === 'hyzen') return; // hyzen commenting on their own site — skip
        $subject  = "A new comment from {$actor}";
        $msg      =
            "A new comment from {$actor}:\n\n" .
            "{$body}\n\n" .
            "Post: {$post_url}\n\n" .
            "freedoms4.org";
        @mail('hyzen@freedoms4.org', $subject, $msg, $headers);
    }
}

function logged_in_user(): ?array {
    if (empty($_SESSION['user_id']) || empty($_SESSION['username'])) return null;
    // Verify the user still exists in the DB (handles deleted accounts / wiped DB)
    try {
        $pdo  = db_connect();
        $stmt = $pdo->prepare('SELECT blocked FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if (!$row) {
            $_SESSION = [];
            session_destroy();
            return null;
        }
        if ($row['blocked'] === true || $row['blocked'] === 't') {
            return null;
        }
    } catch (Exception $e) {
        // DB unavailable — treat as logged-out to be safe
        return null;
    }
    return ['id' => (int)$_SESSION['user_id'], 'username' => $_SESSION['username']];
}

// ── GET: fetch comments ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $post_id = trim($_GET['post_id'] ?? '');
    if ($post_id === '') {
        json_out(['success' => false, 'message' => 'post_id is required.'], 400);
    }

    start_session();
    $viewer   = logged_in_user();
    $is_admin = $viewer && $viewer['username'] === 'hyzen';

    $pdo  = db_connect();
    $stmt = $pdo->prepare(
        "SELECT c.id, c.post_id, c.parent_id, c.user_id, COALESCE(u.username, c.username, '[deleted user]') AS username,
                c.body, c.created_at, c.deleted, c.deleted_by
         FROM comments c
         LEFT JOIN users u ON u.id = c.user_id
         WHERE c.post_id = :pid
         ORDER BY c.created_at ASC"
    );
    $stmt->execute([':pid' => $post_id]);
    $rows = $stmt->fetchAll();

    // Build tree: top-level comments with nested replies
    $top   = [];
    $index = [];
    foreach ($rows as $row) {
        if ($row['deleted']) {
            $row['body']          = null;
            $row['deleted_label'] = $row['deleted_by'] === 'admin' ? 'deleted by admin' : 'deleted by user';
        } else {
            $row['deleted_label'] = null;
        }
        $row['replies'] = [];
        $row['is_own']  = $viewer && (int)$row['user_id'] === $viewer['id'];
        $index[$row['id']] = $row;
    }
    foreach ($index as $id => &$node) {
        if ($node['parent_id'] === null) {
            $top[$id] = &$node;
        } else {
            $index[$node['parent_id']]['replies'][$id] = &$node;
        }
    }
    unset($node);

    json_out(['success' => true, 'comments' => array_values($top), 'logged_in' => $viewer !== null, 'username' => $viewer['username'] ?? null, 'is_admin' => $is_admin]);
}

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$content_length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($content_length > MAX_BODY_BYTES) {
    json_out(['success' => false, 'message' => 'Request too large.'], 413);
}
$raw  = fread(fopen('php://input', 'r'), MAX_BODY_BYTES + 1);
$body = json_decode($raw, true);
if (!is_array($body)) {
    json_out(['success' => false, 'message' => 'Invalid request body.'], 400);
}

start_session();
$user = logged_in_user();
if (!$user) {
    json_out(['success' => false, 'message' => 'You must be logged in to comment.'], 401);
}

$action = $body['action'] ?? '';

// ── POST: add comment or reply ──
if ($action === 'post' || $action === 'reply') {
    $post_id   = trim($body['post_id']   ?? '');
    $text      = trim($body['body']      ?? '');
    $parent_id = isset($body['parent_id']) ? (int)$body['parent_id'] : null;

    if ($post_id === '') {
        json_out(['success' => false, 'message' => 'post_id is required.']);
    }
    if ($text === '') {
        json_out(['success' => false, 'message' => 'Comment cannot be empty.']);
    }
    if (strlen($text) > MAX_COMMENT_LEN) {
        json_out(['success' => false, 'message' => 'Comment is too long (max 2000 characters).']);
    }

    $pdo = db_connect();

    // Rate limit: max 1 comment per user per minute (hyzen is exempt)
    if ($user['username'] !== 'hyzen') {
        $rate_stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM comments
             WHERE user_id = :uid AND created_at > NOW() - INTERVAL '1 minute'"
        );
        $rate_stmt->execute([':uid' => $user['id']]);
        if ((int)$rate_stmt->fetchColumn() >= 1) {
            json_out(['success' => false, 'message' => 'You are posting too fast. Please wait a moment.'], 429);
        }
    }

    // Validate parent exists and belongs to same post
    if ($parent_id !== null) {
        $stmt = $pdo->prepare("SELECT id FROM comments WHERE id = :pid AND post_id = :post AND deleted = FALSE LIMIT 1");
        $stmt->execute([':pid' => $parent_id, ':post' => $post_id]);
        if (!$stmt->fetch()) {
            json_out(['success' => false, 'message' => 'Parent comment not found.'], 404);
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO comments (post_id, parent_id, user_id, username, body, created_at, deleted)
         VALUES (:post, :parent, :uid, :username, :body, NOW(), FALSE)
         RETURNING id, created_at"
    );
    $stmt->execute([
        ':post'     => $post_id,
        ':parent'   => $parent_id,
        ':uid'      => $user['id'],
        ':username' => $user['username'],
        ':body'     => $text,
    ]);
    $row = $stmt->fetch();

    // ── Email notifications ──
    if ($parent_id !== null) {
        // It's a reply — find the parent comment's author for notification
        $parent_stmt = $pdo->prepare(
            "SELECT c.user_id, u.username, u.email
             FROM comments c JOIN users u ON u.id = c.user_id
             WHERE c.id = :pid LIMIT 1"
        );
        $parent_stmt->execute([':pid' => $parent_id]);
        $parent_author = $parent_stmt->fetch() ?: null;
        send_notification('new_reply', $user['username'], $text, $post_id, $parent_author);
    } else {
        // Top-level comment
        send_notification('new_comment', $user['username'], $text, $post_id, null);
    }

    json_out(['success' => true, 'id' => $row['id'], 'created_at' => $row['created_at']]);
}

// ── POST: delete comment (own) or any comment (admin) ──
if ($action === 'delete') {
    $comment_id = (int)($body['comment_id'] ?? 0);
    if ($comment_id === 0) {
        json_out(['success' => false, 'message' => 'comment_id is required.']);
    }

    $is_admin = $user['username'] === 'hyzen';

    $pdo = db_connect();

    // Check if the comment belongs to the deleter
    $owner_stmt = $pdo->prepare("SELECT user_id FROM comments WHERE id = :id LIMIT 1");
    $owner_stmt->execute([':id' => $comment_id]);
    $comment_row = $owner_stmt->fetch(PDO::FETCH_ASSOC);
    $is_own = $comment_row && (int)$comment_row['user_id'] === (int)$user['id'];

    $deleted_by = $is_own ? 'user' : 'admin';

    if ($is_admin) {
        $stmt = $pdo->prepare(
            "UPDATE comments SET deleted = TRUE, body = NULL, deleted_by = :by
             WHERE id = :id AND deleted = FALSE"
        );
        $stmt->execute([':id' => $comment_id, ':by' => $deleted_by]);
    } else {
        $stmt = $pdo->prepare(
            "UPDATE comments SET deleted = TRUE, body = NULL, deleted_by = :by
             WHERE id = :id AND user_id = :uid AND deleted = FALSE"
        );
        $stmt->execute([':id' => $comment_id, ':uid' => $user['id'], ':by' => $deleted_by]);
    }

    if ($stmt->rowCount() === 0) {
        json_out(['success' => false, 'message' => 'Comment not found or not yours.'], 403);
    }
    json_out(['success' => true]);
}

json_out(['success' => false, 'message' => 'Unknown action.'], 400);
