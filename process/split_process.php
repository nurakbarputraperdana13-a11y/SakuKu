<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_post();
verify_csrf();

function sp_positive_int(mixed $raw): int
{
    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? 0 : (int) $value;
}

function sp_money(mixed $raw): int
{
    if (is_int($raw)) {
        return $raw;
    }
    if (!is_string($raw) && !is_numeric($raw)) {
        return 0;
    }

    $text = trim((string) $raw);
    if ($text === '' || preg_match('/[^0-9.,\s]/', $text) === 1) {
        return 0;
    }

    $digits = preg_replace('/\D+/', '', $text) ?? '';
    return $digits === '' ? 0 : (int) $digits;
}

function sp_date(mixed $raw): ?string
{
    $text = trim((string) $raw);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $parts) !== 1) {
        return null;
    }
    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $text : null;
}

function sp_group_redirect(int $groupId): never
{
    redirect('pages/group_detail.php?id=' . $groupId);
}

function sp_membership_or_redirect(PDO $pdo, int $groupId, int $userId): array
{
    $membership = group_membership($pdo, $groupId, $userId);
    if ($membership === null) {
        set_flash('error', 'Grup tidak ditemukan atau Anda bukan anggota grup ini.');
        redirect('pages/groups.php');
    }
    return $membership;
}

function sp_is_group_member(PDO $pdo, int $groupId, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = :group_id AND user_id = :user_id LIMIT 1');
    $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
    return $stmt->fetchColumn() !== false;
}

