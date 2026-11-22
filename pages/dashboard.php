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

$balance = available_balance($pdo, $userId);
$totals = month_totals($pdo, $userId, $month);

$savingsStmt = $pdo->prepare(
    "SELECT COUNT(*) AS goal_count, COALESCE(SUM(current_amount), 0) AS locked
     FROM savings_goals WHERE user_id = :user_id AND status = 'active'"
);
$savingsStmt->execute([':user_id' => $userId]);
$savingsSummary = $savingsStmt->fetch() ?: ['goal_count' => 0, 'locked' => 0];

$activeGoalsStmt = $pdo->prepare(
    'SELECT id, title, current_amount, target_amount, deadline
     FROM savings_goals WHERE user_id = :user_id AND status = :status
     ORDER BY created_at DESC, id DESC LIMIT 4'
);
$activeGoalsStmt->execute([':user_id' => $userId, ':status' => 'active']);
$activeGoals = $activeGoalsStmt->fetchAll();

$recentStmt = $pdo->prepare(
    'SELECT t.id, t.type, t.amount, t.transaction_date, t.note, c.name AS category_name
     FROM transactions t JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = :user_id ORDER BY t.transaction_date DESC, t.id DESC LIMIT 5'
);
$recentStmt->execute([':user_id' => $userId]);
$recentTransactions = $recentStmt->fetchAll();

$categories = get_categories($pdo, $userId);

// PRD Alur A: read the one-shot banner after an expense, then clear it.
$sessionAlert = $_SESSION['budget_alert'] ?? null;
unset($_SESSION['budget_alert']);

$alerts = budget_alerts($pdo, $userId, $month);
if (is_array($sessionAlert) && isset($sessionAlert['category'], $sessionAlert['status'])) {
    $duplicate = false;
    foreach ($alerts as $alert) {
        if ((string) $alert['category'] === (string) $sessionAlert['category']
            && (string) $alert['status'] === (string) $sessionAlert['status']
            && (int) $alert['percent'] === (int) ($sessionAlert['percent'] ?? 0)) {
            $duplicate = true;
            break;
        }
    }
    if (!$duplicate) {
        $alerts[] = [
            'category'  => (string) $sessionAlert['category'],
            'status'    => (string) $sessionAlert['status'],
            'percent'   => (int) ($sessionAlert['percent'] ?? 0),
            'overage'   => (int) ($sessionAlert['overage'] ?? 0),
            'remaining' => (int) ($sessionAlert['remaining'] ?? 0),
            'message'   => (string) ($sessionAlert['message'] ?? ''),
        ];
    }
}

render_header('Dashboard', 'dashboard', true);
?>
<div class="page-head">
  <div>
    <h1>Ringkasan Keuangan</h1>
    <p><?= e((string) ($currentUser['name'] ?? 'Pengguna')) ?>, ini kondisi keuanganmu untuk periode <?= e($month) ?>.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" type="button" data-modal-open="quick-transaction"><?= icon('plus') ?> Catat transaksi</button>
    <a class="btn" href="<?= e(url('pages/reports.php')) ?>"><?= icon('download') ?> Laporan</a>
  </div>
</div>

<form class="filter-bar" method="get" action="<?= e(url('pages/dashboard.php')) ?>">
  <div class="field">
    <label for="dashboard-month">Periode</label>
    <input id="dashboard-month" name="month" type="month" value="<?= e($month) ?>" required>
  </div>
  <button class="btn btn-sm btn-primary" type="submit"><?= icon('calendar') ?> Tampilkan</button>
  <a class="btn btn-sm btn-ghost" href="<?= e(url('pages/dashboard.php')) ?>">Bulan ini</a>
</form>

