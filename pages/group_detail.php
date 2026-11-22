<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/navbar.php';

/**
 * Shared split-bill form markup. Used by the add modal and every inline edit form.
 *
 * @param list<array{id: int, name: string, email: string, role: string}> $members
 * @param array<int, string> $userNames
 * @param list<int> $selectedIds
 * @param array<int, int> $initialAmounts
 */
function gd_render_split_form(
    int $groupId,
    bool $isAdmin,
    array $members,
    array $userNames,
    string $action,
    string $submitLabel,
    string $title,
    string $total,
    string $date,
    int $payerId,
    array $selectedIds,
    array $initialAmounts,
    string $mode,
    ?int $expenseId
): void {
    $lockPayer = $expenseId !== null && !$isAdmin;
    $initialJson = json_encode(
        $initialAmounts,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    if ($initialJson === false) {
        $initialJson = '{}';
    }
    $selectedSet = array_fill_keys($selectedIds, true);
    ?>
    <form method="post" action="<?= e(url('process/split_process.php')) ?>" data-split-form
          data-initial-amounts="<?= e($initialJson) ?>">
      <input type="hidden" name="action" value="<?= e($action) ?>">
      <input type="hidden" name="group_id" value="<?= $groupId ?>">
      <?php if ($expenseId !== null): ?>
        <input type="hidden" name="expense_id" value="<?= $expenseId ?>">
      <?php endif; ?>
      <?= csrf_field() ?>

      <div class="form-grid cols-1">
        <div class="field">
          <label>Judul pengeluaran</label>
          <input name="title" type="text" maxlength="100" placeholder="Mis. Makan malam kelompok" value="<?= e($title) ?>" required>
        </div>

        <div class="form-grid">
          <div class="field">
            <label>Total (Rp)</label>
            <input name="total_amount" type="text" inputmode="numeric" data-split-total placeholder="100000" value="<?= e($total) ?>" required>
          </div>
          <div class="field">
            <label>Tanggal</label>
            <input name="expense_date" type="date" value="<?= e($date) ?>" required>
          </div>
        </div>

        <div class="field">
          <label>Pembayar</label>
          <?php if ($lockPayer): ?>
            <input type="hidden" name="paid_by_user_id" value="<?= $payerId ?>">
            <input type="text" value="<?= e($userNames[$payerId] ?? ('Anggota #' . $payerId)) ?>" disabled>
            <span class="field-hint">Hanya admin yang bisa mengubah pembayar pengeluaran orang lain.</span>
          <?php else: ?>
            <select name="paid_by_user_id" required>
              <?php foreach ($members as $member): ?>
                <?php $memberId = (int) $member['id']; ?>
                <option value="<?= $memberId ?>" <?= $memberId === $payerId ? 'selected' : '' ?>>
                  <?= e($member['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php endif; ?>
        </div>

        <div class="field">
          <label>Mode pembagian</label>
          <div class="segmented">
            <label>
              <input type="radio" name="mode" value="equal" <?= $mode === 'equal' ? 'checked' : '' ?>>
              <span><?= icon('users') ?> Bagi rata</span>
            </label>
            <label>
              <input type="radio" name="mode" value="exact" <?= $mode === 'exact' ? 'checked' : '' ?>>
              <span><?= icon('wallet') ?> Nominal pasti</span>
            </label>
          </div>
        </div>

        <div class="field">
          <label>Peserta</label>
          <div class="member-list">
            <?php foreach ($members as $member): ?>
              <?php $memberId = (int) $member['id']; ?>
              <label class="split-row">
                <span class="split-check">
                  <input type="checkbox" name="participants[]" value="<?= $memberId ?>"
                         data-participant data-name="<?= e($member['name']) ?>"
                         <?= isset($selectedSet[$memberId]) ? 'checked' : '' ?>>
                </span>
                <span class="split-person">
                  <span class="avatar" aria-hidden="true"><?= e(mb_substr((string) $member['name'], 0, 1)) ?></span>
                  <strong><?= e($member['name']) ?></strong>
                </span>
                <span class="field-hint"><?= $memberId === $payerId ? 'Pembayar' : '' ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="field">
          <label>Preview pembagian</label>
          <div class="member-list" data-split-preview></div>
          <div class="alert alert-warning" data-split-warning style="display:none;"></div>
          <noscript><span class="field-hint">Aktifkan JavaScript untuk melihat preview dan mengisi nominal pasti.</span></noscript>
        </div>
      </div>

      <div class="form-actions">
        <button class="btn btn-primary" type="submit" data-split-submit><?= icon('check') ?> <?= e($submitLabel) ?></button>
      </div>
    </form>
    <?php
}

$userId = (int) $currentUser['id'];
$groupId = (int) ($_GET['id'] ?? 0);

if ($groupId <= 0) {
    set_flash('error', 'Grup tidak ditemukan.');
    redirect('pages/groups.php');
}

$membership = require_group_member($pdo, $groupId, $userId);
$isAdmin = ($membership['role'] ?? '') === 'admin';

$stmt = $pdo->prepare('SELECT id, name, description, invite_code FROM `groups` WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $groupId]);
$group = $stmt->fetch();
if (!$group) {
    set_flash('error', 'Grup tidak ditemukan.');
    redirect('pages/groups.php');
}

$stmt = $pdo->prepare(
    'SELECT gm.user_id, gm.role, u.name, u.email
     FROM group_members gm
     JOIN users u ON u.id = gm.user_id
     WHERE gm.group_id = :group_id
     ORDER BY (gm.role = "admin") DESC, u.name ASC'
);
$stmt->execute([':group_id' => $groupId]);
$members = [];
$userNames = [];
foreach ($stmt->fetchAll() as $row) {
    $memberId = (int) $row['user_id'];
    $members[] = [
        'id'    => $memberId,
        'name'  => (string) $row['name'],
        'email' => (string) $row['email'],
        'role'  => (string) $row['role'],
    ];
    $userNames[$memberId] = (string) $row['name'];
}

$balances = group_net_balances($pdo, $groupId);
$transfers = simplify_debts($balances);

$stmt = $pdo->prepare(
    'SELECT ge.id, ge.title, ge.total_amount, ge.expense_date, ge.paid_by_user_id, u.name AS payer_name
     FROM group_expenses ge
     JOIN users u ON u.id = ge.paid_by_user_id
     WHERE ge.group_id = :group_id
     ORDER BY ge.expense_date DESC, ge.id DESC'
);
$stmt->execute([':group_id' => $groupId]);

$splitStmt = $pdo->prepare(
    'SELECT es.user_id, es.owed_amount, es.is_settled, u.name
     FROM expense_splits es
     JOIN users u ON u.id = es.user_id
     WHERE es.group_expense_id = :expense_id
     ORDER BY es.id ASC'
);

$expenses = [];
foreach ($stmt->fetchAll() as $row) {
    $expenseId = (int) $row['id'];
    $payerId = (int) $row['paid_by_user_id'];
    $totalAmount = (int) $row['total_amount'];
    if (!isset($userNames[$payerId])) {
        $userNames[$payerId] = (string) $row['payer_name'];
    }

    $splitStmt->execute([':expense_id' => $expenseId]);
    $splits = [];
    $amounts = [];
    $participantIds = [];
    $hasOutstanding = false;

    foreach ($splitStmt->fetchAll() as $split) {
        $splitUserId = (int) $split['user_id'];
        $settled = (int) $split['is_settled'] === 1;
        if (!isset($userNames[$splitUserId])) {
            $userNames[$splitUserId] = (string) $split['name'];
        }
        $splits[] = [
            'user_id' => $splitUserId,
            'amount'  => (int) $split['owed_amount'],
            'settled' => $settled,
        ];
        $amounts[$splitUserId] = (int) $split['owed_amount'];
        $participantIds[] = $splitUserId;
        if ($splitUserId !== $payerId && !$settled) {
            $hasOutstanding = true;
        }
    }

    $equalAmounts = split_equal_amounts($totalAmount, $participantIds);
    $mode = $amounts === $equalAmounts ? 'equal' : 'exact';

    $expenses[] = [
        'id'            => $expenseId,
        'title'         => (string) $row['title'],
        'total'         => $totalAmount,
        'date'          => (string) $row['expense_date'],
        'payer_id'      => $payerId,
        'payer_name'    => (string) $row['payer_name'],
        'splits'        => $splits,
        'amounts'       => $amounts,
        'participants'  => $participantIds,
        'mode'          => $mode,
        'has_outstanding' => $hasOutstanding,
    ];
}

$today = date('Y-m-d');
$allMemberIds = array_column($members, 'id');

render_header('Detail Patungan', 'groups');
?>

<div class="page-head">
  <div>
    <h1><?= e($group['name']) ?></h1>
    <p>
      <?= e($group['description'] !== null && $group['description'] !== '' ? $group['description'] : 'Patungan anggota grup.') ?>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn" href="<?= e(url('pages/groups.php')) ?>"><?= icon('arrow-down') ?> Daftar grup</a>
    <div class="invite-code">
      <span><?= e($group['invite_code']) ?></span>
      <button class="btn btn-sm btn-icon" type="button" data-copy="<?= e($group['invite_code']) ?>" aria-label="Salin kode undangan">
        <?= icon('copy') ?>
      </button>
    </div>
  </div>
</div>

<div class="alert alert-warning">
  <?= icon('alert') ?>
  <span>Anggota hanya bisa keluar atau dikeluarkan saat saldo bersihnya tepat <strong>Rp0</strong>. Selesaikan semua tagihan terlebih dahulu.</span>
</div>

<div class="grid grid-2">
  <section class="card">
    <div class="card-header">
      <h2><?= icon('users') ?> Anggota grup</h2>
      <span class="badge"><?= count($members) ?> orang</span>
    </div>
    <div class="card-body">
      <?php if ($isAdmin): ?>
        <form method="post" action="<?= e(url('process/group_process.php')) ?>" style="margin-bottom: var(--space-4);">
          <input type="hidden" name="action" value="add_member">
          <input type="hidden" name="group_id" value="<?= $groupId ?>">
          <?= csrf_field() ?>
          <div class="form-grid">
            <div class="field">
              <label for="member-email">Tambah anggota via email</label>
              <input id="member-email" name="email" type="email" placeholder="nama@email.com" required>
            </div>
            <div class="field" style="align-self: end;">
              <button class="btn" type="submit"><?= icon('plus') ?> Tambah</button>
            </div>
          </div>
        </form>
      <?php endif; ?>

      <div class="member-list">
        <?php foreach ($members as $member): ?>
          <?php
          $memberId = $member['id'];
          $balance = (int) ($balances[$memberId] ?? 0);
          $netClass = $balance > 0 ? 'net-positive' : ($balance < 0 ? 'net-negative' : 'net-zero');
          $netLabel = $balance > 0 ? 'Piutang' : ($balance < 0 ? 'Utang' : 'Lunas');
          ?>
          <div class="member-row">
            <span class="avatar" aria-hidden="true"><?= e(mb_substr($member['name'], 0, 1)) ?></span>
            <div class="member-meta">
              <span class="member-name"><?= e($member['name']) ?> <?= $memberId === $userId ? '(Kamu)' : '' ?></span>
              <span class="cell-sub"><?= e($member['email']) ?></span>
            </div>
            <span class="badge <?= $member['role'] === 'admin' ? 'badge-info' : '' ?>">
              <?= $member['role'] === 'admin' ? 'Admin' : 'Anggota' ?>
            </span>
            <span class="<?= $netClass ?>">
              <?= e($netLabel) ?> <?= e(format_rupiah(abs($balance))) ?>
            </span>

            <?php if ($isAdmin && $memberId !== $userId): ?>
              <div class="member-actions">
                <form method="post" action="<?= e(url('process/group_process.php')) ?>">
                  <input type="hidden" name="action" value="change_role">
                  <input type="hidden" name="group_id" value="<?= $groupId ?>">
                  <input type="hidden" name="user_id" value="<?= $memberId ?>">
                  <?= csrf_field() ?>
                  <select name="role" aria-label="Role <?= e($member['name']) ?>">
                    <option value="admin" <?= $member['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="member" <?= $member['role'] === 'member' ? 'selected' : '' ?>>Anggota</option>
                  </select>
                  <button class="btn btn-sm" type="submit"><?= icon('check') ?></button>
                </form>
                <form method="post" action="<?= e(url('process/group_process.php')) ?>" data-confirm="Keluarkan <?= e($member['name']) ?> dari grup?">
                  <input type="hidden" name="action" value="kick">
                  <input type="hidden" name="group_id" value="<?= $groupId ?>">
                  <input type="hidden" name="user_id" value="<?= $memberId ?>">
                  <?= csrf_field() ?>
                  <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash') ?></button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="form-actions">
        <form method="post" action="<?= e(url('process/group_process.php')) ?>" data-confirm="Yakin keluar dari grup ini?">
          <input type="hidden" name="action" value="leave">
          <input type="hidden" name="group_id" value="<?= $groupId ?>">
          <?= csrf_field() ?>
          <button class="btn" type="submit"><?= icon('logout') ?> Keluar grup</button>
        </form>

        <?php if ($isAdmin): ?>
          <form method="post" action="<?= e(url('process/group_process.php')) ?>" data-confirm="Hapus grup beserta semua tagihannya? Tindakan ini tidak bisa dibatalkan.">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="group_id" value="<?= $groupId ?>">
            <?= csrf_field() ?>
            <button class="btn btn-danger" type="submit"><?= icon('trash') ?> Hapus grup</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <h2><?= icon('chart') ?> Ringkasan Utang</h2>
      <span class="badge <?= $transfers ? 'badge-warning' : 'badge-success' ?>">
        <?= $transfers ? count($transfers) . ' transfer' : 'Lunas' ?>
      </span>
    </div>
    <div class="card-body">
      <?php if (!$transfers): ?>
        <div class="empty-state">
          <?= icon('check') ?>
          <strong>Semasa lunas</strong>
          <span>Tidak ada utang aktif di grup ini.</span>
        </div>
      <?php else: ?>
        <div class="member-list">
          <?php foreach ($transfers as $transfer): ?>
            <?php
            $fromId = (int) $transfer['from'];
            $toId = (int) $transfer['to'];
            $amount = (int) $transfer['amount'];
            $canSettle = $isAdmin || $fromId === $userId || $toId === $userId;
            ?>
            <div class="member-row">
              <span class="avatar" aria-hidden="true"><?= e(mb_substr($userNames[$fromId] ?? '?', 0, 1)) ?></span>
              <div class="member-meta">
                <span class="member-name">
                  <?= e($userNames[$fromId] ?? ('Anggota #' . $fromId)) ?>
                  bayar <strong><?= e(format_rupiah($amount)) ?></strong>
                  ke <?= e($userNames[$toId] ?? ('Anggota #' . $toId)) ?>
                </span>
                <span class="cell-sub">Transfer disederhanakan otomatis.</span>
              </div>
              <?php if ($canSettle): ?>
                <div class="member-actions">
                  <form method="post" action="<?= e(url('process/split_process.php')) ?>">
                    <input type="hidden" name="action" value="settle">
                    <input type="hidden" name="group_id" value="<?= $groupId ?>">
                    <input type="hidden" name="from_user_id" value="<?= $fromId ?>">
                    <input type="hidden" name="to_user_id" value="<?= $toId ?>">
                    <input type="hidden" name="amount" value="<?= $amount ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-accent" type="submit"><?= icon('check') ?> Tandai Lunas</button>
                  </form>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>

<section class="card">
  <div class="card-header">
    <h2><?= icon('list') ?> Pengeluaran grup</h2>
    <button class="btn btn-primary" type="button" data-modal-open="add-expense-modal">
      <?= icon('plus') ?> Catat pengeluaran
    </button>
  </div>
  <div class="card-body">
    <?php if (!$expenses): ?>
      <div class="empty-state">
        <?= icon('wallet') ?>
        <strong>Belum ada pengeluaran</strong>
        <span>Catat tagihan pertama agar utang dihitung otomatis.</span>
      </div>
    <?php else: ?>
      <div class="grid">
        <?php foreach ($expenses as $expense): ?>
          <?php
          $expenseId = (int) $expense['id'];
          $canManage = $isAdmin || $expense['payer_id'] === $userId;
          ?>
          <div class="card">
            <div class="card-header">
              <div>
                <span class="cell-main"><?= e($expense['title']) ?></span>
                <span class="cell-sub">
                  <?= e($expense['date']) ?> · dibayar <?= e($expense['payer_name']) ?>
                </span>
              </div>
              <div class="page-actions">
                <span class="badge <?= $expense['has_outstanding'] ? 'badge-warning' : 'badge-success' ?>">
                  <?= $expense['has_outstanding'] ? 'Belum lunas' : 'Lunas' ?>
                </span>
                <strong><?= e(format_rupiah($expense['total'])) ?></strong>
                <?php if ($canManage): ?>
                  <form method="post" action="<?= e(url('process/split_process.php')) ?>" data-confirm="Hapus pengeluaran <?= e($expense['title']) ?>?">
                    <input type="hidden" name="action" value="delete_expense">
                    <input type="hidden" name="group_id" value="<?= $groupId ?>">
                    <input type="hidden" name="expense_id" value="<?= $expenseId ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash') ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
            <div class="card-body">
              <div class="member-list">
                <?php foreach ($expense['splits'] as $split): ?>
                  <div class="split-row">
                    <span class="split-person">
                      <span class="avatar" aria-hidden="true"><?= e(mb_substr($userNames[$split['user_id']] ?? '?', 0, 1)) ?></span>
                      <strong><?= e($userNames[$split['user_id']] ?? ('Anggota #' . $split['user_id'])) ?></strong>
                    </span>
                    <div class="field-hint">
                      <?= $split['user_id'] === $expense['payer_id'] ? 'Bagian pembayar' : ($split['settled'] ? 'Lunas' : 'Belum lunas') ?>
                    </div>
                    <span class="split-amount"><?= e(format_rupiah($split['amount'])) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>

              <?php if ($canManage): ?>
                <details style="margin-top: var(--space-4);">
                  <summary class="btn btn-sm"><?= icon('edit') ?> Edit pengeluaran</summary>
                  <div style="margin-top: var(--space-4);">
                    <?php
                    gd_render_split_form(
                        $groupId,
                        $isAdmin,
                        $members,
                        $userNames,
                        'update_expense',
                        'Simpan perubahan',
                        (string) $expense['title'],
                        (string) $expense['total'],
                        (string) $expense['date'],
                        (int) $expense['payer_id'],
                        $expense['participants'],
                        $expense['amounts'],
                        (string) $expense['mode'],
                        $expenseId
                    );
                    ?>
                  </div>
                </details>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<div class="modal" id="add-expense-modal" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="add-expense-title">
    <div class="modal-head">
      <h2 id="add-expense-title"><?= icon('plus') ?> Catat pengeluaran</h2>
      <button class="icon-button" type="button" data-modal-close aria-label="Tutup"><?= icon('close') ?></button>
    </div>
    <div class="modal-body">
      <?php
      gd_render_split_form(
          $groupId,
          $isAdmin,
          $members,
          $userNames,
          'create_expense',
          'Simpan pengeluaran',
          '',
          '',
          $today,
          $userId,
          $allMemberIds,
          [],
          'equal',
          null
      );
      ?>
    </div>
  </div>
</div>

<?php
render_footer();
