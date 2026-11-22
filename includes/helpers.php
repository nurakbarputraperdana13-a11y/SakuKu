<?php
declare(strict_types=1);

/** Shared helpers for SakuKu. Requires config/database.php to define $pdo and BASE_URL. */

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return (BASE_URL ?? '') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function require_post(): void
{
    if (!is_post()) {
        http_response_code(405);
        exit('Method not allowed.');
    }
}

function format_rupiah(int|float|string|null $amount): string
{
    return 'Rp' . number_format((int) $amount, 0, ',', '.');
}

/** Accepts "Rp50.000", "50000", "50,000" and returns a positive integer. */
function parse_money(mixed $raw): int
{
    if (is_int($raw)) {
        return $raw;
    }
    $text = trim((string) $raw);
    $negative = str_contains($text, '-') || str_contains($text, '(');
    $digits = preg_replace('/[^\d]/', '', $text) ?? '';
    if ($digits === '') {
        return 0;
    }
    return $negative ? -((int) $digits) : (int) $digits;
}

function current_month(): string
{
    return date('Y-m');
}

function get_categories(PDO $pdo, int $userId, ?string $type = null): array
{
    $sql = 'SELECT id, user_id, name, type, icon FROM categories
            WHERE (user_id IS NULL OR user_id = :user_id)';
    $params = [':user_id' => $userId];
    if ($type !== null && in_array($type, ['income', 'expense'], true)) {
        $sql .= ' AND type = :type';
        $params[':type'] = $type;
    }
    $sql .= ' ORDER BY user_id IS NOT NULL, type, name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_category(PDO $pdo, int $categoryId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, user_id, name, type, icon FROM categories
         WHERE id = :id AND (user_id IS NULL OR user_id = :user_id) LIMIT 1'
    );
    $stmt->execute([':id' => $categoryId, ':user_id' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Total income - total expense - money locked in active savings goals. */
function available_balance(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE -amount END), 0) AS cash
         FROM transactions WHERE user_id = :user_id"
    );
    $stmt->execute([':user_id' => $userId]);
    $cash = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(current_amount), 0) FROM savings_goals
         WHERE user_id = :user_id AND status = 'active'"
    );
    $stmt->execute([':user_id' => $userId]);
    $locked = (int) $stmt->fetchColumn();

    return $cash - $locked;
}

function month_totals(PDO $pdo, int $userId, string $month): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense
         FROM transactions
         WHERE user_id = :user_id AND DATE_FORMAT(transaction_date, '%Y-%m') = :month"
    );
    $stmt->execute([':user_id' => $userId, ':month' => $month]);
    $row = $stmt->fetch() ?: [];
    return [
        'income'  => (int) ($row['income'] ?? 0),
        'expense' => (int) ($row['expense'] ?? 0),
    ];
}

function expense_by_category(PDO $pdo, int $userId, string $month): array
{
    $stmt = $pdo->prepare(
        "SELECT c.name AS label, c.icon, COALESCE(SUM(t.amount), 0) AS total
         FROM transactions t
         JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = :user_id AND t.type = 'expense'
           AND DATE_FORMAT(t.transaction_date, '%Y-%m') = :month
         GROUP BY c.id, c.name, c.icon
         HAVING total > 0
         ORDER BY total DESC"
    );
    $stmt->execute([':user_id' => $userId, ':month' => $month]);
    return $stmt->fetchAll();
}

function daily_totals(PDO $pdo, int $userId, string $month): array
{
    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(transaction_date, '%Y-%m-%d') AS day,
            COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense
         FROM transactions
         WHERE user_id = :user_id AND DATE_FORMAT(transaction_date, '%Y-%m') = :month
         GROUP BY day ORDER BY day ASC"
    );
    $stmt->execute([':user_id' => $userId, ':month' => $month]);
    return $stmt->fetchAll();
}

