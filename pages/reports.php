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
             JOIN groups g ON g.id = ge.group_id
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
             FROM groups g
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
<div class="page-head">
  <div>
    <h1>Laporan Keuangan</h1>
    <p>Rekap transaksi pribadi dan patungan grup, siap diunduh.</p>
  </div>
  <div class="page-actions">
    <?php if ($exportDisabled): ?>
      <span class="btn btn-primary" aria-disabled="true" title="Perbaiki rentang tanggal terlebih dahulu"><?= icon('download') ?> Unduh CSV</span>
      <span class="btn" aria-disabled="true" title="Perbaiki rentang tanggal terlebih dahulu"><?= icon('download') ?> Unduh PDF</span>
    <?php else: ?>
      <a class="btn btn-primary" href="<?= e($csvUrl) ?>"><?= icon('download') ?> Unduh CSV</a>
      <a class="btn" href="<?= e($pdfUrl) ?>"><?= icon('download') ?> Unduh PDF</a>
    <?php endif; ?>
  </div>
</div>

<section class="card">
  <div class="card-header">
    <h2><?= icon('calendar') ?> Pilih Laporan</h2>
    <span class="badge badge-info">CSV &amp; PDF</span>
  </div>
  <div class="card-body">
    <form class="form-grid" method="get" action="<?= e(url('pages/reports.php')) ?>">
      <div class="field">
        <label for="report-type">Jenis laporan</label>
        <select id="report-type" name="type">
          <option value="personal" <?= $type === 'personal' ? 'selected' : '' ?>>Transaksi Pribadi</option>
          <option value="group" <?= $type === 'group' ? 'selected' : '' ?>>Patungan Grup</option>
        </select>
      </div>

      <div class="field">
        <span class="field-label">Mode periode</span>
        <div class="segmented" id="report-period-month">
          <label>
            <input type="radio" name="period" value="month" <?= $period === 'month' ? 'checked' : '' ?>>
            <span><?= icon('calendar') ?> Bulan</span>
          </label>
          <label>
            <input type="radio" name="period" value="range" <?= $period === 'range' ? 'checked' : '' ?>>
            <span><?= icon('chart') ?> Rentang</span>
          </label>
        </div>
      </div>

      <div class="field">
        <label for="report-month">Bulan</label>
        <input type="month" id="report-month" name="month" value="<?= e($month) ?>">
        <span class="field-hint">Dipakai saat mode Bulan.</span>
      </div>

      <div class="field">
        <label for="report-start">Tanggal mulai</label>
        <input type="date" id="report-start" name="start" value="<?= e($start) ?>">
      </div>

      <div class="field">
        <label for="report-end">Tanggal selesai</label>
        <input type="date" id="report-end" name="end" value="<?= e($end) ?>">
        <span class="field-hint">Dipakai saat mode Rentang.</span>
      </div>

      <div class="field full form-actions">
        <button class="btn btn-primary" type="submit" name="preview" value="1"><?= icon('search') ?> Tampilkan Pratinjau</button>
        <span class="field-hint">Unduhan memakai pilihan yang sama dengan pratinjau.</span>
      </div>
    </form>
  </div>
</section>

