<?php
header('Content-Type: application/json');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/wp-publish.php';

startSecureSession();
if (empty($_SESSION['client_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true);
$id     = (int)($body['id']     ?? 0);
$action = trim($body['action']  ?? '');

if (!in_array($action, ['approve', 'reject', 'save', 'disconnect_wp'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

try {
    $db = getDB();

    // WordPress disconnect — no article ID needed
    if ($action === 'disconnect_wp') {
        $db->prepare('UPDATE clients SET wp_url = NULL, wp_username = NULL, wp_app_password = NULL WHERE id = ?')
           ->execute([$_SESSION['client_id']]);
        echo json_encode(['success' => true, 'message' => 'WordPress disconnected']);
        exit;
    }

    // Article actions — require a valid article ID
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Article ID required']);
        exit;
    }

    $check = $db->prepare('SELECT id, status FROM articles WHERE id = ? AND client_id = ?');
    $check->execute([$id, $_SESSION['client_id']]);
    $article = $check->fetch();

    if (!$article) {
        http_response_code(404);
        echo json_encode(['error' => 'Article not found']);
        exit;
    }

    if ($action === 'save') {
        $title    = trim($body['title']    ?? '');
        $content  = trim($body['content']  ?? '');
        $metaDesc = trim($body['meta_desc'] ?? '');

        if (!$title) {
            http_response_code(400);
            echo json_encode(['error' => 'Title is required']);
            exit;
        }

        $db->prepare('UPDATE articles SET title = ?, content = ?, meta_desc = ? WHERE id = ?')
           ->execute([$title, $content, $metaDesc, $id]);
        echo json_encode(['success' => true, 'message' => '✓ Changes saved']);

    } elseif ($action === 'approve') {
        // Mark as approved first
        $db->prepare('UPDATE articles SET status = "approved", approved_at = NOW() WHERE id = ?')
           ->execute([$id]);

        // Fetch full client record to check WordPress credentials
        $clientStmt = $db->prepare('SELECT * FROM clients WHERE id = ?');
        $clientStmt->execute([$_SESSION['client_id']]);
        $fullClient = $clientStmt->fetch();

        if (!empty($fullClient['wp_url']) && !empty($fullClient['wp_username']) && !empty($fullClient['wp_app_password'])) {
            // WordPress connected — publish now
            $result = publishToWordPress($id, $fullClient);
            if ($result['ok']) {
                $msg = $result['scheduled']
                    ? '✓ Article approved and scheduled on WordPress'
                    : '✓ Article approved and published live on WordPress';
                echo json_encode([
                    'success'    => true,
                    'message'    => $msg,
                    'wp_post_id' => $result['wp_post_id'],
                    'wp_url'     => $result['wp_url'],
                    'published'  => true,
                ]);
            } else {
                // WP publish failed — article stays approved, show warning
                echo json_encode([
                    'success'   => true,
                    'message'   => '✓ Article approved — WordPress publish failed: ' . $result['error'],
                    'wp_error'  => $result['error'],
                    'published' => false,
                ]);
            }
        } else {
            // No WordPress — just approved, cron will handle publishing later
            echo json_encode([
                'success'   => true,
                'message'   => '✓ Article approved and queued for publishing',
                'published' => false,
            ]);
        }

    } elseif ($action === 'reject') {
        $db->prepare('UPDATE articles SET status = "draft", approved_at = NULL WHERE id = ?')
           ->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Article moved back to draft']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
