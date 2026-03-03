<?php
/**
 * Article Generator — with images, LSI keywords & internal links
 * Called by: cron-generate.php (daily), admin.php (on demand)
 * HTTP POST: { client_id, keyphrase? }  → returns JSON
 * CLI:       php generate-article.php [client_id]
 */

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) header('Content-Type: application/json');

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

// ── HTTP entry point ──────────────────────────────────────────────
if (!$isCli) {
    $adminKey = $_ENV['ADMIN_KEY'] ?? '';
    $provided = $_SERVER['HTTP_X_ADMIN_KEY'] ?? ($_POST['admin_key'] ?? '');
    if (!$adminKey || !hash_equals($adminKey, $provided)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    $clientId  = (int)($_POST['client_id'] ?? 0);
    $keyphrase = trim($_POST['keyphrase'] ?? '');
    if (!$clientId) { echo json_encode(['error' => 'client_id required']); exit; }
    echo json_encode(generateArticle($clientId, $keyphrase));
    exit;
}

// ── CLI entry point ───────────────────────────────────────────────
$clientId  = isset($argv[1]) ? (int)$argv[1] : 0;
$keyphrase = $argv[2] ?? '';
if ($clientId) {
    $result = generateArticle($clientId, $keyphrase);
    echo ($result['success'] ? '✓' : '✗') . ' ' . $result['message'] . "\n";
} else {
    echo "Usage: php generate-article.php <client_id> [keyphrase]\n";
}
exit;


// ══════════════════════════════════════════════════════════════════
// CORE FUNCTION
// ══════════════════════════════════════════════════════════════════

function generateArticle(int $clientId, string $forcedKeyphrase = ''): array
{
    $log = [];

    try {
        $db = getDB();

        // ── 1. Load client ────────────────────────────────────────
        $stmt = $db->prepare('SELECT * FROM clients WHERE id = ? AND status = "active"');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        if (!$client) return ['success' => false, 'message' => "Client #$clientId not found or inactive"];

        $brand       = $client['brand_name']   ?: 'the business';
        $location    = $client['location']     ?: 'the UK';
        $serviceArea = $client['service_area'] ?: 'local';
        $primary     = $client['keyphrase']    ?: '';
        $summary     = $client['summary']      ?: '';
        $offers      = json_decode($client['offers'] ?? '[]', true) ?: [];
        $services    = implode(', ', array_filter(array_map(fn($o) => $o['label'] ?? '', $offers)));
        $log[]       = "Client: $brand ($location)";

        // ── 2. Get / generate keyphrase plan ──────────────────────
        if ($forcedKeyphrase) {
            $keyphrase   = $forcedKeyphrase;
            $keyphraseId = null;
            $log[]       = "Forced keyphrase: $keyphrase";
        } else {
            $kCount = $db->prepare('SELECT COUNT(*) FROM keyphrases WHERE client_id = ?');
            $kCount->execute([$clientId]);

            if ((int)$kCount->fetchColumn() === 0) {
                $log[] = "Generating 30-article content plan…";
                $plan  = generateKeyphrasePlan($client);
                if (!$plan) return ['success' => false, 'message' => 'Failed to generate content plan'];
                $ins = $db->prepare('INSERT INTO keyphrases (client_id, keyphrase) VALUES (?,?)');
                foreach ($plan as $kp) $ins->execute([$clientId, $kp]);
                $log[] = count($plan) . " keyphrases created";
            }

            $next = $db->prepare('SELECT id, keyphrase FROM keyphrases WHERE client_id = ? AND used = 0 ORDER BY id ASC LIMIT 1');
            $next->execute([$clientId]);
            $row = $next->fetch();
            if (!$row) return ['success' => false, 'message' => 'All keyphrases used — content plan complete!'];

            $keyphrase   = $row['keyphrase'];
            $keyphraseId = $row['id'];
            $log[]       = "Keyphrase: $keyphrase";
        }

        // ── 3. Generate LSI / related keywords ───────────────────
        $log[] = "Generating LSI keywords…";
        $lsiKeywords = generateLsiKeywords($keyphrase, $services, $location);
        $log[] = count($lsiKeywords) . " LSI keywords";

        // ── 4. Research with Jina ─────────────────────────────────
        $log[] = "Researching topic…";
        $research = jinaResearch($keyphrase, $location);

        // ── 5. Fetch internal links (published articles) ──────────
        $pubStmt = $db->prepare(
            'SELECT title, wp_post_url FROM articles
             WHERE client_id = ? AND status = "published" AND wp_post_url IS NOT NULL
             ORDER BY created_at DESC LIMIT 10'
        );
        $pubStmt->execute([$clientId]);
        $publishedArticles = $pubStmt->fetchAll();
        $log[] = count($publishedArticles) . " published articles for internal links";

        // ── 6. Write article with OpenAI ──────────────────────────
        $log[] = "Writing article…";
        $article = openAiWriteArticle(
            $keyphrase, $brand, $location, $serviceArea,
            $services, $summary, $research, $lsiKeywords
        );
        if (!$article || empty($article['title']) || empty($article['content'])) {
            return ['success' => false, 'message' => 'OpenAI failed to generate article', 'log' => $log];
        }

        // ── 7. Add internal links ─────────────────────────────────
        if (!empty($publishedArticles)) {
            $log[] = "Adding internal links…";
            $article['content'] = addInternalLinks($article['content'], $publishedArticles);
        }

        // ── 8. Generate image with DALL-E 3 ──────────────────────
        $log[]    = "Generating image…";
        $image    = generateAndSaveImage($keyphrase, $brand, $location, $services, $clientId);
        $imageUrl = null;

        if ($image) {
            $article['content'] = insertImageIntoContent($article['content'], $image);
            $imageUrl           = $image['web_url'];
            $log[]              = "Image saved: " . $image['web_url'];
        } else {
            $log[] = "Image generation skipped or failed";
        }

        // ── 9. Scheduled date ─────────────────────────────────────
        $scheduledDate = nextScheduledDate($db, $clientId);

        // ── 10. Save to DB ────────────────────────────────────────
        $db->prepare(
            'INSERT INTO articles (client_id, title, content, keyphrase, meta_desc, image_url, status, scheduled_date)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $clientId,
            $article['title'],
            $article['content'],
            $keyphrase,
            $article['meta_description'] ?? '',
            $imageUrl,
            'draft',
            $scheduledDate,
        ]);
        $articleId = (int)$db->lastInsertId();

        if ($keyphraseId) {
            $db->prepare('UPDATE keyphrases SET used = 1, article_id = ? WHERE id = ?')
               ->execute([$articleId, $keyphraseId]);
        }

        $log[] = "Saved article ID: $articleId, date: $scheduledDate";

        // ── 11. Notify client ─────────────────────────────────────
        notifyClientArticleReady($client, $article['title'], $keyphrase, $scheduledDate);
        $log[] = "Client notified";

        articleLog('generated', [
            'client_id'  => $clientId,
            'article_id' => $articleId,
            'keyphrase'  => $keyphrase,
            'title'      => $article['title'],
            'scheduled'  => $scheduledDate,
            'has_image'  => (bool)$imageUrl,
            'lsi_count'  => count($lsiKeywords),
            'int_links'  => count($publishedArticles),
        ]);

        return [
            'success'    => true,
            'message'    => "Article generated: \"{$article['title']}\"",
            'article_id' => $articleId,
            'title'      => $article['title'],
            'scheduled'  => $scheduledDate,
            'has_image'  => (bool)$imageUrl,
            'log'        => $log,
        ];

    } catch (Exception $e) {
        articleLog('error', ['client_id' => $clientId, 'error' => $e->getMessage()]);
        return ['success' => false, 'message' => 'Exception: ' . $e->getMessage(), 'log' => $log];
    }
}


