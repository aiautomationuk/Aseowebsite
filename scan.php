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
$apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
$model  = $_ENV['OPENAI_MODEL']   ?? 'gpt-4o-mini';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'API key not configured on server.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$url  = isset($body['url']) ? trim($body['url']) : '';

if (!$url) {
    http_response_code(400);
    echo json_encode(['error' => 'No URL provided']);
    exit;
}

if (!preg_match('/^https?:\/\//i', $url)) {
    $url = 'https://' . $url;
}

// ── STEP 1: Fetch website text via Jina ──────────────────────────
$websiteText = null;
$ch = curl_init('https://r.jina.ai/' . $url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'AutoSEO/1.0',
    CURLOPT_SSL_VERIFYPEER => false,
]);
$jinaResponse = curl_exec($ch);
$jinaStatus   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($jinaStatus === 200 && $jinaResponse) {
    $cleaned = preg_replace('/\s+/', ' ', trim($jinaResponse));
    if (strlen($cleaned) >= 200) {
        $websiteText = substr($cleaned, 0, 10000);
    }
}

if (!$websiteText) {
    http_response_code(422);
    echo json_encode(['error' => 'Could not read that website. Please check the URL and try again.']);
    exit;
}

// ── STEP 2: First AI call — extract keyphrase + basic analysis ────
$prompt1 = 'You are a senior SEO strategist based in the UK. Carefully analyse this website and return ONLY valid JSON (no markdown, no explanation).

IMPORTANT: Use British English spelling throughout — e.g. optimise (not optimize), analyse (not analyze), organise (not organize), recognise (not recognize), colour (not color), customise (not customize), specialise (not specialize), behaviour (not behavior), catalogue (not catalog).

RULES — read carefully before answering:

1. "summary": One clear sentence describing WHAT the business does and WHO it serves. Be specific — mention the industry, service type, and target customer. Do NOT use the brand name.

2. "brand_name": The actual trading name of the business (e.g. "FB Commerce" not "fbcommerce.co.uk").

3. "location": The specific city, town, county or region where this business operates or is based. If they serve a whole country write the country name. If truly global write empty string.

4. "service_area": Must be exactly one of: "local", "national", or "global".

5. "primary_keyphrase": The single highest-value Google search phrase a potential customer would type to find this type of business. This must be:
   - A phrase real people search for (think: "Facebook advertising agency UK", not a product name like "Lead Ads")
   - 3–5 words
   - No brand name
   - Based on the business TYPE and their TARGET CUSTOMER, not their product names

6. "keywords": Exactly 5 SEO search phrases. These must be:
   - Phrases real customers type into Google to find this TYPE of service
   - Varied in intent (e.g. informational, transactional, local)
   - NO product names, feature names, or jargon from the website
   - Example good keywords: "social media marketing agency Manchester", "hire Facebook ads specialist", "Instagram advertising for ecommerce"
   - Example BAD keywords: "Lead Ads", "Call Ads" — these are product names, not search phrases

7. "services": The 3 most important SERVICE CATEGORIES this business offers. These should be short, clear service names a customer would recognise (e.g. "Facebook Advertising", "Instagram Marketing", "Paid Social Strategy"). NOT platform feature names.

8. "fallback_competitors": At least 5 real businesses that directly compete with this company — same TYPE of business, same target customer. These must be:
   - Real businesses with real websites you are confident exist
   - The same business model (e.g. if this is a Facebook ads agency, find other Facebook ads agencies — NOT SaaS tools like Hootsuite or Buffer)
   - Include their real homepage URL
   - Include one line explaining why they compete

Website URL: ' . $url . '
Website content:
"""' . $websiteText . '"""

Return this exact JSON structure:
{
  "summary": "",
  "brand_name": "",
  "location": "",
  "service_area": "",
  "primary_keyphrase": "",
  "keywords": ["","","","",""],
  "services": ["","",""],
  "fallback_competitors": [
    {"name": "", "url": "", "reason": ""}
  ]
}';

$aiData1 = openaiCall($apiKey, $model, $prompt1);
if (!$aiData1) {
    http_response_code(502);
    echo json_encode(['error' => 'AI scan failed. Please try again.']);
    exit;
}

$keyphrase = $aiData1['primary_keyphrase'] ?? '';

// ── STEP 3: Search SERP for that keyphrase via Jina Search ────────
$serpText = null;
if ($keyphrase) {
    $searchUrl = 'https://s.jina.ai/' . urlencode($keyphrase);
    $ch = curl_init($searchUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'AutoSEO/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: text/plain'],
    ]);
    $serpResponse = curl_exec($ch);
    $serpStatus   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($serpStatus === 200 && $serpResponse) {
        $serpText = substr(preg_replace('/\s+/', ' ', trim($serpResponse)), 0, 8000);
    }
}

