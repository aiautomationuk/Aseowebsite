<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/rank-helper.php';
$client = requireLogin();
$db     = getDB();

// ── Plan tier config ──────────────────────────────────────────────
$tierConfig = [
    'trial'            => ['label'=>'Free Trial',       'price'=>'£0',    'kps'=>1],
    'starter'          => ['label'=>'Local Starter',    'price'=>'£49',   'kps'=>1],
    'local_starter'    => ['label'=>'Local Starter',    'price'=>'£49',   'kps'=>1],
    'local_growth'     => ['label'=>'Local Growth',     'price'=>'£79',   'kps'=>3],
    'growth'           => ['label'=>'Local Growth',     'price'=>'£79',   'kps'=>3],
    'local_pro'        => ['label'=>'Local Pro',        'price'=>'£129',  'kps'=>5],
    'pro'              => ['label'=>'Local Pro',        'price'=>'£129',  'kps'=>5],
    'national_starter' => ['label'=>'National Starter', 'price'=>'£99',   'kps'=>1],
    'national_growth'  => ['label'=>'National Growth',  'price'=>'£149',  'kps'=>3],
    'national_pro'     => ['label'=>'National Pro',     'price'=>'£249',  'kps'=>5],
];

// Upgrade paths (what to show when at limit)
$upgradePaths = [
    1 => ['label'=>'Upgrade to 3 keyphrases','sub'=>'Local Growth — £79/mo','envKey'=>'STRIPE_LOCAL_GROWTH_URL','kps'=>3],
    3 => ['label'=>'Upgrade to 5 keyphrases','sub'=>'Local Pro — £129/mo',  'envKey'=>'STRIPE_LOCAL_PRO_URL',   'kps'=>5],
];

// Load .env for Stripe upgrade URLs
$envVars = [];
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $envVars[trim($k)] = trim($v);
    }
}

$planTierKey = $client['plan_tier'] ?? ($client['plan'] ?? 'trial');
$tier        = $tierConfig[$planTierKey] ?? $tierConfig['trial'];
$maxKps      = (int)($client['max_keyphrases'] ?? $tier['kps']);

// ── Handle keyphrase POST actions ─────────────────────────────────
$kpMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['kp_action'] ?? '';

    if ($action === 'add') {
        $newKp = trim($_POST['kp_new'] ?? '');
        if ($newKp) {
            $current = [];
            if (!empty($client['keyphrase'])) $current[] = $client['keyphrase'];
            $extra = json_decode($client['tracked_keyphrases'] ?? '[]', true) ?: [];
            $current = array_merge($current, $extra);
            $current = array_unique($current);

            if (count($current) >= $maxKps) {
                $kpMsg = 'error:You have reached your plan limit. Upgrade to add more keyphrases.';
            } elseif (in_array($newKp, $current)) {
                $kpMsg = 'error:That keyphrase is already being tracked.';
            } else {
                $current[] = $newKp;
                $primary   = $current[0];
                $extraNew  = array_slice($current, 1);
                $db->prepare('UPDATE clients SET keyphrase=?, tracked_keyphrases=?, updated_at=NOW() WHERE id=?')
                   ->execute([$primary, $extraNew ? json_encode($extraNew) : null, $client['id']]);

                // Refresh and immediately check ranking for the new keyphrase
                $s = $db->prepare('SELECT * FROM clients WHERE id = ?');
                $s->execute([$client['id']]);
                $client = $s->fetch();

                $position = null;
                if (!empty($client['website_url'])) {
                    set_time_limit(60);
                    $domain   = extractDomain($client['website_url']);
                    $location = $client['location'] ?? '';
                    $r        = checkAndSaveRanking($client['id'], $newKp, $domain, $location, $db);
                    $position = $r['position'];
                }
                $posText = $position ? "#$position on Google" : 'Not in top 10 yet';
                $kpMsg   = "success:Keyphrase added — current position: $posText";
            }
        }
    }

    if ($action === 'remove') {
        $removeKp = $_POST['kp_remove'] ?? '';
        $current  = [];
        if (!empty($client['keyphrase'])) $current[] = $client['keyphrase'];
        $extra = json_decode($client['tracked_keyphrases'] ?? '[]', true) ?: [];
        $current = array_merge($current, $extra);
        $current = array_values(array_filter($current, fn($k) => $k !== $removeKp));

        if (count($current) === 0) {
            $kpMsg = 'error:You must keep at least one keyphrase.';
        } else {
            $primary  = $current[0];
            $extraNew = array_slice($current, 1);
            $db->prepare('UPDATE clients SET keyphrase=?, tracked_keyphrases=?, updated_at=NOW() WHERE id=?')
               ->execute([$primary, $extraNew ? json_encode($extraNew) : null, $client['id']]);
            $kpMsg = 'success:Keyphrase removed.';
        }
    }

    // Refresh client data
    $s = $db->prepare('SELECT * FROM clients WHERE id = ?');
    $s->execute([$client['id']]);
    $client = $s->fetch();
}

