<?php
/**
 * wp-publish.php
 * Shared WordPress REST API publishing helper.
 * Include this file wherever you need to publish an article.
 *
 * Main function: publishToWordPress(int $articleId, array $client): array
 * Returns: ['ok' => bool, 'wp_post_id' => int|null, 'wp_url' => string|null, 'error' => string|null]
 */

/**
 * Publish a single article to the client's WordPress site.
 */
function publishToWordPress(int $articleId, array $client): array
{
    // ── Guard: WordPress credentials present? ────────────────────
    $wpUrl  = rtrim($client['wp_url']          ?? '', '/');
    $wpUser = $client['wp_username']            ?? '';
    $wpPass = $client['wp_app_password']        ?? '';

    if (!$wpUrl || !$wpUser || !$wpPass) {
        return ['ok' => false, 'error' => 'WordPress not connected for this client.'];
    }

    // ── Fetch article from DB ─────────────────────────────────────
    $db      = getDB();
    $stmt    = $db->prepare('SELECT * FROM articles WHERE id = ? AND client_id = ?');
    $stmt->execute([$articleId, $client['id']]);
    $article = $stmt->fetch();

    if (!$article) {
        return ['ok' => false, 'error' => 'Article not found.'];
    }

    $title     = $article['title']     ?? 'Untitled';
    $content   = $article['content']   ?? '';
    $metaDesc  = $article['meta_desc'] ?? '';
    $imageUrl  = $article['image_url'] ?? '';
    $schedDate = $article['scheduled_date'] ?? null;

    $authHeader = 'Authorization: Basic ' . base64_encode("$wpUser:$wpPass");

    // ── 1. Upload featured image ──────────────────────────────────
    $featuredMediaId = null;
    if ($imageUrl) {
        $featuredMediaId = uploadImageToWordPress($imageUrl, $title, $wpUrl, $authHeader);
    }

    // ── 2. Determine post status / date ──────────────────────────
    $today = date('Y-m-d');
    if ($schedDate && $schedDate > $today) {
        // Schedule for future
        $postStatus = 'future';
        $postDate   = $schedDate . 'T09:00:00';
    } else {
        $postStatus = 'publish';
        $postDate   = null;
    }

    // ── 3. Build post body ────────────────────────────────────────
    $postBody = [
        'title'   => $title,
        'content' => $content,
        'status'  => $postStatus,
        'excerpt' => $metaDesc,
        'format'  => 'standard',
    ];
    if ($featuredMediaId) {
        $postBody['featured_media'] = $featuredMediaId;
    }
    if ($postDate) {
        $postBody['date'] = $postDate;
    }

    // ── 4. Create the post ────────────────────────────────────────
    $response = wpRequest('POST', "$wpUrl/wp-json/wp/v2/posts", $postBody, $authHeader);

    if (!$response['ok']) {
        return ['ok' => false, 'error' => 'WordPress API error: ' . $response['error']];
    }

    $wpPost   = $response['data'];
    $wpPostId = $wpPost['id']   ?? null;
    $wpLink   = $wpPost['link'] ?? null;

    if (!$wpPostId) {
        return ['ok' => false, 'error' => 'WordPress did not return a post ID.'];
    }

    // ── 5. Inject meta description (Yoast + RankMath) ────────────
    if ($metaDesc) {
        injectSeoMeta($wpPostId, $metaDesc, $wpUrl, $authHeader);
    }

    // ── 6. Update article status in DB ───────────────────────────
    $db->prepare('UPDATE articles SET status = "published", wp_post_id = ?, wp_post_url = ?, approved_at = COALESCE(approved_at, NOW()) WHERE id = ?')
       ->execute([$wpPostId, $wpLink, $articleId]);

    return [
        'ok'        => true,
        'wp_post_id'=> $wpPostId,
        'wp_url'    => $wpLink,
        'scheduled' => ($postStatus === 'future'),
    ];
}

// ══════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════

/**
 * Upload an image to WordPress media library.
 * Returns the WordPress media ID on success, null on failure.
 */
function uploadImageToWordPress(string $imageUrl, string $title, string $wpBase, string $authHeader): ?int
{
    // Download the image to a temp file
    $tmpFile = tempnam(sys_get_temp_dir(), 'wp_img_');
    $ch = curl_init($imageUrl);
    $fh = fopen($tmpFile, 'w');
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($httpCode !== 200 || !filesize($tmpFile)) {
        @unlink($tmpFile);
        return null;
    }

    // Determine MIME type
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mime     = finfo_file($finfo, $tmpFile);
    finfo_close($finfo);
    $ext      = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'jpg',
    };

    $filename = sanitiseFilename($title) . '.' . $ext;

    // Upload via WP REST API
    $ch = curl_init("$wpBase/wp-json/wp/v2/media");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => file_get_contents($tmpFile),
        CURLOPT_HTTPHEADER     => [
            $authHeader,
            "Content-Disposition: attachment; filename=\"$filename\"",
            "Content-Type: $mime",
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmpFile);

    if ($code !== 201) return null;

    $data = json_decode($res, true);
    return isset($data['id']) ? (int)$data['id'] : null;
}

/**
 * Inject SEO meta description via Yoast or RankMath custom fields.
 */
function injectSeoMeta(int $postId, string $metaDesc, string $wpBase, string $authHeader): void
{
    // Try Yoast meta endpoint first
    wpRequest('POST', "$wpBase/wp-json/wp/v2/posts/$postId", [
        'meta' => [
            '_yoast_wpseo_metadesc'       => $metaDesc,
            'rank_math_description'        => $metaDesc,
        ],
    ], $authHeader);
}

/**
 * Generic WordPress REST API request.
 * Returns ['ok' => bool, 'data' => array, 'error' => string]
 */
function wpRequest(string $method, string $url, array $body, string $authHeader): array
{
    $ch = curl_init($url);
    $jsonBody = json_encode($body);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $jsonBody,
        CURLOPT_HTTPHEADER     => [
            $authHeader,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonBody),
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['ok' => false, 'error' => "cURL error: $curlErr", 'data' => []];
    }

    $data = json_decode($response, true) ?: [];

    if ($httpCode >= 400) {
        $msg = $data['message'] ?? $data['error'] ?? "HTTP $httpCode";
        return ['ok' => false, 'error' => $msg, 'data' => $data];
    }

    return ['ok' => true, 'data' => $data, 'error' => null];
}

function sanitiseFilename(string $str): string
{
    $str = strtolower(trim($str));
    $str = preg_replace('/[^a-z0-9]+/', '-', $str);
    return substr(trim($str, '-'), 0, 60) ?: 'article-image';
}
