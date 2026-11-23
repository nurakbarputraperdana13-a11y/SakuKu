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
<section class="flex flex-col gap-space-md md:flex-row md:items-end md:justify-between">
  <div>
    <p class="font-label-lg text-label-lg uppercase tracking-wider text-primary">Ringkasan Keuangan</p>
    <h1 class="mt-1 font-headline-xl text-headline-xl text-on-surface md:font-display-lg md:text-display-lg">Halo, <?= e((string) ($currentUser['name'] ?? 'Pengguna')) ?></h1>
    <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Kondisi keuanganmu untuk periode <?= e($month) ?>.</p>
  </div>
  <div class="flex flex-wrap items-center gap-space-sm">
    <form class="flex items-center gap-space-xs rounded-full bg-surface-container-low p-space-xs shadow-sm" method="get" action="<?= e(url('pages/dashboard.php')) ?>">
      <label class="sr-only" for="dashboard-month">Periode</label>
      <input class="h-9 rounded-full border-0 bg-transparent px-space-md font-label-lg text-label-lg text-on-surface outline-none" id="dashboard-month" name="month" type="month" value="<?= e($month) ?>" required>
      <button class="rounded-full bg-primary-container px-space-md py-space-sm font-label-lg text-label-lg text-on-primary shadow-sm transition-colors hover:bg-primary" type="submit">Tampilkan</button>
    </form>
    <button class="inline-flex h-12 items-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary shadow-md transition-colors hover:bg-primary" type="button" data-modal-open="quick-transaction">
      <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add</span>
      Catat transaksi
    </button>
    <a class="inline-flex h-12 items-center gap-2 rounded-xl border border-on-surface/10 bg-white px-5 font-label-lg text-label-lg text-on-surface shadow-sm transition-colors hover:bg-surface-container-low" href="<?= e(url('pages/reports.php')) ?>">
      <span class="material-symbols-outlined text-[18px]" aria-hidden="true">download</span>
      Laporan
    </a>
  </div>
</section>

<?php if ($alerts !== []): ?>
  <div class="mt-space-lg grid grid-cols-1 gap-space-md md:grid-cols-2">
    <?php foreach ($alerts as $alert): ?>
      <?php $isOver = (string) $alert['status'] === 'over'; ?>
      <div class="flex items-start gap-space-sm rounded-2xl border p-space-md <?= $isOver ? 'border-red-200 bg-red-50' : 'border-lime-300/60 bg-lime-50' ?>">
        <span class="material-symbols-outlined text-[22px] <?= $isOver ? 'text-red-600' : 'text-green-700' ?>" aria-hidden="true">warning</span>
        <div class="font-body-md text-body-md text-on-surface">
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
  </div>
<?php endif; ?>

<section class="mt-space-lg grid grid-cols-1 gap-gutter md:gap-gutter-desktop lg:grid-cols-12">
  <div class="bento-dark relative flex flex-col justify-between gap-space-lg overflow-hidden lg:col-span-5">
    <div class="relative z-10">
      <div class="flex items-center justify-between">
        <span class="font-label-md text-label-md uppercase tracking-wider text-[#99f6e4]">Saldo tersedia</span>
        <span class="material-symbols-outlined text-[22px] text-secondary-fixed" aria-hidden="true">account_balance_wallet</span>
      </div>
      <p class="mt-3 font-metric-numeral-lg text-metric-numeral-lg tabular-nums text-white"><?= e(format_rupiah($balance)) ?></p>
      <p class="mt-1 font-label-md text-label-md text-[#99f6e4]">Setelah dikurangi tabungan aktif</p>
    </div>
    <div class="relative z-10 grid grid-cols-2 gap-space-md">
      <div class="rounded-2xl bg-white/10 p-space-md">
        <span class="font-label-sm text-label-sm uppercase tracking-wider text-[#99f6e4]">Masuk bulan ini</span>
        <p class="mt-1 font-metric-numeral-md text-metric-numeral-md tabular-nums text-white"><?= e(format_rupiah((int) $totals['income'])) ?></p>
      </div>
      <div class="rounded-2xl bg-white/10 p-space-md">
        <span class="font-label-sm text-label-sm uppercase tracking-wider text-[#99f6e4]">Keluar bulan ini</span>
        <p class="mt-1 font-metric-numeral-md text-metric-numeral-md tabular-nums text-white"><?= e(format_rupiah((int) $totals['expense'])) ?></p>
      </div>
    </div>
  </div>

  <div class="grid grid-cols-1 gap-gutter sm:grid-cols-3 md:gap-gutter-desktop lg:col-span-7">
    <article class="bento-card flex flex-col justify-between gap-space-md">
      <div class="flex items-center justify-between">
        <span class="font-label-lg text-label-lg text-on-surface-variant">Pemasukan</span>
        <span class="grid h-10 w-10 place-items-center rounded-full bg-surface-container-low text-primary"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">arrow_downward</span></span>
      </div>
      <p class="font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah((int) $totals['income'])) ?></p>
      <span class="pill pill-lime w-fit"><?= e($month) ?></span>
    </article>
    <article class="bento-card flex flex-col justify-between gap-space-md">
      <div class="flex items-center justify-between">
        <span class="font-label-lg text-label-lg text-on-surface-variant">Pengeluaran</span>
        <span class="grid h-10 w-10 place-items-center rounded-full bg-surface-container-low text-red-600"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">arrow_upward</span></span>
      </div>
      <p class="font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah((int) $totals['expense'])) ?></p>
      <span class="pill pill-red w-fit"><?= e($month) ?></span>
    </article>
    <article class="bento-card flex flex-col justify-between gap-space-md">
      <div class="flex items-center justify-between">
        <span class="font-label-lg text-label-lg text-on-surface-variant">Dana tabungan</span>
        <span class="grid h-10 w-10 place-items-center rounded-full bg-surface-container-low text-primary"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">savings</span></span>
      </div>
      <p class="font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah((int) $savingsSummary['locked'])) ?></p>
      <span class="pill pill-neutral w-fit"><?= (int) $savingsSummary['goal_count'] ?> target aktif</span>
    </article>
  </div>
