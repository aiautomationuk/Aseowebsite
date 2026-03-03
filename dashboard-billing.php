<?php
require_once __DIR__ . '/auth.php';
$client = requireLogin();
$db     = getDB();

// ── Load .env ─────────────────────────────────────────────────────
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

$stripeKey = $envVars['STRIPE_SECRET_KEY'] ?? '';
$stripeOk  = $stripeKey && !str_contains($stripeKey, 'YOUR');

// ── Plan tier config ──────────────────────────────────────────────
$tierConfig = [
    'trial'            => ['label'=>'Free Trial',       'price'=>'£0',    'kps'=>1, 'articles'=>3,  'colour'=>'slate'],
    'starter'          => ['label'=>'Local Starter',    'price'=>'£49',   'kps'=>1, 'articles'=>4,  'colour'=>'indigo'],
    'local_starter'    => ['label'=>'Local Starter',    'price'=>'£49',   'kps'=>1, 'articles'=>4,  'colour'=>'indigo'],
    'local_growth'     => ['label'=>'Local Growth',     'price'=>'£79',   'kps'=>3, 'articles'=>12, 'colour'=>'indigo'],
    'growth'           => ['label'=>'Local Growth',     'price'=>'£79',   'kps'=>3, 'articles'=>12, 'colour'=>'indigo'],
    'local_pro'        => ['label'=>'Local Pro',        'price'=>'£129',  'kps'=>5, 'articles'=>20, 'colour'=>'violet'],
    'pro'              => ['label'=>'Local Pro',        'price'=>'£129',  'kps'=>5, 'articles'=>20, 'colour'=>'violet'],
    'national_starter' => ['label'=>'National Starter', 'price'=>'£99',   'kps'=>1, 'articles'=>8,  'colour'=>'indigo'],
    'national_growth'  => ['label'=>'National Growth',  'price'=>'£149',  'kps'=>3, 'articles'=>20, 'colour'=>'indigo'],
    'national_pro'     => ['label'=>'National Pro',     'price'=>'£249',  'kps'=>5, 'articles'=>30, 'colour'=>'violet'],
    'active'           => ['label'=>'Active Plan',      'price'=>'—',     'kps'=>1, 'articles'=>8,  'colour'=>'indigo'],
    'cancelled'        => ['label'=>'Cancelled',        'price'=>'—',     'kps'=>0, 'articles'=>0,  'colour'=>'rose'],
    'cancelling'       => ['label'=>'Cancelling',       'price'=>'—',     'kps'=>1, 'articles'=>8,  'colour'=>'amber'],
];

$planTierKey = $client['plan_tier'] ?? ($client['plan'] ?? 'trial');
$tier        = $tierConfig[$planTierKey] ?? $tierConfig['trial'];
$isTrial     = ($planTierKey === 'trial');
$isCancelled = in_array($planTierKey, ['cancelled', 'cancelling']);