// ── Build tracked keyphrases list ─────────────────────────────────
$trackedKps = [];
if (!empty($client['keyphrase'])) $trackedKps[] = $client['keyphrase'];
$extraJson = $client['tracked_keyphrases'] ?? null;
if ($extraJson) {
    $extra = json_decode($extraJson, true);
    if (is_array($extra)) {
        foreach ($extra as $kp) {
            if ($kp && !in_array($kp, $trackedKps)) $trackedKps[] = $kp;
        }
    }
}

$usedSlots = count($trackedKps);
$atLimit   = ($usedSlots >= $maxKps);

// ── Build week date labels starting from first check (or today) ──
$weekLabels = [];
$weekDates  = [];

// Find the earliest ranking check for this client
try {
    $firstCheck = $db->prepare('SELECT MIN(checked_at) FROM rankings WHERE client_id = ?');
    $firstCheck->execute([$client['id']]);
    $firstDate = $firstCheck->fetchColumn(); // e.g. "2026-03-03" or false
} catch (Exception $e) {
    $firstDate = false;
}

if ($firstDate) {
    // Start one week before first check, cap history at 26 weeks max
    $startTs  = strtotime('-1 week Monday', strtotime($firstDate));
    $todayTs  = strtotime('Monday this week') ?: strtotime('last Monday');
    $maxWeeks = 26;
    $weeks    = min($maxWeeks, (int)ceil(($todayTs - $startTs) / (7 * 86400)) + 1);
    $weeks    = max($weeks, 2); // always at least 2 points
    for ($w = $weeks - 1; $w >= 0; $w--) {
        $ts = strtotime("-$w weeks", $todayTs);
        $weekLabels[] = date('d M', $ts);
        $weekDates[]  = date('Y-m-d', $ts);
    }
} else {
    // No data yet — just show last 4 weeks so there's a clean empty state
    for ($w = 3; $w >= 0; $w--) {
        $weekLabels[] = date('d M', strtotime("-$w weeks Monday"));
        $weekDates[]  = date('Y-m-d', strtotime("-$w weeks Monday"));
    }
}

// ── Fetch ranking history ─────────────────────────────────────────
$rankingData     = [];
$rankingsDbReady = false;

if (!empty($trackedKps)) {
    try {
        $rankStmt = $db->prepare(
            'SELECT keyphrase, position, checked_at FROM rankings
             WHERE client_id = ? ORDER BY checked_at ASC'
        );
        $rankStmt->execute([$client['id']]);
        $rawRankings     = $rankStmt->fetchAll();
        $rankingsDbReady = true;

        $byKpDate = [];
        foreach ($rawRankings as $r) {
            $byKpDate[$r['keyphrase']][$r['checked_at']] = $r['position'];
        }

        $chartColours = ['#6366f1','#10b981','#f59e0b','#ef4444','#8b5cf6'];
        foreach ($trackedKps as $idx => $kp) {
            $points = [];
            foreach ($weekDates as $date) {
                $val = null;
                if (isset($byKpDate[$kp])) {
                    foreach ($byKpDate[$kp] as $rd => $pos) {
                        if ($rd <= $date) $val = $pos;
                    }
                }
                $points[] = $val;
            }
            $latest  = !empty($byKpDate[$kp]) ? end($byKpDate[$kp]) : null;
            $prevDts = array_keys($byKpDate[$kp] ?? []);
            $prev    = count($prevDts) >= 2 ? $byKpDate[$kp][$prevDts[count($prevDts) - 2]] : null;

            $rankingData[] = [
                'label'   => $kp,
                'data'    => $points,
                'colour'  => $chartColours[$idx % count($chartColours)],
                'latest'  => $latest,
                'prev'    => $prev,
                'history' => $byKpDate[$kp] ?? [],
            ];
        }
    } catch (Exception $e) {
        $rankingsDbReady = false;
    }
}