/** Result keys: limit, spent, percent, remaining, overage, status. */
function budget_status(PDO $pdo, int $userId, int $categoryId, string $month): array
{
    $stmt = $pdo->prepare(
        'SELECT amount_limit FROM budgets
         WHERE user_id = :user_id AND category_id = :category_id AND month_year = :month LIMIT 1'
    );
    $stmt->execute([':user_id' => $userId, ':category_id' => $categoryId, ':month' => $month]);
    $limit = (int) $stmt->fetchColumn();

    if ($limit <= 0) {
        return ['limit' => 0, 'spent' => 0, 'percent' => 0, 'remaining' => 0, 'overage' => 0, 'status' => 'none'];
    }

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM transactions
         WHERE user_id = :user_id AND category_id = :category_id AND type = 'expense'
           AND DATE_FORMAT(transaction_date, '%Y-%m') = :month"
    );
    $stmt->execute([':user_id' => $userId, ':category_id' => $categoryId, ':month' => $month]);
    $spent = (int) $stmt->fetchColumn();

    $percent = (int) round($spent / $limit * 100);
    $status = $percent >= 100 ? 'over' : ($percent >= 80 ? 'warning' : 'safe');

    return [
        'limit'     => $limit,
        'spent'     => $spent,
        'percent'   => $percent,
        'remaining' => max(0, $limit - $spent),
        'overage'   => max(0, $spent - $limit),
        'status'    => $status,
    ];
}

function budget_alerts(PDO $pdo, int $userId, string $month): array
{
    $stmt = $pdo->prepare(
        'SELECT b.category_id, b.amount_limit FROM budgets b WHERE b.user_id = :user_id AND b.month_year = :month'
    );
    $stmt->execute([':user_id' => $userId, ':month' => $month]);
    $alerts = [];
    foreach ($stmt->fetchAll() as $budget) {
        $status = budget_status($pdo, $userId, (int) $budget['category_id'], $month);
        if (in_array($status['status'], ['warning', 'over'], true)) {
            $category = get_category($pdo, (int) $budget['category_id'], $userId);
            $alerts[] = [
                'category' => $category['name'] ?? 'Kategori',
                'status'   => $status['status'],
                'percent'  => $status['percent'],
                'overage'  => $status['overage'],
            ];
        }
    }
    usort($alerts, static fn(array $a, array $b): int => $b['percent'] <=> $a['percent']);
    return $alerts;
}

function generate_invite_code(int $length = 8): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }
    return $code;
}

/**
 * Split a Rupiah total evenly without losing a single Rupiah.
 * The remainder goes to the first participant in the given order.
 *
 * @param list<int> $userIds
 * @return array<int, int> keyed by user id
 */
function split_equal_amounts(int $total, array $userIds): array
{
    $count = count($userIds);
    if ($count === 0) {
        return [];
    }
    $base = intdiv($total, $count);
    $remainder = $total % $count;
    $result = [];
    foreach ($userIds as $index => $userId) {
        $result[$userId] = $base + ($index < $remainder ? 1 : 0);
    }
    return $result;
}

/** Net balance per group member: positive = receives money, negative = pays money. */
function group_net_balances(PDO $pdo, int $groupId): array
{
    $stmt = $pdo->prepare('SELECT user_id FROM group_members WHERE group_id = :group_id');
    $stmt->execute([':group_id' => $groupId]);
    $balances = [];
    foreach ($stmt->fetchAll() as $row) {
        $balances[(int) $row['user_id']] = 0;
    }

    $stmt = $pdo->prepare(
        'SELECT id, paid_by_user_id, total_amount FROM group_expenses WHERE group_id = :group_id'
    );
    $stmt->execute([':group_id' => $groupId]);
    $expenses = $stmt->fetchAll();

    $splitStmt = $pdo->prepare(
        'SELECT user_id, owed_amount, is_settled FROM expense_splits WHERE group_expense_id = :expense_id'
    );

    foreach ($expenses as $expense) {
        $expenseId = (int) $expense['id'];
        $payerId = (int) $expense['paid_by_user_id'];
        $splitStmt->execute([':expense_id' => $expenseId]);
        $splits = $splitStmt->fetchAll();

        $ownShare = 0;
        foreach ($splits as $split) {
            if ((int) $split['user_id'] === $payerId) {
                $ownShare = (int) $split['owed_amount'];
                break;
            }
        }

        $outstanding = 0;
        foreach ($splits as $split) {
            $userId = (int) $split['user_id'];
            if ($userId === $payerId || (int) $split['is_settled'] === 1) {
                continue;
            }
            $amount = (int) $split['owed_amount'];
            $outstanding += $amount;
            $balances[$userId] = ($balances[$userId] ?? 0) - $amount;
        }
        $balances[$payerId] = ($balances[$payerId] ?? 0) + $outstanding;
        unset($ownShare);
    }

    return $balances;
}

