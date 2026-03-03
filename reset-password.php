<?php
require_once __DIR__ . '/db.php';

// Load .env for mail envelope sender
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

$submitted = false;
$error     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $db   = getDB();
            $stmt = $db->prepare('SELECT id, first_name FROM clients WHERE email = ? AND status = "active"');
            $stmt->execute([$email]);
            $client = $stmt->fetch();

            // Always show the success screen — never reveal whether the email exists
            if ($client) {
                $token   = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $db->prepare('UPDATE clients SET login_token = ?, token_expires = ? WHERE id = ?')
                   ->execute([$token, $expires, $client['id']]);

                $name = $client['first_name'] ?: 'there';
                $link = 'https://auto-seo.co.uk/set-password.php?token=' . $token;

                $subject = 'Reset your Auto-Seo password';
                $body    = "Hi $name,\n\n"
                    . "We received a request to reset your password.\n\n"
                    . "Click the link below to choose a new password:\n\n"
                    . "$link\n\n"
                    . "This link expires in 1 hour. If you didn't request this, you can safely ignore this email — your password won't change.\n\n"
                    . "The Auto-Seo Team\n"
                    . "hello@auto-seo.co.uk";

                $headers  = "From: Auto-Seo <hello@auto-seo.co.uk>\r\n";
                $headers .= "Reply-To: hello@auto-seo.co.uk";

                mail($email, $subject, $body, $headers, '-f hello@auto-seo.co.uk');
            }

            $submitted = true;

        } catch (Exception $e) {
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Reset Password — Auto-Seo.co.uk</title>
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
        <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center text-white text-xl font-bold">⚡</div>
        <span class="text-2xl font-extrabold text-white">Auto-Seo<span class="text-white/60">.co.uk</span></span>
      </a>
    </div>

    <div class="bg-white rounded-2xl shadow-2xl p-8">

      <?php if ($submitted): ?>
        <!-- Success state -->
        <div class="text-center space-y-4">
          <div class="w-16 h-16 bg-emerald-100 rounded-full flex items-center justify-center mx-auto text-3xl">📧</div>
          <h1 class="text-xl font-extrabold text-slate-900">Check your inbox</h1>
          <p class="text-sm text-slate-500 leading-relaxed">
            If that email address is registered with Auto-Seo, you'll receive a password reset link shortly.<br/>
            <span class="text-xs text-slate-400 mt-1 block">The link expires in 1 hour.</span>
          </p>
          <a href="/login.php"
            class="btn-gradient inline-block w-full py-3.5 rounded-xl text-white font-bold text-sm text-center transition active:scale-95 shadow-lg shadow-indigo-200 mt-2">
            Back to Sign In
          </a>
          <p class="text-xs text-slate-400">Didn't receive it? Check your spam folder or
            <a href="/reset-password.php" class="text-indigo-600 hover:underline">try again</a>.
          </p>
        </div>

      <?php else: ?>
        <!-- Form state -->
        <h1 class="text-2xl font-extrabold text-slate-900 mb-2">Forgot your password?</h1>
        <p class="text-sm text-slate-500 mb-6">Enter your email address and we'll send you a reset link.</p>

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
          <button type="submit"
            class="btn-gradient w-full py-3.5 rounded-xl text-white font-bold text-sm transition active:scale-95 shadow-lg shadow-indigo-200">
            Send Reset Link →
          </button>
        </form>

        <p class="text-center text-xs text-slate-400 mt-6">
          Remembered it?
          <a href="/login.php" class="text-indigo-600 font-semibold hover:underline">Back to Sign In</a>
        </p>

      <?php endif; ?>

    </div>
  </div>

</body>
</html>