<?php if ($alerts !== []): ?>
  <?php foreach ($alerts as $alert): ?>
    <?php $isOver = (string) $alert['status'] === 'over'; ?>
    <div class="alert <?= $isOver ? 'alert-danger' : 'alert-warning' ?>">
      <?= icon('alert') ?>
      <div>
        <strong><?= e($alert['category']) ?></strong>
        <?php if ((string) ($alert['message'] ?? '') !== ''): ?>
          <?= e($alert['message']) ?>
        <?php elseif ($isOver): ?>
          Pengeluaran mencapai <?= e((int) $alert['percent']) ?>% dari batas. Kelebihan <?= e(format_rupiah((int) $alert['overage'])) ?>.
        <?php else: ?>
          Pengeluaran mencapai <?= e((int) $alert['percent']) ?>% dari batas. Sisa <?= e(format_rupiah((int) $alert['remaining'])) ?>.
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="grid grid-stats">
  <div class="stat-card">
    <p class="stat-label">Saldo tersedia</p>
    <p class="stat-value"><?= e(format_rupiah($balance)) ?></p>
    <div class="stat-meta"><?= icon('wallet') ?> setelah dikurangi tabungan aktif</div>
    <span class="stat-icon"><?= icon('wallet') ?></span>
  </div>
  <div class="stat-card stat-income">
    <p class="stat-label">Pemasukan bulan ini</p>
    <p class="stat-value"><?= e(format_rupiah((int) $totals['income'])) ?></p>
    <div class="stat-meta"><?= icon('arrow-up') ?> <?= e($month) ?></div>
    <span class="stat-icon"><?= icon('arrow-up') ?></span>
  </div>
  <div class="stat-card stat-expense">
    <p class="stat-label">Pengeluaran bulan ini</p>
    <p class="stat-value"><?= e(format_rupiah((int) $totals['expense'])) ?></p>
    <div class="stat-meta"><?= icon('arrow-down') ?> <?= e($month) ?></div>
    <span class="stat-icon"><?= icon('arrow-down') ?></span>
  </div>
  <div class="stat-card stat-saving">
    <p class="stat-label">Dana di tabungan aktif</p>
    <p class="stat-value"><?= e(format_rupiah((int) $savingsSummary['locked'])) ?></p>
    <div class="stat-meta"><?= icon('target') ?> <?= (int) $savingsSummary['goal_count'] ?> target berjalan</div>
    <span class="stat-icon"><?= icon('target') ?></span>
  </div>
</div>

<div id="charts-root" data-endpoint="<?= e(url('process/api_dashboard.php')) ?>" data-month="<?= e($month) ?>">
  <div class="grid grid-charts">
    <section class="card">
      <div class="card-header"><h2>Pengeluaran per Kategori</h2></div>
      <div class="card-body">
        <div class="chart-box"><canvas id="chart-category"></canvas></div>
      </div>
    </section>
    <section class="card">
      <div class="card-header"><h2>Tren Pemasukan vs Pengeluaran</h2></div>
      <div class="card-body">
        <div class="chart-box"><canvas id="chart-trend"></canvas></div>
      </div>
    </section>
  </div>
</div>