// ── STEP 4: Second AI call — identify competitors from SERP ───────
$competitors = [];
$targetHost  = parse_url($url, PHP_URL_HOST) ?? '';
$targetHost  = preg_replace('/^www\./', '', strtolower($targetHost));

function isTargetDomain(string $competitorUrl, string $targetHost): bool {
    $host = parse_url($competitorUrl, PHP_URL_HOST) ?? '';
    $host = preg_replace('/^www\./', '', strtolower($host));
    return $host === $targetHost || empty($host);
}

function isExcluded(string $competitorUrl): bool {
    $excluded = ['wikipedia.org','facebook.com','linkedin.com','twitter.com','instagram.com',
                 'youtube.com','yelp.com','trustpilot.com','checkatrade.com','yell.com',
                 'indeed.com','glassdoor.com','amazon.','google.','apple.com'];
    foreach ($excluded as $ex) {
        if (stripos($competitorUrl, $ex) !== false) return true;
    }
    return false;
}

if ($serpText) {
    $prompt2 = 'You are a UK-based SEO competitor analyst. Use British English spelling throughout (e.g. optimise, analyse, organise, specialise, recognise, behaviour, colour).

Target business: ' . $url . '
Keyphrase searched: "' . $keyphrase . '"

Below are the top Google search results for that keyphrase. Your job is to identify REAL DIRECT COMPETITORS — businesses that:
- Offer the SAME TYPE of service or product as the target business
- Target the SAME type of customer
- Are actual businesses (NOT directories, NOT Wikipedia, NOT news articles, NOT social media platforms, NOT SaaS tools unless the target is also a SaaS tool)

Return ONLY a valid JSON array (no markdown, no explanation):
[
  {"name": "Actual Business Name", "url": "https://their-homepage.com", "reason": "one sentence why they directly compete"}
]

Return between 4 and 8 competitors. Use the real trading name of the business, not the domain.

Search results:
"""' . $serpText . '"""';

    $aiData2 = openaiCall($apiKey, $model, $prompt2);
    if (is_array($aiData2)) {
        foreach ($aiData2 as $c) {
            if (empty($c['url'])) continue;
            if (isTargetDomain($c['url'], $targetHost)) continue;
            if (isExcluded($c['url'])) continue;
            $competitors[] = [
                'name'   => $c['name']   ?? parse_url($c['url'], PHP_URL_HOST),
                'url'    => $c['url'],
                'reason' => $c['reason'] ?? 'Ranks for "' . $keyphrase . '"',
            ];
        }
    }
}

// ── Fallback: use AI-suggested competitors if SERP gave fewer than 3 ──
if (count($competitors) < 3 && !empty($aiData1['fallback_competitors'])) {
    foreach ($aiData1['fallback_competitors'] as $c) {
        if (count($competitors) >= 6) break;
        if (empty($c['url'])) continue;
        if (isTargetDomain($c['url'], $targetHost)) continue;
        if (isExcluded($c['url'])) continue;
        // Don't duplicate
        $alreadyIn = false;
        foreach ($competitors as $existing) {
            if (parse_url($existing['url'], PHP_URL_HOST) === parse_url($c['url'], PHP_URL_HOST)) {
                $alreadyIn = true;
                break;
            }
        }
        if (!$alreadyIn) {
            $competitors[] = [
                'name'   => $c['name']   ?? parse_url($c['url'], PHP_URL_HOST),
                'url'    => $c['url'],
                'reason' => $c['reason'] ?? 'Competitor in the same niche',
            ];
        }
    }
}

// ── Return combined result ────────────────────────────────────────
echo json_encode([
    'summary'           => $aiData1['summary']           ?? '',
    'brand_name'        => $aiData1['brand_name']        ?? '',
    'location'          => $aiData1['location']          ?? '',
    'service_area'      => $aiData1['service_area']      ?? '',
    'primary_keyphrase' => $keyphrase,
    'keywords'          => $aiData1['keywords']           ?? [],
    'services'          => $aiData1['services']           ?? [],
    'competitors'       => $competitors,
]);

// ── Helper: OpenAI API call ───────────────────────────────────────
function openaiCall(string $apiKey, string $model, string $prompt): ?array {
    $payload = json_encode([
        'model'       => $model,
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.2,
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($status !== 200 || !$response) return null;

    $data    = json_decode($response, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    $content = trim($content);
    $content = preg_replace('/^```json\n?/', '', $content);
    $content = preg_replace('/\n?```$/',     '', $content);

    return json_decode($content, true);
}