// ── Stripe helper ─────────────────────────────────────────────────
function stripeGet(string $endpoint, array $params = [], string $key = ''): ?array {
    $url = 'https://api.stripe.com/v1/' . $endpoint;
    if ($params) $url .= '?' . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "$key:",
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

// ── Fetch Stripe data ─────────────────────────────────────────────
$subscription  = null;
$invoices      = [];
$nextBillDate  = null;
$nextBillAmt   = null;
$subStatus     = null;
$cancelAtEnd   = false;

if ($stripeOk) {
    // Subscription
    $subId = $client['stripe_subscription_id'] ?? null;
    if ($subId) {
        $subscription = stripeGet("subscriptions/$subId", [], $stripeKey);
        if ($subscription && !isset($subscription['error'])) {
            $subStatus   = $subscription['status'] ?? null;
            $cancelAtEnd = (bool)($subscription['cancel_at_period_end'] ?? false);
            if (!empty($subscription['current_period_end'])) {
                $nextBillDate = date('d M Y', $subscription['current_period_end']);
            }
            $items = $subscription['items']['data'][0] ?? null;
            if ($items) {
                $amt         = $items['price']['unit_amount'] ?? null;
                $currency    = strtoupper($items['price']['currency'] ?? 'gbp');
                $symbol      = $currency === 'GBP' ? '£' : ($currency === 'USD' ? '$' : $currency . ' ');
                $nextBillAmt = $amt ? $symbol . number_format($amt / 100, 2) : null;
            }
        }
    }

    // Invoices
    $customerId = $client['stripe_customer_id'] ?? null;
    if ($customerId) {
        $invData = stripeGet('invoices', ['customer' => $customerId, 'limit' => 12], $stripeKey);
        if ($invData && isset($invData['data'])) {
            $invoices = $invData['data'];
        }
    }
}

$firstName = htmlspecialchars($client['first_name'] ?: 'there');
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Billing — AutoSEO Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
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
      <a href="/dashboard-rankings.php"                   class="sidebar-link">📈 &nbsp;Rankings</a>
      <a href="/dashboard-settings.php?section=keywords"  class="sidebar-link">📍 &nbsp;Keywords</a>
      <a href="/dashboard-settings.php?section=wordpress" class="sidebar-link">🔌 &nbsp;WordPress<?php if (!empty($client['wp_url'])): ?><span class="ml-auto w-2 h-2 bg-emerald-500 rounded-full"></span><?php endif; ?></a>
      <a href="/dashboard-settings.php?section=profile"   class="sidebar-link">👤 &nbsp;Profile</a>
      <a href="/dashboard-billing.php"                    class="sidebar-link active">💳 &nbsp;Billing</a>
      <a href="/dashboard-settings.php?section=security"  class="sidebar-link">🔒 &nbsp;Security</a>
    </nav>
    <div class="mt-auto pt-6 border-t border-slate-100">
      <p class="text-xs text-slate-400 mb-3 px-4">Signed in as<br><strong class="text-slate-600"><?= $firstName ?></strong></p>
      <a href="/logout.php" class="sidebar-link text-rose-500 hover:text-rose-700 hover:bg-rose-50">🚪 &nbsp;Sign Out</a>
    </div>
  </aside>

  <!-- Main -->
  <main class="flex-1 md:ml-60 p-6 max-w-3xl">

    <div class="mb-7">
      <h1 class="text-2xl font-extrabold text-slate-900">Billing &amp; Subscription</h1>
      <p class="text-sm text-slate-400 mt-0.5">Manage your plan, view payments, and billing details</p>
    </div>

    <!-- Flash message (set via JS after cancel action) -->
    <div id="flashMsg" class="hidden mb-5 px-5 py-3.5 rounded-xl text-sm font-semibold"></div>

    <!-- ── Current Plan Card ──────────────────────────────────── -->
    <div class="bg-white rounded-2xl border border-slate-100 p-6 mb-5">
      <div class="flex items-start justify-between flex-wrap gap-4">
        <div>
          <p class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-1">Current Plan</p>
          <h2 class="text-2xl font-extrabold text-slate-900 flex items-center gap-3">
            <?= htmlspecialchars($tier['label']) ?>
            <?php if ($cancelAtEnd): ?>
              <span class="text-xs font-bold bg-amber-100 text-amber-700 px-3 py-1 rounded-full">Cancels at period end</span>
            <?php elseif ($isCancelled): ?>
              <span class="text-xs font-bold bg-rose-100 text-rose-700 px-3 py-1 rounded-full">Cancelled</span>
            <?php elseif (!$isTrial): ?>
              <span class="text-xs font-bold bg-emerald-100 text-emerald-700 px-3 py-1 rounded-full">Active</span>
            <?php else: ?>
              <span class="text-xs font-bold bg-slate-100 text-slate-500 px-3 py-1 rounded-full">Trial</span>
            <?php endif; ?>
          </h2>
          <p class="text-sm text-slate-500 mt-1">
            <?= htmlspecialchars($tier['kps']) ?> keyphrase<?= $tier['kps'] !== 1 ? 's' : '' ?> ·
            <?= htmlspecialchars($tier['articles']) ?> articles/month
          </p>
        </div>
        <div class="text-right">
          <p class="text-3xl font-black text-slate-900"><?= htmlspecialchars($tier['price']) ?><?= !$isTrial ? '<span class="text-sm font-normal text-slate-400">/mo</span>' : '' ?></p>
          <?php if ($nextBillDate && !$cancelAtEnd): ?>
            <p class="text-xs text-slate-400 mt-1">Next payment: <strong class="text-slate-700"><?= $nextBillDate ?></strong>
            <?= $nextBillAmt ? " · <strong class='text-slate-700'>$nextBillAmt</strong>" : '' ?></p>
          <?php elseif ($cancelAtEnd && $nextBillDate): ?>
            <p class="text-xs text-amber-600 mt-1">Access until: <strong><?= $nextBillDate ?></strong></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Action buttons -->
      <div class="mt-5 pt-5 border-t border-slate-100 flex items-center justify-between flex-wrap gap-3">
        <a href="/pricing.html"
          class="inline-flex items-center gap-2 bg-indigo-600 text-white font-bold text-sm px-5 py-2.5 rounded-full hover:bg-indigo-500 transition active:scale-95">
          <?= $isTrial ? '🚀 Upgrade to a paid plan' : '🔄 Change plan' ?>
        </a>

        <?php if (!$isTrial && !$isCancelled && !$cancelAtEnd): ?>
        <button onclick="openCancelModal()"
          class="text-xs text-slate-400 hover:text-rose-500 transition underline underline-offset-2">
          Cancel subscription
        </button>
        <?php elseif ($cancelAtEnd): ?>
        <p class="text-xs text-amber-600">Your subscription will not renew. You'll keep access until <?= $nextBillDate ?>.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Payment History ────────────────────────────────────── -->
    <div class="bg-white rounded-2xl border border-slate-100 p-6 mb-5">
      <h2 class="text-sm font-extrabold text-slate-800 mb-4">Payment History</h2>

      <?php if (!$stripeOk): ?>
        <div class="bg-slate-50 rounded-xl p-5 text-sm text-slate-500 text-center border border-dashed border-slate-200">
          <p class="font-semibold mb-1">Stripe not connected</p>
          <p class="text-xs">Add your <code>STRIPE_SECRET_KEY</code> to <code>.env</code> to view payment history.</p>
        </div>

      <?php elseif (empty($invoices)): ?>
        <div class="text-center py-8 border-2 border-dashed border-slate-100 rounded-xl">
          <p class="text-2xl mb-2">🧾</p>
          <p class="text-sm text-slate-500">No payments yet</p>
          <?php if ($isTrial): ?>
          <p class="text-xs text-slate-400 mt-1">Upgrade to a paid plan to see invoices here.</p>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100">
                <th class="text-left pb-2 text-xs font-semibold text-slate-400">Date</th>
                <th class="text-left pb-2 text-xs font-semibold text-slate-400">Description</th>
                <th class="text-right pb-2 text-xs font-semibold text-slate-400">Amount</th>
                <th class="text-center pb-2 text-xs font-semibold text-slate-400">Status</th>
                <th class="text-right pb-2 text-xs font-semibold text-slate-400">Invoice</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($invoices as $inv):
                $invDate   = isset($inv['created'])       ? date('d M Y', $inv['created']) : '—';
                $invAmt    = isset($inv['amount_paid'])    ? '£' . number_format($inv['amount_paid'] / 100, 2) : '—';
                $invStatus = $inv['status'] ?? 'unknown';
                $invDesc   = $inv['lines']['data'][0]['description'] ?? ($inv['description'] ?? 'Subscription');
                $invUrl    = $inv['hosted_invoice_url'] ?? null;
                $statusCls = match($invStatus) {
                    'paid'   => 'bg-emerald-50 text-emerald-700',
                    'open'   => 'bg-amber-50 text-amber-700',
                    'void'   => 'bg-slate-100 text-slate-500',
                    default  => 'bg-slate-100 text-slate-500',
                };
              ?>
              <tr class="border-b border-slate-50 hover:bg-slate-50">
                <td class="py-3 text-slate-600 whitespace-nowrap"><?= $invDate ?></td>
                <td class="py-3 text-slate-700 max-w-xs truncate"><?= htmlspecialchars($invDesc) ?></td>
                <td class="py-3 text-right font-semibold text-slate-800"><?= $invAmt ?></td>
                <td class="py-3 text-center">
                  <span class="text-xs font-bold px-2.5 py-1 rounded-full <?= $statusCls ?>">
                    <?= ucfirst($invStatus) ?>
                  </span>
                </td>
                <td class="py-3 text-right">
                  <?php if ($invUrl): ?>
                  <a href="<?= htmlspecialchars($invUrl) ?>" target="_blank"
                    class="text-xs text-indigo-600 font-semibold hover:underline">View →</a>
                  <?php else: ?>
                  <span class="text-xs text-slate-300">—</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- ── Next payment info ──────────────────────────────────── -->
    <?php if ($nextBillDate && !$cancelAtEnd): ?>
    <div class="bg-slate-50 rounded-2xl border border-slate-100 px-6 py-4 flex items-center gap-4 text-sm mb-5">
      <span class="text-2xl">📅</span>
      <div>
        <p class="font-bold text-slate-700">Next payment: <?= $nextBillDate ?><?= $nextBillAmt ? " · $nextBillAmt" : '' ?></p>
        <p class="text-xs text-slate-400 mt-0.5">Your subscription renews automatically. <a href="/pricing.html" class="text-indigo-600 hover:underline">Change plan</a></p>
      </div>
    </div>
    <?php endif; ?>

    <p class="text-xs text-slate-300 mt-2">Payments processed securely by Stripe. Your card details are never stored on our servers.</p>

  </main>
</div>

<!-- ══════════════════════════════════════════════════════════════ -->
<!-- Cancel Subscription Modal                                      -->
<!-- ══════════════════════════════════════════════════════════════ -->
<div id="cancelModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4"
  style="background:rgba(0,0,0,0.5)">
  <div class="bg-white rounded-2xl max-w-md w-full p-8 shadow-2xl">
    <div class="text-center mb-6">
      <div class="w-14 h-14 bg-rose-50 rounded-full flex items-center justify-center mx-auto mb-4">
        <span class="text-2xl">⚠️</span>
      </div>
      <h2 class="text-xl font-extrabold text-slate-900 mb-2">Cancel Subscription?</h2>
      <p class="text-sm text-slate-500">
        Your subscription will remain active until the end of your current billing period.
        <?php if ($nextBillDate): ?>
        That's <strong><?= $nextBillDate ?></strong>.
        <?php endif; ?>
        No further payments will be taken.
      </p>
    </div>

    <div class="bg-rose-50 border border-rose-100 rounded-xl p-4 mb-5 text-xs text-rose-700">
      <strong>You'll lose access to:</strong> AI article generation, rank tracking, and your content calendar.
    </div>

    <div class="mb-5">
      <label class="block text-xs font-bold text-slate-600 mb-2">
        Enter your password to confirm cancellation
      </label>
      <input type="password" id="cancelPassword" placeholder="Your password"
        class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-rose-300" />
      <p id="cancelError" class="text-xs text-rose-600 mt-2 hidden"></p>
    </div>

    <div class="flex gap-3">
      <button onclick="closeCancelModal()"
        class="flex-1 bg-slate-100 text-slate-700 font-bold py-3 rounded-xl hover:bg-slate-200 transition text-sm">
        Keep My Plan
      </button>
      <button onclick="submitCancel()"
        id="cancelSubmitBtn"
        class="flex-1 bg-rose-600 text-white font-bold py-3 rounded-xl hover:bg-rose-500 transition text-sm active:scale-95">
        Cancel Subscription
      </button>
    </div>

    <p class="text-xs text-center text-slate-400 mt-4">
      Changed your mind? <a href="/pricing.html" class="text-indigo-600 hover:underline" onclick="closeCancelModal()">Upgrade instead →</a>
    </p>
  </div>
</div>

<script>
function openCancelModal() {
  document.getElementById('cancelModal').classList.remove('hidden');
  document.getElementById('cancelModal').classList.add('flex');
  document.getElementById('cancelPassword').focus();
}
function closeCancelModal() {
  document.getElementById('cancelModal').classList.add('hidden');
  document.getElementById('cancelModal').classList.remove('flex');
  document.getElementById('cancelPassword').value = '';
  document.getElementById('cancelError').classList.add('hidden');
}

async function submitCancel() {
  const pw  = document.getElementById('cancelPassword').value.trim();
  const err = document.getElementById('cancelError');
  const btn = document.getElementById('cancelSubmitBtn');
  err.classList.add('hidden');

  if (!pw) { err.textContent = 'Please enter your password.'; err.classList.remove('hidden'); return; }

  btn.disabled    = true;
  btn.textContent = 'Processing…';

  try {
    const res  = await fetch('/billing-action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'cancel', password: pw }),
    });
    const data = await res.json();

    if (data.ok) {
      closeCancelModal();
      const flash = document.getElementById('flashMsg');
      flash.className = 'mb-5 px-5 py-3.5 rounded-xl text-sm font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200';
      flash.textContent = '✓ ' + data.message;
      flash.classList.remove('hidden');
      setTimeout(() => location.reload(), 2500);
    } else {
      err.textContent = data.error || 'Something went wrong. Please try again.';
      err.classList.remove('hidden');
      btn.disabled    = false;
      btn.textContent = 'Cancel Subscription';
    }
  } catch (e) {
    err.textContent = 'Network error. Please try again.';
    err.classList.remove('hidden');
    btn.disabled    = false;
    btn.textContent = 'Cancel Subscription';
  }
}

// Close modal on backdrop click
document.getElementById('cancelModal').addEventListener('click', function(e) {
  if (e.target === this) closeCancelModal();
});

// Enter key in password field
document.getElementById('cancelPassword').addEventListener('keydown', function(e) {
  if (e.key === 'Enter') submitCancel();
});
</script>

</body>
</html>
