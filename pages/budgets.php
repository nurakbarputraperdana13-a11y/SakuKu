<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);

$month = (string) ($_GET['month'] ?? current_month());
if (!preg_match('/^\d{4}-\d{2}$/', $month) || !checkdate((int) substr($month, 5, 2), 1, (int) substr($month, 0, 4))) {
    $month = current_month();
}

$expenseCategories = get_categories($pdo, $userId, 'expense');
$rows = [];
$totalLimit = 0;
$totalSpent = 0;
$alertCount = 0;

foreach ($expenseCategories as $category) {
    $status = budget_status($pdo, $userId, (int) $category['id'], $month);
    $rows[] = ['category' => $category, 'status' => $status];
    $totalLimit += (int) $status['limit'];
    $totalSpent += (int) $status['spent'];
    if (in_array($status['status'], ['warning', 'over'], true)) {
        $alertCount++;
    }
}

$statusLabels = [
    'none'    => 'Belum ada batas',
    'safe'    => 'Aman',
    'warning' => 'Peringatan',
    'over'    => 'Overbudget',
];
$statusPills = [
    'none'    => 'pill-neutral',
    'safe'    => 'pill-lime',
    'warning' => 'pill-warning',
    'over'    => 'pill-red',
];

$healthPercent = $totalLimit > 0 ? (int) round($totalSpent / $totalLimit * 100) : 0;

render_header('Budget', 'budgets');
?>
<section class="flex flex-col gap-space-md md:flex-row md:items-end md:justify-between">
  <div>
    <p class="font-label-lg text-label-lg uppercase tracking-wider text-primary">Manajemen Notifikasi Budget</p>
    <h1 class="mt-1 font-headline-xl text-headline-xl text-on-surface md:font-display-lg md:text-display-lg">Budget bulanan</h1>
    <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Tetapkan batas pengeluaran per kategori dan pantau pemakaiannya.</p>
  </div>
  <form class="flex items-center gap-space-xs rounded-full bg-surface-container-low p-space-xs shadow-sm" method="get" action="<?= e(url('pages/budgets.php')) ?>">
    <label class="sr-only" for="budget-month">Periode bulan</label>
    <input class="h-9 rounded-full border-0 bg-transparent px-space-md font-label-lg text-label-lg text-on-surface outline-none" id="budget-month" name="month" type="month" value="<?= e($month) ?>" required>
    <button class="rounded-full bg-primary-container px-space-md py-space-sm font-label-lg text-label-lg text-on-primary shadow-sm hover:bg-primary" type="submit">Lihat</button>
    <a class="rounded-full px-space-md py-space-sm font-label-lg text-label-lg text-on-surface-variant hover:text-on-surface" href="<?= e(url('pages/budgets.php')) ?>">Bulan ini</a>
  </form>
</section>

<section class="mt-space-lg grid grid-cols-1 gap-gutter md:gap-gutter-desktop lg:grid-cols-12">
  <div class="bento-dark flex flex-col justify-between gap-space-lg lg:col-span-5">
    <div>
      <span class="font-label-md text-label-md uppercase tracking-wider text-[#99f6e4]">Kesehatan anggaran</span>
      <div class="mt-2 flex items-baseline gap-2">
        <span class="font-metric-numeral-lg text-metric-numeral-lg tabular-nums text-white"><?= $healthPercent ?></span>
        <span class="font-headline-md text-headline-md text-[#99f6e4]">/ 100</span>
      </div>
      <p class="mt-1 font-body-md text-body-md text-[#99f6e4]">Realisasi terhadap total pagu bulan <?= e($month) ?>.</p>
    </div>
    <div class="rounded-2xl bg-white/10 p-space-md">
      <div class="flex items-center justify-between font-label-md text-label-md text-[#99f6e4]">
        <span>Sisa anggaran aman</span>
        <span class="material-symbols-outlined text-[18px] text-secondary-fixed" aria-hidden="true">verified_user</span>
      </div>
      <p class="mt-1 font-headline-md text-headline-md tabular-nums text-white"><?= e(format_rupiah(max(0, $totalLimit - $totalSpent))) ?></p>
    </div>
  </div>

  <div class="grid grid-cols-1 gap-gutter sm:grid-cols-3 md:gap-gutter-desktop lg:col-span-7">
    <article class="bento-card">
      <span class="font-label-lg text-label-lg text-on-surface-variant">Total batas budget</span>
      <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah($totalLimit)) ?></p>
      <span class="pill pill-neutral mt-space-md w-fit"><?= e($month) ?></span>
    </article>
    <article class="bento-card">
      <span class="font-label-lg text-label-lg text-on-surface-variant">Total terpakai</span>
      <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah($totalSpent)) ?></p>
      <span class="pill pill-lime mt-space-md w-fit">dari pagu kategori</span>
    </article>
    <article class="bento-card">
      <span class="font-label-lg text-label-lg text-on-surface-variant">Perlu perhatian</span>
      <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= $alertCount ?></p>
      <span class="pill pill-red mt-space-md w-fit">warning / overbudget</span>
    </article>
  </div>
</section>

