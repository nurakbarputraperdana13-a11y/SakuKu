<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';

function skx_valid_ym(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}$/', $value)) {
        return false;
    }
    return checkdate((int) substr($value, 5, 2), 1, (int) substr($value, 0, 4));
}

function skx_valid_ymd(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
}

function skx_month_label(string $month): string
{
    $names = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];
    return ($names[(int) substr($month, 5, 2) - 1] ?? 'Bulan') . ' ' . substr($month, 0, 4);
}

function skx_reject(): never
{
    set_flash('error', 'Parameter laporan tidak valid. Pilih kembali jenis dan periode laporan.');
    redirect('pages/reports.php');
}

/** Prevent spreadsheet formula execution from user-supplied CSV cells. */
function skx_csv_cell(mixed $value): string
{
    $text = (string) $value;
    if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
        return "'" . $text;
    }
    return $text;
}

/** PHP 8.4+ requires an explicit escape argument. */
function skx_fputcsv($handle, array $fields): void
{
    fputcsv($handle, $fields, ',', '"', '\\');
}

/** Core FPDF fonts use a single-byte encoding. Transliterate before Cell/MultiCell. */
function skx_pdf_text(string $text): string
{
    $text = str_replace(["\r\n", "\r", "\n", "\t"], ' ', $text);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }

    $cp1252 = [
        "\x80" => 'EUR', "\x82" => ',', "\x83" => 'f', "\x84" => '"',
        "\x85" => '...', "\x86" => '+', "\x87" => '+', "\x88" => '^',
        "\x89" => '%', "\x8A" => 'S', "\x8B" => '<', "\x8C" => 'OE',
        "\x8E" => 'Z', "\x91" => "'", "\x92" => "'", "\x93" => '"',
        "\x94" => '"', "\x95" => '*', "\x96" => '-', "\x97" => '-',
        "\x98" => '~', "\x99" => 'TM', "\x9A" => 's', "\x9B" => '>',
        "\x9C" => 'oe', "\x9E" => 'z', "\x9F" => 'Y',
    ];
    $text = strtr($text, $cp1252);
    $text = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
    return trim(preg_replace('/\s+/', ' ', $text) ?? '');
}

function skx_pdf_wrap(FPDF $pdf, string $text, float $width): array
{
    $text = skx_pdf_text($text);
    if ($text === '') {
        return [''];
    }

    $maxWidth = max(10.0, $width - 2.0);
    $words = preg_split('/\s+/', $text) ?: [''];
    $lines = [];
    $line = '';

    foreach ($words as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        if ($pdf->GetStringWidth($candidate) <= $maxWidth || $line === '') {
            $line = $candidate;
            continue;
        }
        $lines[] = $line;
        $line = $word;
    }
    if ($line !== '' || !$lines) {
        $lines[] = $line;
    }
    return $lines;
}

function skx_pdf_table_row(
    FPDF $pdf,
    array $widths,
    array $cells,
    array $aligns = [],
    bool $header = false,
    bool $fill = false,
    ?callable $onPageBreak = null
): void {
    $pdf->SetFont('Helvetica', $header ? 'B' : '', 8);
    $lineHeight = 4.2;
    $wrapped = [];
    $lineCount = 1;

    foreach ($cells as $index => $cell) {
        $lines = skx_pdf_wrap($pdf, (string) $cell, (float) $widths[$index]);
        $wrapped[$index] = $lines;
        $lineCount = max($lineCount, count($lines));
    }

    $rowHeight = ($lineCount * $lineHeight) + 1.5;
    if (!$header && $pdf->GetY() + $rowHeight > $pdf->GetPageHeight() - 18) {
        $pdf->AddPage();
        if ($onPageBreak) {
            $onPageBreak();
        }
        $pdf->SetFont('Helvetica', '', 8);
    }

    $left = $pdf->GetX();
    $top = $pdf->GetY();
    $cursor = $left;

    foreach ($widths as $index => $width) {
        $align = $aligns[$index] ?? 'L';
        $pdf->MultiCell(
            (float) $width,
            $lineHeight,
            implode("\n", $wrapped[$index]),
            1,
            $align,
            $fill
        );
        $pdf->SetXY($cursor + (float) $width, $top);
        $cursor += (float) $width;
    }

    $pdf->SetXY($left, $top + $rowHeight);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    set_flash('error', 'Metode tidak diizinkan.');
    redirect('pages/reports.php');
}

$typeRaw = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
$formatRaw = isset($_GET['format']) && is_string($_GET['format']) ? $_GET['format'] : '';
$periodRaw = isset($_GET['period']) && is_string($_GET['period']) ? $_GET['period'] : '';

if (!in_array($typeRaw, ['personal', 'group'], true) || !in_array($formatRaw, ['csv', 'pdf'], true) || !in_array($periodRaw, ['month', 'range'], true)) {
    skx_reject();
}

