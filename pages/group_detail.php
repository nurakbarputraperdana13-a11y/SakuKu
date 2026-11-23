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
          data-initial-amounts="<?= e($initialJson) ?>"
          class="flex flex-col gap-space-md">
      <input type="hidden" name="action" value="<?= e($action) ?>">
      <input type="hidden" name="group_id" value="<?= $groupId ?>">
      <?php if ($expenseId !== null): ?>
        <input type="hidden" name="expense_id" value="<?= $expenseId ?>">
      <?php endif; ?>
      <?= csrf_field() ?>

      <div class="field">
        <label>Judul pengeluaran</label>
        <input name="title" type="text" maxlength="100" placeholder="Mis. Makan malam kelompok" value="<?= e($title) ?>" required>
      </div>

      <div class="grid grid-cols-1 gap-space-md sm:grid-cols-2">
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
        <div class="flex gap-1 rounded-xl bg-surface-container-low p-1">
          <label class="flex-1">
            <input class="peer sr-only" type="radio" name="mode" value="equal" <?= $mode === 'equal' ? 'checked' : '' ?>>
            <span class="block cursor-pointer rounded-lg px-3 py-2 text-center font-label-md text-label-md text-on-surface-variant peer-checked:bg-secondary-fixed peer-checked:font-bold peer-checked:text-on-secondary-fixed">Bagi rata</span>
          </label>
          <label class="flex-1">
            <input class="peer sr-only" type="radio" name="mode" value="exact" <?= $mode === 'exact' ? 'checked' : '' ?>>
            <span class="block cursor-pointer rounded-lg px-3 py-2 text-center font-label-md text-label-md text-on-surface-variant peer-checked:bg-secondary-fixed peer-checked:font-bold peer-checked:text-on-secondary-fixed">Nominal pasti</span>
          </label>
        </div>
      </div>

      <div class="field">
        <label>Peserta</label>
        <div class="flex flex-col gap-space-xs">
          <?php foreach ($members as $member): ?>
            <?php $memberId = (int) $member['id']; ?>
            <label class="split-row cursor-pointer">
              <input class="h-4 w-4 accent-primary" type="checkbox" name="participants[]" value="<?= $memberId ?>"
                     data-participant data-name="<?= e($member['name']) ?>"
                     <?= isset($selectedSet[$memberId]) ? 'checked' : '' ?>>
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
        <div class="flex flex-col gap-space-xs rounded-2xl bg-surface-container-low p-space-md" data-split-preview></div>
        <div class="hidden rounded-2xl border border-red-200 bg-red-50 p-space-md font-label-md text-label-md text-red-700" data-split-warning></div>
        <noscript><span class="field-hint">Aktifkan JavaScript untuk melihat preview dan mengisi nominal pasti.</span></noscript>
      </div>

      <button class="inline-flex h-11 items-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary transition-colors hover:bg-primary disabled:cursor-not-allowed disabled:opacity-50" type="submit" data-split-submit><?= e($submitLabel) ?></button>
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

render_header('Detail Split Bill', 'groups');
?>
<section class="flex flex-col gap-space-md md:flex-row md:items-end md:justify-between">
  <div>
    <p class="font-label-lg text-label-lg uppercase tracking-wider text-primary">Wealth Operations / Split Bill</p>
    <h1 class="mt-1 font-headline-xl text-headline-xl text-on-surface md:font-display-lg md:text-display-lg"><?= e($group['name']) ?></h1>
    <p class="mt-1 font-body-md text-body-md text-on-surface-variant"><?= e($group['description'] !== null && $group['description'] !== '' ? $group['description'] : 'Patungan anggota grup.') ?></p>
  </div>
  <div class="flex flex-wrap items-center gap-space-sm">
    <a class="inline-flex h-11 items-center rounded-xl border border-on-surface/10 bg-white px-5 font-label-lg text-label-lg text-on-surface hover:bg-surface-container-low" href="<?= e(url('pages/groups.php')) ?>">Daftar grup</a>
    <div class="inline-flex items-center gap-space-xs rounded-full bg-surface-container-low px-space-md py-space-xs font-label-md text-label-md tabular-nums text-on-surface">
      <span><?= e($group['invite_code']) ?></span>
      <button class="font-bold text-primary hover:underline" type="button" data-copy="<?= e($group['invite_code']) ?>" aria-label="Salin kode undangan">Salin</button>
    </div>
  </div>