// ══════════════════════════════════════════════════════════════════
// LSI KEYWORDS
// ══════════════════════════════════════════════════════════════════

function generateLsiKeywords(string $keyphrase, string $services, string $location): array
{
    $prompt = "Generate 14 LSI (semantically related) keywords and phrases for an SEO article about \"$keyphrase\".
Business context: $services in $location, UK.

Include a mix of:
- Synonyms and closely related terms
- Question-style phrases people search
- Supporting topic words
- Location variations

Return ONLY a JSON array of 14 strings. British English. No markdown.";

    $response = openAiCall([['role' => 'user', 'content' => $prompt]], 400);
    if (!$response) return [];

    if (preg_match('/\[.*\]/s', $response, $m)) {
        $kws = json_decode($m[0], true);
        return is_array($kws) ? array_slice($kws, 0, 14) : [];
    }
    return [];
}


// ══════════════════════════════════════════════════════════════════
// ARTICLE WRITING
// ══════════════════════════════════════════════════════════════════

function openAiWriteArticle(
    string $keyphrase,
    string $brand,
    string $location,
    string $serviceArea,
    string $services,
    string $summary,
    string $research,
    array  $lsiKeywords = []
): ?array {

    $lsiList         = !empty($lsiKeywords) ? implode(', ', $lsiKeywords) : 'none provided';
    $researchSection = $research
        ? "\n\nRESEARCH NOTES (use relevant facts — do not copy verbatim):\n" . mb_substr($research, 0, 2500)
        : '';

    $prompt = "You are an expert SEO content writer specialising in UK local businesses. Write a complete, high-quality, SEO-rich article.

BUSINESS PROFILE:
- Name: $brand
- Location: $location
- Service area: $serviceArea
- Services: $services
- Background: $summary

PRIMARY KEYPHRASE: \"$keyphrase\"

LSI / RELATED KEYWORDS (weave these naturally throughout — do NOT stuff, use each 1–2 times):
$lsiList
$researchSection

ARTICLE REQUIREMENTS:
1. Length: 1,700–2,300 words
2. British English throughout (optimise, colour, realise, organise, recognise, behaviour, etc.)
3. Naturally include the PRIMARY keyphrase 6–8 times across headings and body text
4. Use LSI keywords naturally in paragraphs — never forced
5. Format with clean HTML: <h2>, <h3>, <p>, <ul>, <ol>, <strong>, <em>
6. Structure:
   - Compelling opening paragraph hooking the reader with a relatable problem (no heading tag)
   - <h2>Key Takeaways</h2> with 4–5 <li> bullet points
   - 4–5 main <h2> sections, each with 1–2 <h3> subsections and 2–3 paragraphs
   - Mention $location naturally at least 3 times for local SEO
   - <h2>Frequently Asked Questions</h2> with 5 Q&A pairs (use <h3> per question)
   - Closing paragraph with a soft, natural CTA mentioning $brand
7. Bold (<strong>) the primary keyphrase on its first use in the body
8. Bold 4–6 other important phrases or statistics for scannability
9. Write for real people — expert, conversational, no corporate jargon
10. Do NOT say 'In conclusion', do NOT mention AI, do NOT use filler phrases

Return ONLY valid JSON — no markdown fences, no explanation:
{
  \"title\": \"SEO title including primary keyphrase (55–60 chars)\",
  \"meta_description\": \"Compelling meta description including keyphrase (145–155 chars)\",
  \"content\": \"<p>Full HTML article content...</p>\"
}";

    $response = openAiCall([
        ['role' => 'system', 'content' => 'You are an expert UK SEO content writer. Always return valid JSON only.'],
        ['role' => 'user',   'content' => $prompt],
    ], 4000);

    if (!$response) return null;

    $json = $response;
    if (preg_match('/\{.*\}/s', $json, $m)) $json = $m[0];
    $data = json_decode($json, true);
    if (!$data || empty($data['title']) || empty($data['content'])) return null;
    return $data;
}


