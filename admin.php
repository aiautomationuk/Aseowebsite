<?php
session_start();

// ── Load .env ─────────────────────────────────────────────────────
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

// ── Auth ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    if ($adminKey && hash_equals($adminKey, $_POST['password'] ?? '')) {
        $_SESSION['admin_authed'] = true;
    } else {
        $loginError = 'Incorrect password.';
    }
}
if (isset($_POST['admin_logout'])) {
    session_destroy();
    header('Location: /admin.php');
    exit;
}

$authed = !empty($_SESSION['admin_authed']);

// ── DB + data (only when authed) ──────────────────────────────────
$clients  = [];
$recentLog = [];
if ($authed) {
    require_once __DIR__ . '/db.php';
    $db = getDB();

    $clients = $db->query("
        SELECT c.*,
               COUNT(DISTINCT a.id) as total_articles,
               SUM(a.status = 'published') as published,
               SUM(a.status = 'draft') as drafts,
               SUM(a.status = 'approved') as approved,
               MAX(a.created_at) as last_article,
               (SELECT COUNT(*) FROM keyphrases k WHERE k.client_id = c.id AND k.used = 0) as kp_remaining
        FROM clients c
        LEFT JOIN articles a ON a.client_id = c.id
        WHERE c.status = 'active'
        GROUP BY c.id
        ORDER BY c.created_at DESC
    ")->fetchAll();

    // Read last 30 lines of article log
    $logFile = __DIR__ . '/article_log.txt';
    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recentLog = array_slice(array_reverse($lines), 0, 30);
    }
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Admin — Auto-Seo</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
    .badge { display:inline-flex;align-items:center;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700; }
  </style>
</head>
<body class="bg-slate-100 min-h-screen text-slate-900">

<?php if (!$authed): ?>
<!-- ── Login ──────────────────────────────────────────────────────── -->
<div class="min-h-screen flex items-center justify-center px-4">
  <div class="w-full max-w-sm bg-white rounded-2xl shadow-xl p-8">
    <div class="flex items-center gap-2 mb-6">
      <div class="w-8 h-8 bg-indigo-500 rounded-lg flex items-center justify-center text-white font-bold text-sm">⚡</div>
      <span class="font-extrabold text-slate-900">Auto-Seo Admin</span>
    </div>
    <?php if (!empty($loginError)): ?>
      <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-semibold px-4 py-3 rounded-xl"><?= htmlspecialchars($loginError) ?></div>
    <?php endif; ?>
    <form method="POST" class="space-y-4">
      <input type="hidden" name="admin_login" value="1"/>
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Admin Password</label>
        <input type="password" name="password" required autofocus
          class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"/>
      </div>
      <button type="submit" class="w-full bg-indigo-600 text-white font-bold py-3 rounded-xl hover:bg-indigo-500 transition text-sm">
        Sign In →
      </button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── Admin panel ────────────────────────────────────────────────── -->

<div class="max-w-7xl mx-auto px-6 py-8">

  <!-- Header -->
  <div class="flex items-center justify-between mb-8">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 bg-indigo-500 rounded-xl flex items-center justify-center text-white font-bold">⚡</div>
      <div>
        <h1 class="text-xl font-extrabold text-slate-900">Auto-Seo Admin</h1>
        <p class="text-xs text-slate-400"><?= count($clients) ?> active client<?= count($clients) !== 1 ? 's' : '' ?></p>
      </div>
    </div>
    <div class="flex items-center gap-3">
      <a href="/dashboard.php" class="text-xs text-indigo-600 hover:underline font-semibold">View Dashboard →</a>
      <form method="POST" class="inline">
        <input type="hidden" name="admin_logout" value="1"/>
        <button class="text-xs text-slate-400 hover:text-slate-700 font-semibold">Sign Out</button>
      </form>
    </div>
  </div>

  <!-- Summary stats -->
  <?php
  $totalClients   = count($clients);
  $activeClients  = count(array_filter($clients, fn($c) => $c['plan'] === 'active'));
  $totalArticles  = array_sum(array_column($clients, 'total_articles'));
  $totalPublished = array_sum(array_column($clients, 'published'));
  $totalDrafts    = array_sum(array_column($clients, 'drafts'));
  ?>
  <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
    <?php foreach ([
      ['Total Clients',    $totalClients,   'text-slate-900'],
      ['Active Plans',     $activeClients,  'text-emerald-600'],
      ['Articles Written', $totalArticles,  'text-indigo-600'],
      ['Published',        $totalPublished, 'text-emerald-600'],
      ['Awaiting Approval',$totalDrafts,    'text-amber-600'],
    ] as [$label, $val, $col]): ?>
    <div class="bg-white rounded-2xl border border-slate-200 px-5 py-4">
      <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1"><?= $label ?></p>
      <p class="text-2xl font-extrabold <?= $col ?>"><?= $val ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="grid lg:grid-cols-3 gap-6">

    <!-- Clients table -->
    <div class="lg:col-span-2">
      <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
          <h2 class="font-extrabold text-slate-900">Clients</h2>
          <span class="text-xs text-slate-400">Click Generate to create an article now</span>
        </div>

        <?php if (empty($clients)): ?>
          <div class="px-6 py-12 text-center text-sm text-slate-400">No active clients yet.</div>
        <?php else: ?>
        <div class="divide-y divide-slate-50">
          <?php foreach ($clients as $c): ?>
          <div class="px-6 py-4" id="client-<?= $c['id'] ?>">
            <div class="flex items-start justify-between gap-4">
              <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                  <p class="font-bold text-slate-900 text-sm"><?= htmlspecialchars($c['brand_name'] ?: $c['first_name'] . ' ' . $c['last_name']) ?></p>
                  <?php
                  $planBadge = match($c['plan']) {
                      'active'    => 'bg-emerald-50 text-emerald-700',
                      'trial'     => 'bg-amber-50 text-amber-700',
                      'cancelled' => 'bg-rose-50 text-rose-600',
                      default     => 'bg-slate-100 text-slate-500',
                  };
                  ?>
                  <span class="badge <?= $planBadge ?>"><?= ucfirst($c['plan']) ?></span>
                  <?php if (!$c['wp_url']): ?>
                    <span class="badge bg-slate-100 text-slate-400">No WP</span>
                  <?php else: ?>
                    <span class="badge bg-indigo-50 text-indigo-600">WP ✓</span>
                  <?php endif; ?>
                </div>
                <p class="text-xs text-slate-400 mt-0.5"><?= htmlspecialchars($c['email']) ?></p>
                <?php if ($c['keyphrase']): ?>
                  <p class="text-xs text-indigo-500 mt-0.5 font-medium"><?= htmlspecialchars($c['keyphrase']) ?></p>
                <?php endif; ?>
                <div class="flex gap-3 mt-1.5 text-xs text-slate-400 flex-wrap">
                  <span><?= (int)$c['total_articles'] ?> articles</span>
                  <span class="text-emerald-600"><?= (int)$c['published'] ?> published</span>
                  <?php if ($c['drafts'] > 0): ?>
                    <span class="text-amber-600"><?= (int)$c['drafts'] ?> awaiting approval</span>
                  <?php endif; ?>
                  <span><?= (int)$c['kp_remaining'] ?> keyphrases left</span>
                  <?php if ($c['last_article']): ?>
                    <span>Last: <?= date('d M', strtotime($c['last_article'])) ?></span>
                  <?php endif; ?>
                </div>
              </div>

              <div class="flex items-center gap-2 flex-shrink-0">
                <button onclick="generateArticle(<?= $c['id'] ?>, '<?= htmlspecialchars($c['brand_name'] ?: $c['email']) ?>')"
                  class="generate-btn bg-indigo-600 text-white font-bold text-xs px-4 py-2 rounded-full hover:bg-indigo-500 transition active:scale-95 whitespace-nowrap">
                  ✨ Generate
                </button>
                <a href="/dashboard-articles.php" target="_blank"
                  class="bg-slate-100 text-slate-600 font-semibold text-xs px-4 py-2 rounded-full hover:bg-slate-200 transition whitespace-nowrap">
                  Articles →
                </a>
              </div>
            </div>

            <!-- Per-client status message -->
            <div id="status-<?= $c['id'] ?>" class="hidden mt-3 text-xs font-semibold px-3 py-2 rounded-lg"></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Activity log -->
    <div class="lg:col-span-1">
      <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
          <h2 class="font-extrabold text-slate-900 text-sm">Activity Log</h2>
          <button onclick="location.reload()" class="text-xs text-slate-400 hover:text-slate-600">Refresh</button>
        </div>
        <div class="px-5 py-3 space-y-2 max-h-[600px] overflow-y-auto">
          <?php if (empty($recentLog)): ?>
            <p class="text-xs text-slate-400 py-4 text-center">No activity yet.</p>
          <?php else: ?>
            <?php foreach ($recentLog as $line): ?>
              <?php
              $parts  = explode(' | ', $line, 3);
              $time   = $parts[0] ?? '';
              $type   = $parts[1] ?? '';
              $detail = isset($parts[2]) ? json_decode($parts[2], true) : [];
              $colour = match(true) {
                  str_contains($type, 'error')     => 'text-rose-600',
                  str_contains($type, 'generated') => 'text-emerald-600',
                  str_contains($type, 'plan')      => 'text-indigo-600',
                  default                          => 'text-slate-500',
              };
              ?>
              <div class="text-xs border-b border-slate-50 pb-2 last:border-0">
                <span class="font-bold <?= $colour ?>"><?= htmlspecialchars($type) ?></span>
                <span class="text-slate-300 ml-1"><?= htmlspecialchars(substr($time, 5)) ?></span>
                <?php if (!empty($detail['title'])): ?>
                  <p class="text-slate-600 mt-0.5 truncate">"<?= htmlspecialchars($detail['title']) ?>"</p>
                <?php elseif (!empty($detail['message'])): ?>
                  <p class="text-slate-400 mt-0.5 truncate"><?= htmlspecialchars($detail['message']) ?></p>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Cron info -->
      <div class="mt-4 bg-slate-800 text-slate-300 rounded-2xl p-5 text-xs">
        <p class="font-bold text-white mb-2">⏱ Cron Job Setup</p>
        <p class="text-slate-400 mb-1">Article generator — daily at 08:00:</p>
        <code class="block bg-slate-900 text-emerald-400 rounded-lg p-3 text-xs leading-relaxed break-all mb-3">
          /usr/local/bin/php /home/<?= htmlspecialchars(explode('/', $_SERVER['DOCUMENT_ROOT'] ?? 'username/public_html')[3] ?? 'USERNAME') ?>/public_html/cron-generate.php >> /home/<?= htmlspecialchars(explode('/', $_SERVER['DOCUMENT_ROOT'] ?? 'username/public_html')[3] ?? 'USERNAME') ?>/public_html/cron_log.txt 2>&1
        </code>
        <p class="text-slate-400 mb-1">Rank checker — weekly (Monday 07:00):</p>
        <code class="block bg-slate-900 text-emerald-400 rounded-lg p-3 text-xs leading-relaxed break-all">
          /usr/local/bin/php /home/<?= htmlspecialchars(explode('/', $_SERVER['DOCUMENT_ROOT'] ?? 'username/public_html')[3] ?? 'USERNAME') ?>/public_html/check-rankings.php >> /home/<?= htmlspecialchars(explode('/', $_SERVER['DOCUMENT_ROOT'] ?? 'username/public_html')[3] ?? 'USERNAME') ?>/public_html/cron_log.txt 2>&1
        </code>
        <div class="mt-4">
          <button onclick="runRankCheck()"
            class="bg-emerald-600 text-white font-bold text-sm px-5 py-2 rounded-full hover:bg-emerald-500 transition active:scale-95">
            📊 Run Rank Check Now
          </button>
          <span id="rankStatus" class="ml-3 text-xs text-slate-400"></span>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
const ADMIN_KEY = <?= json_encode($_ENV['ADMIN_KEY'] ?? '') ?>;

async function generateArticle(clientId, clientName) {
  const btn    = document.querySelector(`#client-${clientId} .generate-btn`);
  const status = document.getElementById(`status-${clientId}`);

  btn.disabled    = true;
  btn.textContent = '⏳ Generating…';
  status.className = 'mt-3 text-xs font-semibold px-3 py-2 rounded-lg bg-indigo-50 text-indigo-700';
  status.textContent = `Writing article for ${clientName}… this takes ~30 seconds.`;

  try {
    const res  = await fetch('/generate-article.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: `client_id=${clientId}&admin_key=${encodeURIComponent(ADMIN_KEY)}`,
    });
    const data = await res.json();

    if (data.success) {
      status.className  = 'mt-3 text-xs font-semibold px-3 py-2 rounded-lg bg-emerald-50 text-emerald-700';
      status.textContent = `✓ ${data.message} — scheduled ${data.scheduled}`;
      btn.textContent   = '✨ Generate';
      btn.disabled      = false;
    } else {
      status.className  = 'mt-3 text-xs font-semibold px-3 py-2 rounded-lg bg-rose-50 text-rose-700';
      status.textContent = `✗ ${data.error || data.message}`;
      btn.textContent   = '✨ Generate';
      btn.disabled      = false;
    }
  } catch (err) {
    status.className  = 'mt-3 text-xs font-semibold px-3 py-2 rounded-lg bg-rose-50 text-rose-700';
    status.textContent = '✗ Network error. Please try again.';
    btn.textContent   = '✨ Generate';
    btn.disabled      = false;
  }
}

async function runRankCheck() {
  const btn    = document.querySelector('[onclick="runRankCheck()"]');
  const status = document.getElementById('rankStatus');
  btn.disabled    = true;
  btn.textContent = '⏳ Checking…';
  status.textContent = 'Running… this may take a minute.';
  try {
    const adminKey = <?= json_encode($_ENV['ADMIN_KEY'] ?? '') ?>;
    const res  = await fetch(`/check-rankings.php?key=${encodeURIComponent(adminKey)}`);
    const text = await res.text();
    status.textContent = res.ok ? '✓ Done! Refresh dashboard to see results.' : '✗ Error — check cron_log.txt';
  } catch (e) {
    status.textContent = '✗ Network error.';
  }
  btn.textContent = '📊 Run Rank Check Now';
  btn.disabled    = false;
}
</script>

<?php endif; ?>

</body>
</html>
