<?php
// Stripe webhook — receives payment events and updates client plan in DB
// Set up in Stripe Dashboard → Developers → Webhooks → Add endpoint
// Endpoint URL: https://auto-seo.co.uk/stripe-webhook.php

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// ── Load .env ─────────────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

$webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

// ── Verify Stripe signature ───────────────────────────────────────
function verifyStripeSignature(string $payload, string $sigHeader, string $secret): bool {
    if (!$sigHeader || !$secret) return false;

    $timestamp  = null;
    $signatures = [];

    foreach (explode(',', $sigHeader) as $part) {
        $part = trim($part);
        [$key, $val] = array_pad(explode('=', $part, 2), 2, '');
        if ($key === 't')  $timestamp    = $val;
        if ($key === 'v1') $signatures[] = $val;
    }

    if (!$timestamp || empty($signatures)) return false;

    // Reject events older than 5 minutes (replay attack protection)
    if (abs(time() - (int)$timestamp) > 300) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

if (!verifyStripeSignature($payload, $sigHeader, $webhookSecret)) {
    http_response_code(400);
    logWebhook('signature_failed', ['sig' => substr($sigHeader, 0, 60)]);
    exit('Invalid signature');
}

$event = json_decode($payload, true);
if (!$event || !isset($event['type'])) {
    http_response_code(400);
    exit('Invalid payload');
}

// ── Log all incoming events ───────────────────────────────────────
logWebhook('received', ['type' => $event['type'], 'id' => $event['id'] ?? '']);

// ── Route events ──────────────────────────────────────────────────
require_once __DIR__ . '/db.php';

// ── Plan tier lookup by monthly price (in pence) ──────────────────
function planTierFromAmount(int $pence): array {
    return match(true) {
        $pence <=  4900 => ['tier' => 'local_starter',    'kps' => 1],
        $pence <=  7900 => ['tier' => 'local_growth',     'kps' => 3],
        $pence <=  9900 => ['tier' => 'national_starter', 'kps' => 1],
        $pence <= 12900 => ['tier' => 'local_pro',        'kps' => 5],
        $pence <= 14900 => ['tier' => 'national_growth',  'kps' => 3],
        $pence <= 24900 => ['tier' => 'national_pro',     'kps' => 5],
        default         => ['tier' => 'local_starter',    'kps' => 1],
    };
}

// ── Derive tier from a Stripe subscription object ─────────────────
function tierFromSubscription(array $sub): array {
    $item   = $sub['items']['data'][0] ?? [];
    $price  = $item['price'] ?? [];
    $amount = (int)($price['unit_amount'] ?? 0);
    // Nickname on the price overrides amount-based lookup
    $nick   = strtolower(trim($price['nickname'] ?? $price['metadata']['plan_tier'] ?? ''));
    $tierMap = [
        'local_starter' => ['tier'=>'local_starter','kps'=>1],
        'local_growth'  => ['tier'=>'local_growth', 'kps'=>3],
        'local_pro'     => ['tier'=>'local_pro',    'kps'=>5],
        'national_starter'=>['tier'=>'national_starter','kps'=>1],
        'national_growth' =>['tier'=>'national_growth', 'kps'=>3],
        'national_pro'  => ['tier'=>'national_pro', 'kps'=>5],
    ];
    return $tierMap[$nick] ?? planTierFromAmount($amount);
}

try {
    $db = getDB();

    switch ($event['type']) {

        // ── Payment completed (Payment Link or one-time checkout) ──
        case 'checkout.session.completed':
            $session        = $event['data']['object'];
            $email          = strtolower(trim($session['customer_details']['email'] ?? $session['customer_email'] ?? ''));
            $customerId     = $session['customer']     ?? null;
            $subscriptionId = $session['subscription'] ?? null;
            $amountTotal    = (int)($session['amount_total'] ?? 0);

            if (!$email) {
                logWebhook('checkout_no_email', $session);
                break;
            }

            $stmt = $db->prepare('SELECT id, plan FROM clients WHERE email = ?');
            $stmt->execute([$email]);
            $client = $stmt->fetch();

            if (!$client) {
                logWebhook('checkout_no_client', ['email' => $email]);
                break;
            }

            // Derive plan tier from amount paid
            $tierInfo = planTierFromAmount($amountTotal);

            $db->prepare('UPDATE clients SET
                    plan = "active",
                    plan_tier = ?,
                    max_keyphrases = ?,
                    stripe_customer_id = ?,
                    stripe_subscription_id = ?,
                    updated_at = NOW()
                  WHERE id = ?')
               ->execute([$tierInfo['tier'], $tierInfo['kps'], $customerId, $subscriptionId, $client['id']]);

            logWebhook('plan_activated', [
                'email'  => $email,
                'amount' => $amountTotal,
                'tier'   => $tierInfo['tier'],
                'kps'    => $tierInfo['kps'],
            ]);

            sendActivationEmail($email, $db, $client['id']);
            break;

        // ── Subscription renewed / updated ────────────────────────
        case 'customer.subscription.updated':
            $sub    = $event['data']['object'];
            $status = $sub['status'] ?? '';
            $custId = $sub['customer'] ?? null;
            $subId  = $sub['id'] ?? null;

            if (!$custId) break;

            $cancelAtEnd = (bool)($sub['cancel_at_period_end'] ?? false);

            if ($cancelAtEnd) {
                $newPlan = 'cancelling';
                $db->prepare('UPDATE clients SET plan = ?, plan_tier = "cancelling", stripe_subscription_id = ?, updated_at = NOW() WHERE stripe_customer_id = ?')
                   ->execute([$newPlan, $subId, $custId]);
            } elseif (in_array($status, ['active', 'trialing'])) {
                $tierInfo = tierFromSubscription($sub);
                $db->prepare('UPDATE clients SET
                        plan = "active",
                        plan_tier = ?,
                        max_keyphrases = ?,
                        stripe_subscription_id = ?,
                        updated_at = NOW()
                      WHERE stripe_customer_id = ?')
                   ->execute([$tierInfo['tier'], $tierInfo['kps'], $subId, $custId]);
                $newPlan = $tierInfo['tier'];
            } else {
                $newPlan = 'cancelled';
                $db->prepare('UPDATE clients SET plan = "cancelled", plan_tier = "cancelled", stripe_subscription_id = ?, updated_at = NOW() WHERE stripe_customer_id = ?')
                   ->execute([$subId, $custId]);
            }

            logWebhook('subscription_updated', ['stripe_status' => $status, 'plan' => $newPlan, 'customer' => $custId]);
            break;

        // ── Subscription cancelled ────────────────────────────────
        case 'customer.subscription.deleted':
            $sub    = $event['data']['object'];
            $custId = $sub['customer'] ?? null;

            if (!$custId) break;

            $db->prepare('UPDATE clients SET plan = "cancelled", plan_tier = "cancelled", updated_at = NOW() WHERE stripe_customer_id = ?')
               ->execute([$custId]);

            logWebhook('subscription_cancelled', ['customer' => $custId]);
            break;

        // ── Payment failed ────────────────────────────────────────
        case 'invoice.payment_failed':
            $invoice = $event['data']['object'];
            $custId  = $invoice['customer'] ?? null;
            $email   = $invoice['customer_email'] ?? null;

            logWebhook('payment_failed', ['customer' => $custId, 'email' => $email]);

            // Optional: send a payment failed email to the client
            if ($email) {
                $subject = 'Action needed — Auto-Seo payment failed';
                $body    = "Hi,\n\nWe were unable to process your Auto-Seo payment. "
                    . "Please update your payment details to keep your account active:\n\n"
                    . "https://auto-seo.co.uk/login.php\n\n"
                    . "If you need any help, just reply to this email.\n\n"
                    . "The Auto-Seo Team";
                $headers = "From: Auto-Seo <hello@auto-seo.co.uk>\r\nReply-To: hello@auto-seo.co.uk";
                mail($email, $subject, $body, $headers, '-f hello@auto-seo.co.uk');
            }
            break;

        default:
            logWebhook('unhandled_event', ['type' => $event['type']]);
            break;
    }

} catch (Exception $e) {
    logWebhook('db_error', ['message' => $e->getMessage()]);
    http_response_code(500);
    exit('Server error');
}

http_response_code(200);
echo 'OK';


// ── Helpers ───────────────────────────────────────────────────────

function logWebhook(string $type, array $data = []): void {
    $entry = date('Y-m-d H:i:s') . " | $type | " . json_encode($data) . "\n";
    file_put_contents(__DIR__ . '/webhook_log.txt', $entry, FILE_APPEND);
}

function sendActivationEmail(string $email, PDO $db, int $clientId): void {
    $stmt = $db->prepare('SELECT first_name, brand_name FROM clients WHERE id = ?');
    $stmt->execute([$clientId]);
    $c = $stmt->fetch();

    $name  = $c['first_name'] ?: 'there';
    $brand = $c['brand_name'] ?: 'your business';

    $subject = "You're live on Auto-Seo! 🚀";
    $body    = "Hi $name,\n\n"
        . "Your Auto-Seo account for $brand is now active.\n\n"
        . "We'll start generating your SEO articles right away. "
        . "Log in to your dashboard to see your content calendar and approve articles before they go live:\n\n"
        . "https://auto-seo.co.uk/dashboard.php\n\n"
        . "If you have any questions, just reply to this email.\n\n"
        . "The Auto-Seo Team\n"
        . "hello@auto-seo.co.uk";

    $headers  = "From: Auto-Seo <hello@auto-seo.co.uk>\r\n";
    $headers .= "Reply-To: hello@auto-seo.co.uk";

    mail($email, $subject, $body, $headers, '-f hello@auto-seo.co.uk');
}
