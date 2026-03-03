<?php
// Shared auth helper — include in any page that requires a logged-in client

require_once __DIR__ . '/db.php';

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 86400 * 30,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function requireLogin(): array {
    startSecureSession();
    if (empty($_SESSION['client_id'])) {
        header('Location: /login.php');
        exit;
    }
    $db     = getDB();
    $stmt   = $db->prepare('SELECT * FROM clients WHERE id = ? AND status = "active"');
    $stmt->execute([$_SESSION['client_id']]);
    $client = $stmt->fetch();
    if (!$client) {
        session_destroy();
        header('Location: /login.php');
        exit;
    }
    return $client;
}

function loginClient(int $id): void {
    startSecureSession();
    session_regenerate_id(true);
    $_SESSION['client_id'] = $id;
}

function logoutClient(): void {
    startSecureSession();
    session_destroy();
}
