<?php
require_once __DIR__ . '/auth.php';
$client = requireLogin();
$db     = getDB();

// ── Filter ────────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';
$validFilters = ['all', 'draft', 'approved', 'published', 'failed'];
if (!in_array($filter, $validFilters)) $filter = 'all';

$where  = 'WHERE a.client_id = ?';
$params = [$client['id']];
if ($filter !== 'all') { $where .= ' AND a.status = ?'; $params[] = $filter; }

$articles = $db->prepare("SELECT * FROM articles a $where ORDER BY scheduled_date DESC, created_at DESC");
$articles->execute($params);
$articles = $articles->fetchAll();

// ── Counts per status ─────────────────────────────────────────────
$counts = ['all' => 0, 'draft' => 0, 'approved' => 0, 'published' => 0, 'failed' => 0];
$cntStmt = $db->prepare('SELECT status, COUNT(*) as n FROM articles WHERE client_id = ? GROUP BY status');
$cntStmt->execute([$client['id']]);
foreach ($cntStmt->fetchAll() as $row) {
    $counts[$row['status']] = (int)$row['n'];
    $counts['all'] += (int)$row['n'];
}

// ── Single article view ───────────────────────────────────────────
$viewArticle = null;
if (isset($_GET['id'])) {
    $vs = $db->prepare('SELECT * FROM articles WHERE id = ? AND client_id = ?');
    $vs->execute([(int)$_GET['id'], $client['id']]);
    $viewArticle = $vs->fetch();
}

