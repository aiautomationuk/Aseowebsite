<?php
require_once __DIR__ . '/auth.php';
$client = requireLogin();
$db     = getDB();

// ── Load .env for Stripe trial URL ────────────────────────────────
$stripeTrialUrl = '#';
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        if (trim($k) === 'STRIPE_TRIAL_URL') { $stripeTrialUrl = trim($v); break; }
    }
}

// ── Calendar month navigation ─────────────────────────────────────
$month = isset($_GET['m']) ? (int)$_GET['m'] : (int)date('n');
$year  = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }
$monthStart  = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
$monthEnd    = date('Y-m-t', mktime(0, 0, 0, $month, 1, $year));
$prevMonth   = $month === 1  ? 12 : $month - 1;
$prevYear    = $month === 1  ? $year - 1 : $year;
$nextMonth   = $month === 12 ? 1  : $month + 1;
$nextYear    = $month === 12 ? $year + 1 : $year;

// ── Fetch calendar articles ───────────────────────────────────────
$calStmt = $db->prepare(
    'SELECT id, title, keyphrase, status, scheduled_date FROM articles
     WHERE client_id = ? AND scheduled_date BETWEEN ? AND ?
     ORDER BY scheduled_date ASC'
);
$calStmt->execute([$client['id'], $monthStart, $monthEnd]);
$calArticles = $calStmt->fetchAll();

$byDate = [];
foreach ($calArticles as $a) {
    $byDate[$a['scheduled_date']][] = $a;
}

// ── Stats ─────────────────────────────────────────────────────────
$totalArticles = (int) $db->prepare('SELECT COUNT(*) FROM articles WHERE client_id = ?')
    ->execute([$client['id']]) ? $db->query("SELECT COUNT(*) FROM articles WHERE client_id = {$client['id']}")->fetchColumn() : 0;

$stTot = $db->prepare('SELECT COUNT(*) FROM articles WHERE client_id = ?');
$stTot->execute([$client['id']]);
$totalArticles = (int)$stTot->fetchColumn();

$stPub = $db->prepare('SELECT COUNT(*) FROM articles WHERE client_id = ? AND status = "published"');
$stPub->execute([$client['id']]);
$publishedArticles = (int)$stPub->fetchColumn();

$stPend = $db->prepare('SELECT COUNT(*) FROM articles WHERE client_id = ? AND status = "draft"');
$stPend->execute([$client['id']]);
$pendingApproval = (int)$stPend->fetchColumn();

$firstName   = htmlspecialchars($client['first_name'] ?: 'there');
$brandName   = htmlspecialchars($client['brand_name'] ?: 'Your Business');
$keyphrase   = htmlspecialchars($client['keyphrase']  ?: '');
$plan        = ucfirst($client['plan'] ?? 'trial');
$memberSince = date('F Y', strtotime($client['created_at']));
$hasWP       = !empty($client['wp_url']);

// ── Calendar helpers ──────────────────────────────────────────────
$firstDow    = (int)date('N', mktime(0, 0, 0, $month, 1, $year)); // 1=Mon 7=Sun
$daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
$today       = date('Y-m-d');

