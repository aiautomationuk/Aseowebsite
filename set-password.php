<?php
require_once __DIR__ . '/auth.php';

startSecureSession();
$error   = '';
$success = false;
$client  = null;

// Accept token from URL or existing session
$token = trim($_GET['token'] ?? '');

if ($token) {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM clients WHERE login_token = ? AND token_expires > NOW()');
    $stmt->execute([$token]);
    $client = $stmt->fetch();
    if (!$client) {
        $error = 'This link has expired or is invalid. Please contact support.';
    }
} elseif (!empty($_SESSION['client_id'])) {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$_SESSION['client_id']]);
    $client = $stmt->fetch();
} else {
    header('Location: /login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $client) {
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
    } else {
        $db   = getDB();
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->prepare('UPDATE clients SET password_hash = ?, login_token = NULL, token_expires = NULL WHERE id = ?')
           ->execute([$hash, $client['id']]);

        loginClient($client['id']);
        header('Location: /dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Set Your Password — Auto-Seo.co.uk</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
    .btn-gradient { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); }
    .btn-gradient:hover { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); }
  </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-indigo-600 via-purple-600 to-purple-800 flex items-center justify-center px-4">

  <div class="w-full max-w-md">
    <div class="text-center mb-8">
      <a href="/" class="inline-flex items-center gap-2">
        <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center text-white text-xl">⚡</div>
        <span class="text-2xl font-extrabold text-white">Auto-Seo<span class="text-white/60">.co.uk</span></span>
      </a>
    </div>

    <div class="bg-white rounded-2xl shadow-2xl p-8">
      <?php if ($error && !$client): ?>
        <div class="text-center space-y-4">
          <div class="w-16 h-16 bg-rose-100 rounded-full flex items-center justify-center mx-auto text-3xl">⚠️</div>
          <h1 class="text-xl font-extrabold text-slate-900">Link Expired</h1>
          <p class="text-sm text-slate-500"><?= htmlspecialchars($error) ?></p>
          <a href="/login.php" class="btn-gradient inline-block px-6 py-3 rounded-xl text-white font-bold text-sm">Go to Login</a>
        </div>
      <?php else: ?>
        <h1 class="text-2xl font-extrabold text-slate-900 mb-2">
          <?= $client && !$client['password_hash'] ? 'Set your password' : 'Change your password' ?>
        </h1>
        <p class="text-sm text-slate-500 mb-6">
          <?php if ($client): ?>
            Welcome, <strong><?= htmlspecialchars($client['first_name'] ?: $client['email']) ?></strong>! Choose a password for your account.
          <?php endif; ?>
        </p>

        <?php if ($error): ?>
          <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium px-4 py-3 rounded-xl">
            <?= htmlspecialchars($error) ?>
          </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
          <?php if ($token): ?>
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>"/>
          <?php endif; ?>
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1.5">New Password</label>
            <input type="password" name="password" required minlength="8" autofocus
              class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
              placeholder="Minimum 8 characters"/>
          </div>
          <div>
            <label class="block text-sm font-semibold text-slate-700 mb-1.5">Confirm Password</label>
            <input type="password" name="password2" required minlength="8"
              class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
              placeholder="Repeat your password"/>
          </div>
          <button type="submit"
            class="btn-gradient w-full py-3.5 rounded-xl text-white font-bold text-sm transition active:scale-95 shadow-lg shadow-indigo-200">
            Set Password &amp; Go to Dashboard →
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

</body>
</html>