$firstName = htmlspecialchars($client['first_name'] ?: 'there');

// Upgrade prompt data
$upgradeInfo = null;
if ($atLimit && isset($upgradePaths[$maxKps])) {
    $up = $upgradePaths[$maxKps];
    $upgradeInfo = $up;
    $upgradeInfo['url'] = $envVars[$up['envKey']] ?? '/pricing.html';
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Rankings — AutoSEO Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" />
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
      <a href="/dashboard.php"                             class="sidebar-link">📊 &nbsp;Dashboard</a>
      <a href="/dashboard-articles.php"                   class="sidebar-link">📄 &nbsp;Articles</a>
      <a href="/dashboard-rankings.php"                   class="sidebar-link active">📈 &nbsp;Rankings</a>
      <a href="/dashboard-settings.php?section=keywords"  class="sidebar-link">📍 &nbsp;Keywords</a>
      <a href="/dashboard-settings.php?section=wordpress" class="sidebar-link">🔌 &nbsp;WordPress<?php if (!empty($client['wp_url'])): ?><span class="ml-auto w-2 h-2 bg-emerald-500 rounded-full"></span><?php endif; ?></a>
      <a href="/dashboard-settings.php?section=profile"   class="sidebar-link">👤 &nbsp;Profile</a>
      <a href="/dashboard-billing.php"                    class="sidebar-link">💳 &nbsp;Billing</a>
      <a href="/dashboard-settings.php?section=security"  class="sidebar-link">🔒 &nbsp;Security</a>
    </nav>
    <div class="mt-auto pt-6 border-t border-slate-100">
      <p class="text-xs text-slate-400 mb-3 px-4">Signed in as<br><strong class="text-slate-600"><?= $firstName ?></strong></p>
      <a href="/logout.php" class="sidebar-link text-rose-500 hover:text-rose-700 hover:bg-rose-50">🚪 &nbsp;Sign Out</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="flex-1 md:ml-60 p-6 max-w-5xl">

    <!-- Page header -->
    <div class="flex items-center justify-between mb-7 flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Google Rankings</h1>
        <p class="text-sm text-slate-400 mt-0.5">Tracked weekly — position 1 is top of Google</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <a href="/rank-check-now.php"
          class="inline-flex items-center gap-1.5 bg-indigo-600 text-white font-bold text-sm px-4 py-2 rounded-full hover:bg-indigo-500 transition active:scale-95">
          🔍 Manual check
        </a>
        <span class="bg-indigo-50 text-indigo-700 text-xs font-bold px-3 py-1.5 rounded-full">
          <?= htmlspecialchars($tier['label']) ?> · <?= $usedSlots ?>/<?= $maxKps ?>
        </span>
      </div>
    </div>

    <!-- Flash message -->
    <?php if ($kpMsg): ?>
    <?php [$type, $msg] = explode(':', $kpMsg, 2); ?>
    <div class="mb-5 px-5 py-3.5 rounded-xl text-sm font-semibold
      <?= $type === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' ?>">
      <?= $type === 'success' ? '✓' : '✗' ?> <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <!-- ── Keyphrase Manager ─────────────────────────────────────── -->
    <div class="bg-white rounded-2xl border border-slate-100 p-6 mb-6">
      <div class="flex items-center justify-between mb-5 flex-wrap gap-2">
        <div>
          <h2 class="text-sm font-extrabold text-slate-800">Your Tracked Keyphrases</h2>
          <p class="text-xs text-slate-400 mt-0.5">These are the phrases we check on Google every Monday</p>
        </div>
        <!-- Slot indicator -->
        <div class="flex items-center gap-2">
          <?php for ($i = 0; $i < $maxKps; $i++): ?>
            <div class="w-3 h-3 rounded-full <?= $i < $usedSlots ? 'bg-indigo-500' : 'bg-slate-200' ?>"></div>
          <?php endfor; ?>
          <span class="text-xs font-semibold text-slate-400 ml-1"><?= $usedSlots ?>/<?= $maxKps ?> used</span>
        </div>
      </div>

      <!-- Current keyphrases list -->
      <div class="space-y-2 mb-4">
        <?php foreach ($trackedKps as $idx => $kp): ?>
        <div class="flex items-center justify-between bg-slate-50 rounded-xl px-4 py-3 border border-slate-100">
          <div class="flex items-center gap-3">
            <span class="w-2.5 h-2.5 rounded-full flex-shrink-0"
              style="background:<?= ['#6366f1','#10b981','#f59e0b','#ef4444','#8b5cf6'][$idx % 5] ?>"></span>
            <span class="text-sm font-semibold text-slate-700"><?= htmlspecialchars($kp) ?></span>
            <?php if ($idx === 0): ?>
              <span class="text-xs text-indigo-500 font-bold">Primary</span>
            <?php endif; ?>
          </div>
          <form method="POST" class="ml-3" onsubmit="return confirm('Remove this keyphrase?')">
            <input type="hidden" name="kp_action" value="remove">
            <input type="hidden" name="kp_remove" value="<?= htmlspecialchars($kp) ?>">
            <button type="submit"
              class="text-slate-300 hover:text-rose-500 transition text-lg leading-none font-bold"
              title="Remove keyphrase"
              <?= count($trackedKps) <= 1 ? 'disabled title="You must keep at least one keyphrase"' : '' ?>>
              ×
            </button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Add new keyphrase OR upgrade prompt -->
      <?php if (!$atLimit): ?>
      <form method="POST" class="flex gap-2">
        <input type="hidden" name="kp_action" value="add">
        <input type="text" name="kp_new" placeholder='e.g. "boiler repair Leeds"'
          class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"
          required maxlength="255" />
        <button type="submit"
          class="bg-indigo-600 text-white font-bold text-sm px-5 py-2.5 rounded-xl hover:bg-indigo-500 transition active:scale-95 whitespace-nowrap">
          + Add
        </button>
      </form>
      <p class="text-xs text-slate-400 mt-2">
        <?= $maxKps - $usedSlots ?> slot<?= ($maxKps - $usedSlots) !== 1 ? 's' : '' ?> remaining on your plan
      </p>

      <?php else: ?>

      <!-- At plan limit — upgrade prompt -->
      <?php if ($upgradeInfo): ?>
      <div class="mt-2 bg-indigo-50 border border-indigo-100 rounded-xl px-5 py-4 flex items-center justify-between gap-4 flex-wrap">
        <div>
          <p class="text-sm font-extrabold text-indigo-800"><?= htmlspecialchars($upgradeInfo['label']) ?></p>
          <p class="text-xs text-indigo-500 mt-0.5"><?= htmlspecialchars($upgradeInfo['sub']) ?></p>
        </div>
        <a href="<?= htmlspecialchars($upgradeInfo['url']) ?>"
          class="bg-indigo-600 text-white font-bold text-sm px-5 py-2.5 rounded-xl hover:bg-indigo-500 transition whitespace-nowrap">
          Upgrade →
        </a>
      </div>
      <?php else: ?>
      <div class="mt-2 bg-slate-50 border border-slate-100 rounded-xl px-5 py-4 text-sm text-slate-500">
        You're on the maximum keyphrase plan.
        <a href="/pricing.html" class="text-indigo-600 font-semibold hover:underline ml-1">View all plans →</a>
      </div>
      <?php endif; ?>

      <!-- Can still show add field but disabled -->
      <div class="flex gap-2 mt-3 opacity-40 pointer-events-none select-none">
        <input type="text" placeholder='Upgrade to add more keyphrases'
          class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 text-sm bg-slate-50" disabled />
        <button class="bg-slate-300 text-white font-bold text-sm px-5 py-2.5 rounded-xl" disabled>+ Add</button>
      </div>

      <?php endif; ?>

      <!-- Downgrade link -->
      <p class="text-xs text-slate-300 mt-4 text-right">
        <a href="/dashboard-billing.php" class="hover:text-slate-400 transition">Change or downgrade plan →</a>
      </p>
    </div>

    <?php if (!$rankingsDbReady && !empty($trackedKps)): ?>
      <div class="bg-amber-50 border border-amber-200 rounded-2xl px-6 py-5 text-sm text-amber-800 mb-6">
        <p class="font-bold mb-1">Database table needed</p>
        <p class="mb-3">Run this in <strong>cPanel → phpMyAdmin → autoseo_db → SQL tab</strong>:</p>
        <pre class="bg-amber-100 rounded-xl p-4 text-xs overflow-x-auto text-amber-900">CREATE TABLE IF NOT EXISTS rankings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    keyphrase VARCHAR(500) NOT NULL,
    position SMALLINT DEFAULT NULL,
    checked_at DATE NOT NULL,
    UNIQUE KEY unique_check (client_id, keyphrase(200), checked_at),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;</pre>
      </div>

    <?php elseif (empty($trackedKps)): ?>
      <div class="bg-white rounded-2xl border border-slate-100 p-12 text-center">
        <p class="text-4xl mb-3">📈</p>
        <p class="text-lg font-extrabold text-slate-700 mb-1">Add a keyphrase above to start tracking</p>
        <p class="text-sm text-slate-400">We'll check your Google position every Monday.</p>
      </div>

    <?php else: ?>

      <!-- Position badges -->
      <div class="grid grid-cols-2 md:grid-cols-<?= min(count($rankingData), 4) ?> gap-4 mb-6">
        <?php foreach ($rankingData as $kpData): ?>
        <?php
          $latest = $kpData['latest'];
          $prev   = $kpData['prev'];
          $trend  = '';
          if ($latest !== null && $prev !== null) {
              $diff = $prev - $latest;
              if ($diff > 0)     $trend = '<span class="text-emerald-600 text-xs font-bold">▲ +' . $diff . '</span>';
              elseif ($diff < 0) $trend = '<span class="text-rose-500 text-xs font-bold">▼ ' . $diff . '</span>';
              else               $trend = '<span class="text-slate-400 text-xs">→</span>';
          }
        ?>
        <div class="bg-white rounded-2xl border border-slate-100 p-5">
          <p class="text-xs font-semibold text-slate-400 truncate mb-2"><?= htmlspecialchars($kpData['label']) ?></p>
          <?php if ($latest !== null): ?>
            <div class="flex items-baseline gap-2 flex-wrap">
              <p class="text-4xl font-black" style="color:<?= htmlspecialchars($kpData['colour']) ?>">#<?= $latest ?></p>
              <?= $trend ?>
            </div>
            <p class="text-xs mt-1 <?= $latest <= 3 ? 'text-emerald-600 font-bold' : ($latest <= 10 ? 'text-indigo-600 font-semibold' : 'text-slate-400') ?>">
              <?= $latest <= 3 ? '🏆 Top 3!' : ($latest <= 10 ? '✓ Page 1' : 'Page 2+') ?>
            </p>
          <?php else: ?>
            <p class="text-3xl font-black text-slate-200 mt-1">—</p>
            <p class="text-xs text-slate-400 mt-1">Checking soon</p>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Chart -->
      <div class="bg-white rounded-2xl border border-slate-100 p-6 mb-6">
        <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
          <h2 class="text-sm font-bold text-slate-700">Position History — Last 12 Weeks</h2>
          <span class="text-xs text-slate-400">Lower position number = higher on Google</span>
        </div>
        <div class="relative" style="height:300px">
          <canvas id="rankingChart"></canvas>
        </div>
      </div>

      <!-- Per-keyphrase history tables -->
      <?php foreach ($rankingData as $kpData): ?>
      <div class="bg-white rounded-2xl border border-slate-100 p-6 mb-4">
        <h3 class="text-sm font-extrabold text-slate-800 mb-4 flex items-center gap-2">
          <span class="w-3 h-3 rounded-full flex-shrink-0" style="background:<?= htmlspecialchars($kpData['colour']) ?>"></span>
          <?= htmlspecialchars($kpData['label']) ?>
        </h3>
        <?php if (!empty($kpData['history'])): ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100">
                <th class="text-left pb-2 text-xs font-semibold text-slate-400">Date</th>
                <th class="text-center pb-2 text-xs font-semibold text-slate-400">Position</th>
                <th class="text-center pb-2 text-xs font-semibold text-slate-400">Change</th>
                <th class="text-left pb-2 text-xs font-semibold text-slate-400">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $historyRows = array_reverse($kpData['history'], true);
              $prevPos = null;
              foreach ($historyRows as $date => $pos):
                  $change = '—'; $chClass = 'text-slate-300';
                  if ($prevPos !== null && $pos !== null) {
                      $diff = $prevPos - $pos;
                      if ($diff > 0)      { $change = '▲ +' . $diff; $chClass = 'text-emerald-600 font-bold'; }
                      elseif ($diff < 0)  { $change = '▼ ' . $diff;  $chClass = 'text-rose-500 font-bold'; }
                      else                { $change = '→';            $chClass = 'text-slate-400'; }
                  }
                  $prevPos = $pos;
              ?>
              <tr class="border-b border-slate-50 hover:bg-slate-50">
                <td class="py-2.5 text-slate-600"><?= date('d M Y', strtotime($date)) ?></td>
                <td class="py-2.5 text-center font-extrabold" style="color:<?= htmlspecialchars($kpData['colour']) ?>">
                  <?= $pos !== null ? '#' . $pos : '<span class="text-slate-300 font-normal">—</span>' ?>
                </td>
                <td class="py-2.5 text-center <?= $chClass ?>"><?= $change ?></td>
                <td class="py-2.5 text-xs">
                  <?php if ($pos === null): ?>
                    <span class="text-slate-300">Not in top 10</span>
                  <?php elseif ($pos <= 3): ?>
                    <span class="bg-emerald-50 text-emerald-700 font-bold px-2 py-0.5 rounded-full">🏆 Top 3</span>
                  <?php elseif ($pos <= 10): ?>
                    <span class="bg-indigo-50 text-indigo-700 font-semibold px-2 py-0.5 rounded-full">Page 1</span>
                  <?php else: ?>
                    <span class="bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full">Page 2+</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <p class="text-xs text-slate-400">No checks recorded yet — rankings are checked every Monday.</p>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <p class="text-xs text-slate-300 text-right mt-2">Checks top 10 positions · Updated weekly</p>

    <?php endif; ?>

  </main>
</div>

<?php if (!empty($rankingData)): ?>
<script>
const labels   = <?= json_encode($weekLabels) ?>;
const datasets = <?= json_encode(array_map(fn($d) => [
    'label'                => $d['label'],
    'data'                 => $d['data'],
    'borderColor'          => $d['colour'],
    'backgroundColor'      => $d['colour'] . '15',
    'borderWidth'          => 2.5,
    'pointBackgroundColor' => $d['colour'],
    'pointRadius'          => 4,
    'pointHoverRadius'     => 7,
    'tension'              => 0.35,
    'fill'                 => true,
    'spanGaps'             => true,
], $rankingData)) ?>;

new Chart(document.getElementById('rankingChart').getContext('2d'), {
  type: 'line',
  data: { labels, datasets },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: {
        position: 'bottom',
        labels: { font: { size: 12, family: 'Inter' }, padding: 20, usePointStyle: true, pointStyleWidth: 10 }
      },
      tooltip: {
        callbacks: {
          label: ctx => ctx.parsed.y !== null
            ? ` ${ctx.dataset.label}: #${ctx.parsed.y}`
            : ` ${ctx.dataset.label}: Not in top 10`
        }
      }
    },
    scales: {
      y: {
        reverse: true, min: 1, max: 10,
        ticks: { stepSize: 1, font: { size: 11 }, callback: v => '#' + v },
        grid: { color: '#f1f5f9' },
        title: { display: true, text: 'Google Position', font: { size: 11 }, color: '#94a3b8' }
      },
      x: { ticks: { font: { size: 11 }, maxRotation: 0 }, grid: { display: false } }
    }
  }
});
</script>
<?php endif; ?>

</body>
</html>