$monthRaw = isset($_GET['month']) && is_string($_GET['month']) ? trim($_GET['month']) : '';
$startRaw = isset($_GET['start']) && is_string($_GET['start']) ? trim($_GET['start']) : '';
$endRaw = isset($_GET['end']) && is_string($_GET['end']) ? trim($_GET['end']) : '';

if ($periodRaw === 'month') {
    if (!skx_valid_ym($monthRaw)) {
        skx_reject();
    }
    $from = $monthRaw . '-01';
    $to = date('Y-m-t', strtotime($monthRaw . '-01') ?: time());
    $periodLabel = 'Bulan ' . skx_month_label($monthRaw);
    $slug = $monthRaw;
} else {
    if (!skx_valid_ymd($startRaw) || !skx_valid_ymd($endRaw) || strcmp($startRaw, $endRaw) > 0) {
        skx_reject();
    }
    $from = $startRaw;
    $to = $endRaw;
    $periodLabel = $startRaw . ' s.d. ' . $endRaw;
    $slug = $startRaw . '_' . $endRaw;
}

$userId = (int) $currentUser['id'];
$userName = (string) $currentUser['name'];
$filename = 'sakuku-laporan-' . $typeRaw . '-' . $slug . '.' . $formatRaw;

$personalRows = [];
$income = 0;
$expense = 0;
$saldo = 0;
$groupRows = [];
$groupTotal = 0;

if ($typeRaw === 'personal') {
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
            $income += $amount;
        } else {
            $expense += $amount;
        }
        $personalRows[] = [
            'date'     => (string) $row['transaction_date'],
            'type'     => $isIncome ? 'Pemasukan' : 'Pengeluaran',
            'category' => (string) $row['category'],
            'amount'   => $amount,
            'note'     => (string) $row['note'],
        ];
    }
    $saldo = $income - $expense;
} else {
    $stmt = $pdo->prepare(
        "SELECT ge.expense_date, g.name AS group_name, ge.title,
                u.name AS paid_by, ge.total_amount, ge.paid_by_user_id,
                COALESCE(SUM(CASE
                    WHEN es.user_id <> ge.paid_by_user_id AND COALESCE(es.is_settled, 0) = 0 THEN 1
                    ELSE 0
                END), 0) AS unsettled_count
         FROM group_expenses ge
         JOIN `groups` g ON g.id = ge.group_id
         JOIN group_members gm ON gm.group_id = ge.group_id AND gm.user_id = :user_id
         JOIN users u ON u.id = ge.paid_by_user_id
         LEFT JOIN expense_splits es ON es.group_expense_id = ge.id
         WHERE ge.expense_date BETWEEN :start AND :end
         GROUP BY ge.id, ge.expense_date, g.name, ge.title, u.name, ge.total_amount, ge.paid_by_user_id
         ORDER BY ge.expense_date ASC, ge.id ASC"
    );
    $stmt->execute([':user_id' => $userId, ':start' => $from, ':end' => $to]);

    foreach ($stmt->fetchAll() as $row) {
        $amount = (int) $row['total_amount'];
        $groupTotal += $amount;
        $groupRows[] = [
            'date'    => (string) $row['expense_date'],
            'group'   => (string) $row['group_name'],
            'title'   => (string) $row['title'],
            'paid_by' => (string) $row['paid_by'],
            'amount'  => $amount,
            'status'  => (int) $row['unsettled_count'] > 0 ? 'Belum lunas' : 'Lunas',
        ];
    }
}

if ($formatRaw === 'csv') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $handle = fopen('php://output', 'w');
    fwrite($handle, "\xEF\xBB\xBF");

    if ($typeRaw === 'personal') {
        skx_fputcsv($handle, ['Tanggal', 'Jenis', 'Kategori', 'Nominal', 'Catatan']);
        foreach ($personalRows as $row) {
            skx_fputcsv($handle, [
                skx_csv_cell($row['date']),
                skx_csv_cell($row['type']),
                skx_csv_cell($row['category']),
                $row['amount'],
                skx_csv_cell($row['note']),
            ]);
        }
        skx_fputcsv($handle, ['RINGKASAN', 'Pemasukan', '', $income, '']);
        skx_fputcsv($handle, ['', 'Pengeluaran', '', $expense, '']);
        skx_fputcsv($handle, ['', 'Saldo Periode', '', $saldo, '']);
    } else {
        skx_fputcsv($handle, ['Tanggal', 'Grup', 'Judul', 'Dibayar Oleh', 'Total', 'Status']);
        foreach ($groupRows as $row) {
            skx_fputcsv($handle, [
                skx_csv_cell($row['date']),
                skx_csv_cell($row['group']),
                skx_csv_cell($row['title']),
                skx_csv_cell($row['paid_by']),
                $row['amount'],
                skx_csv_cell($row['status']),
            ]);
        }
        skx_fputcsv($handle, ['RINGKASAN', '', '', 'Total Pengeluaran', $groupTotal, '']);
    }

    fclose($handle);
    exit;
}