</section>

<div class="mt-space-lg flex items-start gap-space-sm rounded-2xl border border-lime-300/60 bg-lime-50 p-space-md">
  <span class="material-symbols-outlined text-[20px] text-green-700" aria-hidden="true">info</span>
  <p class="font-body-md text-body-md text-on-surface">Anggota hanya bisa keluar atau dikeluarkan saat saldo bersihnya tepat <strong>Rp0</strong>. Selesaikan semua tagihan terlebih dahulu.</p>
</div>

<section class="mt-space-lg grid grid-cols-1 gap-gutter md:grid-cols-2 md:gap-gutter-desktop">
  <div class="bento-card">
    <div class="flex flex-wrap items-center justify-between gap-space-sm">
      <div>
        <h2 class="font-headline-lg text-headline-lg text-on-surface">Anggota grup</h2>
        <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Posisi saldo bersih tiap anggota.</p>
      </div>
      <span class="pill pill-neutral"><?= count($members) ?> orang</span>
    </div>

    <?php if ($isAdmin): ?>
      <form class="mt-space-lg flex flex-wrap items-end gap-space-sm" method="post" action="<?= e(url('process/group_process.php')) ?>">
        <input type="hidden" name="action" value="add_member">
        <input type="hidden" name="group_id" value="<?= $groupId ?>">
        <?= csrf_field() ?>
        <div class="field min-w-[200px] flex-1"><label for="member-email">Tambah anggota via email</label><input id="member-email" name="email" type="email" placeholder="nama@email.com" required></div>
        <button class="inline-flex h-11 items-center rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary hover:bg-primary" type="submit">Tambah</button>
      </form>
    <?php endif; ?>

    <div class="mt-space-lg flex flex-col gap-space-xs">
      <?php foreach ($members as $member): ?>
        <?php
          $memberId = $member['id'];
          $balance = (int) ($balances[$memberId] ?? 0);
          $netPill = $balance > 0 ? 'pill-lime' : ($balance < 0 ? 'pill-red' : 'pill-neutral');
          $netLabel = $balance > 0 ? 'Piutang' : ($balance < 0 ? 'Utang' : 'Lunas');
        ?>
        <div class="flex flex-wrap items-center gap-space-sm rounded-2xl bg-surface-container-low p-space-md">
          <span class="avatar" aria-hidden="true"><?= e(mb_substr($member['name'], 0, 1)) ?></span>
          <div class="min-w-0 flex-1">
            <span class="block font-label-lg text-label-lg font-semibold text-on-surface"><?= e($member['name']) ?> <?= $memberId === $userId ? '(Kamu)' : '' ?></span>
            <span class="font-label-sm text-label-sm text-on-surface-variant"><?= e($member['email']) ?></span>
          </div>
          <span class="pill <?= $member['role'] === 'admin' ? 'pill-lime' : 'pill-neutral' ?>"><?= $member['role'] === 'admin' ? 'Admin' : 'Anggota' ?></span>
          <span class="pill <?= $netPill ?>"><?= e($netLabel) ?> <?= e(format_rupiah(abs($balance))) ?></span>

          <?php if ($isAdmin && $memberId !== $userId): ?>
            <div class="flex items-center gap-space-xs">
              <form method="post" action="<?= e(url('process/group_process.php')) ?>" class="flex items-center gap-space-xs">
                <input type="hidden" name="action" value="change_role">
                <input type="hidden" name="group_id" value="<?= $groupId ?>">
                <input type="hidden" name="user_id" value="<?= $memberId ?>">
                <?= csrf_field() ?>
                <label class="sr-only" for="role-<?= $memberId ?>">Role <?= e($member['name']) ?></label>
                <select id="role-<?= $memberId ?>" name="role" class="h-9 rounded-lg border border-on-surface/10 bg-white px-2 font-label-sm text-label-sm">
                  <option value="admin" <?= $member['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                  <option value="member" <?= $member['role'] === 'member' ? 'selected' : '' ?>>Anggota</option>
                </select>
                <button class="grid h-9 w-9 place-items-center rounded-full bg-primary-container text-on-primary" type="submit" aria-label="Simpan role"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">check</span></button>
              </form>
              <form method="post" action="<?= e(url('process/group_process.php')) ?>" data-confirm="Keluarkan <?= e($member['name']) ?> dari grup?">
                <input type="hidden" name="action" value="kick">
                <input type="hidden" name="group_id" value="<?= $groupId ?>">
                <input type="hidden" name="user_id" value="<?= $memberId ?>">
                <?= csrf_field() ?>
                <button class="grid h-9 w-9 place-items-center rounded-full bg-red-50 text-red-600 hover:bg-red-100" type="submit" aria-label="Keluarkan anggota"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">person_remove</span></button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mt-space-lg flex flex-wrap items-center gap-space-sm border-t border-surface-container pt-space-md">
      <form method="post" action="<?= e(url('process/group_process.php')) ?>" data-confirm="Yakin keluar dari grup ini?">
        <input type="hidden" name="action" value="leave">
        <input type="hidden" name="group_id" value="<?= $groupId ?>">
        <?= csrf_field() ?>
        <button class="inline-flex h-10 items-center rounded-xl border border-on-surface/10 px-space-md font-label-lg text-label-lg text-on-surface-variant hover:bg-surface-container-low" type="submit">Keluar grup</button>
      </form>
      <?php if ($isAdmin): ?>
        <form method="post" action="<?= e(url('process/group_process.php')) ?>" data-confirm="Hapus grup beserta semua tagihannya? Tindakan ini tidak bisa dibatalkan.">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="group_id" value="<?= $groupId ?>">
          <?= csrf_field() ?>
          <button class="inline-flex h-10 items-center rounded-xl bg-red-50 px-space-md font-label-lg text-label-lg text-red-600 hover:bg-red-100" type="submit">Hapus grup</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="bento-dark flex flex-col justify-between gap-space-lg">
    <div class="flex items-start justify-between gap-space-sm">
      <div>
        <span class="font-label-md text-label-md uppercase tracking-wider text-[#99f6e4]">Greedy Settlement</span>
        <h2 class="mt-2 font-headline-lg text-headline-lg text-white">Ringkasan utang</h2>
      </div>
      <span class="pill <?= $transfers ? 'bg-amber-400/20 text-amber-200' : 'bg-lime-400/20 text-lime-200' ?>"><?= $transfers ? count($transfers) . ' transfer' : 'Lunas' ?></span>
    </div>
    <div class="flex flex-col gap-space-sm">
      <?php if (!$transfers): ?>
        <div class="rounded-2xl bg-white/10 p-space-md">
          <p class="font-headline-md text-headline-md text-white">Semasa lunas</p>
          <p class="font-label-md text-label-md text-[#99f6e4]">Tidak ada utang aktif di grup ini.</p>
        </div>
      <?php else: ?>
        <?php foreach ($transfers as $transfer): ?>
          <?php
            $fromId = (int) $transfer['from'];
            $toId = (int) $transfer['to'];
            $amount = (int) $transfer['amount'];
            $canSettle = $isAdmin || $fromId === $userId || $toId === $userId;
          ?>
          <div class="rounded-2xl bg-white/10 p-space-md">
            <p class="font-body-md text-body-md text-white">
              <?= e($userNames[$fromId] ?? ('Anggota #' . $fromId)) ?> bayar
              <strong class="tabular-nums text-secondary-fixed"><?= e(format_rupiah($amount)) ?></strong>
              ke <?= e($userNames[$toId] ?? ('Anggota #' . $toId)) ?>
            </p>
            <?php if ($canSettle): ?>
              <form class="mt-space-sm" method="post" action="<?= e(url('process/split_process.php')) ?>">
                <input type="hidden" name="action" value="settle">
                <input type="hidden" name="group_id" value="<?= $groupId ?>">
                <input type="hidden" name="from_user_id" value="<?= $fromId ?>">
                <input type="hidden" name="to_user_id" value="<?= $toId ?>">
                <input type="hidden" name="amount" value="<?= $amount ?>">
                <?= csrf_field() ?>
                <button class="inline-flex h-9 items-center rounded-full bg-secondary-fixed px-space-md font-label-md text-label-md font-bold text-on-secondary-fixed hover:bg-secondary-fixed-dim" type="submit">Tandai lunas</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="bento-card mt-space-lg">
  <div class="flex flex-wrap items-center justify-between gap-space-sm">
    <div>
      <h2 class="font-headline-lg text-headline-lg text-on-surface">Pengeluaran grup</h2>
      <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Daftar tagihan dan rincian pembagian.</p>
    </div>
    <button class="inline-flex h-11 items-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary hover:bg-primary" type="button" data-modal-open="add-expense-modal">
      <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add</span> Catat pengeluaran
    </button>
  </div>
  <div class="mt-space-lg">
    <?php if (!$expenses): ?>
      <div class="flex flex-col items-center justify-center gap-2 rounded-2xl bg-surface-container-low px-4 py-12 text-center">
        <span class="material-symbols-outlined text-[28px] text-outline-variant" aria-hidden="true">receipt_long</span>
        <p class="font-headline-md text-headline-md text-on-surface">Belum ada pengeluaran</p>
        <p class="max-w-sm font-body-md text-body-md text-on-surface-variant">Catat tagihan pertama agar utang dihitung otomatis.</p>
      </div>
    <?php else: ?>
      <div class="grid grid-cols-1 gap-space-md xl:grid-cols-2">
        <?php foreach ($expenses as $expense): ?>
          <?php
          $expenseId = (int) $expense['id'];
          $canManage = $isAdmin || $expense['payer_id'] === $userId;
          ?>
          <div class="rounded-2xl border border-surface-container bg-surface-container-lowest p-space-md">
            <div class="flex flex-wrap items-start justify-between gap-space-sm">
              <div>
                <h3 class="font-headline-md text-headline-md text-on-surface"><?= e($expense['title']) ?></h3>
                <p class="font-label-sm text-label-sm text-on-surface-variant"><?= e($expense['date']) ?> · dibayar <?= e($expense['payer_name']) ?></p>
              </div>
              <div class="flex items-center gap-space-xs">
                <span class="pill <?= $expense['has_outstanding'] ? 'pill-warning' : 'pill-lime' ?>"><?= $expense['has_outstanding'] ? 'Belum lunas' : 'Lunas' ?></span>
                <strong class="tabular-nums text-on-surface"><?= e(format_rupiah($expense['total'])) ?></strong>
                <?php if ($canManage): ?>
                  <form method="post" action="<?= e(url('process/split_process.php')) ?>" data-confirm="Hapus pengeluaran <?= e($expense['title']) ?>?">
                    <input type="hidden" name="action" value="delete_expense">
                    <input type="hidden" name="group_id" value="<?= $groupId ?>">
                    <input type="hidden" name="expense_id" value="<?= $expenseId ?>">
                    <?= csrf_field() ?>
                    <button class="grid h-8 w-8 place-items-center rounded-full bg-red-50 text-red-600 hover:bg-red-100" type="submit" aria-label="Hapus pengeluaran"><span class="material-symbols-outlined text-[16px]" aria-hidden="true">delete</span></button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
            <div class="mt-space-md flex flex-col gap-space-xs">
              <?php foreach ($expense['splits'] as $split): ?>
                <div class="split-row">
                  <span class="split-person">
                    <span class="avatar" aria-hidden="true"><?= e(mb_substr($userNames[$split['user_id']] ?? '?', 0, 1)) ?></span>
                    <strong><?= e($userNames[$split['user_id']] ?? ('Anggota #' . $split['user_id'])) ?></strong>
                  </span>
                  <span class="font-label-sm text-label-sm text-on-surface-variant"><?= $split['user_id'] === $expense['payer_id'] ? 'Bagian pembayar' : ($split['settled'] ? 'Lunas' : 'Belum lunas') ?></span>
                  <span class="split-amount"><?= e(format_rupiah($split['amount'])) ?></span>
                </div>
              <?php endforeach; ?>
            </div>

            <?php if ($canManage): ?>
              <details class="mt-space-md border-t border-surface-container pt-space-md">
                <summary class="cursor-pointer font-label-md text-label-md font-bold text-primary">Edit pengeluaran</summary>
                <div class="mt-space-md">
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
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<div class="modal" id="add-expense-modal" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel max-w-2xl overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="add-expense-title">
    <div class="flex items-center justify-between gap-space-sm border-b border-surface-container p-6">
      <h2 class="font-headline-md text-headline-md" id="add-expense-title">Catat pengeluaran</h2>
      <button class="grid h-9 w-9 place-items-center rounded-full bg-surface-container-low text-on-surface-variant" type="button" data-modal-close aria-label="Tutup"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span></button>
    </div>
    <div class="max-h-[70vh] overflow-y-auto p-6">
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