$plan        = ucfirst($client['plan'] ?? 'trial');
$statusLabel = ['draft'=>'Awaiting Approval','approved'=>'Approved','published'=>'Published','failed'=>'Failed'];
$statusBadge = [
    'draft'     => 'bg-amber-50 text-amber-700 border border-amber-200',
    'approved'  => 'bg-indigo-50 text-indigo-700 border border-indigo-200',
    'published' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
    'failed'    => 'bg-rose-50 text-rose-600 border border-rose-200',
];
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Articles — Auto-Seo.co.uk</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
  <style>
    body { font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
    .sidebar-link { display:flex;align-items:center;gap:10px;padding:10px 16px;border-radius:10px;font-size:14px;font-weight:600;color:#64748b;transition:all .15s; }
    .sidebar-link:hover { background:#f1f5f9;color:#0f172a; }
    .sidebar-link.active { background:#eef2ff;color:#6366f1; }
    .badge { display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700; }

    /* Article read view */
    .article-content h2,.article-content h3 { font-weight:700;margin:1.2em 0 .5em; }
    .article-content h2 { font-size:1.2em;color:#1e293b; }
    .article-content h3 { font-size:1.05em;color:#334155; }
    .article-content p  { margin:0 0 1em;line-height:1.8;color:#475569;font-size:.95em; }
    .article-content ul,.article-content ol { margin:0 0 1em 1.5em;color:#475569;line-height:1.8;font-size:.95em; }
    .article-content strong { color:#1e293b; }
    .article-content a  { color:#6366f1;text-decoration:underline; }
    .article-content figure { margin:1.5em 0; }
    .article-content img { max-width:100%;border-radius:8px; }
    .article-content figcaption { font-size:.8em;color:#94a3b8;text-align:center;margin-top:.4em; }

    /* Quill editor */
    #quill-editor { min-height: 480px; font-size: 14px; line-height: 1.8; }
    .ql-container { border-radius: 0 0 12px 12px !important; border-color: #e2e8f0 !important; }
    .ql-toolbar { border-radius: 12px 12px 0 0 !important; border-color: #e2e8f0 !important; background: #f8fafc; }
    .ql-editor { min-height: 480px; }
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
      <a href="/dashboard-articles.php"                   class="sidebar-link active">📄 &nbsp;Articles</a>
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

    <?php if ($viewArticle): ?>
    <!-- ══════════════════════════════════════════════════════════ -->
    <!-- Single Article View + Edit                                 -->
    <!-- ══════════════════════════════════════════════════════════ -->
    <div class="max-w-4xl" id="article-page">

      <div class="flex items-center justify-between mb-6">
        <a href="/dashboard-articles.php" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-slate-800 transition">
          ← Back to articles
        </a>
        <!-- Edit / View toggle (only for non-published) -->
        <?php if ($viewArticle['status'] !== 'published'): ?>
        <div class="flex items-center gap-2">
          <button id="btn-view" onclick="setMode('view')"
            class="text-xs font-bold px-4 py-2 rounded-full border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 transition">
            👁 Read
          </button>
          <button id="btn-edit" onclick="setMode('edit')"
            class="text-xs font-bold px-4 py-2 rounded-full border border-indigo-200 bg-indigo-50 text-indigo-600 hover:bg-indigo-100 transition">
            ✏️ Edit
          </button>
        </div>
        <?php endif; ?>
      </div>

      <!-- ── Status banner ──────────────────────────────────────── -->
      <?php if ($viewArticle['status'] === 'draft'): ?>
      <div class="mb-5 bg-amber-50 border border-amber-200 rounded-2xl p-5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
          <p class="font-extrabold text-amber-800 text-sm">⏳ Awaiting your approval</p>
          <p class="text-xs text-amber-700 mt-0.5">Read or edit the article, then approve it to schedule for publishing — or reject to discard.</p>
        </div>
        <div class="flex gap-2 flex-shrink-0">
          <button onclick="saveAndApprove()"
            class="bg-emerald-600 text-white font-bold text-sm px-5 py-2.5 rounded-full hover:bg-emerald-500 transition active:scale-95">
            ✓ Approve
          </button>
          <button onclick="articleAction(<?= $viewArticle['id'] ?>, 'reject')"
            class="bg-white text-rose-600 border border-rose-200 font-bold text-sm px-5 py-2.5 rounded-full hover:bg-rose-50 transition active:scale-95">
            ✗ Reject
          </button>
        </div>
      </div>

      <?php elseif ($viewArticle['status'] === 'approved'): ?>
      <div class="mb-5 bg-indigo-50 border border-indigo-200 rounded-2xl p-5 flex items-center justify-between gap-4">
        <div>
          <p class="font-extrabold text-indigo-800 text-sm">✓ Approved — queued for publishing</p>
          <p class="text-xs text-indigo-600 mt-0.5">Will publish automatically on its scheduled date. You can still edit or undo the approval.</p>
        </div>
        <div class="flex gap-2">
          <button onclick="saveChanges()"
            class="bg-indigo-600 text-white font-bold text-xs px-4 py-2 rounded-full hover:bg-indigo-500 transition" id="save-btn" style="display:none">
            💾 Save
          </button>
          <button onclick="articleAction(<?= $viewArticle['id'] ?>, 'reject')"
            class="bg-white text-slate-600 border border-slate-200 font-semibold text-xs px-4 py-2 rounded-full hover:bg-slate-50 transition">
            Undo approval
          </button>
        </div>
      </div>

      <?php elseif ($viewArticle['status'] === 'published'): ?>
      <div class="mb-5 bg-emerald-50 border border-emerald-200 rounded-2xl p-5 flex items-center justify-between gap-4">
        <div>
          <p class="font-extrabold text-emerald-800 text-sm">✓ Published live</p>
          <?php if ($viewArticle['published_at']): ?>
            <p class="text-xs text-emerald-700 mt-0.5">Published <?= date('d M Y', strtotime($viewArticle['published_at'])) ?></p>
          <?php endif; ?>
        </div>
        <?php if ($viewArticle['wp_post_url']): ?>
          <a href="<?= htmlspecialchars($viewArticle['wp_post_url']) ?>" target="_blank"
            class="bg-emerald-600 text-white font-bold text-sm px-5 py-2.5 rounded-full hover:bg-emerald-500 transition">
            View on site →
          </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- ── Article card ───────────────────────────────────────── -->
      <div class="bg-white rounded-2xl border border-slate-100 p-8">

        <!-- Header meta -->
        <div class="flex items-start justify-between gap-4 mb-4">
          <span class="badge flex-shrink-0 <?= $statusBadge[$viewArticle['status']] ?? '' ?>">
            <?= $statusLabel[$viewArticle['status']] ?? $viewArticle['status'] ?>
          </span>
          <div class="flex items-center gap-3 text-xs text-slate-400 flex-shrink-0 flex-wrap justify-end">
            <?php if ($viewArticle['scheduled_date']): ?>
              <span>📅 <?= date('d F Y', strtotime($viewArticle['scheduled_date'])) ?></span>
            <?php endif; ?>
            <?php if ($viewArticle['keyphrase']): ?>
              <span class="bg-indigo-50 text-indigo-600 font-semibold px-2 py-0.5 rounded-full border border-indigo-100">
                <?= htmlspecialchars($viewArticle['keyphrase']) ?>
              </span>
            <?php endif; ?>
          </div>
        </div>

        <!-- ── READ MODE ─────────────────────────────────────────── -->
        <div id="view-mode">
          <h1 id="view-title" class="text-2xl font-extrabold text-slate-900 leading-snug mb-6">
            <?= htmlspecialchars($viewArticle['title'] ?: 'Untitled Article') ?>
          </h1>

          <?php if ($viewArticle['meta_desc']): ?>
          <div class="bg-slate-50 border border-slate-100 rounded-xl p-4 mb-6">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Meta Description</p>
            <p id="view-meta" class="text-sm text-slate-600"><?= htmlspecialchars($viewArticle['meta_desc']) ?></p>
          </div>
          <?php endif; ?>

          <?php if ($viewArticle['image_url']): ?>
          <figure class="mb-6">
            <img src="<?= htmlspecialchars($viewArticle['image_url']) ?>"
              alt="<?= htmlspecialchars($viewArticle['title']) ?>"
              class="w-full rounded-xl shadow-sm"/>
          </figure>
          <?php endif; ?>

          <div class="border-t border-slate-100 pt-6 article-content" id="view-content">
            <?= $viewArticle['content'] ?: '<p class="text-slate-400 italic">No content.</p>' ?>
          </div>
        </div>

        <!-- ── EDIT MODE ─────────────────────────────────────────── -->
        <div id="edit-mode" style="display:none">
          <div class="space-y-5">

            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Title</label>
              <input type="text" id="edit-title"
                value="<?= htmlspecialchars($viewArticle['title'] ?: '') ?>"
                class="w-full border border-slate-200 rounded-xl px-4 py-3 text-lg font-bold text-slate-900 focus:outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"/>
            </div>

            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">
                Meta Description <span class="text-slate-400 font-normal normal-case" id="meta-counter">(<?= strlen($viewArticle['meta_desc'] ?? '') ?>/155)</span>
              </label>
              <textarea id="edit-meta" maxlength="155" rows="2"
                oninput="document.getElementById('meta-counter').textContent='('+this.value.length+'/155)'"
                class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-700 resize-none focus:outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"><?= htmlspecialchars($viewArticle['meta_desc'] ?? '') ?></textarea>
            </div>

            <div>
              <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Article Content</label>
              <div id="quill-editor"></div>
            </div>

            <!-- Edit mode action bar -->
            <div class="flex items-center gap-3 pt-4 border-t border-slate-100 flex-wrap">
              <button onclick="saveChanges()"
                class="bg-indigo-600 text-white font-bold text-sm px-6 py-2.5 rounded-full hover:bg-indigo-500 transition active:scale-95">
                💾 Save Changes
              </button>
              <?php if ($viewArticle['status'] === 'draft'): ?>
              <button onclick="saveAndApprove()"
                class="bg-emerald-600 text-white font-bold text-sm px-6 py-2.5 rounded-full hover:bg-emerald-500 transition active:scale-95">
                ✓ Save & Approve
              </button>
              <button onclick="articleAction(<?= $viewArticle['id'] ?>, 'reject')"
                class="bg-white text-rose-600 border border-rose-200 font-bold text-sm px-5 py-2.5 rounded-full hover:bg-rose-50 transition">
                ✗ Reject
              </button>
              <?php endif; ?>
              <button onclick="setMode('view')"
                class="text-sm text-slate-400 hover:text-slate-600 font-semibold transition ml-auto">
                Cancel
              </button>
            </div>

          </div>
        </div>

      </div>
    </div>

    <?php else: ?>
    <!-- ══════════════════════════════════════════════════════════ -->
    <!-- Article list view                                          -->
    <!-- ══════════════════════════════════════════════════════════ -->

    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-2xl font-extrabold text-slate-900">Articles</h1>
        <p class="text-sm text-slate-500 mt-0.5">Review, edit, approve and track your SEO content</p>
      </div>
    </div>

    <!-- Filter tabs -->
    <div class="flex gap-1 bg-white border border-slate-100 rounded-xl p-1 mb-6 w-fit flex-wrap">
      <?php
      $tabs = [
          'all'       => 'All',
          'draft'     => 'Needs Approval',
          'approved'  => 'Approved',
          'published' => 'Published',
          'failed'    => 'Failed',
      ];
      foreach ($tabs as $key => $label):
          $active = ($filter === $key);
          $cnt    = $counts[$key] ?? 0;
          if ($key !== 'all' && $cnt === 0) continue;
      ?>
        <a href="?filter=<?= $key ?>"
          class="px-4 py-2 rounded-lg text-sm font-semibold transition whitespace-nowrap
            <?= $active ? 'bg-indigo-600 text-white shadow' : 'text-slate-500 hover:text-slate-800 hover:bg-slate-50' ?>">
          <?= $label ?>
          <?php if ($cnt > 0): ?>
            <span class="ml-1 <?= $active ? 'bg-white/20 text-white' : 'bg-slate-100 text-slate-500' ?> text-xs font-bold px-1.5 py-0.5 rounded-full"><?= $cnt ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($articles)): ?>
      <div class="bg-white rounded-2xl border border-slate-100 p-12 text-center">
        <p class="text-4xl mb-3">📄</p>
        <p class="text-sm font-bold text-slate-700 mb-1">No articles <?= $filter !== 'all' ? 'with this status' : 'yet' ?></p>
        <p class="text-xs text-slate-400">Articles generated for your account will appear here.</p>
      </div>
    <?php else: ?>

      <?php if ($counts['draft'] > 0 && $filter !== 'draft'): ?>
      <div class="mb-4 bg-amber-50 border border-amber-200 rounded-xl px-5 py-3.5 flex items-center justify-between gap-4">
        <div class="flex items-center gap-2">
          <span class="text-amber-500 text-lg">⚠️</span>
          <p class="text-sm font-semibold text-amber-800"><?= $counts['draft'] ?> article<?= $counts['draft'] !== 1 ? 's' : '' ?> waiting for your approval</p>
        </div>
        <a href="?filter=draft" class="text-xs font-bold text-amber-700 hover:underline whitespace-nowrap">Review now →</a>
      </div>
      <?php endif; ?>

      <div class="space-y-3">
        <?php foreach ($articles as $art): ?>
          <?php $sb = $statusBadge[$art['status']] ?? 'bg-slate-100 text-slate-600'; ?>
          <div class="bg-white rounded-2xl border border-slate-100 p-5 flex flex-col sm:flex-row items-start sm:items-center gap-4 hover:border-slate-200 transition">

            <!-- Image thumbnail -->
            <?php if ($art['image_url']): ?>
            <div class="flex-shrink-0 hidden sm:block">
              <img src="<?= htmlspecialchars($art['image_url']) ?>" alt=""
                class="w-16 h-16 object-cover rounded-xl border border-slate-100"/>
            </div>
            <?php endif; ?>

            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2 flex-wrap mb-1">
                <a href="?id=<?= $art['id'] ?>" class="text-sm font-extrabold text-slate-900 hover:text-indigo-600 transition truncate">
                  <?= htmlspecialchars($art['title'] ?: 'Untitled Article') ?>
                </a>
                <span class="badge <?= $sb ?>"><?= $statusLabel[$art['status']] ?? $art['status'] ?></span>
              </div>
              <div class="flex items-center gap-3 text-xs text-slate-400 flex-wrap">
                <?php if ($art['keyphrase']): ?>
                  <span class="bg-indigo-50 text-indigo-600 font-semibold px-2 py-0.5 rounded-full border border-indigo-100">
                    <?= htmlspecialchars($art['keyphrase']) ?>
                  </span>
                <?php endif; ?>
                <?php if ($art['scheduled_date']): ?>
                  <span>📅 <?= date('d M Y', strtotime($art['scheduled_date'])) ?></span>
                <?php endif; ?>
                <span>Created <?= date('d M Y', strtotime($art['created_at'])) ?></span>
              </div>
            </div>

            <div class="flex items-center gap-2 flex-shrink-0 flex-wrap">
              <?php if ($art['status'] === 'draft'): ?>
                <a href="?id=<?= $art['id'] ?>"
                  class="bg-indigo-600 text-white font-bold text-xs px-4 py-2 rounded-full hover:bg-indigo-500 transition">
                  Review →
                </a>
              <?php elseif ($art['status'] === 'approved'): ?>
                <button onclick="articleAction(<?= $art['id'] ?>, 'reject')"
                  class="bg-white text-slate-500 border border-slate-200 font-semibold text-xs px-4 py-2 rounded-full hover:bg-slate-50 transition">
                  Undo
                </button>
              <?php elseif ($art['status'] === 'published' && $art['wp_post_url']): ?>
                <a href="<?= htmlspecialchars($art['wp_post_url']) ?>" target="_blank"
                  class="bg-emerald-50 text-emerald-700 border border-emerald-200 font-bold text-xs px-4 py-2 rounded-full hover:bg-emerald-100 transition">
                  View live →
                </a>
              <?php endif; ?>
              <a href="?id=<?= $art['id'] ?>"
                class="bg-slate-50 text-slate-600 border border-slate-200 font-semibold text-xs px-4 py-2 rounded-full hover:bg-slate-100 transition">
                <?= $art['status'] === 'draft' ? '✏️ Edit' : 'Read' ?> →
              </a>
            </div>

          </div>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>
    <?php endif; ?>

  </main>
</div>

<!-- Toast -->
<div id="toast" class="fixed bottom-6 right-6 z-50 hidden">
  <div id="toast-msg" class="bg-slate-900 text-white text-sm font-semibold px-5 py-3 rounded-full shadow-xl"></div>
</div>

<?php if ($viewArticle): ?>
<script>
// ── Article data ──────────────────────────────────────────────────
const ARTICLE_ID  = <?= $viewArticle['id'] ?>;
const ARTICLE_CONTENT = <?= json_encode($viewArticle['content'] ?? '') ?>;

// ── Quill editor ──────────────────────────────────────────────────
const quill = new Quill('#quill-editor', {
  theme: 'snow',
  modules: {
    toolbar: [
      [{ 'header': [1, 2, 3, false] }],
      ['bold', 'italic', 'underline'],
      [{ 'list': 'ordered'}, { 'list': 'bullet' }],
      ['link'],
      ['clean'],
    ]
  }
});

// Populate Quill with existing HTML content
quill.root.innerHTML = ARTICLE_CONTENT;

// ── Mode switching ────────────────────────────────────────────────
function setMode(mode) {
  const viewEl = document.getElementById('view-mode');
  const editEl = document.getElementById('edit-mode');
  const btnView = document.getElementById('btn-view');
  const btnEdit = document.getElementById('btn-edit');

  if (mode === 'edit') {
    viewEl.style.display = 'none';
    editEl.style.display = 'block';
    if (btnEdit) {
      btnEdit.className = btnEdit.className.replace('bg-indigo-50 text-indigo-600 border-indigo-200', 'bg-indigo-600 text-white border-indigo-600');
      btnView.className = btnView.className.replace('bg-indigo-600 text-white', 'bg-white text-slate-600');
    }
  } else {
    // Sync edits back to read view
    document.getElementById('view-title').textContent = document.getElementById('edit-title').value;
    const metaEl = document.getElementById('view-meta');
    if (metaEl) metaEl.textContent = document.getElementById('edit-meta').value;
    document.getElementById('view-content').innerHTML = quill.root.innerHTML;

    viewEl.style.display = 'block';
    editEl.style.display = 'none';
    if (btnView) {
      btnView.className = btnView.className.replace('bg-white text-slate-600', 'bg-indigo-600 text-white');
      btnEdit.className = btnEdit.className.replace('bg-indigo-600 text-white border-indigo-600', 'bg-indigo-50 text-indigo-600 border-indigo-200');
    }
  }
}

// ── Save changes ──────────────────────────────────────────────────
async function saveChanges(thenApprove = false) {
  const title   = document.getElementById('edit-title').value.trim();
  const meta    = document.getElementById('edit-meta').value.trim();
  const content = quill.root.innerHTML;

  if (!title) { showToast('Please enter a title.'); return; }

  showToast('Saving…');

  try {
    const res  = await fetch('/article-action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: ARTICLE_ID, action: 'save', title, meta_desc: meta, content }),
    });
    const data = await res.json();

    if (data.success) {
      showToast('✓ Changes saved');
      if (thenApprove) {
        setTimeout(() => articleAction(ARTICLE_ID, 'approve'), 600);
      } else {
        setTimeout(() => setMode('view'), 600);
      }
    } else {
      showToast('Error: ' + (data.error || 'Save failed'));
    }
  } catch { showToast('Network error. Please try again.'); }
}

function saveAndApprove() {
  // If in edit mode, save then approve; if in view mode just approve
  const editVisible = document.getElementById('edit-mode').style.display !== 'none';
  if (editVisible) {
    saveChanges(true);
  } else {
    articleAction(ARTICLE_ID, 'approve');
  }
}

// ── Approve / reject ──────────────────────────────────────────────
async function articleAction(id, action) {
  const labels = { approve: 'Approving…', reject: 'Rejecting…' };
  showToast(labels[action] || 'Updating…');

  try {
    const res  = await fetch('/article-action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id, action }),
    });
    const data = await res.json();
    if (data.success) {
      showToast(data.message || 'Done!');
      setTimeout(() => location.href = '/dashboard-articles.php', 900);
    } else {
      showToast('Error: ' + (data.error || 'Something went wrong'));
    }
  } catch { showToast('Network error. Please try again.'); }
}

// ── Toast ─────────────────────────────────────────────────────────
function showToast(msg) {
  const t = document.getElementById('toast');
  document.getElementById('toast-msg').textContent = msg;
  t.classList.remove('hidden');
  clearTimeout(window._toastTimer);
  window._toastTimer = setTimeout(() => t.classList.add('hidden'), 3000);
}
</script>
<?php else: ?>
<script>
// List view approve/reject
async function articleAction(id, action) {
  const res  = await fetch('/article-action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id, action }),
  });
  const data = await res.json();
  if (data.success) location.reload();
}
</script>
<?php endif; ?>

</body>
</html>