$statusColour = [
    'draft'     => 'bg-amber-100 text-amber-800 border-amber-200',
    'approved'  => 'bg-indigo-100 text-indigo-800 border-indigo-200',
    'published' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
    'failed'    => 'bg-rose-100 text-rose-800 border-rose-200',
];
$statusDot = [
    'draft'     => 'bg-amber-400',
    'approved'  => 'bg-indigo-500',
    'published' => 'bg-emerald-500',
    'failed'    => 'bg-rose-500',
];
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Dashboard — Auto-Seo.co.uk</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
    .sidebar-link { display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:10px;font-size:14px;font-weight:600;color:#64748b;transition:all .15s; }
    .sidebar-link:hover { background:#f1f5f9;color:#0f172a; }
    .sidebar-link.active { background:#eef2ff;color:#6366f1; }
    .stat-card { background:#fff;border-radius:16px;border:1px solid #e2e8f0;padding:20px 24px; }
    .badge { display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700; }
    .cal-day { min-height:88px; }
  </style>
</head>
<body class="bg-slate-50 text-slate-900 min-h-screen">

<div class="flex min-h-screen">

  <!-- Sidebar -->
  <aside class="hidden md:flex flex-col w-64 bg-white border-r border-slate-100 px-4 py-6 fixed h-full">
    <a href="/" class="flex items-center gap-2 px-2 mb-8">
      <div class="w-8 h-8 bg-indigo-500 rounded-lg flex items-center justify-center text-white text-sm font-bold">⚡</div>
      <span class="font-extrabold text-slate-900">Auto-Seo<span class="text-indigo-500">.co.uk</span></span>
    </a>
    <nav class="flex-1 space-y-1">
      <a href="/dashboard.php"                             class="sidebar-link active">📊 &nbsp;Dashboard</a>
      <a href="/dashboard-articles.php"                   class="sidebar-link">📄 &nbsp;Articles</a>
      <a href="/dashboard-rankings.php"                   class="sidebar-link">📈 &nbsp;Rankings</a>
      <a href="/dashboard-settings.php?section=keywords"  class="sidebar-link">📍 &nbsp;Keywords</a>
      <a href="/dashboard-settings.php?section=wordpress" class="sidebar-link">🔌 &nbsp;WordPress<?php if (!empty($client['wp_url'])): ?><span class="ml-auto w-2 h-2 bg-emerald-500 rounded-full"></span><?php endif; ?></a>
      <a href="/dashboard-settings.php?section=profile"   class="sidebar-link">👤 &nbsp;Profile</a>
      <a href="/dashboard-billing.php"                    class="sidebar-link">💳 &nbsp;Billing</a>
      <a href="/dashboard-settings.php?section=security"  class="sidebar-link">🔒 &nbsp;Security</a>
    </nav>
    <div class="mt-auto pt-6 border-t border-slate-100">
      <div class="px-2 mb-3">
        <p class="text-xs font-bold text-slate-500 truncate"><?= htmlspecialchars($client['email']) ?></p>
        <span class="badge bg-indigo-50 text-indigo-600 mt-1"><?= $plan ?></span>
      </div>
      <a href="/logout.php" class="sidebar-link text-rose-500 hover:text-rose-700 hover:bg-rose-50">🚪 &nbsp;Sign Out</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="flex-1 md:ml-64 px-6 py-8">

    <!-- Header -->
    <div class="flex items-center justify-between mb-8">
      <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Good <?= (date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening')) ?>, <?= $firstName ?>! 👋</h1>
        <p class="text-sm text-slate-500 mt-0.5">Here's your SEO overview for <strong><?= $brandName ?></strong></p>
      </div>
      <a href="/dashboard-articles.php"
        class="hidden sm:inline-flex items-center gap-2 bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-full text-sm hover:bg-indigo-500 transition active:scale-95">
        📄 View Articles
      </a>
    </div>

    <?php if (($client['plan'] ?? 'trial') === 'trial'): ?>
    <!-- Trial upgrade banner -->
    <div class="relative mb-8 rounded-2xl overflow-hidden" style="background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 60%,#a855f7 100%);">
      <div class="absolute inset-0 opacity-10" style="background-image:radial-gradient(circle at 80% 50%,#fff 0%,transparent 60%);"></div>
      <div class="relative flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 px-6 py-5">
        <div class="flex items-start gap-4">
          <div class="text-3xl mt-0.5">🚀</div>
          <div>
            <p class="font-extrabold text-white text-base leading-tight">Start your £1 trial today</p>
            <p class="text-white/80 text-sm mt-0.5">Get 3 fully written SEO articles in minutes — auto-published to your website.</p>
          </div>
        </div>
        <?php
        $stripeWithEmail = $stripeTrialUrl;
        if ($stripeTrialUrl !== '#' && $client['email']) {
            $sep = strpos($stripeTrialUrl, '?') !== false ? '&' : '?';
            $stripeWithEmail = $stripeTrialUrl . $sep . 'prefilled_email=' . urlencode($client['email']);
        }
        ?>
        <a href="<?= htmlspecialchars($stripeWithEmail) ?>" target="_blank" rel="noopener"
          class="flex-shrink-0 bg-white text-indigo-600 font-extrabold text-sm px-6 py-3 rounded-full hover:bg-indigo-50 transition active:scale-95 shadow-lg whitespace-nowrap">
          Claim your £1 trial →
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Stats row -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
      <div class="stat-card">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Articles Generated</p>
        <p class="text-3xl font-extrabold text-slate-900"><?= $totalArticles ?></p>
      </div>
      <div class="stat-card">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Published</p>
        <p class="text-3xl font-extrabold text-emerald-600"><?= $publishedArticles ?></p>
      </div>
      <?php if ($pendingApproval > 0): ?>
      <div class="stat-card border-amber-200 bg-amber-50">
        <p class="text-xs font-bold text-amber-600 uppercase tracking-wider mb-1">Awaiting Approval</p>
        <p class="text-3xl font-extrabold text-amber-600"><?= $pendingApproval ?></p>
      </div>
      <?php else: ?>
      <div class="stat-card">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Plan</p>
        <p class="text-3xl font-extrabold text-indigo-600"><?= $plan ?></p>
      </div>
      <?php endif; ?>
      <div class="stat-card">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Member Since</p>
        <p class="text-lg font-extrabold text-slate-900"><?= $memberSince ?></p>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-6">

      <!-- Content Calendar (left, 2/3) -->
      <div class="lg:col-span-2">
        <div class="bg-white rounded-2xl border border-slate-100 p-6">

          <!-- Calendar header -->
          <div class="flex items-center justify-between mb-5">
            <h2 class="text-sm font-bold uppercase tracking-wider text-slate-400">Content Calendar</h2>
            <div class="flex items-center gap-3">
              <a href="?m=<?= $prevMonth ?>&y=<?= $prevYear ?>"
                class="w-8 h-8 flex items-center justify-center rounded-full border border-slate-200 text-slate-500 hover:bg-slate-50 transition text-sm">‹</a>
              <span class="text-sm font-extrabold text-slate-900 min-w-[120px] text-center">
                <?= date('F Y', mktime(0, 0, 0, $month, 1, $year)) ?>
              </span>
              <a href="?m=<?= $nextMonth ?>&y=<?= $nextYear ?>"
                class="w-8 h-8 flex items-center justify-center rounded-full border border-slate-200 text-slate-500 hover:bg-slate-50 transition text-sm">›</a>
            </div>
          </div>

          <!-- Day headers -->
          <div class="grid grid-cols-7 mb-1">
            <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
              <div class="text-center text-xs font-bold text-slate-400 py-1"><?= $d ?></div>
            <?php endforeach; ?>
          </div>

          <!-- Calendar grid -->
          <div class="grid grid-cols-7 gap-px bg-slate-100 rounded-xl overflow-hidden border border-slate-100">
            <?php
            // Leading blank cells
            for ($b = 1; $b < $firstDow; $b++) {
                echo '<div class="cal-day bg-slate-50 p-1"></div>';
            }
            // Day cells
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $dateStr  = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($day, 2, '0', STR_PAD_LEFT);
                $isToday  = ($dateStr === $today);
                $dayArts  = $byDate[$dateStr] ?? [];
                $bgClass  = $isToday ? 'bg-indigo-50' : 'bg-white';
                ?>
                <div class="cal-day <?= $bgClass ?> p-1.5">
                  <div class="text-right mb-1">
                    <span class="<?= $isToday ? 'w-6 h-6 bg-indigo-600 text-white rounded-full inline-flex items-center justify-center text-xs font-extrabold' : 'text-xs font-semibold text-slate-400' ?>">
                      <?= $day ?>
                    </span>
                  </div>
                  <?php foreach ($dayArts as $art): ?>
                    <?php $sc = $statusColour[$art['status']] ?? 'bg-slate-100 text-slate-600 border-slate-200'; ?>
                    <a href="/dashboard-articles.php?id=<?= $art['id'] ?>"
                      class="block mb-0.5 px-1.5 py-0.5 rounded border text-xs font-semibold truncate <?= $sc ?> hover:opacity-80 transition"
                      title="<?= htmlspecialchars($art['title'] ?: $art['keyphrase'] ?: 'Untitled') ?>">
                      <span class="inline-block w-1.5 h-1.5 rounded-full <?= $statusDot[$art['status']] ?? 'bg-slate-400' ?> mr-1"></span>
                      <?= htmlspecialchars(mb_strimwidth($art['title'] ?: $art['keyphrase'] ?: 'Untitled', 0, 22, '…')) ?>
                    </a>
                  <?php endforeach; ?>
                  <?php if (empty($dayArts) && $dateStr >= $today): ?>
                    <div class="text-slate-200 text-center text-lg leading-none mt-2 select-none">·</div>
                  <?php endif; ?>
                </div>
                <?php
            }
            // Trailing blank cells
            $lastDow = (int)date('N', mktime(0, 0, 0, $month, $daysInMonth, $year));
            for ($t = $lastDow + 1; $t <= 7; $t++) {
                echo '<div class="cal-day bg-slate-50 p-1"></div>';
            }
            ?>
          </div>

          <?php if (empty($calArticles)): ?>
          <div class="mt-6 text-center py-6 border-2 border-dashed border-slate-100 rounded-xl">
            <p class="text-2xl mb-2">📅</p>
            <p class="text-sm font-bold text-slate-600 mb-1">No articles scheduled this month</p>
            <p class="text-xs text-slate-400 mb-3">Articles will appear here once generated with a scheduled date.</p>
            <a href="/dashboard-articles.php"
              class="inline-flex items-center gap-1 bg-indigo-600 text-white text-xs font-bold px-4 py-2 rounded-full hover:bg-indigo-500 transition">
              View all articles →
            </a>
          </div>
          <?php else: ?>
          <div class="mt-4 flex items-center justify-between">
            <p class="text-xs text-slate-400"><?= count($calArticles) ?> article<?= count($calArticles) !== 1 ? 's' : '' ?> scheduled this month</p>
            <a href="/dashboard-articles.php" class="text-xs text-indigo-600 font-semibold hover:underline">Manage all articles →</a>
          </div>
          <?php endif; ?>

        </div>
      </div>

      <!-- Right column: SEO Profile + WordPress + Legend -->
      <div class="lg:col-span-1 space-y-4">

        <div class="bg-white rounded-2xl border border-slate-100 p-6">
          <h2 class="text-sm font-bold uppercase tracking-wider text-slate-400 mb-4">SEO Profile</h2>
          <div class="space-y-3">
            <div>
              <p class="text-xs text-slate-400 font-semibold">Brand</p>
              <p class="text-sm font-bold text-slate-900"><?= $brandName ?></p>
            </div>
            <?php if ($keyphrase): ?>
            <div>
              <p class="text-xs text-slate-400 font-semibold">Primary Keyphrase</p>
              <span class="inline-block bg-indigo-50 text-indigo-700 text-xs font-semibold px-3 py-1 rounded-full border border-indigo-100 mt-0.5"><?= $keyphrase ?></span>
            </div>
            <?php endif; ?>
            <?php if ($client['location']): ?>
            <div>
              <p class="text-xs text-slate-400 font-semibold">Location</p>
              <p class="text-sm text-slate-700"><?= htmlspecialchars($client['location']) ?> (<?= htmlspecialchars($client['service_area'] ?? '') ?>)</p>
            </div>
            <?php endif; ?>
            <?php if ($client['website_url']): ?>
            <div>
              <p class="text-xs text-slate-400 font-semibold">Website</p>
              <a href="<?= htmlspecialchars($client['website_url']) ?>" target="_blank" class="text-sm text-indigo-600 underline truncate block"><?= htmlspecialchars($client['website_url']) ?></a>
            </div>
            <?php endif; ?>
          </div>
          <a href="/dashboard-settings.php" class="mt-4 block text-xs text-indigo-600 font-semibold hover:underline">Edit profile →</a>
        </div>

        <!-- WordPress status -->
        <div class="bg-white rounded-2xl border border-slate-100 p-6">
          <h2 class="text-sm font-bold uppercase tracking-wider text-slate-400 mb-3">WordPress</h2>
          <?php if ($hasWP): ?>
            <div class="flex items-center gap-2 text-emerald-600 text-sm font-semibold">
              <span class="w-2 h-2 bg-emerald-500 rounded-full"></span> Connected
            </div>
            <p class="text-xs text-slate-400 mt-1 truncate"><?= htmlspecialchars($client['wp_url']) ?></p>
          <?php else: ?>
            <p class="text-sm text-slate-500 mb-3">Connect your WordPress site to auto-publish articles.</p>
            <a href="/dashboard-settings.php#wordpress"
              class="inline-flex items-center gap-1 bg-slate-900 text-white text-xs font-bold px-4 py-2 rounded-full hover:bg-slate-700 transition">
              Connect WordPress →
            </a>
          <?php endif; ?>
        </div>

        <!-- Legend -->
        <div class="bg-white rounded-2xl border border-slate-100 p-5">
          <h2 class="text-sm font-bold uppercase tracking-wider text-slate-400 mb-3">Calendar Key</h2>
          <div class="space-y-2">
            <div class="flex items-center gap-2 text-xs font-semibold text-slate-600">
              <span class="w-3 h-3 rounded-full bg-amber-400 flex-shrink-0"></span> Awaiting your approval
            </div>
            <div class="flex items-center gap-2 text-xs font-semibold text-slate-600">
              <span class="w-3 h-3 rounded-full bg-indigo-500 flex-shrink-0"></span> Approved — ready to publish
            </div>
            <div class="flex items-center gap-2 text-xs font-semibold text-slate-600">
              <span class="w-3 h-3 rounded-full bg-emerald-500 flex-shrink-0"></span> Published live
            </div>
          </div>
        </div>

      </div>

    </div>

  </main>
</div>

</body>
</html>
