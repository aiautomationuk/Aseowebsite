<?php
/**
 * Weekly SERP rank checker
 * Checks Google positions for each client's tracked keyphrases via Jina AI
 *
 * Add to cPanel Cron Jobs (once per week, e.g. Monday 07:00):
 * /usr/local/bin/php /home/USERNAME/public_html/check-rankings.php >> /home/USERNAME/public_html/cron_log.txt 2>&1
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    // Allow admin HTTP trigger
    require_once __DIR__ . '/db.php';
    $envFile = __DIR__ . '/.env';
    if (file_exists($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            [$k, $v] = explode('=', $line, 2);
            $_ENV[trim($k)] = trim($v);
        }
    }
    $adminKey = $_ENV['ADMIN_KEY'] ?? '';
    $provided = $_GET['key'] ?? ($_POST['admin_key'] ?? '');
    if (!$adminKey || !hash_equals($adminKey, $provided)) {
        http_response_code(403); echo 'Forbidden'; exit;
    }
    header('Content-Type: text/plain');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rank-helper.php';

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

$startTime = microtime(true);
echo "\n" . str_repeat('─', 60) . "\n";
echo "Rank Checker — " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('─', 60) . "\n";

$today = date('Y-m-d');

try {
    $db = getDB();

    // Get all active clients with a website URL and at least one keyphrase
    $clients = $db->query("
        SELECT id, email, first_name, brand_name, website_url,
               keyphrase, location, tracked_keyphrases, plan_tier
        FROM clients
        WHERE status = 'active'
          AND website_url IS NOT NULL
          AND (keyphrase IS NOT NULL OR tracked_keyphrases IS NOT NULL)
    ")->fetchAll();

    if (empty($clients)) {
        echo "No clients to check.\n";
        exit;
    }

    echo "Checking " . count($clients) . " client(s)…\n\n";

    $totalChecked = 0;
    $totalFound   = 0;

    foreach ($clients as $client) {
        $domain   = extractDomain($client['website_url']);
        $location = $client['location'] ?: '';

        // Build list of keyphrases to check
        $keyphrases = [];

        // Primary keyphrase always checked
        if ($client['keyphrase']) $keyphrases[] = $client['keyphrase'];

        // Additional tracked keyphrases (from settings)
        if ($client['tracked_keyphrases']) {
            $extra = json_decode($client['tracked_keyphrases'], true);
            if (is_array($extra)) {
                foreach ($extra as $kp) {
                    if ($kp && !in_array($kp, $keyphrases)) $keyphrases[] = $kp;
                }
            }
        }

        echo "► [{$client['id']}] {$client['brand_name']} ($domain)\n";

        foreach ($keyphrases as $keyphrase) {
            // Skip if already checked today
            $existing = $db->prepare('SELECT id FROM rankings WHERE client_id = ? AND keyphrase = ? AND checked_at = ?');
            $existing->execute([$client['id'], $keyphrase, $today]);
            if ($existing->fetchColumn()) {
                echo "   · \"$keyphrase\" — already checked today, skipping\n";
                continue;
            }

            $position = checkRanking($keyphrase, $domain, $location);
            $totalChecked++;
            if ($position) $totalFound++;

            // Upsert ranking record
            $db->prepare('INSERT INTO rankings (client_id, keyphrase, position, checked_at)
                          VALUES (?,?,?,?)
                          ON DUPLICATE KEY UPDATE position = VALUES(position)')
               ->execute([$client['id'], $keyphrase, $position, $today]);

            $posDisplay = $position ? "#$position" : "Not in top 10";
            echo "   · \"$keyphrase\" → $posDisplay\n";

            // Small delay to avoid Jina rate limits
            sleep(2);
        }
        echo "\n";
    }

    $elapsed = round(microtime(true) - $startTime, 1);
    echo str_repeat('─', 60) . "\n";
    echo "Done. Checked: $totalChecked | Found in top 10: $totalFound | Time: {$elapsed}s\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}


// ══════════════════════════════════════════════════════════════════
// Functions are provided by rank-helper.php
// ══════════════════════════════════════════════════════════════════

if (false) { // kept for reference only — actual functions in rank-helper.php
function checkRanking(string $keyphrase, string $domain, string $location = ''): ?int
{
    $jinaKey = $_ENV['JINA_API_KEY'] ?? '';

    // Search with location context
    $query   = $keyphrase . ($location ? ' ' . $location : '');
    $encoded = urlencode($query);
    $url     = "https://s.jina.ai/$encoded";

    $headers = ['Accept: application/json', 'X-Return-Format: json'];
    if ($jinaKey) $headers[] = "Authorization: Bearer $jinaKey";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || !$response) return null;

    $data    = json_decode($response, true);
    $results = $data['data'] ?? [];

    // Also try text parsing if JSON data array is empty
    if (empty($results)) {
        // Fall back to scanning raw text for domain occurrences
        $lines = explode("\n", $response);
        $pos   = 0;
        foreach ($lines as $line) {
            if (preg_match('/https?:\/\//i', $line)) {
                $pos++;
                if (isDomainMatch($line, $domain)) return $pos;
                if ($pos >= 10) break;
            }
        }
        return null;
    }

    // Scan structured results
    foreach ($results as $i => $result) {
        $resultUrl = $result['url'] ?? ($result['link'] ?? '');
        if (isDomainMatch($resultUrl, $domain)) {
            return $i + 1;
        }
        if ($i >= 9) break; // Top 10 only
    }

    return null;
}

function isDomainMatch(string $url, string $targetDomain): bool
{
    $parsed = parse_url(strtolower(trim($url)), PHP_URL_HOST) ?: strtolower($url);
    $parsed = preg_replace('/^www\./', '', $parsed);
    return $parsed === $targetDomain || str_ends_with($parsed, '.' . $targetDomain);
}

function extractDomain(string $url): string
{
    $host = parse_url(strtolower(trim($url)), PHP_URL_HOST) ?: $url;
    return preg_replace('/^www\./', '', $host);
}
} // end if(false)