<div class="grid grid-2">
  <section class="card">
    <div class="card-header">
      <h2>Progres tabungan aktif</h2>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('pages/savings.php')) ?>">Lihat semua</a>
    </div>
    <div class="card-body">
      <?php if ($activeGoals === []): ?>
        <div class="empty-state">
          <?= icon('target') ?>
          <strong>Belum ada target aktif</strong>
          <span>Buat target tabungan untuk melihat progresnya di sini.</span>
        </div>
      <?php else: ?>
        <div class="grid" style="gap: 12px;">
          <?php foreach ($activeGoals as $goal): ?>
            <?php
              $target = (int) $goal['target_amount'];
              $current = (int) $goal['current_amount'];
              $percent = (int) min(100, (int) round($target > 0 ? $current / $target * 100 : 0));
            ?>
            <div>
              <div class="progress-meta" style="margin-top: 0;">
                <span><strong><?= e($goal['title']) ?></strong></span>
                <span><?= e(format_rupiah($current)) ?> / <?= e(format_rupiah($target)) ?></span>
              </div>
              <div class="progress-track" role="img" aria-label="Progres <?= e($goal['title']) ?> <?= $percent ?> persen">
                <div class="progress-fill" style="width: <?= $percent ?>%"></div>
              </div>
              <div class="progress-meta">
                <span><?= $percent ?>% terkumpul</span>
                <span>Sisa <?= e(format_rupiah(max(0, $target - $current))) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <h2>Transaksi terbaru</h2>
      <a class="btn btn-sm btn-ghost" href="<?= e(url('pages/transactions.php')) ?>">Lihat semua</a>
    </div>
    <div class="card-body">
      <?php if ($recentTransactions === []): ?>
        <div class="empty-state">
          <?= icon('list') ?>
          <strong>Belum ada transaksi</strong>
          <span>Catat transaksi pertamamu untuk melihat ringkasan di sini.</span>
        </div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Tanggal</th>
                <th>Kategori</th>
                <th>Jenis</th>
                <th class="num">Nominal</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentTransactions as $transaction): ?>
                <tr>
                  <td><?= e(date('d/m/Y', strtotime((string) $transaction['transaction_date']))) ?></td>
                  <td>
                    <span class="cell-main"><?= e($transaction['category_name']) ?></span>
                    <?php if (trim((string) $transaction['note']) !== ''): ?>
                      <span class="cell-sub"><?= e($transaction['note']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge <?= (string) $transaction['type'] === 'income' ? 'badge-success' : 'badge-danger' ?>">
                      <?= (string) $transaction['type'] === 'income' ? 'Masuk' : 'Keluar' ?>
                    </span>
                  </td>
                  <td class="num cell-main"><?= e(format_rupiah((int) $transaction['amount'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>

<div class="modal" id="quick-transaction" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="quick-transaction-title">
    <div class="modal-head">
      <h2 id="quick-transaction-title">Catat transaksi cepat</h2>
      <button class="icon-button" type="button" data-modal-close aria-label="Tutup"><?= icon('close') ?></button>
    </div>
    <div class="modal-body">
      <form method="post" action="<?= e(url('process/transaction_process.php')) ?>" data-category-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="return_to" value="dashboard">
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <div class="form-grid">
          <div class="field full">
            <label>Jenis transaksi</label>
            <div class="segmented">
              <label><input type="radio" name="type" value="expense" checked><span><?= icon('arrow-down') ?> Pengeluaran</span></label>
              <label><input type="radio" name="type" value="income"><span><?= icon('arrow-up') ?> Pemasukan</span></label>
            </div>
          </div>
          <div class="field">
            <label for="quick-amount">Nominal</label>
            <input id="quick-amount" name="amount" type="text" inputmode="numeric" placeholder="Rp50.000" required>
          </div>
          <div class="field">
            <label for="quick-category">Kategori</label>
            <select id="quick-category" name="category_id" data-category-select required>
              <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" data-type="<?= e($category['type']) ?>"><?= e($category['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field full">
            <label for="quick-date">Tanggal</label>
            <input id="quick-date" name="transaction_date" type="date" value="<?= e(date('Y-m-d')) ?>" required>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= icon('check') ?> Simpan transaksi</button>
          <a class="btn btn-ghost" href="<?= e(url('pages/transactions.php')) ?>">Form lengkap</a>
          <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  (function () {
    const form = document.querySelector('[data-category-form]');
    if (!form) return;
    const select = form.querySelector('[data-category-select]');
    if (!select) return;

    function applyFilter() {
      const checked = form.querySelector('input[name="type"]:checked');
      if (!checked) return;
      const options = Array.from(select.options);
      options.forEach((option) => {
        const match = option.dataset.type === checked.value;
        option.hidden = !match;
        option.disabled = !match;
      });
      const selected = options.find((option) => option.selected && !option.disabled);
      if (!selected) {
        const first = options.find((option) => !option.disabled);
        if (first) select.value = first.value;
      }
    }

    applyFilter();
    form.querySelectorAll('input[name="type"]').forEach((input) => {
      input.addEventListener('change', () => {
        Array.from(select.options).forEach((option) => { option.selected = false; });
        applyFilter();
      });
    });
  })();
</script>
<?php
render_footer();