</section>

<div id="charts-root" class="mt-space-lg" data-endpoint="<?= e(url('process/api_dashboard.php')) ?>" data-month="<?= e($month) ?>">
  <section class="grid grid-cols-1 gap-gutter md:gap-gutter-desktop lg:grid-cols-12">
    <div class="bento-card lg:col-span-4">
      <h2 class="font-headline-lg text-headline-lg text-on-surface">Pengeluaran per Kategori</h2>
      <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Komposisi pengeluaran bulan ini</p>
      <div class="chart-box mt-space-lg"><canvas id="chart-category"></canvas></div>
    </div>
    <div class="bento-card lg:col-span-8">
      <div class="flex flex-wrap items-start justify-between gap-space-sm">
        <div>
          <h2 class="font-headline-lg text-headline-lg text-on-surface">Tren Transaksi</h2>
          <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Perbandingan pemasukan dan pengeluaran bulan ini</p>
        </div>
        <div class="flex items-center gap-space-md font-label-md text-label-md text-on-surface-variant">
          <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-primary-container"></span>Pemasukan</span>
          <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-red-500"></span>Pengeluaran</span>
        </div>
      </div>
      <div class="chart-box mt-space-lg"><canvas id="chart-trend"></canvas></div>
    </div>
  </section>
</div>