<?php if ($hasPreview): ?>
<section class="card" style="margin-top: var(--space-5);">
  <div class="card-header">
    <h2><?= icon($type === 'personal' ? 'list' : 'users') ?> Pratinjau <?= e($typeLabel) ?></h2>
    <span class="badge"><?= e($periodLabel) ?></span>
  </div>
  <div class="card-body">
    <?php if ($rangeInvalid): ?>
      <div class="alert alert-warning">
        <?= icon('alert') ?>
        <span>Tanggal mulai harus sebelum atau sama dengan tanggal selesai. Perbaiki rentang untuk melihat data dan mengunduh laporan.</span>
      </div>
    <?php elseif ($type === 'personal'): ?>
      <div class="grid grid-stats" style="margin-bottom: var(--space-5);">
        <div class="stat-card stat-income">
          <p class="stat-label">Pemasukan periode</p>
          <p class="stat-value"><?= e(format_rupiah($personalIncome)) ?></p>
          <span class="stat-icon"><?= icon('arrow-up') ?></span>
        </div>
        <div class="stat-card stat-expense">
          <p class="stat-label">Pengeluaran periode</p>
          <p class="stat-value"><?= e(format_rupiah($personalExpense)) ?></p>
          <span class="stat-icon"><?= icon('arrow-down') ?></span>
        </div>
        <div class="stat-card">
          <p class="stat-label">Saldo periode</p>
          <p class="stat-value"><?= e(format_rupiah($personalSaldo)) ?></p>
          <span class="stat-icon"><?= icon('wallet') ?></span>
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>Jenis</th>
              <th>Kategori</th>
              <th class="num">Nominal</th>
              <th>Catatan</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$personalRows): ?>
              <tr><td colspan="5">
                <div class="empty-state">
                  <?= icon('info') ?>
                  <strong>Belum ada transaksi</strong>
                  <span>Tidak ada data pada periode ini.</span>
                </div>
              </td></tr>
            <?php else: ?>
              <?php foreach ($personalRows as $row): ?>
                <tr>
                  <td><?= e($row['date']) ?></td>
                  <td><span class="badge <?= $row['income'] ? 'badge-success' : 'badge-danger' ?>"><?= e($row['type']) ?></span></td>
                  <td class="cell-main"><?= e($row['category']) ?></td>
                  <td class="num"><?= e(format_rupiah($row['amount'])) ?></td>
                  <td><?= e($row['note'] !== '' ? $row['note'] : '-') ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php else: ?>
      <div class="grid grid-2" style="margin-bottom: var(--space-5);">
        <div class="stat-card">
          <p class="stat-label">Total pengeluaran grup periode ini</p>
          <p class="stat-value"><?= e(format_rupiah($groupTotal)) ?></p>
          <span class="stat-icon"><?= icon('users') ?></span>
        </div>
        <div class="stat-card">
          <p class="stat-label">Jumlah catatan patungan</p>
          <p class="stat-value"><?= e(count($groupRows)) ?></p>
          <span class="stat-icon"><?= icon('list') ?></span>
        </div>
      </div>

      <h3 style="margin: 0 0 var(--space-3);">Ringkasan per grup</h3>
      <div class="table-wrap" style="margin-bottom: var(--space-5);">
        <table>
          <thead>
            <tr>
              <th>Grup</th>
              <th class="num">Pengeluaran periode</th>
              <th class="num">Saldo bersih kamu (saat ini)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$groupSummaries): ?>
              <tr><td colspan="3">
                <div class="empty-state">
                  <?= icon('info') ?>
                  <strong>Belum ada grup</strong>
                  <span>Kamu belum menjadi anggota grup mana pun.</span>
                </div>
              </td></tr>
            <?php else: ?>
              <?php foreach ($groupSummaries as $summary): ?>
                <tr>
                  <td class="cell-main"><?= e($summary['name']) ?></td>
                  <td class="num"><?= e(format_rupiah($summary['total'])) ?></td>
                  <td class="num">
                    <?php if ($summary['net'] > 0): ?>
                      <span class="badge badge-success">Piutang <?= e(format_rupiah($summary['net'])) ?></span>
                    <?php elseif ($summary['net'] < 0): ?>
                      <span class="badge badge-danger">Utang <?= e(format_rupiah(abs($summary['net']))) ?></span>
                    <?php else: ?>
                      <span class="badge">Lunas</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <h3 style="margin: 0 0 var(--space-3);">Rincian pengeluaran grup</h3>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Tanggal</th>
              <th>Grup</th>
              <th>Judul</th>
              <th>Dibayar Oleh</th>
              <th class="num">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$groupRows): ?>
              <tr><td colspan="5">
                <div class="empty-state">
                  <?= icon('info') ?>
                  <strong>Belum ada pengeluaran</strong>
                  <span>Tidak ada catatan patungan pada periode ini.</span>
                </div>
              </td></tr>
            <?php else: ?>
              <?php foreach ($groupRows as $row): ?>
                <tr>
                  <td><?= e($row['date']) ?></td>
                  <td><?= e($row['group']) ?></td>
                  <td class="cell-main"><?= e($row['title']) ?></td>
                  <td><?= e($row['paid_by']) ?></td>
                  <td class="num"><?= e(format_rupiah($row['amount'])) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php
render_footer();
