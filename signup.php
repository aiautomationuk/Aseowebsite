<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

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
$notifyEmail    = $_ENV['NOTIFY_EMAIL']        ?? '';
$pipedriveToken = $_ENV['PIPEDRIVE_API_TOKEN'] ?? '';
// ─────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body  = json_decode(file_get_contents('php://input'), true);
$email = isset($body['email']) ? trim($body['email']) : '';

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email address.']);
    exit;
}

$firstName   = $body['firstName']   ?? '';
$lastName    = $body['lastName']    ?? '';
$phone       = $body['phone']       ?? '';
$websiteUrl  = $body['websiteUrl']  ?? '';
$summary     = $body['summary']     ?? '';
$brandName   = $body['brandName']   ?? '';
$location    = $body['location']    ?? '';
$serviceArea = $body['serviceArea'] ?? '';
$keyphrase   = $body['keyphrase']   ?? '';
$offers      = $body['offers']      ?? [];
$competitors = $body['competitors'] ?? [];

// ── Build admin notification email ───────────────────────────────
$offerList = implode("\n", array_map(fn($o) => '  - ' . ($o['label'] ?? ''), $offers));
$compList  = implode("\n", array_map(fn($c) => '  - ' . ($c['name'] ?? '') . ' (' . ($c['url'] ?? '') . ')', $competitors));

$adminMessage = "New AutoSEO sign-up!\n\n"
    . "Name: $firstName $lastName\n"
    . "Email: $email\n"
    . "Phone: $phone\n\n"
    . "Website Scanned: $websiteUrl\n"
    . "Brand: $brandName\n"
    . "Location: $location ($serviceArea)\n"
    . "Primary Keyphrase: $keyphrase\n"
    . "Summary: $summary\n\n"
    . "Offerings:\n$offerList\n\n"
    . "Competitors:\n$compList\n";

$adminTo      = $notifyEmail ?: 'hello@auto-seo.co.uk';
$adminSubject = "New AutoSEO sign-up — $email";
$adminHeaders = "From: Auto-Seo <hello@auto-seo.co.uk>\r\nReply-To: $email";

mail($adminTo, $adminSubject, $adminMessage, $adminHeaders, '-f hello@auto-seo.co.uk');

// ── Generate login token early (used in email + DB) ──────────────
$loginToken   = bin2hex(random_bytes(32));
$tokenExpires = date('Y-m-d H:i:s', strtotime('+7 days'));

// ── Send welcome email to client ──────────────────────────────────
$firstName1        = $firstName ?: 'there';
$brandDisplay      = $brandName ?: $websiteUrl;
$offerLabelsInline = implode(', ', array_filter(array_map(fn($o) => $o['label'] ?? '', $offers)));

$eName   = htmlspecialchars($firstName1,        ENT_QUOTES, 'UTF-8');
$eBrand  = htmlspecialchars($brandDisplay,      ENT_QUOTES, 'UTF-8');
$eSumm   = htmlspecialchars($summary,           ENT_QUOTES, 'UTF-8');
$eKw     = htmlspecialchars($keyphrase,         ENT_QUOTES, 'UTF-8');
$eOffers = htmlspecialchars($offerLabelsInline, ENT_QUOTES, 'UTF-8');

$kwBlock = $eKw
    ? '<p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6366f1;margin:0 0 8px;">Your Primary Search Term</p>'
      . '<p style="margin:0 0 24px;"><span style="display:inline-block;background:#eef2ff;color:#4f46e5;font-weight:700;font-size:14px;padding:8px 16px;border-radius:999px;border:1px solid #c7d2fe;">' . $eKw . '</span></p>'
    : '';

$offersBlock = $eOffers
    ? '<p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6366f1;margin:0 0 8px;">Services We Will Promote</p>'
      . '<p style="font-size:14px;color:#334155;margin:0 0 24px;">' . $eOffers . '</p>'
    : '';

$welcomeHtml = '<!DOCTYPE html><html lang="en-GB"><head><meta charset="UTF-8"/></head>'
. '<body style="margin:0;padding:0;background:#f8fafc;font-family:Helvetica,Arial,sans-serif;">'
. '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;padding:32px 16px;"><tr><td align="center">'
. '<table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;">'

. '<tr><td style="background:#6366f1;padding:36px 40px;text-align:center;">'
. '<p style="font-size:20px;font-weight:800;color:#ffffff;margin:0 0 12px;">&#9889; Auto-Seo.co.uk</p>'
. '<h1 style="color:#ffffff;font-size:24px;font-weight:800;margin:0 0 8px;">Welcome, ' . $eName . '!</h1>'
. '<p style="color:rgba(255,255,255,0.85);font-size:14px;margin:0;">Your SEO profile for <strong>' . $eBrand . '</strong> is ready.</p>'
. '</td></tr>'

. '<tr><td style="padding:32px 40px;">'
. '<p style="font-size:14px;line-height:1.7;color:#475569;margin:0 0 20px;">Thank you for joining Auto-Seo. We have analysed your website and built your personalised SEO profile. Here is a quick summary of what we found:</p>'

