<?php
// TEMPORARY ADMIN TOOL — delete after use
require_once __DIR__ . '/db.php';

$message = '';
$db = null;
$clients = [];
$dbOk = false;

try {
    $db = getDB();
    $dbOk = true;
    $clients = $db->query('SELECT id, email, first_name, last_name, password_hash, login_token, token_expires, status, created_at FROM clients ORDER BY id DESC')->fetchAll();
} catch (Exception $e) {
    $message = '❌ DB Error: ' . $e->getMessage();
}

// Action: send new magic link
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbOk) {
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'send_link' && $id) {
        $row = $db->prepare('SELECT * FROM clients WHERE id = ?');
        $row->execute([$id]);
        $client = $row->fetch();

        if ($client) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $db->prepare('UPDATE clients SET login_token = ?, token_expires = ? WHERE id = ?')
               ->execute([$token, $expires, $id]);

            $name    = $client['first_name'] ?: 'there';
            $link    = 'https://auto-seo.co.uk/set-password.php?token=' . $token;
            $subject = 'Your Auto-Seo Dashboard Access Link';
            $body    = "Hi $name,\n\nClick the link below to access your dashboard and set your password:\n\n$link\n\nThis link expires in 24 hours.\n\nThe Auto-Seo Team";
            $headers = "From: Auto-Seo <hello@auto-seo.co.uk>\r\nReply-To: hello@auto-seo.co.uk";

            $sent = mail($client['email'], $subject, $body, $headers, '-f hello@auto-seo.co.uk');
            $message = $sent
                ? '✅ Magic link sent to ' . $client['email'] . '<br><small style="color:#666">Link: <a href="' . $link . '">' . $link . '</a></small>'
                : '⚠️ mail() returned false. Use the link directly: <a href="' . $link . '">' . $link . '</a>';
        }
    }

    if ($action === 'set_password' && $id) {
        $password = $_POST['password'] ?? '';
        if (strlen($password) >= 8) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $db->prepare('UPDATE clients SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
            $message = '✅ Password updated for client #' . $id;
        } else {
            $message = '❌ Password must be at least 8 characters.';
        }
    }

    // Reload clients
    $clients = $db->query('SELECT id, email, first_name, last_name, password_hash, login_token, token_expires, status, created_at FROM clients ORDER BY id DESC')->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8"/>
  <title>Admin Tool</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 p-6 font-sans text-slate-900">

  <div class="max-w-5xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-extrabold">🛠 Auto-Seo Admin Tool</h1>
      <span class="text-xs text-rose-600 font-bold bg-rose-50 border border-rose-200 px-3 py-1 rounded-full">Delete this file after use</span>
    </div>

    <?php if ($message): ?>
      <div class="bg-white border border-slate-200 rounded-xl p-4 text-sm"><?= $message ?></div>
    <?php endif; ?>

    <?php if (!$dbOk): ?>
      <div class="bg-rose-50 border border-rose-200 rounded-xl p-4 text-rose-700 text-sm">
        Database connection failed. Check DB_PASS in your .env file.
      </div>
    <?php elseif (empty($clients)): ?>
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-amber-700 text-sm">
        ⚠️ No clients in the database yet. The DB is connected but signup.php hasn't saved anyone.<br/>
        <strong>Do a fresh sign-up from the homepage</strong> — with DB_PASS now set it will save correctly.
      </div>
    <?php else: ?>
      <p class="text-sm text-slate-500"><?= count($clients) ?> client(s) found in database.</p>

      <?php foreach ($clients as $c): ?>
        <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
          <div class="flex items-start justify-between gap-4">
            <div>
              <p class="font-bold text-slate-900"><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></p>
              <p class="text-sm text-indigo-600"><?= htmlspecialchars($c['email']) ?></p>
              <p class="text-xs text-slate-400 mt-0.5">Signed up: <?= $c['created_at'] ?></p>
            </div>
            <div class="text-right text-xs space-y-1">
              <div class="<?= $c['password_hash'] ? 'text-emerald-600' : 'text-rose-600' ?> font-bold">
                <?= $c['password_hash'] ? '✓ Password set' : '✗ No password yet' ?>
              </div>
              <div class="<?= $c['login_token'] ? 'text-amber-600' : 'text-slate-400' ?> font-semibold">
                <?= $c['login_token'] ? 'Token: expires ' . $c['token_expires'] : 'No active token' ?>
              </div>
            </div>
          </div>

          <div class="flex flex-wrap gap-3 pt-2 border-t border-slate-100">
            <!-- Send magic link -->
            <form method="POST" class="inline">
              <input type="hidden" name="action" value="send_link"/>
              <input type="hidden" name="id" value="<?= $c['id'] ?>"/>
              <button type="submit"
                class="bg-indigo-600 text-white text-xs font-bold px-4 py-2 rounded-full hover:bg-indigo-500 transition">
                📧 Send New Magic Link
              </button>
            </form>

            <!-- Set password directly -->
            <form method="POST" class="inline flex items-center gap-2">
              <input type="hidden" name="action" value="set_password"/>
              <input type="hidden" name="id" value="<?= $c['id'] ?>"/>
              <input type="password" name="password" placeholder="New password (8+ chars)" minlength="8" required
                class="border border-slate-200 rounded-full px-3 py-1.5 text-xs focus:outline-none focus:border-indigo-400"/>
              <button type="submit"
                class="bg-slate-800 text-white text-xs font-bold px-4 py-2 rounded-full hover:bg-slate-700 transition">
                🔑 Set Password
              </button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</body>
</html>
