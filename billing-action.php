<?php
/**
 * billing-action.php
 * AJAX endpoint for billing actions (cancel subscription)
 */
require_once __DIR__ . '/auth.php';
$client = requireLogin();
$db     = getDB();

header('Content-Type: application/json');

$raw    = file_get_contents('php://input');
$body   = json_decode($raw, true) ?: [];
$action = $body['action'] ?? '';

// ── Load .env ─────────────────────────────────────────────────────
$envVars = [];
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $envVars[trim($k)] = trim($v);
    }
}

// ── Cancel subscription ───────────────────────────────────────────
if ($action === 'cancel') {
    $password = $body['password'] ?? '';

    // 1. Verify password
    if (!$password) {
        echo json_encode(['ok' => false, 'error' => 'Please enter your password.']);
        exit;
    }
    if (empty($client['password_hash']) || !password_verify($password, $client['password_hash'])) {
        echo json_encode(['ok' => false, 'error' => 'Incorrect password. Please try again.']);
        exit;
    }

    // 2. Check there's a subscription to cancel
    $subId = $client['stripe_subscription_id'] ?? null;
    if (!$subId) {
        // No Stripe subscription — just mark as cancelled in DB
        $db->prepare('UPDATE clients SET plan = "cancelled", plan_tier = "cancelled", updated_at = NOW() WHERE id = ?')
           ->execute([$client['id']]);
        echo json_encode(['ok' => true, 'message' => 'Subscription cancelled successfully.']);
        exit;
    }

    // 3. Call Stripe API to cancel at period end
    $stripeKey = $envVars['STRIPE_SECRET_KEY'] ?? '';
    if (!$stripeKey || str_contains($stripeKey, 'YOUR')) {
        echo json_encode(['ok' => false, 'error' => 'Payment system not configured. Please contact support.']);
        exit;
    }

    $ch = curl_init("https://api.stripe.com/v1/subscriptions/$subId");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => http_build_query(['cancel_at_period_end' => 'true']),
        CURLOPT_USERPWD        => "$stripeKey:",
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = $response ? json_decode($response, true) : null;

    if ($httpCode !== 200 || !$result || isset($result['error'])) {
        $errMsg = $result['error']['message'] ?? 'Stripe error. Please try again or contact support.';
        echo json_encode(['ok' => false, 'error' => $errMsg]);
        exit;
    }

    // 4. Update DB — mark as cancelling (still active until period end)
    $db->prepare('UPDATE clients SET plan_tier = "cancelling", updated_at = NOW() WHERE id = ?')
       ->execute([$client['id']]);

    // 5. Send confirmation email to client
    $periodEnd = isset($result['current_period_end'])
        ? date('d F Y', $result['current_period_end'])
        : 'the end of your billing period';

    $to      = $client['email'];
    $name    = $client['first_name'] ?: 'there';
    $subject = 'Your AutoSEO subscription has been cancelled';
    $html    = '<!DOCTYPE html><html lang="en-GB"><body style="font-family:Inter,Arial,sans-serif;background:#f8fafc;padding:40px 20px">'
             . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:16px;padding:40px;border:1px solid #e2e8f0">'
             . '<p style="font-size:28px;margin:0 0 16px">👋</p>'
             . '<h1 style="font-size:20px;font-weight:800;color:#0f172a;margin:0 0 12px">Subscription cancelled</h1>'
             . "<p style=\"color:#475569;font-size:15px\">Hi $name,</p>"
             . "<p style=\"color:#475569;font-size:15px\">We've cancelled your AutoSEO subscription as requested. "
             . "You'll keep full access to all features until <strong>$periodEnd</strong>.</p>"
             . '<p style="color:#475569;font-size:15px">After that date, no further payments will be taken.</p>'
             . '<div style="background:#f1f5f9;border-radius:12px;padding:20px;margin:24px 0">'
             . '<p style="font-size:14px;color:#64748b;margin:0">Changed your mind? You can reactivate or switch to a new plan any time.</p>'
             . '<a href="https://auto-seo.co.uk/pricing.html" style="display:inline-block;margin-top:12px;background:#6366f1;color:#fff;font-weight:700;padding:10px 20px;border-radius:8px;text-decoration:none;font-size:14px">View plans →</a>'
             . '</div>'
             . '<p style="color:#94a3b8;font-size:13px;margin-top:24px">— The AutoSEO Team</p>'
             . '</div></body></html>';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";
    $headers .= "From: AutoSEO <hello@auto-seo.co.uk>\r\n";
    $headers .= "Reply-To: hello@auto-seo.co.uk\r\n";
    mail($to, $subject, $html, $headers, '-f hello@auto-seo.co.uk');

    echo json_encode([
        'ok'      => true,
        'message' => "Subscription cancelled. You'll keep access until $periodEnd.",
    ]);
    exit;
}

// Unknown action
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