// ══════════════════════════════════════════════════════════════════
// INTERNAL LINKS
// ══════════════════════════════════════════════════════════════════

function addInternalLinks(string $content, array $publishedArticles): string
{
    if (empty($publishedArticles)) return $content;

    $articleList = '';
    foreach (array_slice($publishedArticles, 0, 8) as $a) {
        if ($a['title'] && $a['wp_post_url']) {
            $articleList .= "- \"{$a['title']}\" → {$a['wp_post_url']}\n";
        }
    }
    if (!$articleList) return $content;

    $prompt = "Add 2–3 natural internal links to the article HTML below.

AVAILABLE ARTICLES TO LINK TO:
$articleList

RULES:
- Find relevant existing phrases in the article body and wrap with <a href=\"URL\" title=\"...\">
- Links must be genuinely relevant to the surrounding context
- Do NOT add links inside headings (<h2>, <h3>)
- Do NOT add links in the Key Takeaways bullets
- Each link should use descriptive anchor text (not \"click here\")
- Return the COMPLETE article HTML with links added — do not truncate or summarise

ARTICLE HTML:
$content";

    $response = openAiCall([
        ['role' => 'system', 'content' => 'You add internal HTML links to articles. Return the complete HTML only — no explanation.'],
        ['role' => 'user',   'content' => $prompt],
    ], 4000);

    // Only use the response if it looks like a full article (at least 80% the original length)
    if ($response && mb_strlen($response) > mb_strlen($content) * 0.8 && strpos($response, '<') !== false) {
        return trim($response);
    }

    return $content;
}


// ══════════════════════════════════════════════════════════════════
// IMAGE GENERATION
// ══════════════════════════════════════════════════════════════════

function generateAndSaveImage(string $keyphrase, string $brand, string $location, string $services, int $clientId): ?array
{
    $key = $_ENV['OPENAI_API_KEY'] ?? '';
    if (!$key) return null;

    // Create a safe, professional DALL-E prompt
    $imagePrompt = "Professional editorial photograph for a UK business website article about \"$keyphrase\". "
        . "Relevant to: $services. Location context: $location, United Kingdom. "
        . "Clean, modern composition. Natural lighting. No text overlays, no watermarks, no logos. "
        . "High quality, suitable for a professional business blog post.";

    $payload = json_encode([
        'model'   => 'dall-e-3',
        'prompt'  => $imagePrompt,
        'n'       => 1,
        'size'    => '1792x1024',
        'quality' => 'standard',
        'style'   => 'natural',
    ]);

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
    ]);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr || !$response) {
        articleLog('image_curl_error', ['error' => $curlErr]);
        return null;
    }

    $data     = json_decode($response, true);
    $imageUrl = $data['data'][0]['url'] ?? null;

    if (!$imageUrl) {
        articleLog('image_api_error', ['response' => substr($response, 0, 300)]);
        return null;
    }

    // ── Download and save image ───────────────────────────────────
    $uploadsDir = __DIR__ . '/uploads/articles/';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
        // Prevent PHP execution in uploads folder
        file_put_contents($uploadsDir . '.htaccess',
            "Options -ExecCGI\nphp_flag engine off\nAddHandler cgi-script .php .php3 .phtml .pl .py .jsp .asp .sh\n"
        );
    }

    $filename  = 'article-' . $clientId . '-' . time() . '.jpg';
    $localPath = $uploadsDir . $filename;

    $imageData = @file_get_contents($imageUrl);
    if (!$imageData) {
        articleLog('image_download_error', ['url' => $imageUrl]);
        return null;
    }

    file_put_contents($localPath, $imageData);

    // Alt text and caption
    $alt     = ucwords($keyphrase) . ' — ' . $brand . ', ' . $location;
    $caption = ucwords($keyphrase) . ' in ' . $location;

    return [
        'local_path' => $localPath,
        'web_url'    => '/uploads/articles/' . $filename,
        'alt'        => $alt,
        'caption'    => $caption,
    ];
}


