<?php
/**
 * rank-helper.php
 * Shared rank-checking functions used by:
 *   - check-rankings.php  (weekly cron)
 *   - dashboard-settings.php  (on keyphrase save)
 *   - dashboard-rankings.php  (on keyphrase add)
 */

/**
 * Check and immediately save Google position for a single keyphrase.
 * Returns ['position' => int|null, 'cached' => bool]
 */
function checkAndSaveRanking(int $clientId, string $keyphrase, string $domain, string $location, PDO $db): array
{
    $today = date('Y-m-d');

    // Already checked today? Return cached result
    $existing = $db->prepare('SELECT position FROM rankings WHERE client_id = ? AND keyphrase = ? AND checked_at = ?');
    $existing->execute([$clientId, $keyphrase, $today]);
    $row = $existing->fetch();
    if ($row !== false) {
        return ['position' => $row['position'], 'cached' => true];
    }

    $position = checkRanking($keyphrase, $domain, $location);

    $db->prepare('INSERT INTO rankings (client_id, keyphrase, position, checked_at)
                  VALUES (?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE position = VALUES(position)')
       ->execute([$clientId, $keyphrase, $position, $today]);

    return ['position' => $position, 'cached' => false];
}

/**
 * Run rank checks for all of a client's keyphrases immediately.
 * Returns array of results keyed by keyphrase.
 */
function checkAllKeyphrasesForClient(array $client, PDO $db): array
{
    $domain   = extractDomain($client['website_url'] ?? '');
    $location = $client['location'] ?? '';
    $results  = [];

    if (!$domain) return $results;

    $keyphrases = [];
    if (!empty($client['keyphrase'])) $keyphrases[] = $client['keyphrase'];
    $extra = json_decode($client['tracked_keyphrases'] ?? '[]', true) ?: [];
    foreach ($extra as $kp) {
        if ($kp && !in_array($kp, $keyphrases)) $keyphrases[] = $kp;
    }

    foreach ($keyphrases as $kp) {
        $result = checkAndSaveRanking($client['id'], $kp, $domain, $location, $db);
        $results[$kp] = $result;
        if (!$result['cached']) sleep(2); // rate-limit Jina
    }

    return $results;
}

/**
 * Check Google ranking position for a keyphrase via Serper.dev.
 * Returns 1-100 (position) or null (not found in top 100).
 */
function checkRanking(string $keyphrase, string $domain, string $location = ''): ?int
{
    $serperKey = $_ENV['SERPER_API_KEY'] ?? '';

    if (!$serperKey) {
        // Fallback to Jina if no Serper key
        return checkRankingJina($keyphrase, $domain, $location);
    }

    // Detect country from location — default GB
    $countryCode = 'gb';
    $locationLower = strtolower($location);
    if (str_contains($locationLower, 'spain') || str_contains($locationLower, 'marbella')
        || str_contains($locationLower, 'madrid') || str_contains($locationLower, 'barcelona')) {
        $countryCode = 'es';
    } elseif (str_contains($locationLower, 'usa') || str_contains($locationLower, 'united states')) {
        $countryCode = 'us';
    } elseif (str_contains($locationLower, 'ireland')) {
        $countryCode = 'ie';
    } elseif (str_contains($locationLower, 'australia')) {
        $countryCode = 'au';
    }

    $payload = json_encode([
        'q'    => $keyphrase,
        'gl'   => $countryCode,
        'hl'   => 'en',
        'num'  => 100,
    ]);

    $ch = curl_init('https://google.serper.dev/search');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'X-API-KEY: ' . $serperKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;

    $data    = json_decode($response, true);
    $organic = $data['organic'] ?? [];

    foreach ($organic as $result) {
        $link = $result['link'] ?? '';
        if (isDomainMatch($link, $domain)) {
            return (int)($result['position'] ?? 0) ?: null;
        }
    }

    return null;
}

/**
 * Fallback: Jina AI search (if no Serper key).
 */
function checkRankingJina(string $keyphrase, string $domain, string $location = ''): ?int
{
    $jinaKey = $_ENV['JINA_API_KEY'] ?? '';
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
    curl_close($ch);

    if (!$response) return null;
    $data    = json_decode($response, true);
    $results = $data['data'] ?? [];

    foreach ($results as $i => $result) {
        $resultUrl = $result['url'] ?? ($result['link'] ?? '');
        if (isDomainMatch($resultUrl, $domain)) return $i + 1;
        if ($i >= 9) break;
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
