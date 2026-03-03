<?php
/**
 * Daily cron job — generates one article per active client
 *
 * Add to cPanel Cron Jobs (once per day, e.g. 08:00):
 * /usr/local/bin/php /home/USERNAME/public_html/cron-generate.php >> /home/USERNAME/public_html/cron_log.txt 2>&1
 *
 * Replace USERNAME with your actual cPanel username
 */

define('CRON_MODE', true);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/generate-article.php';

// Load .env
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
echo "Auto-Seo Article Generator — " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('─', 60) . "\n";

try {
    $db = getDB();

    // ── Find clients due for a new article ────────────────────────
    // Active or trial clients who haven't had an article generated today
    // and who have WordPress connected (needed for publishing)
    $clients = $db->query("
        SELECT c.id, c.email, c.first_name, c.brand_name, c.plan,
               c.wp_url, c.keyphrase,
               COUNT(a.id) as total_articles,
               MAX(a.created_at) as last_generated
        FROM clients c
        LEFT JOIN articles a ON a.client_id = c.id
        WHERE c.status = 'active'
          AND c.plan IN ('active', 'trial')
          AND (
              -- Never had an article, OR last article was not created today
              a.id IS NULL
              OR DATE(MAX(a.created_at)) < CURDATE()
          )
        GROUP BY c.id
        HAVING
            -- Skip if an article was already generated today
            (last_generated IS NULL OR DATE(last_generated) < CURDATE())
            -- Trial clients: max 3 articles
            AND NOT (c.plan = 'trial' AND total_articles >= 3)
        ORDER BY last_generated ASC
    ")->fetchAll();

    if (empty($clients)) {
        echo "No clients due for article generation today.\n";
        exit;
    }

    echo "Clients to process: " . count($clients) . "\n\n";

    $success = 0;
    $failed  = 0;

    foreach ($clients as $client) {
        echo "► [{$client['id']}] {$client['brand_name']} ({$client['plan']}, {$client['total_articles']} articles)\n";

        if (!$client['wp_url']) {
            echo "  ⚠ Skipped — WordPress not connected\n\n";
            continue;
        }

        $result = generateArticle((int)$client['id']);

        if ($result['success']) {
            echo "  ✓ {$result['message']}\n";
            echo "  Scheduled: {$result['scheduled']}\n";
            $success++;
        } else {
            echo "  ✗ {$result['message']}\n";
            $failed++;
        }

        // Pause between clients to avoid API rate limits
        if (count($clients) > 1) sleep(3);

        echo "\n";
    }

    $elapsed = round(microtime(true) - $startTime, 1);
    echo str_repeat('─', 60) . "\n";
    echo "Done. Success: $success | Failed: $failed | Time: {$elapsed}s\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
