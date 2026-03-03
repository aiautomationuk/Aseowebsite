<?php
/**
 * cron-publish.php
 * Publishes approved articles to WordPress when their scheduled_date arrives.
 * Run daily via cPanel Cron Jobs (e.g. 09:00 every day):
 *
 * /usr/local/bin/php /home/USERNAME/public_html/cron-publish.php >> /home/USERNAME/public_html/cron_log.txt 2>&1
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/wp-publish.php';

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
$today     = date('Y-m-d');

echo "\n" . str_repeat('─', 60) . "\n";
echo "AutoSEO Scheduler — $today " . date('H:i:s') . "\n";
echo str_repeat('─', 60) . "\n";

try {
    $db = getDB();

    // Find all approved articles whose scheduled_date is today or earlier
    // that haven't been published yet (no wp_post_id) and whose client has WP connected
    $articles = $db->query("
        SELECT a.*, c.wp_url, c.wp_username, c.wp_app_password,
               c.email AS client_email, c.first_name AS client_name,
               c.brand_name
        FROM articles a
        JOIN clients  c ON c.id = a.client_id
        WHERE a.status        = 'approved'
          AND a.wp_post_id   IS NULL
          AND a.scheduled_date <= '$today'
          AND c.wp_url         IS NOT NULL
          AND c.wp_username    IS NOT NULL
          AND c.wp_app_password IS NOT NULL
        ORDER BY a.scheduled_date ASC
        LIMIT 50
    ")->fetchAll();

    if (empty($articles)) {
        echo "No articles due for publishing today.\n";
    } else {
        echo count($articles) . " article(s) due for publishing.\n\n";
    }

    $published = 0;
    $failed    = 0;

    foreach ($articles as $art) {
        $label = "[#{$art['id']}] " . mb_substr($art['title'] ?? 'Untitled', 0, 50);
        echo "► $label\n";
        echo "  Client : {$art['brand_name']} ({$art['client_email']})\n";
        echo "  Date   : {$art['scheduled_date']}\n";

        // Build a minimal client array matching what publishToWordPress expects
        $client = [
            'id'              => $art['client_id'],
            'wp_url'          => $art['wp_url'],
            'wp_username'     => $art['wp_username'],
            'wp_app_password' => $art['wp_app_password'],
        ];

        $result = publishToWordPress((int)$art['id'], $client);

        if ($result['ok']) {
            $published++;
            $status = $result['scheduled'] ? 'Scheduled on WP' : 'Published live';
            echo "  ✓ $status — {$result['wp_url']}\n\n";

            // Notify client by email
            notifyClientPublished($art, $result['wp_url']);
        } else {
            $failed++;
            echo "  ✗ Failed: {$result['error']}\n\n";

            // Log failure to DB for admin visibility
            $db->prepare('UPDATE articles SET status = "failed" WHERE id = ?')
               ->execute([$art['id']]);
        }
    }

    $elapsed = round(microtime(true) - $startTime, 1);
    echo str_repeat('─', 60) . "\n";
    echo "Done. Published: $published | Failed: $failed | Time: {$elapsed}s\n";

} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}


// ══════════════════════════════════════════════════════════════════
// EMAIL NOTIFICATION
// ══════════════════════════════════════════════════════════════════

function notifyClientPublished(array $art, ?string $postUrl): void
{
    $to      = $art['client_email'] ?? '';
    $name    = $art['client_name']  ?: 'there';
    $brand   = $art['brand_name']   ?: 'your website';
    $title   = $art['title']        ?: 'Your new article';

    if (!$to) return;

    $subject = "Your new article is live — $title";

    $viewLink = $postUrl
        ? "<a href=\"$postUrl\" style=\"color:#6366f1;font-weight:700\">View it live →</a>"
        : '';

    $html = '<!DOCTYPE html><html lang="en-GB"><body style="font-family:Inter,Arial,sans-serif;background:#f8fafc;padding:40px 20px">'
          . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:16px;padding:40px;border:1px solid #e2e8f0">'
          . '<p style="font-size:28px;margin:0 0 16px">🎉</p>'
          . '<h1 style="font-size:20px;font-weight:800;color:#0f172a;margin:0 0 12px">Your new article is live!</h1>'
          . "<p style=\"color:#475569;font-size:15px\">Hi $name,</p>"
          . "<p style=\"color:#475569;font-size:15px\">A new SEO article has just been published on <strong>$brand</strong>:</p>"
          . '<div style="background:#f1f5f9;border-radius:12px;padding:20px;margin:20px 0">'
          . "<p style=\"font-size:16px;font-weight:700;color:#0f172a;margin:0 0 8px\">" . htmlspecialchars($title) . "</p>"
          . ($postUrl ? "<a href=\"$postUrl\" style=\"color:#6366f1;font-size:14px\">$postUrl</a>" : '')
          . '</div>'
          . "<p style=\"color:#475569;font-size:14px\">This article is targeting your keyphrase <strong>" . htmlspecialchars($art['keyphrase'] ?? '') . "</strong> and is now working to improve your Google rankings.</p>"
          . '<a href="https://auto-seo.co.uk/dashboard-articles.php" style="display:inline-block;background:#6366f1;color:#fff;font-weight:700;padding:12px 24px;border-radius:8px;text-decoration:none;font-size:14px;margin-top:8px">View all articles →</a>'
          . '<p style="color:#94a3b8;font-size:13px;margin-top:28px">— The AutoSEO Team</p>'
          . '</div></body></html>';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";
    $headers .= "From: AutoSEO <hello@auto-seo.co.uk>\r\n";
    $headers .= "Reply-To: hello@auto-seo.co.uk\r\n";

    mail($to, $subject, $html, $headers, '-f hello@auto-seo.co.uk');
}
