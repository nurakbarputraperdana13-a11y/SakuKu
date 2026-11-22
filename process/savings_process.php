<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/navbar.php';

require_post();
verify_csrf();

$userId = (int) ($_SESSION['user_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

function sv_fail(string $message): never
{
    set_flash('error', $message);
    redirect('pages/savings.php');
}

function sv_valid_date(string $value): bool
{
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
        && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
}

function sv_goal(PDO $pdo, int $userId, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, title, target_amount, current_amount, deadline, status
         FROM savings_goals WHERE id = :id AND user_id = :user_id LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':user_id' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

switch ($action) {
    case 'create':
        $title = trim((string) ($_POST['title'] ?? ''));
        $titleLength = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
        if ($title === '' || $titleLength > 100) {
            sv_fail('Nama target wajib diisi dan maksimal 100 karakter.');
        }

        $target = parse_money($_POST['target_amount'] ?? '');
        if ($target <= 0) {
            sv_fail('Target nominal harus lebih dari Rp0.');
        }

        $initialRaw = (string) ($_POST['initial_amount'] ?? '0');
        if (str_contains($initialRaw, '-') || str_contains($initialRaw, '(')) {
            sv_fail('Nominal awal tidak boleh negatif.');
        }
        $initial = parse_money($initialRaw);
        if ($initial > $target) {
            sv_fail('Nominal awal tidak boleh melebihi target tabungan.');
        }

        $available = available_balance($pdo, $userId);
        if ($initial > $available) {
            sv_fail('Nominal awal melebihi saldo tersedia (' . format_rupiah($available) . ').');
        }

        $deadline = trim((string) ($_POST['deadline'] ?? ''));
        if ($deadline !== '' && !sv_valid_date($deadline)) {
            sv_fail('Tenggat waktu tidak valid.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO savings_goals (user_id, title, target_amount, current_amount, deadline, status)
             VALUES (:user_id, :title, :target_amount, :current_amount, :deadline, :status)'
        );
        $stmt->execute([
            ':user_id'        => $userId,
            ':title'          => $title,
            ':target_amount'  => $target,
            ':current_amount' => $initial,
            ':deadline'       => $deadline !== '' ? $deadline : null,
            ':status'         => 'active',
        ]);

        set_flash('success', 'Target tabungan "' . $title . '" berhasil dibuat.');
        redirect('pages/savings.php');

    case 'topup':
        $goalId = (int) ($_POST['id'] ?? 0);
        $amountRaw = (string) ($_POST['amount'] ?? '');
        if (str_contains($amountRaw, '-') || str_contains($amountRaw, '(')) {
            sv_fail('Nominal setor tidak boleh negatif.');
        }
        $amount = parse_money($amountRaw);
        if ($amount <= 0) {
            sv_fail('Nominal setor harus lebih dari Rp0.');
        }

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
            $lock->execute([':id' => $userId]);
            if ($lock->fetch() === false) {
                $pdo->rollBack();
                sv_fail('Akun tidak ditemukan.');
            }

            $goal = sv_goal($pdo, $userId, $goalId);
            if ($goal === null || (string) $goal['status'] !== 'active') {
                $pdo->rollBack();
                sv_fail('Target tabungan tidak ditemukan atau sudah selesai.');
            }

            $available = available_balance($pdo, $userId);
            if ($amount > $available) {
                $pdo->rollBack();
                sv_fail('Setor melebihi saldo tersedia (' . format_rupiah($available) . ').');
            }

            $target = (int) $goal['target_amount'];
            if ((int) $goal['current_amount'] + $amount > $target) {
                $pdo->rollBack();
                sv_fail('Setor membuat dana melebihi target (' . format_rupiah($target) . ').');
            }

            $stmt = $pdo->prepare(
                'UPDATE savings_goals SET current_amount = current_amount + :amount
                 WHERE id = :id AND user_id = :user_id AND status = :status'
            );
            $stmt->execute([
                ':amount'   => $amount,
                ':id'       => $goalId,
                ':user_id'  => $userId,
                ':status'   => 'active',
            ]);
            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                sv_fail('Target tabungan berubah. Coba lagi.');
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        set_flash('success', format_rupiah($amount) . ' disetor ke "' . (string) $goal['title'] . '". Saldo tersedia berkurang.');
        redirect('pages/savings.php');

    case 'withdraw':
        $goalId = (int) ($_POST['id'] ?? 0);
        $goal = sv_goal($pdo, $userId, $goalId);
        if ($goal === null || (string) $goal['status'] !== 'active') {
            sv_fail('Target tabungan tidak ditemukan atau sudah selesai.');
        }

        $amountRaw = (string) ($_POST['amount'] ?? '');
        if (str_contains($amountRaw, '-') || str_contains($amountRaw, '(')) {
            sv_fail('Nominal tarik tidak boleh negatif.');
        }
        $amount = parse_money($amountRaw);
        if ($amount <= 0) {
            sv_fail('Nominal tarik harus lebih dari Rp0.');
        }

        $stmt = $pdo->prepare(
            'UPDATE savings_goals SET current_amount = current_amount - :amount
             WHERE id = :id AND user_id = :user_id AND status = :status
               AND current_amount >= :min_amount'
        );
        $stmt->execute([
            ':amount'    => $amount,
            ':min_amount' => $amount,
            ':id'        => $goalId,
            ':user_id'   => $userId,
            ':status'    => 'active',
        ]);
        if ($stmt->rowCount() === 0) {
            sv_fail('Tarik melebihi dana yang terkumpul (' . format_rupiah((int) $goal['current_amount']) . ').');
        }

        set_flash('success', format_rupiah($amount) . ' ditarik dari "' . (string) $goal['title'] . '". Saldo tersedia bertambah.');
        redirect('pages/savings.php');

    case 'complete':
        $goal = sv_goal($pdo, $userId, (int) ($_POST['id'] ?? 0));
        if ($goal === null) {
            sv_fail('Target tabungan tidak ditemukan.');
        }

        $stmt = $pdo->prepare("UPDATE savings_goals SET status = 'completed' WHERE id = :id AND user_id = :user_id");
        $stmt->execute([':id' => (int) $goal['id'], ':user_id' => $userId]);

        set_flash('success', 'Target "' . (string) $goal['title'] . '" ditandai selesai.');
        redirect('pages/savings.php');

    case 'delete':
        $goal = sv_goal($pdo, $userId, (int) ($_POST['id'] ?? 0));
        if ($goal === null) {
            sv_fail('Target tabungan tidak ditemukan.');
        }

        $stmt = $pdo->prepare('DELETE FROM savings_goals WHERE id = :id AND user_id = :user_id');
        $stmt->execute([':id' => (int) $goal['id'], ':user_id' => $userId]);

        set_flash('success', 'Target "' . (string) $goal['title'] . '" dihapus. Dana kembali ke saldo tersedia.');
        redirect('pages/savings.php');

    default:
        sv_fail('Aksi tabungan tidak dikenali.');
}
