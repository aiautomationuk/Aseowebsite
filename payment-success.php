<?php
// ── Load .env ─────────────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($key, $val) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($val);
    }
}
$stripeSecret = $_ENV['STRIPE_SECRET_KEY'] ?? '';

$sessionId = $_GET['session_id'] ?? '';
$paid = false;

// Verify with Stripe if we have a session ID and secret key
if ($sessionId && $stripeSecret) {
    $ch = curl_init("https://api.stripe.com/v1/checkout/sessions/" . urlencode($sessionId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $stripeSecret . ':',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (isset($data['payment_status']) && $data['payment_status'] === 'paid') {
        $paid = true;
    }
} elseif ($sessionId) {
    // No secret key configured — trust the session_id presence as basic gate
    $paid = true;
}

if ($paid) {
    // Set a PHP session cookie so the wizard pages know payment is done
    session_start();
    $_SESSION['paid'] = true;
    $_SESSION['stripe_session'] = $sessionId;
    header('Location: final.html');
    exit;
} else {
    header('Location: payment.html');
    exit;
}
