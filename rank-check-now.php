<?php
/**
 * rank-check-now.php
 * Manual SERP check tool — shows raw top 10 results from Jina
 * and highlights the client's domain.
 * Accessible from the rankings page.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rank-helper.php';
$client = requireLogin();
$db     = getDB();

// Load .env for Jina key
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

$keyphrase = trim($_GET['kp'] ?? $_POST['kp'] ?? '');
$domain    = trim($_GET['domain'] ?? $_POST['domain'] ?? extractDomain($client['website_url'] ?? ''));
$results   = [];
$position  = null;
$searched  = false;
$error     = '';

// ── Weekly limit tracking ─────────────────────────────────────────
define('WEEKLY_LIMIT', 5);

// Reset counter if a new week has started (rolling 7 days)
$resetDate = $client['manual_checks_reset'] ?? null;
$checksUsed = (int)($client['manual_checks_used'] ?? 0);

if (!$resetDate || strtotime($resetDate) < strtotime('-7 days')) {
    $db->prepare('UPDATE clients SET manual_checks_used = 0, manual_checks_reset = CURDATE() WHERE id = ?')
       ->execute([$client['id']]);
    $checksUsed = 0;
}

$checksRemaining = WEEKLY_LIMIT - $checksUsed;
$atLimit = ($checksRemaining <= 0);

if ($keyphrase && $domain && $atLimit) {
    $error = 'limit:You have used all ' . WEEKLY_LIMIT . ' manual searches for this week. Your allowance resets 7 days after your first search.';
}

if ($keyphrase && $domain && !$atLimit) {
    $searched   = true;
    $serperKey  = $_ENV['SERPER_API_KEY'] ?? '';
    $jinaKey    = $_ENV['JINA_API_KEY']   ?? '';
    $provider   = $serperKey ? 'Google (Serper)' : 'Jina AI';

    if ($serperKey) {
        // ── Serper.dev ────────────────────────────────────────────
        // Auto-detect country
        $countryCode = 'gb';
        $locLower = strtolower($client['location'] ?? '');
        if (str_contains($locLower, 'spain') || str_contains($locLower, 'marbella')
            || str_contains($locLower, 'madrid') || str_contains($locLower, 'barcelona')) {
            $countryCode = 'es';
        } elseif (str_contains($locLower, 'ireland'))   { $countryCode = 'ie'; }
        elseif (str_contains($locLower, 'australia'))   { $countryCode = 'au'; }
        elseif (str_contains($locLower, 'usa') || str_contains($locLower, 'united states')) { $countryCode = 'us'; }

        $payload = json_encode(['q' => $keyphrase, 'gl' => $countryCode, 'hl' => 'en', 'num' => 100]);
        $ch = curl_init('https://google.serper.dev/search');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['X-API-KEY: ' . $serperKey, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr)           { $error = "Connection error: $curlErr"; }
        elseif ($httpCode !== 200) { $error = "Serper returned HTTP $httpCode: " . substr($response, 0, 200); }
        else {
            $data    = json_decode($response, true);
            $organic = $data['organic'] ?? [];
            foreach ($organic as $item) {
                $itemUrl = $item['link']    ?? '';
                $isMatch = isDomainMatch($itemUrl, $domain);
                if ($isMatch && $position === null) $position = (int)($item['position'] ?? 0);
                $results[] = [
                    'pos'   => (int)($item['position'] ?? count($results) + 1),
                    'url'   => $itemUrl,
                    'title' => $item['title']   ?? '',
                    'desc'  => mb_substr($item['snippet'] ?? '', 0, 140),
                    'match' => $isMatch,
                ];
                if (count($results) >= 10) break;
            }
        }

    } else {
        // ── Jina fallback ─────────────────────────────────────────
        $url     = "https://s.jina.ai/" . urlencode($keyphrase);
        $headers = ['Accept: application/json', 'X-Return-Format: json'];
        if ($jinaKey) $headers[] = "Authorization: Bearer $jinaKey";

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_HTTPHEADER=>$headers]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr)           { $error = "Connection error: $curlErr"; }
        elseif ($httpCode !== 200) {
            $error = "Jina returned HTTP $httpCode — add a SERPER_API_KEY to .env for better results. Response: " . substr($response, 0, 150);
        } else {
            $data = json_decode($response, true);
            foreach ($data['data'] ?? [] as $i => $item) {
                $itemUrl = $item['url'] ?? ($item['link'] ?? '');
                $isMatch = isDomainMatch($itemUrl, $domain);
                if ($isMatch && $position === null) $position = $i + 1;
                $results[] = ['pos'=>$i+1,'url'=>$itemUrl,'title'=>$item['title']??'','desc'=>mb_substr($item['description']??'',0,140),'match'=>$isMatch];
                if ($i >= 9) break;
            }
        }
    }

    // Save result to rankings table and increment usage counter
    if (!$error && !empty($results)) {
        $today = date('Y-m-d');
        $db->prepare('INSERT INTO rankings (client_id, keyphrase, position, checked_at)
                      VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE position = VALUES(position)')
           ->execute([$client['id'], $keyphrase, $position, $today]);

        $db->prepare('UPDATE clients SET manual_checks_used = manual_checks_used + 1 WHERE id = ?')
           ->execute([$client['id']]);
        $checksUsed++;
        $checksRemaining = WEEKLY_LIMIT - $checksUsed;
        $atLimit = ($checksRemaining <= 0);
    }
}

$firstName = htmlspecialchars($client['first_name'] ?: 'there');
$hasWP     = !empty($client['wp_url']);
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manual Rank Check — AutoSEO</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet"/>
  <style>
    body { font-family:'Inter',sans-serif; background:#f8fafc; }
    .sidebar-link { display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:10px;font-size:14px;font-weight:600;color:#64748b;transition:all .15s; }
    .sidebar-link:hover { background:#f1f5f9;color:#0f172a; }
    .sidebar-link.active { background:#eef2ff;color:#6366f1; }
  </style>
</head>
<body class="text-slate-800">
<div class="flex min-h-screen">

  <!-- Sidebar -->
  <aside class="hidden md:flex flex-col w-60 bg-white border-r border-slate-100 p-5 fixed top-0 left-0 h-full z-10">
    <a href="/dashboard.php" class="flex items-center gap-2 mb-8">
      <div class="w-7 h-7 bg-indigo-600 rounded-lg flex items-center justify-center flex-shrink-0">
        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
      </div>
      <span class="font-extrabold text-slate-900">AutoSEO</span>
    </a>
    <nav class="space-y-1 flex-1">
      <a href="/dashboard.php"                          class="sidebar-link">📊 &nbsp;Dashboard</a>
      <a href="/dashboard-articles.php"                 class="sidebar-link">📄 &nbsp;Articles</a>
      <a href="/dashboard-rankings.php"                 class="sidebar-link active">📈 &nbsp;Rankings</a>
      <a href="/dashboard-settings.php?section=keywords" class="sidebar-link">📍 &nbsp;Keywords</a>
      <a href="/dashboard-settings.php?section=wordpress" class="sidebar-link">🔌 &nbsp;WordPress<?php if ($hasWP): ?><span class="ml-auto w-2 h-2 bg-emerald-500 rounded-full"></span><?php endif; ?></a>
      <a href="/dashboard-settings.php?section=profile"  class="sidebar-link">👤 &nbsp;Profile</a>
      <a href="/dashboard-billing.php"                   class="sidebar-link">💳 &nbsp;Billing</a>
      <a href="/dashboard-settings.php?section=security" class="sidebar-link">🔒 &nbsp;Security</a>
    </nav>
    <div class="mt-auto pt-6 border-t border-slate-100">
      <p class="text-xs text-slate-400 mb-3 px-4">Signed in as<br><strong class="text-slate-600"><?= $firstName ?></strong></p>
      <a href="/logout.php" class="sidebar-link text-rose-500 hover:text-rose-700 hover:bg-rose-50">🚪 &nbsp;Sign Out</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="flex-1 md:ml-60 p-6 max-w-3xl">

    <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
      <div class="flex items-center gap-3">
        <a href="/dashboard-rankings.php" class="text-slate-400 hover:text-indigo-600 transition text-sm font-semibold">← Rankings</a>
        <span class="text-slate-300">/</span>
        <h1 class="text-xl font-extrabold text-slate-900">Manual Rank Check</h1>
      </div>

      <!-- Weekly usage counter -->
      <div class="flex items-center gap-2">
        <?php
          $dotsTotal = WEEKLY_LIMIT;
          $dotsUsed  = min($checksUsed, $dotsTotal);
        ?>
        <?php for ($i = 0; $i < $dotsTotal; $i++): ?>
          <div class="w-2.5 h-2.5 rounded-full <?= $i < $dotsUsed ? 'bg-rose-400' : 'bg-emerald-400' ?>"></div>
        <?php endfor; ?>
        <span class="text-xs font-bold ml-1 <?= $atLimit ? 'text-rose-600' : 'text-slate-500' ?>">
          <?= $checksRemaining ?>/<?= WEEKLY_LIMIT ?> searches remaining this week
        </span>
      </div>
    </div>

    <!-- Search form -->
    <div class="bg-white rounded-2xl border <?= $atLimit ? 'border-rose-100' : 'border-slate-100' ?> p-6 mb-6">
      <?php if ($atLimit): ?>
        <div class="flex items-center gap-3 mb-4 bg-rose-50 border border-rose-200 rounded-xl px-5 py-4">
          <span class="text-2xl">🚫</span>
          <div>
            <p class="font-bold text-rose-700 text-sm">Weekly limit reached</p>
            <p class="text-xs text-rose-500 mt-0.5">You've used all <?= WEEKLY_LIMIT ?> manual searches for this week. Your allowance resets 7 days after your first search.</p>
          </div>
        </div>
      <?php else: ?>
        <p class="text-sm text-slate-500 mb-4">Search Google and see exactly where your site appears. <strong><?= $checksRemaining ?></strong> search<?= $checksRemaining !== 1 ? 'es' : '' ?> remaining this week.</p>
      <?php endif; ?>

      <form method="GET" class="space-y-3 <?= $atLimit ? 'opacity-50 pointer-events-none' : '' ?>">
        <div class="grid md:grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-bold text-slate-500 mb-1">Keyphrase</label>
            <input type="text" name="kp" value="<?= htmlspecialchars($keyphrase) ?>"
              placeholder='e.g. hair extensions marbella'
              class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"
              <?= $atLimit ? 'disabled' : 'required' ?> />
          </div>
          <div>
            <label class="block text-xs font-bold text-slate-500 mb-1">Domain to find</label>
            <input type="text" name="domain" value="<?= htmlspecialchars($domain) ?>"
              placeholder='e.g. hairandmakeupmarbella.com'
              class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"
              <?= $atLimit ? 'disabled' : 'required' ?> />
          </div>
        </div>
        <button type="submit" <?= $atLimit ? 'disabled' : '' ?>
          class="bg-indigo-600 text-white font-bold text-sm px-6 py-3 rounded-full hover:bg-indigo-500 transition active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed">
          🔍 Search Google Now
        </button>
      </form>
    </div>

    <?php if ($error): ?>
    <div class="bg-rose-50 border border-rose-200 rounded-2xl p-5 text-sm text-rose-700 font-semibold mb-6">
      ✗ <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($searched && !$error): ?>

    <!-- Position result -->
    <div class="mb-5 rounded-2xl p-6 flex items-center gap-5 <?= $position ? 'bg-emerald-50 border border-emerald-200' : 'bg-slate-50 border border-slate-200' ?>">
      <div class="text-5xl font-black <?= $position ? 'text-emerald-600' : 'text-slate-300' ?>">
        <?= $position ? "#$position" : '—' ?>
      </div>
      <div>
        <p class="font-extrabold text-slate-800 text-lg">
          <?php if ($position): ?>
            <?= $position <= 3 ? '🏆 Top 3!' : ($position <= 10 ? '✅ Page 1' : 'Page 2+') ?>
          <?php else: ?>
            Not found in top 10
          <?php endif; ?>
        </p>
        <p class="text-sm text-slate-500 mt-1">
          "<strong><?= htmlspecialchars($keyphrase) ?></strong>"
          for <strong><?= htmlspecialchars($domain) ?></strong>
        </p>
        <?php if ($position): ?>
        <p class="text-xs text-emerald-600 mt-1 font-semibold">✓ Result saved to your rankings chart</p>
        <?php else: ?>
        <p class="text-xs text-slate-400 mt-1">Your site wasn't found in the top 10 results shown below.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Raw results -->
    <?php if (!empty($results)): ?>
    <div class="bg-white rounded-2xl border border-slate-100 p-6">
      <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
        <h2 class="text-sm font-extrabold text-slate-700">
          Top <?= count($results) ?> results for "<?= htmlspecialchars($keyphrase) ?>"
        </h2>
        <span class="text-xs text-slate-400 bg-slate-100 px-3 py-1 rounded-full">via <?= htmlspecialchars($provider ?? 'Google') ?></span>
      </div>
      <div class="space-y-3">
        <?php foreach ($results as $r): ?>
        <div class="flex gap-4 p-4 rounded-xl <?= $r['match'] ? 'bg-emerald-50 border-2 border-emerald-300' : 'bg-slate-50 border border-slate-100' ?>">
          <div class="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 font-extrabold text-sm
            <?= $r['match'] ? 'bg-emerald-500 text-white' : 'bg-slate-200 text-slate-500' ?>">
            <?= $r['pos'] ?>
          </div>
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-sm text-slate-800 truncate">
              <?= htmlspecialchars($r['title'] ?: $r['url']) ?>
              <?php if ($r['match']): ?>
                <span class="ml-2 text-xs font-bold text-emerald-600 bg-emerald-100 px-2 py-0.5 rounded-full">← Your site</span>
              <?php endif; ?>
            </p>
            <a href="<?= htmlspecialchars($r['url']) ?>" target="_blank"
              class="text-xs text-indigo-500 hover:underline truncate block mt-0.5">
              <?= htmlspecialchars($r['url']) ?>
            </a>
            <?php if ($r['desc']): ?>
            <p class="text-xs text-slate-400 mt-1 line-clamp-2"><?= htmlspecialchars($r['desc']) ?></p>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if (!$position): ?>
      <div class="mt-4 bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
        <strong>Your site wasn't matched.</strong> Check the URLs above — if you can see your site listed but it wasn't highlighted, the domain in your profile might not match exactly.
        Current domain being matched: <code class="bg-amber-100 px-1 rounded"><?= htmlspecialchars($domain) ?></code>
      </div>
      <?php endif; ?>
    </div>
    <?php elseif ($searched): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-5 text-sm text-amber-700">
      Jina returned no structured results. Try again in a moment — the search API may be temporarily rate-limited.
    </div>
    <?php endif; ?>

    <?php endif; ?>

  </main>
</div>
</body>
</html>
