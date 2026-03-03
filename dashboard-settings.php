<?php
require_once __DIR__ . '/auth.php';
$client = requireLogin();
$db     = getDB();

$success = '';
$errors  = [];
$section = $_GET['section'] ?? 'profile';
$validSections = ['profile', 'wordpress', 'security', 'keywords'];
if (!in_array($section, $validSections)) $section = 'profile';

// ── Handle POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST['section'] ?? '';

    // ── Profile update ────────────────────────────────────────────
    if ($posted === 'profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName  = trim($_POST['last_name']  ?? '');
        $phone     = trim($_POST['phone']      ?? '');
        $brand     = trim($_POST['brand_name'] ?? '');
        $location  = trim($_POST['location']   ?? '');

        if (!$firstName) $errors[] = 'First name is required.';

        if (empty($errors)) {
            $db->prepare('UPDATE clients SET first_name=?, last_name=?, phone=?, brand_name=?, location=?, updated_at=NOW() WHERE id=?')
               ->execute([$firstName, $lastName, $phone, $brand, $location, $client['id']]);
            $success = 'Profile updated successfully.';
            // Refresh client data
            $s = $db->prepare('SELECT * FROM clients WHERE id = ?');
            $s->execute([$client['id']]);
            $client = $s->fetch();
        }
        $section = 'profile';
    }

    // ── WordPress update ──────────────────────────────────────────
    if ($posted === 'wordpress') {
        $wpUrl  = rtrim(trim($_POST['wp_url']          ?? ''), '/');
        $wpUser = trim($_POST['wp_username']           ?? '');
        $wpPass = trim($_POST['wp_app_password']       ?? '');

        if ($wpUrl && !filter_var($wpUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Please enter a valid WordPress URL (include https://).';
        }

        if (empty($errors)) {
            // Test the connection if all three fields are filled
            $testResult = null;
            if ($wpUrl && $wpUser && $wpPass) {
                $testResult = testWpConnection($wpUrl, $wpUser, $wpPass);
                if (!$testResult['ok']) {
                    $errors[] = 'WordPress connection failed: ' . $testResult['message'];
                }
            }

            if (empty($errors)) {
                $db->prepare('UPDATE clients SET wp_url=?, wp_username=?, wp_app_password=?, updated_at=NOW() WHERE id=?')
                   ->execute([$wpUrl ?: null, $wpUser ?: null, $wpPass ?: null, $client['id']]);
                $success = $testResult ? 'WordPress connected and verified successfully!' : 'WordPress settings saved.';
                $s = $db->prepare('SELECT * FROM clients WHERE id = ?');
                $s->execute([$client['id']]);
                $client = $s->fetch();
            }
        }
        $section = 'wordpress';
    }

    // ── Tracked keyphrases ────────────────────────────────────────
    if ($posted === 'keywords') {
        $maxKps = (int)($client['max_keyphrases'] ?? 1);

        // Collect submitted keyphrases (skip empties)
        $submitted = [];
        for ($i = 0; $i < max($maxKps, 5); $i++) {
            $kp = trim($_POST["kp_$i"] ?? '');
            if ($kp !== '') $submitted[] = $kp;
        }

        // Clamp to allowed count
        $submitted = array_slice($submitted, 0, $maxKps);

        // Primary keyphrase (index 0) → clients.keyphrase
        $primary = $submitted[0] ?? ($client['keyphrase'] ?? '');
        // Extra keyphrases → clients.tracked_keyphrases JSON
        $extra   = array_slice($submitted, 1);

        $db->prepare('UPDATE clients SET keyphrase=?, tracked_keyphrases=?, updated_at=NOW() WHERE id=?')
           ->execute([$primary, $extra ? json_encode($extra) : null, $client['id']]);

        $success = 'Tracked keyphrases saved.';
        $s = $db->prepare('SELECT * FROM clients WHERE id = ?');
        $s->execute([$client['id']]);
        $client = $s->fetch();
        $section = 'keywords';
    }

    // ── Password change ───────────────────────────────────────────
    if ($posted === 'security') {
        $current  = $_POST['current_password']  ?? '';
        $new      = $_POST['new_password']      ?? '';
        $confirm  = $_POST['confirm_password']  ?? '';

        if (!$current) {
            $errors[] = 'Please enter your current password.';
        } elseif (!password_verify($current, $client['password_hash'] ?? '')) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        }

        if (empty($errors)) {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            $db->prepare('UPDATE clients SET password_hash=?, updated_at=NOW() WHERE id=?')
               ->execute([$hash, $client['id']]);
            $success = 'Password changed successfully.';
        }
        $section = 'security';
    }
}