<section class="mt-space-lg grid grid-cols-1 gap-gutter md:gap-gutter-desktop lg:grid-cols-12">
  <div class="bento-card lg:col-span-5">
    <div class="flex items-center justify-between gap-space-sm">
      <div>
        <h2 class="font-headline-lg text-headline-lg text-on-surface">Progres tabungan aktif</h2>
        <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Target yang sedang berjalan</p>
      </div>
      <a class="font-label-md text-label-md font-bold text-primary hover:underline" href="<?= e(url('pages/savings.php')) ?>">Lihat semua</a>
    </div>
    <div class="mt-space-lg flex flex-col gap-space-md">
      <?php if ($activeGoals === []): ?>
        <div class="flex flex-col items-center justify-center gap-2 rounded-2xl bg-surface-container-low px-4 py-10 text-center">
          <span class="material-symbols-outlined text-[28px] text-outline-variant" aria-hidden="true">savings</span>
          <p class="font-headline-md text-headline-md">Belum ada target aktif</p>
          <p class="font-body-md text-body-md text-on-surface-variant">Buat target tabungan untuk melihat progresnya di sini.</p>
        </div>
      <?php else: ?>
        <?php foreach ($activeGoals as $goal): ?>
          <?php
            $target = (int) $goal['target_amount'];
            $current = (int) $goal['current_amount'];
            $percent = (int) min(100, (int) round($target > 0 ? $current / $target * 100 : 0));
          ?>
          <div>
            <div class="flex items-center justify-between gap-space-sm">
              <span class="font-label-lg text-label-lg font-semibold text-on-surface"><?= e($goal['title']) ?></span>
              <span class="font-label-md text-label-md tabular-nums text-on-surface-variant"><?= e(format_rupiah($current)) ?> / <?= e(format_rupiah($target)) ?></span>
            </div>
            <div class="mt-2 h-3 w-full overflow-hidden rounded-full bg-surface-container" role="img" aria-label="Progres <?= e($goal['title']) ?> <?= $percent ?> persen">
              <div class="h-full rounded-full bg-primary-container" style="width: <?= $percent ?>%"></div>
            </div>
            <div class="mt-1 flex items-center justify-between font-label-sm text-label-sm text-on-surface-variant">
              <span><?= $percent ?>% terkumpul</span>
              <span>Sisa <?= e(format_rupiah(max(0, $target - $current))) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="bento-card lg:col-span-7">
    <div class="flex items-center justify-between gap-space-sm">
      <div>
        <h2 class="font-headline-lg text-headline-lg text-on-surface">Transaksi terbaru</h2>
        <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Mutasi transaksi pada rekeningmu</p>
      </div>
      <a class="font-label-md text-label-md font-bold text-primary hover:underline" href="<?= e(url('pages/transactions.php')) ?>">Lihat semua</a>
    </div>
    <div class="mt-space-lg">
      <?php if ($recentTransactions === []): ?>
        <div class="flex flex-col items-center justify-center gap-2 rounded-2xl bg-surface-container-low px-4 py-10 text-center">
          <span class="material-symbols-outlined text-[28px] text-outline-variant" aria-hidden="true">receipt_long</span>
          <p class="font-headline-md text-headline-md">Belum ada mutasi transaksi</p>
          <p class="max-w-sm font-body-md text-body-md text-on-surface-variant">Catat transaksi pertamamu untuk melihat ringkasan di sini.</p>
        </div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-left font-body-md text-body-md">
            <thead>
              <tr class="border-b border-surface-container text-on-surface-variant">
                <th class="py-2 font-label-md text-label-md">Tanggal</th>
                <th class="py-2 font-label-md text-label-md">Kategori</th>
                <th class="py-2 font-label-md text-label-md">Jenis</th>
                <th class="py-2 text-right font-label-md text-label-md">Nominal</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentTransactions as $transaction): ?>
                <tr class="border-b border-surface-container/70 transition-colors hover:bg-surface-container-low">
                  <td class="py-3 tabular-nums text-on-surface-variant"><?= e(date('d/m/Y', strtotime((string) $transaction['transaction_date']))) ?></td>
                  <td class="py-3">
                    <span class="block font-semibold text-on-surface"><?= e($transaction['category_name']) ?></span>
                    <?php if (trim((string) $transaction['note']) !== ''): ?>
                      <span class="block font-label-sm text-label-sm text-on-surface-variant"><?= e($transaction['note']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="py-3">
                    <span class="pill <?= (string) $transaction['type'] === 'income' ? 'pill-lime' : 'pill-red' ?>"><?= (string) $transaction['type'] === 'income' ? 'Masuk' : 'Keluar' ?></span>
                  </td>
                  <td class="py-3 text-right font-bold tabular-nums text-on-surface"><?= e(format_rupiah((int) $transaction['amount'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<div class="modal" id="quick-transaction" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="quick-transaction-title">
    <div class="flex items-center justify-between gap-space-sm border-b border-surface-container p-6">
      <div class="flex items-center gap-space-sm">
        <span class="grid h-10 w-10 place-items-center rounded-full bg-primary-container text-on-primary"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">add_card</span></span>
        <div>
          <h2 class="font-headline-md text-headline-md" id="quick-transaction-title">Catat transaksi cepat</h2>
          <p class="font-label-sm text-label-sm text-on-surface-variant">Input mutasi transaksi harian</p>
        </div>
      </div>
      <button class="grid h-9 w-9 place-items-center rounded-full bg-surface-container-low text-on-surface-variant transition-colors hover:bg-surface-container-high" type="button" data-modal-close aria-label="Tutup">
        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span>
      </button>
    </div>
    <div class="max-h-[70vh] overflow-y-auto p-6">
      <form class="flex flex-col gap-space-md" method="post" action="<?= e(url('process/transaction_process.php')) ?>" data-category-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="return_to" value="dashboard">
        <input type="hidden" name="month" value="<?= e($month) ?>">
        <div class="field">
          <label>Jenis transaksi</label>
          <div class="flex gap-1 rounded-xl bg-surface-container-low p-1">
            <label class="flex-1"><input class="peer sr-only" type="radio" name="type" value="expense" checked><span class="block cursor-pointer rounded-lg bg-secondary-fixed px-3 py-2 text-center font-label-md text-label-md font-bold text-on-secondary-fixed peer-checked:bg-secondary-fixed peer-checked:text-on-secondary-fixed">Pengeluaran</span></label>
            <label class="flex-1"><input class="peer sr-only" type="radio" name="type" value="income"><span class="block cursor-pointer rounded-lg px-3 py-2 text-center font-label-md text-label-md text-on-surface-variant peer-checked:bg-secondary-fixed peer-checked:text-on-secondary-fixed">Pemasukan</span></label>
          </div>
        </div>
        <div class="grid grid-cols-1 gap-space-md sm:grid-cols-2">
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
          <div class="field sm:col-span-2">
            <label for="quick-date">Tanggal</label>
            <input id="quick-date" name="transaction_date" type="date" value="<?= e(date('Y-m-d')) ?>" required>
          </div>
        </div>
        <div class="flex flex-wrap items-center gap-space-sm pt-2">
          <button class="inline-flex h-11 items-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary transition-colors hover:bg-primary" type="submit">Simpan transaksi</button>
          <a class="inline-flex h-11 items-center rounded-xl border border-on-surface/10 px-5 font-label-lg text-label-lg text-on-surface-variant transition-colors hover:bg-surface-container-low" href="<?= e(url('pages/transactions.php')) ?>">Form lengkap</a>
          <button class="inline-flex h-11 items-center rounded-xl px-5 font-label-lg text-label-lg text-on-surface-variant transition-colors hover:bg-surface-container-low" type="button" data-modal-close>Batal</button>
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
