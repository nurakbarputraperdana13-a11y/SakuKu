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
        ['transactions','Transaksi',   'pages/transactions.php','receipt_long'],
        ['budgets',     'Budget',      'pages/budgets.php',     'target'],
        ['savings',     'Tabungan',    'pages/savings.php',     'savings'],
        ['groups',      'Split Bill',  'pages/groups.php',      'group'],
        ['reports',     'Laporan',     'pages/reports.php',     'monitoring'],
        ['profile',     'Profil',      'pages/profile.php',     'person'],
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
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="SakuKu - manajemen keuangan mahasiswa">
  <title><?= e($title) ?> - SakuKu</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="<?= e(url('assets/js/tailwind.config.js')) ?>"></script>
  <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
  <?php if ($withCharts): ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
  <?php endif; ?>
  <script src="<?= e(url('assets/js/main.js')) ?>" defer></script>
</head>
<body class="bg-surface font-body-md text-on-surface antialiased">
  <header class="fixed inset-x-0 top-0 z-50 border-b border-surface-container bg-surface/85 backdrop-blur-xl">
    <div class="mx-auto flex h-20 w-full max-w-[1440px] items-center justify-between gap-space-md px-margin md:px-margin-desktop">
      <div class="flex min-w-0 items-center gap-space-sm">
        <a href="<?= e(url('pages/dashboard.php')) ?>" class="flex shrink-0 items-center gap-space-sm rounded-full bg-surface-container-lowest py-space-xs pl-space-xs pr-space-md shadow-sm">
          <span class="grid h-9 w-9 place-items-center rounded-full bg-primary-container text-[18px] font-bold text-on-primary">S</span>
          <span class="flex flex-col text-left leading-none">
            <span class="font-label-sm text-label-sm text-on-surface-variant">Selamat datang kembali</span>
            <span class="font-label-lg text-label-lg font-bold text-on-surface">Halo, <?= e((string) ($user['name'] ?? 'Pengguna')) ?></span>
          </span>
        </a>
        <span class="hidden items-center gap-space-xs rounded-full bg-surface-container-low px-space-md py-space-xs text-on-surface-variant xl:inline-flex">
          <span class="material-symbols-outlined text-[16px] text-primary">account_balance_wallet</span>
          <span class="font-label-md text-label-md font-semibold text-on-surface">SakuKu</span>
        </span>
      </div>

      <nav class="hidden items-center rounded-full bg-surface-container-low p-space-xs shadow-sm lg:flex" aria-label="Navigasi utama">
        <?php foreach (nav_items() as [$key, $label, $path, $iconName]): ?>
          <a class="inline-flex items-center gap-1.5 rounded-full px-space-md py-space-sm font-label-lg text-label-lg transition-all <?= $active === $key ? 'bg-secondary-fixed text-on-secondary-fixed font-bold shadow-sm' : 'text-on-surface-variant hover:text-on-surface' ?>" href="<?= e(url($path)) ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>>
            <span class="material-symbols-outlined text-[18px]" aria-hidden="true"><?= e($iconName) ?></span>
            <span><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      </nav>

      <div class="flex shrink-0 items-center gap-space-sm">
        <form class="logout-form" method="post" action="<?= e(url('process/auth_process.php')) ?>">
          <input type="hidden" name="action" value="logout">
          <?= csrf_field() ?>
          <button class="inline-flex h-10 items-center gap-space-xs rounded-full bg-surface-container-lowest px-space-md font-label-lg text-label-lg text-on-surface-variant shadow-sm transition-colors hover:bg-surface-container-high hover:text-on-surface" type="submit">
            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">logout</span>
            <span class="hidden sm:inline">Keluar</span>
          </button>
        </form>
      </div>
    </div>

    <nav class="flex items-center gap-space-xs overflow-x-auto border-t border-surface-container bg-surface px-margin py-space-sm lg:hidden" aria-label="Navigasi utama seluler">
      <?php foreach (nav_items() as [$key, $label, $path, $iconName]): ?>
        <a class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-space-md py-space-sm font-label-md text-label-md transition-all <?= $active === $key ? 'bg-secondary-fixed text-on-secondary-fixed font-bold shadow-sm' : 'text-on-surface-variant hover:text-on-surface' ?>" href="<?= e(url($path)) ?>">
          <span class="material-symbols-outlined text-[16px]" aria-hidden="true"><?= e($iconName) ?></span>
          <span><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  </header>

  <main class="mx-auto w-full max-w-[1440px] px-margin pb-space-xl pt-28 md:px-margin-desktop lg:pt-32">
    <?php if ($messages): ?>
      <div class="flash-stack mb-space-lg" aria-live="polite">
        <?php foreach ($messages as $message): ?>
          <?php
            $flashTone = 'border-surface-container bg-white';
            if ($message['type'] === 'success') $flashTone = 'border-primary/20 bg-emerald-50';
            if (in_array($message['type'], ['error', 'warning'], true)) $flashTone = 'border-red-200 bg-red-50';
          ?>
          <div class="flash border <?= $flashTone ?>">
            <span class="material-symbols-outlined text-[20px] text-primary" aria-hidden="true"><?= $message['type'] === 'success' ? 'check_circle' : (in_array($message['type'], ['error', 'warning'], true) ? 'warning' : 'info') ?></span>
            <span class="flex-1 text-body-md text-body-md"><?= e($message['message']) ?></span>
            <button class="flash-close material-symbols-outlined text-[18px]" type="button" data-flash-close aria-label="Tutup pesan">close</button>
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
  <script src="<?= e(url('assets/js/charts.js')) ?>" defer></script>
  <script src="<?= e(url('assets/js/split-bill.js')) ?>" defer></script>
  <script src="<?= e(url('assets/js/admin-users.js')) ?>" defer></script>
</body>
</html>
    <?php
}
