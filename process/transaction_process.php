<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/navbar.php';

require_post();
verify_csrf();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$fallbackMonth = current_month();
$requestedMonth = (string) ($_POST['month'] ?? $fallbackMonth);
$month = tx_valid_month($requestedMonth) ? $requestedMonth : $fallbackMonth;

function tx_valid_month(string $value): bool
{
    return preg_match('/^\d{4}-\d{2}$/', $value) === 1
        && checkdate((int) substr($value, 5, 2), 1, (int) substr($value, 0, 4));
}

function tx_valid_date(string $value): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
        && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
}

function tx_fail(string $message, string $month): never
{
    set_flash('error', $message);
    redirect('pages/transactions.php?month=' . rawurlencode($month));
}

function tx_redirect(string $month, string $returnTo = 'transactions'): never
{
    $page = $returnTo === 'dashboard' ? 'pages/dashboard.php' : 'pages/transactions.php';
    redirect($page . '?month=' . rawurlencode($month));
}

/** Validate shared create/update fields and return normalized values. */
function tx_read_payload(PDO $pdo, int $userId, string $month): array
{
    $type = (string) ($_POST['type'] ?? '');
    if (!in_array($type, ['income', 'expense'], true)) {
        tx_fail('Jenis transaksi tidak valid.', $month);
    }

    $amountRaw = (string) ($_POST['amount'] ?? '');
    if (str_contains($amountRaw, '-') || str_contains($amountRaw, '(')) {
        tx_fail('Nominal tidak boleh negatif.', $month);
    }
    $amount = parse_money($amountRaw);
    if ($amount <= 0) {
        tx_fail('Nominal harus lebih dari Rp0.', $month);
    }

    $categoryId = (int) ($_POST['category_id'] ?? 0);
    $category = $categoryId > 0 ? get_category($pdo, $categoryId, $userId) : null;
    if ($category === null || (string) $category['type'] !== $type) {
        tx_fail('Kategori tidak valid untuk jenis transaksi ini.', $month);
    }

    $date = trim((string) ($_POST['transaction_date'] ?? ''));
    if (!tx_valid_date($date)) {
        tx_fail('Tanggal transaksi tidak valid.', $month);
    }

    $note = trim((string) ($_POST['note'] ?? ''));
    $noteLength = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);
    if ($noteLength > 255) {
        tx_fail('Catatan maksimal 255 karakter.', $month);
    }

    return [
        'type'        => $type,
        'amount'      => $amount,
        'category_id' => $categoryId,
        'category'    => $category,
        'date'        => $date,
        'note'        => $note,
    ];
}

/** PRD Alur A: flash warning/over and store the dashboard banner payload. */
function tx_budget_notice(PDO $pdo, int $userId, array $category, string $month, string $type): void
{
    if ($type !== 'expense') {
        return;
    }

    $status = budget_status($pdo, $userId, (int) $category['id'], $month);
    if (!in_array($status['status'], ['warning', 'over'], true)) {
        return;
    }

    $name = (string) $category['name'];
    if ($status['status'] === 'warning') {
        $message = 'Pengeluaran ' . $name . ' mencapai ' . $status['percent'] . '% dari batas. Sisa ' . format_rupiah($status['remaining']) . '.';
        set_flash('warning', $message);
    } else {
        $message = 'Pengeluaran ' . $name . ' melebihi batas sebesar ' . format_rupiah($status['overage']) . ' (' . $status['percent'] . '%).';
        set_flash('error', $message);
    }

    $_SESSION['budget_alert'] = [
        'category'  => $name,
        'status'    => $status['status'],
        'percent'   => $status['percent'],
        'remaining' => $status['remaining'],
        'overage'   => $status['overage'],
        'message'   => $message,
    ];
}

switch ($action) {
    case 'create':
        $data = tx_read_payload($pdo, $userId, $month);
        $stmt = $pdo->prepare(
            'INSERT INTO transactions (user_id, category_id, type, amount, transaction_date, note)
             VALUES (:user_id, :category_id, :type, :amount, :transaction_date, :note)'
        );
        $stmt->execute([
            ':user_id'          => $userId,
            ':category_id'      => $data['category_id'],
            ':type'             => $data['type'],
            ':amount'           => $data['amount'],
            ':transaction_date' => $data['date'],
            ':note'             => $data['note'] !== '' ? $data['note'] : null,
        ]);

        set_flash('success', 'Transaksi berhasil disimpan.');
        tx_budget_notice($pdo, $userId, $data['category'], substr($data['date'], 0, 7), $data['type']);
        tx_redirect(substr($data['date'], 0, 7), (string) ($_POST['return_to'] ?? 'transactions'));

    case 'update':
        $id = (int) ($_POST['id'] ?? 0);
        $ownerStmt = $pdo->prepare('SELECT id FROM transactions WHERE id = :id AND user_id = :user_id LIMIT 1');
        $ownerStmt->execute([':id' => $id, ':user_id' => $userId]);
        if ($ownerStmt->fetch() === false) {
            tx_fail('Transaksi tidak ditemukan.', $month);
        }

        $data = tx_read_payload($pdo, $userId, $month);
        $stmt = $pdo->prepare(
            'UPDATE transactions
             SET category_id = :category_id, type = :type, amount = :amount,
                 transaction_date = :transaction_date, note = :note
             WHERE id = :id AND user_id = :user_id'
        );
        $stmt->execute([
            ':category_id'      => $data['category_id'],
            ':type'             => $data['type'],
            ':amount'           => $data['amount'],
            ':transaction_date' => $data['date'],
            ':note'             => $data['note'] !== '' ? $data['note'] : null,
            ':id'               => $id,
            ':user_id'          => $userId,
        ]);

        set_flash('success', 'Transaksi berhasil diperbarui.');
        tx_budget_notice($pdo, $userId, $data['category'], substr($data['date'], 0, 7), $data['type']);
        tx_redirect(substr($data['date'], 0, 7));

    case 'delete':
        $id = (int) ($_POST['id'] ?? 0);
        $findStmt = $pdo->prepare(
            "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS tx_month
             FROM transactions WHERE id = :id AND user_id = :user_id LIMIT 1"
        );
        $findStmt->execute([':id' => $id, ':user_id' => $userId]);
        $row = $findStmt->fetch();
        $targetMonth = $row ? (string) $row['tx_month'] : $month;

        $stmt = $pdo->prepare('DELETE FROM transactions WHERE id = :id AND user_id = :user_id');
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
        if ($stmt->rowCount() === 0) {
            set_flash('error', 'Transaksi tidak ditemukan.');
        } else {
            set_flash('success', 'Transaksi berhasil dihapus.');
        }
        tx_redirect($targetMonth);

    case 'create_category':
        $name = trim((string) ($_POST['name'] ?? ''));
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($name === '' || $nameLength > 50) {
            tx_fail('Nama kategori wajib diisi dan maksimal 50 karakter.', $month);
        }

        $type = (string) ($_POST['type'] ?? '');
        if (!in_array($type, ['income', 'expense'], true)) {
            tx_fail('Jenis kategori tidak valid.', $month);
        }

        $icon = (string) ($_POST['icon'] ?? 'circle');
        if (!preg_match('/^[a-z-]+$/', $icon)) {
            $icon = 'circle';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO categories (user_id, name, type, icon) VALUES (:user_id, :name, :type, :icon)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':name'    => $name,
            ':type'    => $type,
            ':icon'    => $icon,
        ]);

        set_flash('success', 'Kategori baru berhasil ditambahkan.');
        tx_redirect($month);

    default:
        tx_fail('Aksi transaksi tidak dikenali.', $month);
}