// ── Test WordPress connection ─────────────────────────────────────
function testWpConnection(string $url, string $user, string $pass): array {
    $endpoint = $url . '/wp-json/wp/v2/users/me';
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . base64_encode($user . ':' . $pass),
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['ok' => false, 'message' => 'Could not reach the URL: ' . $err];
    if ($code === 401) return ['ok' => false, 'message' => 'Invalid username or application password.'];
    if ($code === 404) return ['ok' => false, 'message' => 'WordPress REST API not found. Make sure the URL is correct and permalinks are enabled.'];
    if ($code !== 200) return ['ok' => false, 'message' => "Unexpected response (HTTP $code). Check your WordPress URL."];

    $data = json_decode($body, true);
    $name = $data['name'] ?? 'unknown';
    return ['ok' => true, 'message' => "Connected as \"$name\""];
}

$plan      = ucfirst($client['plan'] ?? 'trial');
$hasWP     = !empty($client['wp_url']) && !empty($client['wp_username']) && !empty($client['wp_app_password']);
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Settings — Auto-Seo.co.uk</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
    .sidebar-link { display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:10px;font-size:14px;font-weight:600;color:#64748b;transition:all .15s; }
    .sidebar-link:hover { background:#f1f5f9;color:#0f172a; }
    .sidebar-link.active { background:#eef2ff;color:#6366f1; }
    .badge { display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700; }

    .field label { display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px; }
    .field input { width:100%;border:1px solid #e2e8f0;border-radius:12px;padding:11px 16px;font-size:14px;color:#0f172a;outline:none;transition:border .15s; }
    .field input:focus { border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1); }
    .field input:disabled { background:#f8fafc;color:#94a3b8; }
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
      <a href="/dashboard.php"                             class="sidebar-link">📊 &nbsp;Dashboard</a>
      <a href="/dashboard-articles.php"                   class="sidebar-link">📄 &nbsp;Articles</a>
      <a href="/dashboard-rankings.php"                   class="sidebar-link">📈 &nbsp;Rankings</a>
      <a href="/dashboard-settings.php?section=keywords"  class="sidebar-link <?= $section==='keywords'  ? 'active':'' ?>">📍 &nbsp;Keywords</a>
      <a href="/dashboard-settings.php?section=wordpress" class="sidebar-link <?= $section==='wordpress' ? 'active':'' ?>">🔌 &nbsp;WordPress<?php if ($hasWP): ?><span class="ml-auto w-2 h-2 bg-emerald-500 rounded-full"></span><?php endif; ?></a>
      <a href="/dashboard-settings.php?section=profile"   class="sidebar-link <?= $section==='profile'   ? 'active':'' ?>">👤 &nbsp;Profile</a>
      <a href="/dashboard-billing.php"                    class="sidebar-link">💳 &nbsp;Billing</a>
      <a href="/dashboard-settings.php?section=security"  class="sidebar-link <?= $section==='security'  ? 'active':'' ?>">🔒 &nbsp;Security</a>
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

    <?php
      $sectionTitles = [
        'profile'   => ['title' => 'Profile',              'sub' => 'Update your name and business details'],
        'wordpress' => ['title' => 'WordPress Connection',  'sub' => 'Connect your site for auto-publishing'],
        'security'  => ['title' => 'Security',             'sub' => 'Change your password'],
        'keywords'  => ['title' => 'Keywords',             'sub' => 'Manage the keyphrases we track for you'],
      ];
      $st = $sectionTitles[$section] ?? $sectionTitles['profile'];
    ?>
    <div class="mb-7">
      <h1 class="text-2xl font-extrabold text-slate-900"><?= $st['title'] ?></h1>
      <p class="text-sm text-slate-500 mt-0.5"><?= $st['sub'] ?></p>
    </div>

    <div class="max-w-2xl">

      <!-- Settings panel -->
      <div class="min-w-0">

        <?php if (!empty($success)): ?>
          <div class="mb-5 bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm font-semibold px-5 py-3.5 rounded-xl flex items-center gap-2">
            <span class="text-emerald-500 text-base">✓</span> <?= htmlspecialchars($success) ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
          <div class="mb-5 bg-rose-50 border border-rose-200 text-rose-700 text-sm px-5 py-3.5 rounded-xl space-y-1">
            <?php foreach ($errors as $e): ?>
              <p class="font-semibold">⚠ <?= htmlspecialchars($e) ?></p>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- ── Profile ──────────────────────────────────────────── -->
        <?php if ($section === 'profile'): ?>
        <div class="bg-white rounded-2xl border border-slate-100 p-7">
          <h2 class="text-base font-extrabold text-slate-900 mb-1">Your Profile</h2>
          <p class="text-sm text-slate-500 mb-6">Update your name, phone number and business details.</p>

          <form method="POST" class="space-y-5">
            <input type="hidden" name="section" value="profile"/>

            <div class="grid grid-cols-2 gap-4">
              <div class="field">
                <label>First Name</label>
                <input type="text" name="first_name" required value="<?= htmlspecialchars($client['first_name'] ?? '') ?>" placeholder="Jane"/>
              </div>
              <div class="field">
                <label>Last Name</label>
                <input type="text" name="last_name" value="<?= htmlspecialchars($client['last_name'] ?? '') ?>" placeholder="Smith"/>
              </div>
            </div>

            <div class="field">
              <label>Email Address</label>
              <input type="email" value="<?= htmlspecialchars($client['email']) ?>" disabled/>
              <p class="text-xs text-slate-400 mt-1.5">To change your email address please contact support.</p>
            </div>

            <div class="field">
              <label>Phone Number</label>
              <input type="tel" name="phone" value="<?= htmlspecialchars($client['phone'] ?? '') ?>" placeholder="+44 7700 900000"/>
            </div>

            <div class="field">
              <label>Business / Brand Name</label>
              <input type="text" name="brand_name" value="<?= htmlspecialchars($client['brand_name'] ?? '') ?>" placeholder="Acme Plumbing Ltd"/>
            </div>

            <div class="field">
              <label>Location</label>
              <input type="text" name="location" value="<?= htmlspecialchars($client['location'] ?? '') ?>" placeholder="Manchester"/>
            </div>

            <div class="pt-2">
              <button type="submit"
                class="bg-indigo-600 text-white font-bold text-sm px-6 py-3 rounded-full hover:bg-indigo-500 transition active:scale-95">
                Save Changes
              </button>
            </div>
          </form>
        </div>

        <!-- ── WordPress ─────────────────────────────────────────── -->
        <?php elseif ($section === 'wordpress'): ?>
        <div class="bg-white rounded-2xl border border-slate-100 p-7">
          <div class="flex items-start justify-between gap-4 mb-6">
            <div>
              <h2 class="text-base font-extrabold text-slate-900 mb-1">WordPress Connection</h2>
              <p class="text-sm text-slate-500">Connect your WordPress site so Auto-Seo can publish articles automatically.</p>
            </div>
            <?php if ($hasWP): ?>
              <span class="flex-shrink-0 flex items-center gap-1.5 bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-bold px-3 py-1.5 rounded-full">
                <span class="w-2 h-2 bg-emerald-500 rounded-full"></span> Connected
              </span>
            <?php else: ?>
              <span class="flex-shrink-0 flex items-center gap-1.5 bg-slate-100 text-slate-500 text-xs font-bold px-3 py-1.5 rounded-full">
                Not connected
              </span>
            <?php endif; ?>
          </div>

          <!-- How to get an Application Password -->
          <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-4 mb-6 text-sm text-indigo-800 space-y-1.5">
            <p class="font-bold text-indigo-900">How to generate a WordPress Application Password:</p>
            <ol class="list-decimal list-inside space-y-1 text-indigo-700">
              <li>Log in to your WordPress admin area</li>
              <li>Go to <strong>Users → Profile</strong></li>
              <li>Scroll down to <strong>Application Passwords</strong></li>
              <li>Enter a name (e.g. "Auto-Seo") and click <strong>Add New</strong></li>
              <li>Copy the generated password and paste it below</li>
            </ol>
            <p class="text-xs text-indigo-600 pt-1">Note: Application Passwords require WordPress 5.6+ and HTTPS on your site.</p>
          </div>

          <form method="POST" class="space-y-5" id="wp-form">
            <input type="hidden" name="section" value="wordpress"/>

            <div class="field">
              <label>WordPress Site URL</label>
              <input type="url" name="wp_url" id="wp_url" required
                value="<?= htmlspecialchars($client['wp_url'] ?? '') ?>"
                placeholder="https://yourwebsite.co.uk"/>
            </div>

            <div class="field">
              <label>WordPress Username</label>
              <input type="text" name="wp_username" id="wp_username" required autocomplete="off"
                value="<?= htmlspecialchars($client['wp_username'] ?? '') ?>"
                placeholder="your-wp-admin-username"/>
            </div>

            <div class="field">
              <label>Application Password</label>
              <input type="password" name="wp_app_password" id="wp_app_password" required autocomplete="off"
                value="<?= htmlspecialchars($client['wp_app_password'] ?? '') ?>"
                placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"/>
              <p class="text-xs text-slate-400 mt-1.5">This is the Application Password from your WordPress profile — not your login password.</p>
            </div>

            <div class="flex items-center gap-3 pt-2 flex-wrap">
              <button type="submit"
                class="bg-indigo-600 text-white font-bold text-sm px-6 py-3 rounded-full hover:bg-indigo-500 transition active:scale-95">
                Save &amp; Test Connection
              </button>
              <?php if ($hasWP): ?>
                <button type="button" onclick="disconnectWP()"
                  class="bg-white text-rose-500 border border-rose-200 font-bold text-sm px-5 py-3 rounded-full hover:bg-rose-50 transition">
                  Disconnect
                </button>
              <?php endif; ?>
            </div>
          </form>
        </div>

        <!-- ── Security ──────────────────────────────────────────── -->
        <?php elseif ($section === 'security'): ?>
        <div class="bg-white rounded-2xl border border-slate-100 p-7">
          <h2 class="text-base font-extrabold text-slate-900 mb-1">Change Password</h2>
          <p class="text-sm text-slate-500 mb-6">Choose a strong password of at least 8 characters.</p>

          <form method="POST" class="space-y-5">
            <input type="hidden" name="section" value="security"/>

            <div class="field">
              <label>Current Password</label>
              <input type="password" name="current_password" required autocomplete="current-password" placeholder="••••••••"/>
            </div>

            <div class="field">
              <label>New Password</label>
              <input type="password" name="new_password" required minlength="8" autocomplete="new-password"
                placeholder="Minimum 8 characters" id="new-pw"
                oninput="checkStrength(this.value)"/>
              <!-- Strength bar -->
              <div class="mt-2 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                <div id="strength-bar" class="h-full rounded-full transition-all duration-300 w-0"></div>
              </div>
              <p id="strength-label" class="text-xs text-slate-400 mt-1"></p>
            </div>

            <div class="field">
              <label>Confirm New Password</label>
              <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Repeat your new password"/>
            </div>

            <div class="pt-2">
              <button type="submit"
                class="bg-indigo-600 text-white font-bold text-sm px-6 py-3 rounded-full hover:bg-indigo-500 transition active:scale-95">
                Update Password
              </button>
            </div>
          </form>
        </div>
        <?php endif; ?>

        <?php if ($section === 'keywords'): ?>
        <?php
          $maxKps   = (int)($client['max_keyphrases'] ?? 1);
          $extraKps = json_decode($client['tracked_keyphrases'] ?? '[]', true) ?: [];
          $allKps   = array_filter(array_merge([$client['keyphrase'] ?? ''], $extraKps));
          $allKps   = array_values($allKps);
          $usedKps  = count($allKps);
          $atLimit  = ($usedKps >= $maxKps);
        ?>
        <div class="bg-white rounded-2xl border border-slate-100 p-7">
          <div class="flex items-start justify-between mb-5 flex-wrap gap-3">
            <div>
              <h2 class="text-base font-extrabold text-slate-900 mb-1">Tracked Keyphrases</h2>
              <p class="text-sm text-slate-500">The phrases we check on Google for you every week.</p>
            </div>
            <!-- Slot dots -->
            <div class="flex items-center gap-1.5 flex-shrink-0">
              <?php for ($i = 0; $i < $maxKps; $i++): ?>
                <div class="w-2.5 h-2.5 rounded-full <?= $i < $usedKps ? 'bg-indigo-500' : 'bg-slate-200' ?>"></div>
              <?php endfor; ?>
              <span class="text-xs font-semibold text-slate-400 ml-1"><?= $usedKps ?>/<?= $maxKps ?></span>
            </div>
          </div>

          <!-- Current keyphrases as editable rows -->
          <form method="POST" id="kpForm" class="space-y-3 mb-4">
            <input type="hidden" name="section" value="keywords">
            <div id="kpList" class="space-y-3">
              <?php foreach ($allKps as $i => $kp): ?>
              <div class="flex items-center gap-2 kp-row" data-index="<?= $i ?>">
                <div class="flex-1">
                  <?php if ($i === 0): ?>
                    <label class="block text-xs font-bold text-slate-400 mb-1">Primary keyphrase ★</label>
                  <?php endif; ?>
                  <input type="text" name="kp_<?= $i ?>"
                    value="<?= htmlspecialchars($kp) ?>"
                    placeholder="e.g. plumbers Manchester"
                    class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" />
                </div>
                <button type="button" onclick="removeKp(this, <?= $i ?>)"
                  class="mt-<?= $i === 0 ? '5' : '0' ?> text-slate-300 hover:text-rose-500 transition text-xl font-bold leading-none flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-lg hover:bg-rose-50"
                  title="Remove" <?= $usedKps <= 1 ? 'disabled style="opacity:.3;cursor:not-allowed"' : '' ?>>×</button>
              </div>
              <?php endforeach; ?>
            </div>

            <!-- Hidden inputs filled by JS when rows are removed -->
            <div id="kpHidden"></div>

            <div class="flex items-center gap-3 pt-1 flex-wrap">
              <button type="submit"
                class="bg-indigo-600 text-white font-bold text-sm px-6 py-2.5 rounded-full hover:bg-indigo-500 transition active:scale-95">
                Save
              </button>

              <!-- Add button — goes to pricing if at limit -->
              <?php if (!$atLimit): ?>
              <button type="button" onclick="addKpRow()"
                class="flex items-center gap-1.5 bg-slate-100 text-slate-700 font-bold text-sm px-5 py-2.5 rounded-full hover:bg-indigo-50 hover:text-indigo-700 transition">
                + Add keyphrase
              </button>
              <?php else: ?>
              <a href="/pricing.html"
                class="flex items-center gap-1.5 bg-indigo-600 text-white font-bold text-sm px-5 py-2.5 rounded-full hover:bg-indigo-500 transition">
                + Add keyphrase — upgrade plan →
              </a>
              <span class="text-xs text-slate-400">You're using all <?= $maxKps ?> slot<?= $maxKps > 1 ? 's' : '' ?> on your plan</span>
              <?php endif; ?>
            </div>
          </form>

          <!-- Recent ranking snapshot -->
          <?php
          $rankRows = [];
          try {
            $recentRanks = $db->prepare(
              'SELECT keyphrase, position, checked_at FROM rankings
               WHERE client_id = ? ORDER BY checked_at DESC LIMIT 10'
            );
            $recentRanks->execute([$client['id']]);
            $rankRows = $recentRanks->fetchAll();
          } catch (Exception $e) {}
          ?>
          <?php if ($rankRows): ?>
          <div class="mt-7 border-t border-slate-100 pt-6">
            <h3 class="text-xs font-bold uppercase text-slate-400 mb-3">Latest Ranking Checks</h3>
            <div class="space-y-2">
              <?php foreach ($rankRows as $r): ?>
              <div class="flex items-center justify-between bg-slate-50 rounded-lg px-4 py-2.5 text-sm">
                <span class="font-medium text-slate-700"><?= htmlspecialchars($r['keyphrase']) ?></span>
                <div class="flex items-center gap-3">
                  <span class="text-xs text-slate-400"><?= date('d M Y', strtotime($r['checked_at'])) ?></span>
                  <?php if ($r['position']): ?>
                    <span class="font-extrabold text-indigo-600">#<?= $r['position'] ?></span>
                  <?php else: ?>
                    <span class="text-xs text-slate-400">Not in top 10</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php else: ?>
          <div class="mt-7 bg-slate-50 rounded-xl p-5 border border-dashed border-slate-200 text-center">
            <p class="text-sm text-slate-500">Rankings are checked weekly — your first check will appear here soon.</p>
          </div>
          <?php endif; ?>
        </div>

        <script>
        const MAX_KPS  = <?= $maxKps ?>;
        let   rowCount = <?= $usedKps ?>;

        function addKpRow() {
          if (rowCount >= MAX_KPS) {
            window.location.href = '/pricing.html';
            return;
          }
          const list  = document.getElementById('kpList');
          const idx   = rowCount;
          const div   = document.createElement('div');
          div.className = 'flex items-center gap-2 kp-row';
          div.dataset.index = idx;
          div.innerHTML = `
            <div class="flex-1">
              <input type="text" name="kp_${idx}"
                placeholder="e.g. emergency plumber Leeds"
                class="w-full px-4 py-3 rounded-xl border border-slate-200 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300" />
            </div>
            <button type="button" onclick="removeKp(this, ${idx})"
              class="text-slate-300 hover:text-rose-500 transition text-xl font-bold leading-none flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-lg hover:bg-rose-50"
              title="Remove">×</button>`;
          list.appendChild(div);
          rowCount++;
          div.querySelector('input').focus();
          updateRemoveButtons();

          // If now at limit, swap Add button to upgrade link
          if (rowCount >= MAX_KPS) swapAddButton();
        }

        function removeKp(btn, idx) {
          const rows = document.querySelectorAll('.kp-row');
          if (rows.length <= 1) return;
          btn.closest('.kp-row').remove();
          rowCount--;
          // Re-index remaining inputs
          reindex();
          updateRemoveButtons();
          // If was at limit and now isn't, restore Add button
          if (rowCount < MAX_KPS) restoreAddButton();
        }

        function reindex() {
          document.querySelectorAll('.kp-row').forEach((row, i) => {
            row.dataset.index = i;
            const inp = row.querySelector('input[type="text"]');
            if (inp) inp.name = `kp_${i}`;
            const btn = row.querySelector('button');
            if (btn) btn.setAttribute('onclick', `removeKp(this, ${i})`);
          });
        }

        function updateRemoveButtons() {
          const rows = document.querySelectorAll('.kp-row');
          rows.forEach(row => {
            const btn = row.querySelector('button');
            if (btn) btn.disabled = rows.length <= 1;
            if (btn) btn.style.opacity = rows.length <= 1 ? '0.3' : '1';
          });
        }

        function swapAddButton() {
          const addBtn = document.getElementById('addKpBtn');
          if (!addBtn) return;
          const a = document.createElement('a');
          a.href = '/pricing.html';
          a.id   = 'addKpBtn';
          a.className = 'flex items-center gap-1.5 bg-indigo-600 text-white font-bold text-sm px-5 py-2.5 rounded-full hover:bg-indigo-500 transition';
          a.textContent = '+ Add keyphrase — upgrade plan →';
          addBtn.replaceWith(a);
        }

        function restoreAddButton() {
          const addLink = document.getElementById('addKpBtn');
          if (!addLink || addLink.tagName === 'BUTTON') return;
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.id   = 'addKpBtn';
          btn.onclick = addKpRow;
          btn.className = 'flex items-center gap-1.5 bg-slate-100 text-slate-700 font-bold text-sm px-5 py-2.5 rounded-full hover:bg-indigo-50 hover:text-indigo-700 transition';
          btn.textContent = '+ Add keyphrase';
          addLink.replaceWith(btn);
        }

        // Give the add button an id so JS can swap it
        document.addEventListener('DOMContentLoaded', () => {
          const btns = document.querySelectorAll('#kpForm button[type="button"]');
          btns.forEach(b => { if (b.textContent.trim().startsWith('+ Add')) b.id = 'addKpBtn'; });
        });
        </script>

        <?php endif; ?>

      </div>
    </div>

  </main>
</div>

<script>
// Password strength indicator
function checkStrength(val) {
  const bar   = document.getElementById('strength-bar');
  const label = document.getElementById('strength-label');
  let score = 0;
  if (val.length >= 8)  score++;
  if (val.length >= 12) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;

  const levels = [
    { w: '20%',  bg: 'bg-rose-400',   text: 'Very weak' },
    { w: '40%',  bg: 'bg-orange-400', text: 'Weak' },
    { w: '60%',  bg: 'bg-amber-400',  text: 'Fair' },
    { w: '80%',  bg: 'bg-indigo-400', text: 'Good' },
    { w: '100%', bg: 'bg-emerald-500',text: 'Strong' },
  ];
  const l = levels[Math.max(0, score - 1)] || levels[0];
  bar.style.width   = val.length ? l.w : '0';
  bar.className     = `h-full rounded-full transition-all duration-300 ${val.length ? l.bg : ''}`;
  label.textContent = val.length ? l.text : '';
}

// Disconnect WordPress
function disconnectWP() {
  if (!confirm('Are you sure you want to disconnect WordPress? Auto-publishing will stop.')) return;
  fetch('/article-action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'disconnect_wp' }),
  }).then(() => location.reload());
}
</script>

</body>
</html>
