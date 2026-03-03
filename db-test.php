<?php
// TEMPORARY diagnostic script — DELETE after testing

$envFile = __DIR__ . '/.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $env[trim($key)] = trim($val);
    }
}

echo '<pre>';
echo "DB_HOST: " . ($env['DB_HOST'] ?? 'NOT SET') . "\n";
echo "DB_NAME: " . ($env['DB_NAME'] ?? 'NOT SET') . "\n";
echo "DB_USER: " . ($env['DB_USER'] ?? 'NOT SET') . "\n";
echo "DB_PASS: " . (isset($env['DB_PASS']) ? (strlen($env['DB_PASS']) . ' chars — ' . ($env['DB_PASS'] === 'YOUR_DB_PASSWORD' ? '⚠️ STILL PLACEHOLDER' : '✓ looks set')) : 'NOT SET') . "\n\n";

try {
    $pdo = new PDO(
        "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
        $env['DB_USER'], $env['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✅ Database connection: SUCCESS\n\n";

    // Check tables exist
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables found: " . implode(', ', $tables) . "\n\n";

    // Check client count
    $count = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    echo "Clients in DB: $count\n";

    // Show latest client if any
    if ($count > 0) {
        $latest = $pdo->query("SELECT id, email, first_name, login_token, token_expires, created_at FROM clients ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        echo "\nLatest client:\n";
        foreach ($latest as $k => $v) {
            echo "  $k: $v\n";
        }
    }

} catch (Exception $e) {
    echo "❌ Database connection FAILED:\n" . $e->getMessage() . "\n";
}
echo '</pre>';
