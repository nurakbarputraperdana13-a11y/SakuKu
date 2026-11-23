<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/navbar.php';

function skr_valid_ym(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}$/', $value)) {
        return false;
    }
    return checkdate((int) substr($value, 5, 2), 1, (int) substr($value, 0, 4));
}

function skr_valid_ymd(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
}

function skr_month_label(string $month): string
{
    $names = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];
    $monthNumber = (int) substr($month, 5, 2);
    $name = $names[$monthNumber - 1] ?? 'Bulan';
    return $name . ' ' . substr($month, 0, 4);
}

$userId = (int) $currentUser['id'];

$typeRaw = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : 'personal';
$type = in_array($typeRaw, ['personal', 'group'], true) ? $typeRaw : 'personal';

$periodRaw = isset($_GET['period']) && is_string($_GET['period']) ? $_GET['period'] : 'month';
$period = in_array($periodRaw, ['month', 'range'], true) ? $periodRaw : 'month';

$monthRaw = isset($_GET['month']) && is_string($_GET['month']) ? trim($_GET['month']) : current_month();
$month = skr_valid_ym($monthRaw) ? $monthRaw : current_month();

$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($month . '-01') ?: time());

$startRaw = isset($_GET['start']) && is_string($_GET['start']) ? trim($_GET['start']) : $monthStart;
$endRaw = isset($_GET['end']) && is_string($_GET['end']) ? trim($_GET['end']) : $monthEnd;
$start = skr_valid_ymd($startRaw) ? $startRaw : $monthStart;
$end = skr_valid_ymd($endRaw) ? $endRaw : $monthEnd;
$rangeInvalid = $period === 'range' && strcmp($start, $end) > 0;

$hasPreview = false;
foreach (['preview', 'type', 'period', 'month', 'start', 'end'] as $key) {
    if (array_key_exists($key, $_GET)) {
        $hasPreview = true;
        break;
    }
}

if ($period === 'month') {
    $from = $monthStart;
    $to = $monthEnd;
    $periodLabel = 'Bulan ' . skr_month_label($month);
} else {
    $from = $start;
    $to = $end;
    $periodLabel = $rangeInvalid ? 'Rentang tidak valid' : $start . ' s.d. ' . $end;
}

$exportParams = [
    'type'   => $type,
    'period' => $period,
    'month'  => $month,
    'start'  => $start,
    'end'    => $end,
];
$csvUrl = url('process/export_report.php') . '?' . http_build_query(['format' => 'csv'] + $exportParams);
$pdfUrl = url('process/export_report.php') . '?' . http_build_query(['format' => 'pdf'] + $exportParams);
$exportDisabled = $rangeInvalid;

$personalRows = [];
$personalIncome = 0;
$personalExpense = 0;
$groupRows = [];
$groupSummaries = [];
$groupTotal = 0;