function sp_load_expense(PDO $pdo, int $groupId, int $expenseId): ?array
{
    if ($expenseId === 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, group_id, paid_by_user_id, title, total_amount, expense_date
         FROM group_expenses
         WHERE id = :id AND group_id = :group_id LIMIT 1'
    );
    $stmt->execute([':id' => $expenseId, ':group_id' => $groupId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @param list<int> $participants
 * @return array{0: array<int, int>, 1: string}
 */
function sp_calculate_amounts(int $total, array $participants, string $mode, mixed $rawAmounts): array
{
    if ($mode === 'equal') {
        return [split_equal_amounts($total, $participants), ''];
    }

    $amounts = $rawAmounts;
    if (!is_array($amounts)) {
        return [[], 'Nominal per peserta tidak valid.'];
    }

    $result = [];
    $sum = 0;
    foreach ($participants as $userId) {
        if (!array_key_exists($userId, $amounts) && !array_key_exists((string) $userId, $amounts)) {
            return [[], 'Nominal untuk setiap peserta wajib diisi.'];
        }
        $raw = array_key_exists($userId, $amounts) ? $amounts[$userId] : $amounts[(string) $userId];
        $amount = sp_money($raw);
        if ($amount <= 0) {
            return [[], 'Setiap nominal peserta harus lebih dari Rp0.'];
        }
        $result[$userId] = $amount;
        $sum += $amount;
    }

    if ($sum !== $total) {
        return [[], 'Jumlah nominal peserta (' . format_rupiah($sum) . ') harus sama dengan total (' . format_rupiah($total) . ').'];
    }

    return [$result, ''];
}

/** @param array<int, int> $amounts */
function sp_insert_splits(PDO $pdo, int $expenseId, int $payerId, array $participants, array $amounts): void
{
    $ownStmt = $pdo->prepare(
        'INSERT INTO expense_splits (group_expense_id, user_id, owed_amount, is_settled, settled_at)
         VALUES (:expense_id, :user_id, :amount, 1, NOW())'
    );
    $debtStmt = $pdo->prepare(
        'INSERT INTO expense_splits (group_expense_id, user_id, owed_amount, is_settled, settled_at)
         VALUES (:expense_id, :user_id, :amount, 0, NULL)'
    );

    foreach ($participants as $userId) {
        $params = [
            ':expense_id' => $expenseId,
            ':user_id'    => $userId,
            ':amount'     => $amounts[$userId],
        ];
        if ($userId === $payerId) {
            $ownStmt->execute($params);
        } else {
            $debtStmt->execute($params);
        }
    }
}

/**
 * @return array{0: list<int>, 1: string}
 */
function sp_participants_from_post(mixed $raw): array
{
    if (!is_array($raw)) {
        return [[], 'Peserta tidak valid.'];
    }

    $participants = [];
    foreach ($raw as $item) {
        $userId = sp_positive_int($item);
        if ($userId > 0 && !in_array($userId, $participants, true)) {
            $participants[] = $userId;
        }
    }

    if ($participants === []) {
        return [[], 'Pilih minimal satu peserta.'];
    }

    return [$participants, ''];
}

function sp_all_members(PDO $pdo, int $groupId, array $userIds): bool
{
    $stmt = $pdo->prepare('SELECT user_id FROM group_members WHERE group_id = :group_id');
    $stmt->execute([':group_id' => $groupId]);
    $memberIds = [];
    foreach ($stmt->fetchAll() as $row) {
        $memberIds[(int) $row['user_id']] = true;
    }

    foreach ($userIds as $userId) {
        if (!isset($memberIds[$userId])) {
            return false;
        }
    }
    return true;
}

$userId = (int) $currentUser['id'];
$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    case 'create_expense':
        $groupId = sp_positive_int($_POST['group_id'] ?? 0);
        if ($groupId === 0) {
            set_flash('error', 'Grup tidak ditemukan.');
            redirect('pages/groups.php');
        }
        $membership = sp_membership_or_redirect($pdo, $groupId, $userId);
        $isAdmin = ($membership['role'] ?? '') === 'admin';

        $title = trim((string) ($_POST['title'] ?? ''));
        $total = sp_money($_POST['total_amount'] ?? 0);
        $expenseDate = sp_date($_POST['expense_date'] ?? '');
        $payerId = sp_positive_int($_POST['paid_by_user_id'] ?? 0);
        $mode = (string) ($_POST['mode'] ?? 'equal');

        if ($title === '' || mb_strlen($title) > 100) {
            set_flash('error', 'Judul pengeluaran wajib diisi dan maksimal 100 karakter.');
            sp_group_redirect($groupId);
        }
        if ($total <= 0) {
            set_flash('error', 'Total pengeluaran harus lebih dari Rp0.');
            sp_group_redirect($groupId);
        }
        if ($expenseDate === null) {
            set_flash('error', 'Tanggal pengeluaran tidak valid.');
            sp_group_redirect($groupId);
        }
        if ($payerId === 0 || !sp_is_group_member($pdo, $groupId, $payerId)) {
            set_flash('error', 'Pembayar harus merupakan anggota grup.');
            sp_group_redirect($groupId);
        }
        if (!$isAdmin && $payerId !== $userId) {
            set_flash('error', 'Anda hanya bisa mencatat pengeluaran yang Anda bayar sendiri.');
            sp_group_redirect($groupId);
        }
        if (!in_array($mode, ['equal', 'exact'], true)) {
            set_flash('error', 'Mode pembagian tidak valid.');
            sp_group_redirect($groupId);
        }

        [$participants, $participantError] = sp_participants_from_post($_POST['participants'] ?? []);
        if ($participantError !== '') {
            set_flash('error', $participantError);
            sp_group_redirect($groupId);
        }
        if (!sp_all_members($pdo, $groupId, $participants)) {
            set_flash('error', 'Semua peserta harus merupakan anggota grup.');
            sp_group_redirect($groupId);
        }

        [$amounts, $amountError] = sp_calculate_amounts($total, $participants, $mode, $_POST['amounts'] ?? []);
        if ($amountError !== '') {
            set_flash('error', $amountError);
            sp_group_redirect($groupId);
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'INSERT INTO group_expenses (group_id, paid_by_user_id, title, total_amount, expense_date)
                 VALUES (:group_id, :payer_id, :title, :total, :expense_date)'
            );
            $stmt->execute([
                ':group_id'    => $groupId,
                ':payer_id'    => $payerId,
                ':title'       => $title,
                ':total'       => $total,
                ':expense_date'=> $expenseDate,
            ]);
            $expenseId = (int) $pdo->lastInsertId();

            sp_insert_splits($pdo, $expenseId, $payerId, $participants, $amounts);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        set_flash('success', 'Pengeluaran grup berhasil disimpan.');
        sp_group_redirect($groupId);

    case 'update_expense':
        $groupId = sp_positive_int($_POST['group_id'] ?? 0);
        $expenseId = sp_positive_int($_POST['expense_id'] ?? 0);

        if ($groupId === 0) {
            set_flash('error', 'Grup tidak ditemukan.');
            redirect('pages/groups.php');
        }
        $membership = sp_membership_or_redirect($pdo, $groupId, $userId);
        $expense = sp_load_expense($pdo, $groupId, $expenseId);
        if ($expense === null) {
            set_flash('error', 'Pengeluaran tidak ditemukan.');
            sp_group_redirect($groupId);
        }

        $isAdmin = ($membership['role'] ?? '') === 'admin';
        $existingPayerId = (int) $expense['paid_by_user_id'];
        if (!$isAdmin && $existingPayerId !== $userId) {
            set_flash('error', 'Anda hanya bisa mengubah pengeluaran yang Anda bayar.');
            sp_group_redirect($groupId);
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $total = sp_money($_POST['total_amount'] ?? 0);
        $expenseDate = sp_date($_POST['expense_date'] ?? '');
        $mode = (string) ($_POST['mode'] ?? 'equal');

        if ($isAdmin) {
            $payerId = sp_positive_int($_POST['paid_by_user_id'] ?? 0);
            if ($payerId === 0 || !sp_is_group_member($pdo, $groupId, $payerId)) {
                set_flash('error', 'Pembayar harus merupakan anggota grup.');
                sp_group_redirect($groupId);
            }
        } else {
            $payerId = $existingPayerId;
            if (!sp_is_group_member($pdo, $groupId, $payerId)) {
                set_flash('error', 'Pembayar lama bukan lagi anggota grup. Minta admin mengubah pembayar.');
                sp_group_redirect($groupId);
            }
        }

        if ($title === '' || mb_strlen($title) > 100) {
            set_flash('error', 'Judul pengeluaran wajib diisi dan maksimal 100 karakter.');
            sp_group_redirect($groupId);
        }
        if ($total <= 0) {
            set_flash('error', 'Total pengeluaran harus lebih dari Rp0.');
            sp_group_redirect($groupId);
        }
        if ($expenseDate === null) {
            set_flash('error', 'Tanggal pengeluaran tidak valid.');
            sp_group_redirect($groupId);
        }
        if (!in_array($mode, ['equal', 'exact'], true)) {
            set_flash('error', 'Mode pembagian tidak valid.');
            sp_group_redirect($groupId);
        }

        [$participants, $participantError] = sp_participants_from_post($_POST['participants'] ?? []);
        if ($participantError !== '') {
            set_flash('error', $participantError);
            sp_group_redirect($groupId);
        }
        if (!sp_all_members($pdo, $groupId, $participants)) {
            set_flash('error', 'Semua peserta harus merupakan anggota grup.');
            sp_group_redirect($groupId);
        }

        [$amounts, $amountError] = sp_calculate_amounts($total, $participants, $mode, $_POST['amounts'] ?? []);
        if ($amountError !== '') {
            set_flash('error', $amountError);
            sp_group_redirect($groupId);
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE group_expenses
                 SET paid_by_user_id = :payer_id, title = :title, total_amount = :total, expense_date = :expense_date
                 WHERE id = :id AND group_id = :group_id'
            );
            $stmt->execute([
                ':payer_id'     => $payerId,
                ':title'        => $title,
                ':total'        => $total,
                ':expense_date' => $expenseDate,
                ':id'           => $expenseId,
                ':group_id'     => $groupId,
            ]);

            $stmt = $pdo->prepare('DELETE FROM expense_splits WHERE group_expense_id = :expense_id');
            $stmt->execute([':expense_id' => $expenseId]);

            sp_insert_splits($pdo, $expenseId, $payerId, $participants, $amounts);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        set_flash('success', 'Pengeluaran berhasil diperbarui.');
        sp_group_redirect($groupId);

    case 'delete_expense':
        $groupId = sp_positive_int($_POST['group_id'] ?? 0);
        $expenseId = sp_positive_int($_POST['expense_id'] ?? 0);

        if ($groupId === 0) {
            set_flash('error', 'Grup tidak ditemukan.');
            redirect('pages/groups.php');
        }
        $membership = sp_membership_or_redirect($pdo, $groupId, $userId);
        $expense = sp_load_expense($pdo, $groupId, $expenseId);
        if ($expense === null) {
            set_flash('error', 'Pengeluaran tidak ditemukan.');
            sp_group_redirect($groupId);
        }

        $isAdmin = ($membership['role'] ?? '') === 'admin';
        if (!$isAdmin && (int) $expense['paid_by_user_id'] !== $userId) {
            set_flash('error', 'Anda hanya bisa menghapus pengeluaran yang Anda bayar.');
            sp_group_redirect($groupId);
        }

        $stmt = $pdo->prepare('DELETE FROM group_expenses WHERE id = :id AND group_id = :group_id');
        $stmt->execute([':id' => $expenseId, ':group_id' => $groupId]);

        set_flash('success', 'Pengeluaran berhasil dihapus.');
        sp_group_redirect($groupId);

    case 'settle':
        $groupId = sp_positive_int($_POST['group_id'] ?? 0);
        $fromId = sp_positive_int($_POST['from_user_id'] ?? 0);
        $toId = sp_positive_int($_POST['to_user_id'] ?? 0);
        $amount = sp_money($_POST['amount'] ?? 0);

        if ($groupId === 0) {
            set_flash('error', 'Grup tidak ditemukan.');
            redirect('pages/groups.php');
        }
        $membership = sp_membership_or_redirect($pdo, $groupId, $userId);

        if ($fromId === 0 || $toId === 0 || $fromId === $toId || $amount <= 0) {
            set_flash('error', 'Data pelunasan tidak valid.');
            sp_group_redirect($groupId);
        }

        $isAdmin = ($membership['role'] ?? '') === 'admin';
        if (!$isAdmin && $fromId !== $userId && $toId !== $userId) {
            set_flash('error', 'Anda hanya bisa melunasi utang yang melibatkan diri sendiri.');
            sp_group_redirect($groupId);
        }

        $transfers = simplify_debts(group_net_balances($pdo, $groupId));
        $matched = false;
        foreach ($transfers as $transfer) {
            if ($transfer['from'] === $fromId && $transfer['to'] === $toId && $transfer['amount'] === $amount) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            set_flash('error', 'Transfer pelunasan sudah tidak berlaku. Muat ulang halaman.');
            sp_group_redirect($groupId);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT es.id, es.owed_amount
                 FROM expense_splits es
                 JOIN group_expenses ge ON ge.id = es.group_expense_id
                 WHERE ge.group_id = :group_id
                   AND es.user_id = :debtor_id
                   AND ge.paid_by_user_id = :creditor_id
                   AND es.is_settled = 0
                 ORDER BY es.id ASC'
            );
            $stmt->execute([
                ':group_id'    => $groupId,
                ':debtor_id'   => $fromId,
                ':creditor_id' => $toId,
            ]);
            $splits = $stmt->fetchAll();

            $settle = $pdo->prepare(
                'UPDATE expense_splits SET is_settled = 1, settled_at = NOW()
                 WHERE id = :id AND is_settled = 0'
            );
            $reduce = $pdo->prepare(
                'UPDATE expense_splits SET owed_amount = owed_amount - :paid
                 WHERE id = :id AND is_settled = 0'
            );

            $remaining = $amount;
            $affected = 0;
            foreach ($splits as $split) {
                if ($remaining <= 0) {
                    break;
                }
                $owed = (int) $split['owed_amount'];
                if ($owed <= $remaining) {
                    $settle->execute([':id' => $split['id']]);
                    $remaining -= $owed;
                } else {
                    $reduce->execute([':paid' => $remaining, ':id' => $split['id']]);
                    $remaining = 0;
                }
                $affected++;
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        if ($affected === 0) {
            set_flash('info', 'Tidak ada tagihan yang perlu dilunasi untuk pasangan ini.');
        } else {
            set_flash('success', 'Transfer berhasil ditandai lunas.');
        }
        sp_group_redirect($groupId);
}

set_flash('error', 'Aksi split bill tidak dikenal.');
redirect('pages/groups.php');
