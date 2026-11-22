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
$month = budget_valid_month($requestedMonth) ? $requestedMonth : $fallbackMonth;

function budget_valid_month(string $value): bool
{
    return preg_match('/^\d{4}-\d{2}$/', $value) === 1
        && checkdate((int) substr($value, 5, 2), 1, (int) substr($value, 0, 4));
}

function budget_fail(string $message, string $month): never
{
    set_flash('error', $message);
    redirect('pages/budgets.php?month=' . rawurlencode($month));
}

if ($action !== 'save') {
    budget_fail('Aksi budget tidak dikenali.', $month);
}

$limits = $_POST['limits'] ?? [];
if (!is_array($limits) || $limits === []) {
    budget_fail('Tidak ada batas budget yang dikirim.', $month);
}

$validated = [];
foreach ($limits as $categoryKey => $rawLimit) {
    $categoryKey = (string) $categoryKey;
    if (!ctype_digit($categoryKey) || (string) (int) $categoryKey !== $categoryKey) {
        budget_fail('Kategori budget tidak valid.', $month);
    }

    $categoryId = (int) $categoryKey;
    $category = get_category($pdo, $categoryId, $userId);
    if ($category === null || (string) $category['type'] !== 'expense') {
        budget_fail('Kategori budget tidak valid.', $month);
    }

    $rawLimit = trim((string) $rawLimit);
    if ($rawLimit === '') {
        $validated[] = ['category_id' => $categoryId, 'limit' => 0];
        continue;
    }

    if (str_contains($rawLimit, '-') || str_contains($rawLimit, '(')) {
        budget_fail('Batas budget tidak boleh negatif.', $month);
    }
    $limit = parse_money($rawLimit);
    if ($limit <= 0) {
        budget_fail('Batas budget ' . (string) $category['name'] . ' harus lebih dari Rp0.', $month);
    }

    $validated[] = ['category_id' => $categoryId, 'limit' => $limit];
}

$saved = 0;
$removed = 0;

try {
    $pdo->beginTransaction();

    $upsert = $pdo->prepare(
        'INSERT INTO budgets (user_id, category_id, amount_limit, month_year)
         VALUES (:user_id, :category_id, :amount_limit, :month_year)
         ON DUPLICATE KEY UPDATE amount_limit = VALUES(amount_limit)'
    );
    $remove = $pdo->prepare(
        'DELETE FROM budgets WHERE user_id = :user_id AND category_id = :category_id AND month_year = :month_year'
    );

    foreach ($validated as $item) {
        $params = [
            ':user_id'     => $userId,
            ':category_id' => $item['category_id'],
            ':month_year'  => $month,
        ];
        if ($item['limit'] > 0) {
            $params[':amount_limit'] = $item['limit'];
            $upsert->execute($params);
            $saved++;
        } else {
            $remove->execute($params);
            $removed++;
        }
    }

    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    budget_fail('Gagal menyimpan budget. Coba lagi.', $month);
}

$message = $saved > 0 ? $saved . ' batas budget berhasil disimpan.' : 'Tidak ada batas baru yang disimpan.';
if ($removed > 0) {
    $message .= ' ' . $removed . ' batas dihapus.';
}
set_flash('success', $message);
redirect('pages/budgets.php?month=' . rawurlencode($month));
