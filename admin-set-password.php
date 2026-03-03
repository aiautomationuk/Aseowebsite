<?php
// ONE-TIME ADMIN SCRIPT — delete after use

require_once __DIR__ . '/db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email && strlen($password) >= 8) {
        $db   = getDB();
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // Generate a fresh login token too
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));

        $stmt = $db->prepare('UPDATE clients SET password_hash = ?, login_token = ?, token_expires = ? WHERE email = ?');
        $stmt->execute([$hash, $token, $expires, $email]);

        if ($stmt->rowCount()) {
            $message = '✅ Password set for ' . htmlspecialchars($email) . '. <a href="/login.php" style="color:#6366f1;font-weight:bold;">Go to Login →</a>';
        } else {
            $message = '❌ Email not found in database: ' . htmlspecialchars($email);
        }
    } else {
        $message = '❌ Please enter a valid email and a password of at least 8 characters.';
    }
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8"/>
  <title>Admin — Set Password</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 flex items-center justify-center p-4">
  <div class="bg-white rounded-2xl shadow p-8 w-full max-w-md">
    <h1 class="text-xl font-bold text-slate-900 mb-1">Admin — Set Client Password</h1>
    <p class="text-sm text-slate-500 mb-6">One-time use. Delete this file after use.</p>

    <?php if ($message): ?>
      <div class="mb-4 p-4 bg-slate-50 border border-slate-200 rounded-xl text-sm"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">Client Email</label>
        <input type="email" name="email" required
          value="iadsmanchester@gmail.com"
          class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-indigo-400"/>
      </div>
      <div>
        <label class="block text-sm font-semibold text-slate-700 mb-1">New Password</label>
        <input type="password" name="password" required minlength="8"
          class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-indigo-400"
          placeholder="Minimum 8 characters"/>
      </div>
      <button type="submit"
        class="w-full bg-indigo-600 text-white font-bold py-3 rounded-xl hover:bg-indigo-500 transition">
        Set Password
      </button>
    </form>
  </div>
</body>
</html>