if ($hasPreview && !$rangeInvalid) {
    if ($type === 'personal') {
        $stmt = $pdo->prepare(
            "SELECT t.transaction_date, t.type, c.name AS category, t.amount, COALESCE(t.note, '') AS note
             FROM transactions t
             JOIN categories c ON c.id = t.category_id
             WHERE t.user_id = :user_id AND t.transaction_date BETWEEN :start AND :end
             ORDER BY t.transaction_date ASC, t.id ASC"
        );
        $stmt->execute([':user_id' => $userId, ':start' => $from, ':end' => $to]);

        foreach ($stmt->fetchAll() as $row) {
            $amount = (int) $row['amount'];
            $isIncome = ($row['type'] ?? '') === 'income';
            if ($isIncome) {
                $personalIncome += $amount;
            } else {
                $personalExpense += $amount;
            }
            $personalRows[] = [
                'date'     => (string) $row['transaction_date'],
                'type'     => $isIncome ? 'Pemasukan' : 'Pengeluaran',
                'income'   => $isIncome,
                'category' => (string) $row['category'],
                'amount'   => $amount,
                'note'     => (string) $row['note'],
            ];
        }
    } else {
        $stmt = $pdo->prepare(
            "SELECT ge.expense_date, g.name AS group_name, ge.title,
                    u.name AS paid_by, ge.total_amount
             FROM group_expenses ge
             JOIN `groups` g ON g.id = ge.group_id
             JOIN group_members gm ON gm.group_id = ge.group_id AND gm.user_id = :user_id
             JOIN users u ON u.id = ge.paid_by_user_id
             WHERE ge.expense_date BETWEEN :start AND :end
             ORDER BY ge.expense_date ASC, ge.id ASC"
        );
        $stmt->execute([':user_id' => $userId, ':start' => $from, ':end' => $to]);

        foreach ($stmt->fetchAll() as $row) {
            $amount = (int) $row['total_amount'];
            $groupTotal += $amount;
            $groupRows[] = [
                'date'       => (string) $row['expense_date'],
                'group'      => (string) $row['group_name'],
                'title'      => (string) $row['title'],
                'paid_by'    => (string) $row['paid_by'],
                'amount'     => $amount,
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT g.id, g.name, COALESCE(SUM(ge.total_amount), 0) AS total
             FROM `groups` g
             JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = :user_id
             LEFT JOIN group_expenses ge
                    ON ge.group_id = g.id AND ge.expense_date BETWEEN :start AND :end
             GROUP BY g.id, g.name
             ORDER BY g.name ASC"
        );
        $stmt->execute([':user_id' => $userId, ':start' => $from, ':end' => $to]);

        foreach ($stmt->fetchAll() as $row) {
            $groupId = (int) $row['id'];
            $balances = group_net_balances($pdo, $groupId);
            $groupSummaries[] = [
                'name'  => (string) $row['name'],
                'total' => (int) $row['total'],
                'net'   => (int) ($balances[$userId] ?? 0),
            ];
        }
    }
}

$personalSaldo = $personalIncome - $personalExpense;
$typeLabel = $type === 'personal' ? 'Transaksi Pribadi' : 'Patungan Grup';

render_header('Laporan Keuangan', 'reports');
?>
<section class="flex flex-col gap-space-md md:flex-row md:items-end md:justify-between">
  <div>
    <p class="font-label-lg text-label-lg uppercase tracking-wider text-primary">Wealth Operations / Ekspor</p>
    <h1 class="mt-1 font-headline-xl text-headline-xl text-on-surface md:font-display-lg md:text-display-lg">Laporan keuangan</h1>
    <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Rekap transaksi pribadi dan patungan grup, siap diunduh.</p>
  </div>
  <div class="flex flex-wrap items-center gap-space-sm">
    <?php if ($exportDisabled): ?>
      <span class="inline-flex h-12 cursor-not-allowed items-center gap-2 rounded-xl border border-on-surface/10 bg-white px-5 font-label-lg text-label-lg text-on-surface-variant opacity-50" title="Perbaiki rentang tanggal terlebih dahulu" aria-disabled="true">
        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">download</span> Unduh CSV
      </span>
      <span class="inline-flex h-12 cursor-not-allowed items-center gap-2 rounded-xl border border-on-surface/10 bg-white px-5 font-label-lg text-label-lg text-on-surface-variant opacity-50" title="Perbaiki rentang tanggal terlebih dahulu" aria-disabled="true">
        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">picture_as_pdf</span> Unduh PDF
      </span>
    <?php else: ?>
      <a class="inline-flex h-12 items-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary shadow-md transition-colors hover:bg-primary" href="<?= e($csvUrl) ?>">
        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">download</span> Unduh CSV
      </a>
      <a class="inline-flex h-12 items-center gap-2 rounded-xl border border-on-surface/10 bg-white px-5 font-label-lg text-label-lg text-on-surface shadow-sm transition-colors hover:bg-surface-container-low" href="<?= e($pdfUrl) ?>">
        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">picture_as_pdf</span> Unduh PDF
      </a>
    <?php endif; ?>
  </div>
</section>

<section class="bento-card mt-space-lg">
  <div class="flex flex-wrap items-center justify-between gap-space-sm">
    <div>
      <h2 class="font-headline-lg text-headline-lg text-on-surface">Pilih laporan</h2>
      <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Pratinjau dan unduhan memakai pengaturan yang sama.</p>
    </div>
    <span class="pill pill-lime">CSV &amp; PDF</span>
  </div>

  <form class="mt-space-lg grid grid-cols-1 gap-space-md sm:grid-cols-2 lg:grid-cols-5" method="get" action="<?= e(url('pages/reports.php')) ?>">
    <div class="field lg:col-span-1">
      <label for="report-type">Jenis laporan</label>
      <select id="report-type" name="type">
        <option value="personal" <?= $type === 'personal' ? 'selected' : '' ?>>Transaksi Pribadi</option>
        <option value="group" <?= $type === 'group' ? 'selected' : '' ?>>Patungan Grup</option>
      </select>
    </div>
    <div class="field lg:col-span-2">
      <span>Mode periode</span>
      <div class="flex gap-1 rounded-xl bg-surface-container-low p-1">
        <label class="flex-1">
          <input class="peer sr-only" type="radio" name="period" value="month" <?= $period === 'month' ? 'checked' : '' ?>>
          <span class="block cursor-pointer rounded-lg px-3 py-2 text-center font-label-md text-label-md text-on-surface-variant peer-checked:bg-secondary-fixed peer-checked:font-bold peer-checked:text-on-secondary-fixed">Bulan</span>
        </label>
        <label class="flex-1">
          <input class="peer sr-only" type="radio" name="period" value="range" <?= $period === 'range' ? 'checked' : '' ?>>
          <span class="block cursor-pointer rounded-lg px-3 py-2 text-center font-label-md text-label-md text-on-surface-variant peer-checked:bg-secondary-fixed peer-checked:font-bold peer-checked:text-on-secondary-fixed">Rentang</span>
        </label>
      </div>
    </div>
    <div class="field lg:col-span-2">
      <label for="report-month">Bulan</label>
      <input id="report-month" name="month" type="month" value="<?= e($month) ?>">
      <span class="field-hint">Dipakai saat mode Bulan.</span>
    </div>
    <div class="field lg:col-span-2">
      <label for="report-start">Tanggal mulai</label>
      <input id="report-start" name="start" type="date" value="<?= e($start) ?>">
    </div>
    <div class="field lg:col-span-2">
      <label for="report-end">Tanggal selesai</label>
      <input id="report-end" name="end" type="date" value="<?= e($end) ?>">
      <span class="field-hint">Dipakai saat mode Rentang.</span>
    </div>
    <div class="flex items-end lg:col-span-1">
      <button class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary transition-colors hover:bg-primary" type="submit" name="preview" value="1">
        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">search</span> Pratinjau
      </button>
    </div>
  </form>
</section>

<?php if (!$hasPreview): ?>
  <section class="bento-card mt-space-lg">
    <div class="flex flex-col items-center justify-center gap-2 rounded-2xl bg-surface-container-low px-4 py-14 text-center">
      <span class="material-symbols-outlined text-[32px] text-outline-variant" aria-hidden="true">description</span>
      <p class="font-headline-lg text-headline-lg text-on-surface">Belum ada pratinjau laporan</p>
      <p class="max-w-md font-body-md text-body-md text-on-surface-variant">Pilih jenis laporan dan periode, lalu tekan Pratinjau untuk melihat data sebelum mengunduh.</p>
    </div>
  </section>
<?php elseif ($rangeInvalid): ?>
  <div class="mt-space-lg flex items-start gap-space-sm rounded-2xl border border-red-200 bg-red-50 p-space-md">
    <span class="material-symbols-outlined text-[20px] text-red-600" aria-hidden="true">warning</span>
    <p class="font-body-md text-body-md text-on-surface">Tanggal mulai harus sebelum atau sama dengan tanggal selesai. Perbaiki rentang untuk melihat data dan mengunduh laporan.</p>
  </div>
<?php elseif ($type === 'personal'): ?>
  <section class="mt-space-lg grid grid-cols-1 gap-gutter sm:grid-cols-3 md:gap-gutter-desktop">
    <article class="bento-card">
      <span class="font-label-lg text-label-lg text-on-surface-variant">Pemasukan periode</span>
      <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah($personalIncome)) ?></p>
      <span class="pill pill-lime mt-space-md w-fit"><?= e($periodLabel) ?></span>
    </article>
    <article class="bento-card">
      <span class="font-label-lg text-label-lg text-on-surface-variant">Pengeluaran periode</span>
      <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah($personalExpense)) ?></p>
      <span class="pill pill-red mt-space-md w-fit"><?= count($personalRows) ?> transaksi</span>
    </article>
    <article class="bento-card">
      <span class="font-label-lg text-label-lg text-on-surface-variant">Saldo periode</span>
      <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah($personalSaldo)) ?></p>
      <span class="pill pill-neutral mt-space-md w-fit">masuk dikurangi keluar</span>
    </article>
  </section>

  <section class="bento-card mt-space-lg">
    <div class="flex flex-wrap items-center justify-between gap-space-sm">
      <div>
        <h2 class="font-headline-lg text-headline-lg text-on-surface">Pratinjau transaksi pribadi</h2>
        <p class="mt-1 font-body-md text-body-md text-on-surface-variant"><?= e($periodLabel) ?></p>
      </div>
      <span class="pill pill-neutral"><?= count($personalRows) ?> data</span>
    </div>
    <div class="mt-space-lg">
      <?php if (!$personalRows): ?>
        <div class="flex flex-col items-center justify-center gap-2 rounded-2xl bg-surface-container-low px-4 py-12 text-center">
          <span class="material-symbols-outlined text-[28px] text-outline-variant" aria-hidden="true">info</span>
          <p class="font-headline-md text-headline-md text-on-surface">Belum ada transaksi</p>
          <p class="max-w-sm font-body-md text-body-md text-on-surface-variant">Tidak ada data pada periode ini.</p>
        </div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-left font-body-md text-body-md">
            <thead>
              <tr class="border-b border-surface-container text-on-surface-variant">
                <th class="py-2 font-label-md text-label-md">Tanggal</th>
                <th class="py-2 font-label-md text-label-md">Jenis</th>
                <th class="py-2 font-label-md text-label-md">Kategori</th>
                <th class="py-2 text-right font-label-md text-label-md">Nominal</th>
                <th class="py-2 font-label-md text-label-md">Catatan</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($personalRows as $row): ?>
                <tr class="border-b border-surface-container/70 transition-colors hover:bg-surface-container-low">
                  <td class="py-3 tabular-nums text-on-surface-variant"><?= e($row['date']) ?></td>
                  <td class="py-3"><span class="pill <?= $row['income'] ? 'pill-lime' : 'pill-red' ?>"><?= e($row['type']) ?></span></td>
                  <td class="py-3 font-semibold text-on-surface"><?= e($row['category']) ?></td>
                  <td class="py-3 text-right font-bold tabular-nums text-on-surface"><?= e(format_rupiah($row['amount'])) ?></td>
                  <td class="py-3 text-on-surface-variant"><?= e($row['note'] !== '' ? $row['note'] : '-') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>
