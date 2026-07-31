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
        error_log('comments.php DB error: ' . $e->getMessage());
        json_out(['success' => false, 'message' => 'Database unavailable.'], 503);
    }
    return $pdo;
}

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.gc_maxlifetime', (string) REMEMBER_COOKIE_TTL);
        ini_set('session.save_path', SESSION_SAVE_PATH);
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
 * $post_url    the post's current live URL
 * $post_title  the post's title, used as the link text
 * $notify_user ['username' => ..., 'email' => ...] | null  — commenter being replied to
 */
function send_notification(string $type, string $actor, string $body, string $post_url, string $post_title, ?array $notify_user): void {
    $from    = 'no-reply@freedoms4.org';
    $headers = implode("\r\n", [
        'From: freedoms4.org <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . PHP_VERSION,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
    ]);

    $safe_actor = htmlspecialchars($actor, ENT_QUOTES, 'UTF-8');
    $safe_body  = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
    $safe_title = htmlspecialchars($post_title !== '' ? $post_title : $post_url, ENT_QUOTES, 'UTF-8');
    $safe_url   = htmlspecialchars($post_url, ENT_QUOTES, 'UTF-8');
    $link       = "<a href=\"{$safe_url}\">{$safe_title}</a>";

    $actor_is_hyzen = $actor === 'hyzen';

    if ($type === 'new_comment') {
        // Top-level comment: hyzen gets notified
        if ($actor_is_hyzen) return;
        $subject = "A new comment from {$actor}";
        $msg     = render_notification_email("A new comment from {$safe_actor}:", $safe_body, $link);
        @mail('hyzen@freedoms4.org', $subject, $msg, $headers);
        return;
    }

    // $type === 'new_reply': two independent recipients — the person replied to and hyzen. Either, both, or neither may end up receiving an email
    $parent_is_hyzen  = $notify_user && $notify_user['username'] === 'hyzen';
    $replying_to_self = $notify_user && $notify_user['username'] === $actor;

    $reply_subject = "You have a new reply from {$actor}";
    $reply_body    = render_notification_email("You have a new reply from {$safe_actor}:", $safe_body, $link);

    // Recipient 1: person being replied to. Skipped when: replying to own comment. Replying to hyzen is handled by Recipient 2 next
    if ($notify_user && !$parent_is_hyzen && !$replying_to_self) {
        if (!empty($notify_user['email'])) {
            @mail($notify_user['email'], $reply_subject, $reply_body, $headers);
        }
        @mail($notify_user['username'] . '@freedoms4.org', $reply_subject, $reply_body, $headers);
    }

    // Recipient 2: hyzen. Skipped when: hyzen sent the reply
    if (!$actor_is_hyzen) {
        if ($parent_is_hyzen) {
            @mail('hyzen@freedoms4.org', $reply_subject, $reply_body, $headers);
        } else {
            $hyzen_subject = "A new comment from {$actor}";
            $hyzen_body    = render_notification_email("A new comment from {$safe_actor}:", $safe_body, $link);
            @mail('hyzen@freedoms4.org', $hyzen_subject, $hyzen_body, $headers);
        }
    }
}

function render_notification_email(string $heading, string $safe_body, string $link): string {
    return "<p>{$heading}</p><p>{$safe_body}</p><p>Post: {$link}</p><p>freedoms4.org</p>";
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
    $post_id    = trim($body['post_id']    ?? '');
    $post_url   = trim($body['post_url']   ?? '');
    $post_title = trim($body['post_title'] ?? '');
    $text       = trim($body['body']       ?? '');
    $parent_id  = isset($body['parent_id']) ? (int)$body['parent_id'] : null;

    if ($post_id === '') {
        json_out(['success' => false, 'message' => 'post_id is required.']);
    }
    // post_id should always be a site-relative path like "/blog/some-post/".
    // Reject anything else here, before it can shape outgoing notification
    // email content or anything else downstream.
    if (!preg_match('#^/[a-zA-Z0-9_/-]{1,200}/$#', $post_id)) {
        json_out(['success' => false, 'message' => 'Invalid post_id.']);
    }
    // post_url used only for notification links
    if (!preg_match('#^https://freedoms4\.org/[a-zA-Z0-9_/-]{1,200}/$#', $post_url)) {
        $post_url = 'https://freedoms4.org' . $post_id;
    }
    $post_title = substr($post_title, 0, 300);
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
        send_notification('new_reply', $user['username'], $text, $post_url, $post_title, $parent_author);
    } else {
        // Top-level comment
        send_notification('new_comment', $user['username'], $text, $post_url, $post_title, null);
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