. '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">'
. '<tr><td style="background:#f1f5f9;border-left:4px solid #6366f1;padding:14px 18px;border-radius:0 8px 8px 0;">'
. '<p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#6366f1;margin:0 0 6px;">AI Analysis</p>'
. '<p style="font-size:13px;color:#334155;line-height:1.6;margin:0;">' . ($eSumm ?: 'Your website has been analysed and your SEO profile is ready.') . '</p>'
. '</td></tr></table>'

. $kwBlock
. $offersBlock

. '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;margin-bottom:28px;">'
. '<tr><td style="padding:18px 22px;">'
. '<p style="font-size:13px;font-weight:700;color:#166534;margin:0 0 10px;">What happens next</p>'
. '<p style="font-size:13px;color:#166534;margin:0 0 6px;">&#10003; Your &#163;1 trial gives you 3 SEO articles in 5 minutes</p>'
. '<p style="font-size:13px;color:#166534;margin:0 0 6px;">&#10003; A 30-day content plan tailored to your search terms</p>'
. '<p style="font-size:13px;color:#166534;margin:0;">&#10003; Auto-publish to your website &#8212; no manual work needed</p>'
. '</td></tr></table>'

. '<table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;"><tr><td align="center">'
. '<a href="https://auto-seo.co.uk/set-password.php?token=' . $loginToken . '" style="display:inline-block;background:#6366f1;color:#ffffff;font-weight:700;font-size:14px;padding:14px 32px;border-radius:999px;text-decoration:none;">Access Your Dashboard &rarr;</a>'
. '<p style="font-size:12px;color:#94a3b8;margin:8px 0 0;">Set your password and go straight to your dashboard.</p>'
. '</td></tr></table>'

. '<p style="font-size:13px;color:#475569;margin:0 0 4px;">If you have any questions, just reply to this email.</p>'
. '<p style="font-size:13px;color:#475569;margin:0;">Best regards,<br/><strong>The Auto-Seo Team</strong></p>'
. '</td></tr>'

. '<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:16px 40px;text-align:center;">'
. '<p style="font-size:12px;color:#94a3b8;margin:0;">Auto-Seo.co.uk &nbsp;|&nbsp; <a href="mailto:hello@auto-seo.co.uk" style="color:#6366f1;text-decoration:none;">hello@auto-seo.co.uk</a></p>'
. '</td></tr>'

. '</table></td></tr></table></body></html>';

$welcomeSubject = "Welcome to Auto-Seo, {$firstName1}! Your SEO profile is ready";
$welcomeHeaders  = "From: Auto-Seo <hello@auto-seo.co.uk>\r\n";
$welcomeHeaders .= "Reply-To: hello@auto-seo.co.uk\r\n";
$welcomeHeaders .= "MIME-Version: 1.0\r\n";
$welcomeHeaders .= "Content-Type: text/html; charset=UTF-8";

$sent = mail($email, $welcomeSubject, $welcomeHtml, $welcomeHeaders, '-f hello@auto-seo.co.uk');

// Log email result for debugging
$logEntry = date('Y-m-d H:i:s') . " | welcome_email | to:{$email} | sent:" . ($sent ? 'YES' : 'NO') . "\n";
file_put_contents(__DIR__ . '/mail_log.txt', $logEntry, FILE_APPEND);

// ── Log signup to CSV ─────────────────────────────────────────────
$logFile = __DIR__ . '/signups.csv';
$isNew   = !file_exists($logFile);
$fh      = fopen($logFile, 'a');
if ($isNew) fputcsv($fh, ['date', 'first_name', 'last_name', 'email', 'phone', 'website_url', 'brand', 'location', 'service_area', 'keyphrase', 'summary', 'offers', 'competitors']);
fputcsv($fh, [
    date('Y-m-d H:i:s'),
    $firstName,
    $lastName,
    $email,
    $phone,
    $websiteUrl,
    $brandName,
    $location,
    $serviceArea,
    $keyphrase,
    $summary,
    implode(' | ', array_map(fn($o) => $o['label'] ?? '', $offers)),
    implode(' | ', array_map(fn($c) => $c['name'] ?? '', $competitors)),
]);
fclose($fh);

// ── Create / update client in database ───────────────────────────
require_once __DIR__ . '/db.php';

