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
 * Check Google ranking position for a keyphrase via Jina AI search.
 * Returns 1-10 (position) or null (not found in top 10).
 */
function checkRanking(string $keyphrase, string $domain, string $location = ''): ?int
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
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || !$response) return null;

    $data    = json_decode($response, true);
    $results = $data['data'] ?? [];

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