<?php else: ?>
  <section class="mt-space-lg grid grid-cols-1 gap-gutter sm:grid-cols-2 md:gap-gutter-desktop">
    <article class="bento-card">
      <span class="font-label-lg text-label-lg text-on-surface-variant">Total pengeluaran grup</span>
      <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah($groupTotal)) ?></p>
      <span class="pill pill-neutral mt-space-md w-fit"><?= e($periodLabel) ?></span>
    </article>
    <article class="bento-card">
      <span class="font-label-lg text-label-lg text-on-surface-variant">Jumlah catatan patungan</span>
      <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(count($groupRows)) ?></p>
      <span class="pill pill-lime mt-space-md w-fit">pengeluaran grup</span>
    </article>
  </section>

  <section class="bento-card mt-space-lg">
    <h2 class="font-headline-lg text-headline-lg text-on-surface">Ringkasan per grup</h2>
    <div class="mt-space-lg">
      <?php if (!$groupSummaries): ?>
        <div class="flex flex-col items-center justify-center gap-2 rounded-2xl bg-surface-container-low px-4 py-10 text-center">
          <span class="material-symbols-outlined text-[28px] text-outline-variant" aria-hidden="true">group</span>
          <p class="font-headline-md text-headline-md text-on-surface">Belum ada grup</p>
          <p class="max-w-sm font-body-md text-body-md text-on-surface-variant">Kamu belum menjadi anggota grup mana pun.</p>
        </div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-left font-body-md text-body-md">
            <thead>
              <tr class="border-b border-surface-container text-on-surface-variant">
                <th class="py-2 font-label-md text-label-md">Grup</th>
                <th class="py-2 text-right font-label-md text-label-md">Pengeluaran periode</th>
                <th class="py-2 text-right font-label-md text-label-md">Saldo bersih kamu (saat ini)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($groupSummaries as $summary): ?>
                <tr class="border-b border-surface-container/70 transition-colors hover:bg-surface-container-low">
                  <td class="py-3 font-semibold text-on-surface"><?= e($summary['name']) ?></td>
                  <td class="py-3 text-right tabular-nums text-on-surface"><?= e(format_rupiah($summary['total'])) ?></td>
                  <td class="py-3 text-right">
                    <?php if ($summary['net'] > 0): ?>
                      <span class="pill pill-lime">Piutang <?= e(format_rupiah($summary['net'])) ?></span>
                    <?php elseif ($summary['net'] < 0): ?>
                      <span class="pill pill-red">Utang <?= e(format_rupiah(abs($summary['net']))) ?></span>
                    <?php else: ?>
                      <span class="pill pill-neutral">Lunas</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="bento-card mt-space-lg">
    <h2 class="font-headline-lg text-headline-lg text-on-surface">Rincian pengeluaran grup</h2>
    <div class="mt-space-lg">
      <?php if (!$groupRows): ?>
        <div class="flex flex-col items-center justify-center gap-2 rounded-2xl bg-surface-container-low px-4 py-10 text-center">
          <span class="material-symbols-outlined text-[28px] text-outline-variant" aria-hidden="true">receipt_long</span>
          <p class="font-headline-md text-headline-md text-on-surface">Belum ada pengeluaran</p>
          <p class="max-w-sm font-body-md text-body-md text-on-surface-variant">Tidak ada catatan patungan pada periode ini.</p>
        </div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="w-full text-left font-body-md text-body-md">
            <thead>
              <tr class="border-b border-surface-container text-on-surface-variant">
                <th class="py-2 font-label-md text-label-md">Tanggal</th>
                <th class="py-2 font-label-md text-label-md">Grup</th>
                <th class="py-2 font-label-md text-label-md">Judul</th>
                <th class="py-2 font-label-md text-label-md">Dibayar oleh</th>
                <th class="py-2 text-right font-label-md text-label-md">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($groupRows as $row): ?>
                <tr class="border-b border-surface-container/70 transition-colors hover:bg-surface-container-low">
                  <td class="py-3 tabular-nums text-on-surface-variant"><?= e($row['date']) ?></td>
                  <td class="py-3 text-on-surface-variant"><?= e($row['group']) ?></td>
                  <td class="py-3 font-semibold text-on-surface"><?= e($row['title']) ?></td>
                  <td class="py-3 text-on-surface-variant"><?= e($row['paid_by']) ?></td>
                  <td class="py-3 text-right font-bold tabular-nums text-on-surface"><?= e(format_rupiah($row['amount'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>
<?php endif; ?>

<?php
render_footer();