require_once __DIR__ . '/../libs/fpdf/fpdf.php';

class SakuKu_Report_PDF extends FPDF
{
    public string $reportMeta = '';

    public function Header(): void
    {
        $this->SetFont('Helvetica', 'B', 14);
        $this->SetTextColor(30, 64, 175);
        $this->Cell(0, 8, skx_pdf_text('SakuKu - Laporan Keuangan'), 0, 1, 'L');

        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(91, 107, 130);
        $this->Cell(0, 5, skx_pdf_text($this->reportMeta), 0, 1, 'L');

        $y = $this->GetY() + 2;
        $this->SetDrawColor(203, 213, 225);
        $this->Line($this->lMargin, $y, $this->w - $this->rMargin, $y);
        $this->Ln(6);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(120, 130, 145);
        $this->Cell(120, 10, skx_pdf_text('Dokumen ini dibuat otomatis oleh SakuKu.'), 0, 0, 'L');
        $this->Cell(0, 10, 'Halaman ' . $this->PageNo(), 0, 0, 'R');
    }
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$available = available_balance($pdo, $userId);
$pdf = new SakuKu_Report_PDF('P', 'mm', 'A4');
$pdf->reportMeta = 'Dibuat untuk: ' . $userName . ' | Periode: ' . $periodLabel;
$pdf->SetTitle(skx_pdf_text('SakuKu - Laporan Keuangan'));
$pdf->SetAuthor(skx_pdf_text($userName));
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

$pdf->SetFont('Helvetica', 'B', 13);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(0, 8, skx_pdf_text($typeRaw === 'personal' ? 'Laporan Transaksi Pribadi' : 'Laporan Patungan Grup'), 0, 1, 'L');
$pdf->SetFont('Helvetica', '', 9);
$pdf->SetTextColor(91, 107, 130);
$pdf->Cell(0, 5, skx_pdf_text('Periode: ' . $periodLabel . ' | Akun: ' . $userName), 0, 1, 'L');
$pdf->Ln(3);

if ($typeRaw === 'personal') {
    $summary = 'Pemasukan: ' . format_rupiah($income)
        . '   Pengeluaran: ' . format_rupiah($expense)
        . '   Saldo periode: ' . format_rupiah($saldo)
        . '   Saldo tersedia: ' . format_rupiah($available);
    $widths = [28, 24, 40, 30, 68];
    $headers = ['Tanggal', 'Jenis', 'Kategori', 'Nominal', 'Catatan'];
    $aligns = ['L', 'L', 'L', 'R', 'L'];
} else {
    $summary = 'Total pengeluaran grup: ' . format_rupiah($groupTotal)
        . '   Saldo tersedia: ' . format_rupiah($available);
    $widths = [25, 34, 45, 30, 28, 28];
    $headers = ['Tanggal', 'Grup', 'Judul', 'Dibayar Oleh', 'Total', 'Status'];
    $aligns = ['L', 'L', 'L', 'L', 'R', 'L'];
}

$pdf->SetFillColor(240, 244, 250);
$pdf->SetTextColor(30, 41, 59);
$pdf->SetFont('Helvetica', '', 9);
$pdf->MultiCell(0, 7, skx_pdf_text($summary), 1, 'L', true);
$pdf->Ln(4);

$drawTableHeader = static function () use ($pdf, $widths, $headers, $aligns): void {
    $pdf->SetFillColor(30, 64, 175);
    $pdf->SetTextColor(255, 255, 255);
    skx_pdf_table_row($pdf, $widths, $headers, $aligns, true, true);
    $pdf->SetTextColor(30, 41, 59);
};

$drawTableHeader();
$pdf->SetTextColor(30, 41, 59);

foreach (($typeRaw === 'personal' ? $personalRows : $groupRows) as $row) {
    if ($typeRaw === 'personal') {
        $cells = [
            $row['date'], $row['type'], $row['category'], format_rupiah($row['amount']), $row['note'],
        ];
    } else {
        $cells = [
            $row['date'], $row['group'], $row['title'], $row['paid_by'], format_rupiah($row['amount']), $row['status'],
        ];
    }
    skx_pdf_table_row($pdf, $widths, $cells, $aligns, false, false, $drawTableHeader);
    $pdf->SetTextColor(30, 41, 59);
}

$pdf->SetFillColor(240, 244, 250);
$totalCells = $typeRaw === 'personal'
    ? ['TOTAL', '', '', format_rupiah($saldo), '']
    : ['TOTAL', '', '', '', format_rupiah($groupTotal), ''];
skx_pdf_table_row($pdf, $widths, $totalCells, $aligns, false, true);

$pdf->Output('D', $filename);
exit;