/** Greedy debtor-to-creditor matching; input keyed by user id, positive = creditor. */
function simplify_debts(array $balances): array
{
    $debtors = [];
    $creditors = [];
    foreach ($balances as $userId => $amount) {
        $amount = (int) $amount;
        if ($amount < 0) {
            $debtors[] = ['id' => (int) $userId, 'amount' => -$amount];
        } elseif ($amount > 0) {
            $creditors[] = ['id' => (int) $userId, 'amount' => $amount];
        }
    }
    usort($debtors, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);
    usort($creditors, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);

    $transfers = [];
    $i = 0;
    $j = 0;
    while ($i < count($debtors) && $j < count($creditors)) {
        $amount = min($debtors[$i]['amount'], $creditors[$j]['amount']);
        if ($amount > 0) {
            $transfers[] = [
                'from'   => $debtors[$i]['id'],
                'to'     => $creditors[$j]['id'],
                'amount' => $amount,
            ];
        }
        $debtors[$i]['amount'] -= $amount;
        $creditors[$j]['amount'] -= $amount;
        if ($debtors[$i]['amount'] === 0) {
            $i++;
        }
        if ($creditors[$j]['amount'] === 0) {
            $j++;
        }
    }
    return $transfers;
}

function group_membership(PDO $pdo, int $groupId, int $userId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT gm.id, gm.group_id, gm.user_id, gm.role, u.name
         FROM group_members gm JOIN users u ON u.id = gm.user_id
         WHERE gm.group_id = :group_id AND gm.user_id = :user_id LIMIT 1'
    );
    $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function require_group_member(PDO $pdo, int $groupId, int $userId): array
{
    $membership = group_membership($pdo, $groupId, $userId);
    if ($membership === null) {
        http_response_code(403);
        exit('Anda bukan anggota grup ini.');
    }
    return $membership;
}

/** Inline SVG icon set (Lucide-style). No emoji, no external icon font. */
function icon(string $name, string $class = ''): string
{
    $paths = [
        'wallet'    => '<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-2"/><path d="M16 12h5v4h-5a2 2 0 0 1 0-4Z"/><path d="M3 7h16"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/>',
        'list'      => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        'target'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'chart'     => '<path d="M3 3v18h18"/><path d="m7 15 4-5 3 3 5-7"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'edit'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'trash'     => '<path d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14"/><path d="M10 11v6M14 11v6"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'alert'     => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
        'check'     => '<path d="m20 6-11 11-5-5"/>',
        'arrow-up'  => '<path d="m5 15 7-7 7 7"/>',
        'arrow-down'=> '<path d="m19 9-7 7-7-7"/>',
        'calendar'  => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
        'gift'      => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9h14v-9M12 8v13"/><path d="M12 8S9.5 3 7 4.5 9 8 12 8Zm0 0s2.5-5 5-3.5S15 8 12 8Z"/>',
        'download'  => '<path d="M12 3v12m0 0 5-5m-5 5-5-5"/><path d="M4 19h16"/>',
        'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close'     => '<path d="M18 6 6 18M6 6l12 12"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
        'key'       => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9 2 2-2 2 2 2-3 3-2-2-2 2"/>',
        'mail'      => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'lock'      => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'eye'       => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'copy'      => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'      => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
        'home'      => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'utensils'  => '<path d="M4 3v7a3 3 0 0 0 6 0V3M7 3v18M17 3c-2 2-2 6 0 8v10"/>',
        'bus'       => '<rect x="4" y="4" width="16" height="13" rx="2"/><path d="M4 11h16M7 21v-2M17 21v-2M7.5 14h.01M16.5 14h.01"/>',
        'book'      => '<path d="M4 4h7a3 3 0 0 1 3 3v13a3 3 0 0 0-3-3H4Z"/><path d="M20 4h-3a3 3 0 0 0-3 3v13a3 3 0 0 1 3-3h3Z"/>',
        'gamepad'   => '<path d="M6 12h4M8 10v4M15 11h.01M18 14h.01"/><rect x="2" y="7" width="20" height="10" rx="5"/>',
        'heart'     => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.7-7.5 1.1-1.1a5.5 5.5 0 0 0 0-7.8Z"/>',
        'dots'      => '<circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/>',
        'award'     => '<circle cx="12" cy="8" r="6"/><path d="m8.5 13-2 8 5.5-3 5.5 3-2-8"/>',
        'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18"/>',
        'circle'    => '<circle cx="12" cy="12" r="9"/>',
        'send'      => '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
        'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
    ];
    $body = $paths[$name] ?? $paths['circle'];
    $classAttr = $class === '' ? '' : ' ' . e($class);
    return '<svg class="icon' . $classAttr . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $body . '</svg>';
}
