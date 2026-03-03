<?php
require_once __DIR__ . '/auth.php';

startSecureSession();
if (!empty($_SESSION['client_id'])) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email && $password) {
        $db   = getDB();
        $stmt = $db->prepare('SELECT id, password_hash, first_name FROM clients WHERE email = ? AND status = "active"');
        $stmt->execute([$email]);
        $client = $stmt->fetch();

        if ($client && $client['password_hash'] && password_verify($password, $client['password_hash'])) {
            loginClient($client['id']);
            header('Location: /dashboard.php');
            exit;
        } else {
            $error = 'Incorrect email or password. Please try again.';
        }
    } else {
        $error = 'Please enter your email and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Login — Auto-Seo.co.uk</title>
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

    <!-- Logo -->
    <div class="text-center mb-8">
      <a href="/" class="inline-flex items-center gap-2">
        <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center text-white text-xl font-bold">⚡</div>
        <span class="text-2xl font-extrabold text-white">Auto-Seo<span class="text-white/60">.co.uk</span></span>
      </a>
      <p class="mt-2 text-white/70 text-sm">Client Dashboard</p>
    </div>

    <!-- Card -->
    <div class="bg-white rounded-2xl shadow-2xl p-8">
      <h1 class="text-2xl font-extrabold text-slate-900 mb-2">Welcome back</h1>
      <p class="text-sm text-slate-500 mb-6">Sign in to your Auto-Seo account</p>

      <?php if ($error): ?>
        <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium px-4 py-3 rounded-xl">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="space-y-4">
        <div>
          <label class="block text-sm font-semibold text-slate-700 mb-1.5">Email Address</label>
          <input type="email" name="email" required autofocus
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
            class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm text-slate-900 focus:outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
            placeholder="you@yourcompany.co.uk"/>
        </div>
        <div>
          <div class="flex items-center justify-between mb-1.5">
            <label class="block text-sm font-semibold text-slate-700">Password</label>
            <a href="/reset-password.php" class="text-xs text-indigo-600 hover:underline">Forgot password?</a>
          </div>
          <input type="password" name="password" required
            class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm text-slate-900 focus:outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"
            placeholder="••••••••"/>
        </div>
        <button type="submit"
          class="btn-gradient w-full py-3.5 rounded-xl text-white font-bold text-sm transition active:scale-95 shadow-lg shadow-indigo-200 mt-2">
          Sign In →
        </button>
      </form>

      <p class="text-center text-xs text-slate-400 mt-6">
        Don't have an account?
        <a href="/" class="text-indigo-600 font-semibold hover:underline">Scan your website to get started</a>
      </p>
    </div>

  </div>

</body>
</html>