try {
    $db = getDB();
    // Upsert — if email already exists update their record
    $stmt = $db->prepare('SELECT id FROM clients WHERE email = ?');
    $stmt->execute([$email]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $db->prepare('UPDATE clients SET first_name=?, last_name=?, phone=?, website_url=?, brand_name=?,
            location=?, service_area=?, keyphrase=?, summary=?, offers=?, competitors=?,
            login_token=?, token_expires=?, updated_at=NOW() WHERE email=?')
           ->execute([
               $firstName, $lastName, $phone, $websiteUrl, $brandName,
               $location, $serviceArea, $keyphrase, $summary,
               json_encode($offers), json_encode($competitors),
               $loginToken, $tokenExpires, $email,
           ]);
    } else {
        $db->prepare('INSERT INTO clients (email, first_name, last_name, phone, website_url, brand_name,
            location, service_area, keyphrase, summary, offers, competitors, login_token, token_expires)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
           ->execute([
               $email, $firstName, $lastName, $phone, $websiteUrl, $brandName,
               $location, $serviceArea, $keyphrase, $summary,
               json_encode($offers), json_encode($competitors),
               $loginToken, $tokenExpires,
           ]);
    }
} catch (Exception $e) {
    // DB errors are non-fatal — log and continue
    file_put_contents(__DIR__ . '/mail_log.txt',
        date('Y-m-d H:i:s') . " | db_error | " . $e->getMessage() . "\n", FILE_APPEND);
}

// ── Push to Pipedrive ─────────────────────────────────────────────
if ($pipedriveToken) {
    pipedriveSync(
        $pipedriveToken,
        $firstName, $lastName, $email, $phone,
        $websiteUrl, $brandName, $location, $serviceArea, $keyphrase, $summary,
        $offers, $competitors
    );
}

echo json_encode(['success' => true]);


// ── Pipedrive helper functions ────────────────────────────────────

function pipedriveRequest(string $token, string $method, string $endpoint, array $data = []): ?array {
    $url = 'https://api.pipedrive.com/v1' . $endpoint . '?api_token=' . urlencode($token);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => $method !== 'GET' ? json_encode($data) : null,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ? json_decode($response, true) : null;
}

function pipedriveSync(
    string $token,
    string $firstName, string $lastName, string $email, string $phone,
    string $websiteUrl, string $brandName, string $location, string $serviceArea,
    string $keyphrase, string $summary, array $offers, array $competitors
): void {

    // ── 1. Find or create Person ──────────────────────────────────
    $personId = null;

    // Search for existing contact by email
    $search = pipedriveRequest($token, 'GET', '/persons/search', []);
    // Use dedicated search endpoint
    $searchUrl = 'https://api.pipedrive.com/v1/persons/search?term=' . urlencode($email) . '&fields=email&api_token=' . urlencode($token);
    $ch = curl_init($searchUrl);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
    $searchResult = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($searchResult['data']['items'])) {
        $personId = $searchResult['data']['items'][0]['item']['id'];
    } else {
        // Create new Person
        $personPayload = [
            'name'   => trim("$firstName $lastName") ?: $email,
            'email'  => [['value' => $email, 'primary' => true]],
        ];
        if ($phone) {
            $personPayload['phone'] = [['value' => $phone, 'primary' => true]];
        }
        $personResult = pipedriveRequest($token, 'POST', '/persons', $personPayload);
        $personId     = $personResult['data']['id'] ?? null;
    }

    if (!$personId) return;

    // ── 2. Find or create Organisation ───────────────────────────
    $orgId = null;
    if ($brandName) {
        $orgSearch = 'https://api.pipedrive.com/v1/organizations/search?term=' . urlencode($brandName) . '&fields=name&api_token=' . urlencode($token);
        $ch = curl_init($orgSearch);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_SSL_VERIFYPEER => false]);
        $orgResult = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (!empty($orgResult['data']['items'])) {
            $orgId = $orgResult['data']['items'][0]['item']['id'];
        } else {
            $newOrg = pipedriveRequest($token, 'POST', '/organizations', [
                'name'    => $brandName,
                'address' => $location ?: null,
            ]);
            $orgId = $newOrg['data']['id'] ?? null;
        }

        // Link person to org
        if ($orgId) {
            pipedriveRequest($token, 'PUT', '/persons/' . $personId, ['org_id' => $orgId]);
        }
    }

    // ── 3. Build deal note ────────────────────────────────────────
    $offerLabels = implode(', ', array_map(fn($o) => $o['label'] ?? '', $offers));
    $compNames   = implode(', ', array_map(fn($c) => $c['name'] ?? '', $competitors));

    $dealTitle = $brandName
        ? "$brandName — AutoSEO Lead"
        : "$firstName $lastName — AutoSEO Lead";

    $dealNote = "🌐 Website Scanned: $websiteUrl\n\n"
        . "📊 AI Scan Summary:\n$summary\n\n"
        . "🔑 Primary Keyphrase: $keyphrase\n"
        . "📍 Location: $location ($serviceArea)\n"
        . "🛠 Offerings: $offerLabels\n"
        . "🏆 Competitors: $compNames";

    // ── 4. Create Deal ────────────────────────────────────────────
    $dealPayload = [
        'title'    => $dealTitle,
        'person_id'=> $personId,
        'status'   => 'open',
        'stage_id' => 1, // Stage 1 = first stage in your default pipeline
    ];
    if ($orgId) $dealPayload['org_id'] = $orgId;

    $dealResult = pipedriveRequest($token, 'POST', '/deals', $dealPayload);
    $dealId     = $dealResult['data']['id'] ?? null;

    // ── 5. Add note to the deal ───────────────────────────────────
    if ($dealId) {
        pipedriveRequest($token, 'POST', '/notes', [
            'content'  => $dealNote,
            'deal_id'  => $dealId,
            'pinned_to_deal_flag' => true,
        ]);
    }
}