<div class="mt-space-lg flex flex-wrap items-center gap-space-sm rounded-2xl border border-surface-container bg-white p-space-md">
  <span class="font-label-md text-label-md text-on-surface-variant">Ambang batas:</span>
  <span class="pill pill-lime">Aman &lt; 80%</span>
  <span class="pill pill-warning">Peringatan 80–99%</span>
  <span class="pill pill-red">Overbudget ≥ 100%</span>
</div>

<form class="mt-space-lg" method="post" action="<?= e(url('process/budget_process.php')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="month" value="<?= e($month) ?>">

  <section class="bento-card">
    <div class="flex flex-wrap items-center justify-between gap-space-sm">
      <div>
        <h2 class="font-headline-lg text-headline-lg text-on-surface">Pagu kategori pengeluaran</h2>
        <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Kosongkan kolom untuk menghapus batas.</p>
      </div>
      <span class="pill pill-neutral"><?= count($rows) ?> kategori terpantau</span>
    </div>
    <div class="mt-space-lg">
      <?php if ($rows === []): ?>
        <div class="flex flex-col items-center justify-center gap-2 rounded-2xl bg-surface-container-low px-4 py-12 text-center">
          <span class="material-symbols-outlined text-[28px] text-outline-variant" aria-hidden="true">target</span>
          <p class="font-headline-md text-headline-md text-on-surface">Belum ada kategori pengeluaran</p>
          <p class="max-w-sm font-body-md text-body-md text-on-surface-variant">Tambah kategori kustom dari halaman Transaksi.</p>
        </div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-left font-body-md text-body-md">
            <thead>
              <tr class="border-b border-surface-container text-on-surface-variant">
                <th class="py-2 font-label-md text-label-md">Kategori</th>
                <th class="py-2 font-label-md text-label-md">Batas bulanan</th>
                <th class="py-2 font-label-md text-label-md">Pemakaian</th>
                <th class="py-2 text-right font-label-md text-label-md">Terpakai</th>
                <th class="py-2 text-right font-label-md text-label-md">Sisa / Kelebihan</th>
                <th class="py-2 font-label-md text-label-md">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <?php
                  $category = $row['category'];
                  $status = $row['status'];
                  $categoryId = (int) $category['id'];
                  $percent = (int) $status['percent'];
                  $barWidth = min(100, max(0, $percent));
                  $barColor = $status['status'] === 'over' ? 'bg-red-500' : ($status['status'] === 'warning' ? 'bg-amber-500' : 'bg-primary-container');
                ?>
                <tr class="border-b border-surface-container/70 transition-colors hover:bg-surface-container-low">
                  <td class="py-3">
                    <span class="block font-semibold text-on-surface"><?= e($category['name']) ?></span>
                    <span class="font-label-sm text-label-sm text-on-surface-variant"><?= e((string) $category['type']) ?></span>
                  </td>
                  <td class="py-3">
                    <div class="field">
                      <label class="sr-only" for="limit-<?= $categoryId ?>">Batas <?= e($category['name']) ?></label>
                      <input id="limit-<?= $categoryId ?>" name="limits[<?= $categoryId ?>]" type="text" inputmode="numeric" placeholder="Rp0" value="<?= (int) $status['limit'] > 0 ? e(format_rupiah((int) $status['limit'])) : '' ?>">
                    </div>
                  </td>
                  <td class="py-3">
                    <div class="h-3 w-full min-w-[120px] overflow-hidden rounded-full bg-surface-container" role="img" aria-label="Pemakaian <?= e($category['name']) ?> <?= $percent ?> persen">
                      <div class="h-full rounded-full <?= $barColor ?>" style="width: <?= $barWidth ?>%"></div>
                    </div>
                    <div class="mt-1 flex justify-between font-label-sm text-label-sm text-on-surface-variant">
                      <span><?= e(format_rupiah((int) $status['spent'])) ?></span><span><?= $percent ?>%</span>
                    </div>
                  </td>
                  <td class="py-3 text-right tabular-nums text-on-surface"><?= e(format_rupiah((int) $status['spent'])) ?></td>
                  <td class="py-3 text-right">
                    <?php if ($status['status'] === 'over'): ?>
                      <span class="pill pill-red">Lebih <?= e(format_rupiah((int) $status['overage'])) ?></span>
                    <?php elseif ((int) $status['limit'] > 0): ?>
                      <span class="pill pill-lime">Sisa <?= e(format_rupiah((int) $status['remaining'])) ?></span>
                    <?php else: ?>
                      <span class="font-label-sm text-label-sm text-outline">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="py-3"><span class="pill <?= e($statusPills[$status['status']] ?? 'pill-neutral') ?>"><?= e($statusLabels[$status['status']] ?? '-') ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <div class="mt-space-lg">
    <button class="inline-flex h-12 items-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary shadow-md transition-colors hover:bg-primary disabled:cursor-not-allowed disabled:opacity-50" type="submit" <?= $rows === [] ? 'disabled' : '' ?>>
      <span class="material-symbols-outlined text-[18px]" aria-hidden="true">check</span>
      Simpan budget
    </button>
  </div>
</form>
<?php
render_footer();
