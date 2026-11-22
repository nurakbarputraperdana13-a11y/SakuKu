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
$statusBadges = [
    'none'    => '',
    'safe'    => 'badge-success',
    'warning' => 'badge-warning',
    'over'    => 'badge-danger',
];

render_header('Budget', 'budgets');
?>
<div class="page-head">
  <div>
    <h1>Budget Bulanan</h1>
    <p>Tetapkan batas pengeluaran per kategori dan pantau pemakaiannya.</p>
  </div>
</div>

<div class="grid grid-stats">
  <div class="stat-card">
    <p class="stat-label">Total batas budget</p>
    <p class="stat-value"><?= e(format_rupiah($totalLimit)) ?></p>
    <div class="stat-meta"><?= icon('target') ?> <?= e($month) ?></div>
    <span class="stat-icon"><?= icon('target') ?></span>
  </div>
  <div class="stat-card stat-expense">
    <p class="stat-label">Total terpakai</p>
    <p class="stat-value"><?= e(format_rupiah($totalSpent)) ?></p>
    <div class="stat-meta"><?= icon('arrow-down') ?> dari kategori dengan budget</div>
    <span class="stat-icon"><?= icon('arrow-down') ?></span>
  </div>
  <div class="stat-card stat-saving">
    <p class="stat-label">Kategori perlu perhatian</p>
    <p class="stat-value"><?= $alertCount ?></p>
    <div class="stat-meta"><?= icon('alert') ?> warning atau overbudget</div>
    <span class="stat-icon"><?= icon('alert') ?></span>
  </div>
</div>

<form class="filter-bar" method="get" action="<?= e(url('pages/budgets.php')) ?>">
  <div class="field">
    <label for="budget-month">Periode bulan</label>
    <input id="budget-month" name="month" type="month" value="<?= e($month) ?>" required>
  </div>
  <button class="btn btn-sm btn-primary" type="submit"><?= icon('calendar') ?> Lihat bulan</button>
  <a class="btn btn-sm btn-ghost" href="<?= e(url('pages/budgets.php')) ?>">Bulan ini</a>
</form>

<form method="post" action="<?= e(url('process/budget_process.php')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="month" value="<?= e($month) ?>">

  <div class="card">
    <div class="card-header">
      <h2>Batas per kategori pengeluaran</h2>
      <span class="badge badge-info">Kosongkan kolom untuk menghapus batas</span>
    </div>
    <div class="card-body">
      <?php if ($rows === []): ?>
        <div class="empty-state">
          <?= icon('target') ?>
          <strong>Belum ada kategori pengeluaran</strong>
          <span>Tambah kategori kustom dari halaman Transaksi.</span>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Kategori</th>
                <th style="width: 200px;">Batas bulanan</th>
                <th>Pemakaian</th>
                <th class="num">Terpakai</th>
                <th class="num">Sisa / Kelebihan</th>
                <th>Status</th>
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
                  $barClass = $status['status'] === 'over' ? ' is-over' : ($status['status'] === 'warning' ? ' is-warning' : '');
                ?>
                <tr>
                  <td>
                    <span class="cell-main"><?= icon((string) $category['icon']) ?> <?= e($category['name']) ?></span>
                    <span class="cell-sub"><?= e((string) $category['type']) ?></span>
                  </td>
                  <td>
                    <div class="field">
                      <label class="sr-only" for="limit-<?= $categoryId ?>">Batas <?= e($category['name']) ?></label>
                      <input id="limit-<?= $categoryId ?>" name="limits[<?= $categoryId ?>]" type="text" inputmode="numeric"
                             placeholder="Rp0" value="<?= (int) $status['limit'] > 0 ? e(format_rupiah((int) $status['limit'])) : '' ?>">
                    </div>
                  </td>
                  <td>
                    <div class="progress-track" role="img" aria-label="Pemakaian <?= e($category['name']) ?> <?= $percent ?> persen">
                      <div class="progress-fill<?= $barClass ?>" style="width: <?= $barWidth ?>%"></div>
                    </div>
                    <div class="progress-meta">
                      <span><?= e(format_rupiah((int) $status['spent'])) ?></span>
                      <span><?= $percent ?>%</span>
                    </div>
                  </td>
                  <td class="num"><?= e(format_rupiah((int) $status['spent'])) ?></td>
                  <td class="num">
                    <?php if ($status['status'] === 'over'): ?>
                      <span class="badge badge-danger">Lebih <?= e(format_rupiah((int) $status['overage'])) ?></span>
                    <?php elseif ((int) $status['limit'] > 0): ?>
                      <span class="badge badge-success">Sisa <?= e(format_rupiah((int) $status['remaining'])) ?></span>
                    <?php else: ?>
                      <span class="cell-sub">-</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge <?= e($statusBadges[$status['status']] ?? '') ?>"><?= e($statusLabels[$status['status']] ?? '-') ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="form-actions">
    <button class="btn btn-primary" type="submit" <?= $rows === [] ? 'disabled' : '' ?>><?= icon('check') ?> Simpan budget</button>
  </div>
</form>
<?php
render_footer();