/**
 * Insert the generated image into the article after the Key Takeaways section
 */
function insertImageIntoContent(string $content, array $image): string
{
    $figure = '<figure style="margin:2em 0;text-align:center;">'
        . '<img src="' . htmlspecialchars($image['web_url']) . '" '
        . 'alt="' . htmlspecialchars($image['alt']) . '" '
        . 'title="' . htmlspecialchars($image['alt']) . '" '
        . 'style="width:100%;max-width:900px;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.08);" '
        . 'loading="lazy" width="1792" height="1024"/>'
        . '<figcaption style="font-size:0.85em;color:#64748b;margin-top:0.6em;font-style:italic;">'
        . htmlspecialchars($image['caption'])
        . '</figcaption></figure>';

    // Insert after Key Takeaways closing </ul>
    if (preg_match('/(<\/ul>)/i', $content, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[1][1] + strlen($m[1][0]);
        return substr($content, 0, $pos) . "\n\n" . $figure . "\n\n" . substr($content, $pos);
    }

    // Fallback: after first </h2>
    if (preg_match('/(<\/h2>)/i', $content, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[1][1] + strlen($m[1][0]);
        return substr($content, 0, $pos) . "\n\n" . $figure . "\n\n" . substr($content, $pos);
    }

    return $figure . "\n\n" . $content;
}


// ══════════════════════════════════════════════════════════════════
// CONTENT PLAN
// ══════════════════════════════════════════════════════════════════

function generateKeyphrasePlan(array $client): ?array
{
    $brand    = $client['brand_name'] ?: 'the business';
    $location = $client['location']   ?: 'the UK';
    $primary  = $client['keyphrase']  ?: '';
    $offers   = json_decode($client['offers'] ?? '[]', true) ?: [];
    $services = implode(', ', array_filter(array_map(fn($o) => $o['label'] ?? '', $offers)));

    $prompt = "You are a UK SEO specialist. Generate exactly 30 unique target keyphrases for a local business content calendar.

Business: $brand | Location: $location | Primary keyphrase: $primary | Services: $services

RULES:
- Each keyphrase: 3–6 words, specific (not generic)
- Cover: how-to guides, costs/pricing, comparisons, local searches, FAQs, tips, benefits, problems
- Mix of local intent (\"in $location\") and national buyer intent
- British English spelling
- No duplicates, no near-duplicates
- Do NOT repeat the primary keyphrase

Return ONLY a valid JSON array of 30 strings. No markdown.";

    $response = openAiCall([['role' => 'user', 'content' => $prompt]], 900);
    if (!$response) return null;

    if (preg_match('/\[.*\]/s', $response, $m)) {
        $plan = json_decode($m[0], true);
        return is_array($plan) && count($plan) >= 5 ? array_slice(array_map('trim', $plan), 0, 30) : null;
    }
    return null;
}


// ══════════════════════════════════════════════════════════════════
// UTILITIES
// ══════════════════════════════════════════════════════════════════

function jinaResearch(string $keyphrase, string $location): string
{
    $jinaKey = $_ENV['JINA_API_KEY'] ?? '';
    $query   = urlencode("$keyphrase $location guide tips");
    $url     = "https://s.jina.ai/$query";

    $headers = ['Accept: application/json', 'X-Return-Format: text'];
    if ($jinaKey) $headers[] = "Authorization: Bearer $jinaKey";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    return $result ? mb_substr(strip_tags($result), 0, 3000) : '';
}

function openAiCall(array $messages, int $maxTokens = 1000): ?string
{
    $key   = $_ENV['OPENAI_API_KEY'] ?? '';
    $model = $_ENV['OPENAI_MODEL']   ?? 'gpt-4o-mini';
    if (!$key) return null;

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $maxTokens,
            'temperature' => 0.7,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    if (!$body) return null;
    $data = json_decode($body, true);
    return $data['choices'][0]['message']['content'] ?? null;
}

function nextScheduledDate(PDO $db, int $clientId): string
{
    $stmt = $db->prepare('SELECT MAX(scheduled_date) FROM articles WHERE client_id = ?');
    $stmt->execute([$clientId]);
    $last = $stmt->fetchColumn();

    if ($last) {
        $next = date('Y-m-d', strtotime($last . ' +1 day'));
        if ($next <= date('Y-m-d')) $next = date('Y-m-d', strtotime('+1 day'));
    } else {
        $next = date('Y-m-d', strtotime('+1 day'));
    }
    return $next;
}

function notifyClientArticleReady(array $client, string $title, string $keyphrase, string $date): void
{
    $email = $client['email'] ?? '';
    if (!$email) return;

    $name        = $client['first_name'] ?: 'there';
    $displayDate = date('d F Y', strtotime($date));

    $subject = "Your new SEO article is ready to review — Auto-Seo";
    $body    = "Hi $name,\n\n"
        . "We've written a brand new SEO article for your website — complete with a custom image and optimised for search.\n\n"
        . "📄 Article: \"$title\"\n"
        . "🔑 Target search term: $keyphrase\n"
        . "📅 Scheduled to publish: $displayDate\n\n"
        . "Log in to your dashboard, read it through, and click Approve. "
        . "Once approved it will publish automatically on the scheduled date.\n\n"
        . "👉 Review it here: https://auto-seo.co.uk/dashboard-articles.php\n\n"
        . "The Auto-Seo Team\nhello@auto-seo.co.uk";

    $headers  = "From: Auto-Seo <hello@auto-seo.co.uk>\r\n";
    $headers .= "Reply-To: hello@auto-seo.co.uk";
    mail($email, $subject, $body, $headers, '-f hello@auto-seo.co.uk');
}

function articleLog(string $type, array $data = []): void
{
    $entry = date('Y-m-d H:i:s') . " | $type | " . json_encode($data) . "\n";
    file_put_contents(__DIR__ . '/article_log.txt', $entry, FILE_APPEND);
}
