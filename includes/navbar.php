<?php
declare(strict_types=1);

/**
 * Shared layout. Call render_header($title, $active, $withCharts) then render_footer().
 * $active values: dashboard, transactions, budgets, savings, groups, reports,
 * profile, admin_users.
 */
function nav_items(): array
{
    $items = [
        ['dashboard',   'Dashboard',   'pages/dashboard.php',   'dashboard'],
        ['transactions','Transaksi',   'pages/transactions.php','list'],
        ['budgets',     'Budget',      'pages/budgets.php',     'target'],
        ['savings',     'Tabungan',    'pages/savings.php',     'wallet'],
        ['groups',      'Patungan',    'pages/groups.php',      'users'],
        ['reports',     'Laporan',     'pages/reports.php',     'chart'],
        ['profile',     'Profil',      'pages/profile.php',     'user'],
    ];

    if (($_SESSION['role'] ?? '') === 'admin') {
        $items[] = ['admin_users', 'Kelola Pengguna', 'pages/admin_users.php', 'shield'];
    }

    return $items;
}

function render_header(string $title, string $active = '', bool $withCharts = false): void
{
    $user = $GLOBALS['currentUser'] ?? ['name' => 'Pengguna'];
    $messages = pull_flash();
    ?>
<!doctype html>
<html lang="id" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="SakuKu - manajemen keuangan mahasiswa">
  <title><?= e($title) ?> - SakuKu</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
  <?php if ($withCharts): ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
  <?php endif; ?>
  <script src="<?= e(url('assets/js/main.js')) ?>" defer></script>
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Navigasi utama">
      <a class="brand" href="<?= e(url('pages/dashboard.php')) ?>">
        <span class="brand-mark" aria-hidden="true"><?= icon('wallet') ?></span>
        <span class="brand-text">Saku<span>Ku</span></span>
      </a>
      <p class="sidebar-caption">MENU UTAMA</p>
      <nav class="nav-list">
        <?php foreach (nav_items() as [$key, $label, $path, $iconName]): ?>
          <a class="nav-link <?= $active === $key ? 'is-active' : '' ?>" href="<?= e(url($path)) ?>">
            <?= icon($iconName) ?><span><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
      <div class="sidebar-foot">
        <div class="sidebar-tip">
          <?= icon('shield') ?>
          <div><strong>Data privat</strong><br><span>Catat keuanganmu sendiri.</span></div>
        </div>
        <form class="logout-form" method="post" action="<?= e(url('process/auth_process.php')) ?>">
          <input type="hidden" name="action" value="logout">
          <?= csrf_field() ?>
          <button class="nav-link nav-logout" type="submit">
            <?= icon('logout') ?><span>Keluar</span>
          </button>
        </form>
      </div>
    </aside>

    <div class="main-column">
      <header class="topbar">
        <button class="icon-button menu-toggle" type="button" data-menu-toggle aria-label="Buka menu" aria-expanded="false">
          <?= icon('menu') ?>
        </button>
        <div class="topbar-title">
          <span class="topbar-eyebrow">SakuKu</span>
          <strong><?= e($title) ?></strong>
        </div>
        <div class="topbar-actions">
          <button class="icon-button" type="button" data-theme-toggle aria-label="Ganti tema terang atau gelap">
            <?= icon('sun', 'icon-sun') ?><?= icon('moon', 'icon-moon') ?>
          </button>
          <a class="user-chip" href="<?= e(url('pages/profile.php')) ?>">
            <span class="avatar" aria-hidden="true"><?= e(mb_substr((string) $user['name'], 0, 1)) ?></span>
            <span class="user-name"><?= e($user['name']) ?></span>
          </a>
        </div>
      </header>

      <main class="page-content" id="main-content">
        <?php if ($messages): ?>
          <div class="flash-stack" aria-live="polite">
            <?php foreach ($messages as $message): ?>
              <div class="flash flash-<?= e($message['type']) ?>">
                <?= icon($message['type'] === 'success' ? 'check' : (in_array($message['type'], ['error', 'warning'], true) ? 'alert' : 'info')) ?>
                <span><?= e($message['message']) ?></span>
                <button class="flash-close" type="button" data-flash-close aria-label="Tutup pesan"><?= icon('close') ?></button>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
    <?php
}

function render_footer(): void
{
    ?>
      </main>
    </div>
  </div>
  <div class="sidebar-backdrop" data-menu-close hidden></div>
  <script src="<?= e(url('assets/js/charts.js')) ?>" defer></script>
  <script src="<?= e(url('assets/js/split-bill.js')) ?>" defer></script>
  <script src="<?= e(url('assets/js/admin-users.js')) ?>" defer></script>
</body>
</html>
    <?php
}
