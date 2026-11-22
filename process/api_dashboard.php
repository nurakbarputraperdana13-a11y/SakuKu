<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Metode tidak diizinkan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$monthRaw = isset($_GET['month']) && is_string($_GET['month']) ? trim($_GET['month']) : '';
$month = $monthRaw === '' ? current_month() : $monthRaw;

if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    http_response_code(400);
    echo json_encode(['error' => 'Format bulan tidak valid.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$year = (int) substr($month, 0, 4);
$monthNumber = (int) substr($month, 5, 2);
if (!checkdate($monthNumber, 1, $year)) {
    http_response_code(400);
    echo json_encode(['error' => 'Bulan tidak valid.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) $currentUser['id'];

$category = [];
foreach (expense_by_category($pdo, $userId, $month) as $row) {
    $category[] = [
        'label' => (string) ($row['label'] ?? 'Lainnya'),
        'total' => (int) ($row['total'] ?? 0),
        'icon'  => (string) ($row['icon'] ?? 'circle'),
    ];
}

$trend = [];
foreach (daily_totals($pdo, $userId, $month) as $row) {
    $day = (string) ($row['day'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
        continue;
    }
    $trend[] = [
        'day'    => $day,
        'income' => (int) ($row['income'] ?? 0),
        'expense' => (int) ($row['expense'] ?? 0),
    ];
}

echo json_encode(
    [
        'month'    => $month,
        'category' => $category,
        'trend'    => $trend,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
