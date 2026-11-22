<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);

/** Weekly/monthly estimate toward the remaining amount. */
function saving_estimate(int $remaining, ?string $deadline): array
{
    $empty = ['weekly' => 0, 'monthly' => 0, 'overdue' => false, 'days' => null];
    if ($deadline === null || $deadline === '') {
        return $empty;
    }

    $due = DateTime::createFromFormat('!Y-m-d', $deadline);
    if ($due === false) {
        return $empty;
    }

    $today = new DateTime('today');
    $days = (int) $today->diff($due)->format('%r%a');
    $daysLeft = max(1, $days);
    $weeks = (int) max(1, (int) ceil($daysLeft / 7));
    $months = (int) max(1, (int) ceil($daysLeft / 30));

    return [
        'weekly'  => (int) ceil($remaining / $weeks),
        'monthly' => (int) ceil($remaining / $months),
        'overdue' => $days < 0,
        'days'    => $days,
    ];
}

$balance = available_balance($pdo, $userId);
$stmt = $pdo->prepare(
    "SELECT id, title, target_amount, current_amount, deadline, status, created_at
     FROM savings_goals WHERE user_id = :user_id
     ORDER BY status = 'active' DESC, created_at DESC, id DESC"
);
$stmt->execute([':user_id' => $userId]);
$goals = $stmt->fetchAll();

$activeCount = 0;
$activeTotal = 0;
foreach ($goals as $goal) {
    if ((string) $goal['status'] === 'active') {
        $activeCount++;
        $activeTotal += (int) $goal['current_amount'];
    }
}

render_header('Tabungan', 'savings');
?>
<div class="page-head">
  <div>
    <h1>Target Tabungan</h1>
    <p>Kunci dana untuk tujuan tertentu tanpa mengubah catatan arus kas.</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-primary" type="button" data-modal-open="goal-create">
      <?= icon('plus') ?> Buat target
    </button>
  </div>
</div>

<div class="grid grid-stats">
  <div class="stat-card">
    <p class="stat-label">Saldo tersedia</p>
    <p class="stat-value"><?= e(format_rupiah($balance)) ?></p>
    <div class="stat-meta"><?= icon('wallet') ?> setelah dikurangi tabungan aktif</div>
    <span class="stat-icon"><?= icon('wallet') ?></span>
  </div>
  <div class="stat-card stat-saving">
    <p class="stat-label">Target aktif</p>
    <p class="stat-value"><?= $activeCount ?></p>
    <div class="stat-meta"><?= icon('target') ?> sedang berjalan</div>
    <span class="stat-icon"><?= icon('target') ?></span>
  </div>
  <div class="stat-card stat-saving">
    <p class="stat-label">Dana tertahan</p>
    <p class="stat-value"><?= e(format_rupiah($activeTotal)) ?></p>
    <div class="stat-meta"><?= icon('lock') ?> tidak termasuk saldo tersedia</div>
    <span class="stat-icon"><?= icon('lock') ?></span>
  </div>
</div>

<?php if ($goals === []): ?>
  <div class="card">
    <div class="card-body">
      <div class="empty-state">
        <?= icon('target') ?>
        <strong>Belum ada target tabungan</strong>
        <span>Buat target pertama untuk mulai menyisihkan dana.</span>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="grid grid-3">
    <?php foreach ($goals as $goal): ?>
      <?php
        $goalId = (int) $goal['id'];
        $target = (int) $goal['target_amount'];
        $current = (int) $goal['current_amount'];
        $remaining = max(0, $target - $current);
        $percent = (int) min(100, (int) round($target > 0 ? $current / $target * 100 : 0));
        $estimate = saving_estimate($remaining, $goal['deadline'] === null ? null : (string) $goal['deadline']);
        $isActive = (string) $goal['status'] === 'active';
        $fillClass = $estimate['overdue'] && $remaining > 0 ? ' is-warning' : '';
      ?>
      <article class="goal-card">
        <div class="goal-head">
          <div>
            <h3><?= icon('target') ?> <?= e($goal['title']) ?></h3>
            <p>
              <?php if ($goal['deadline'] !== null && $goal['deadline'] !== ''): ?>
                <?= icon('calendar') ?> Tenggat <?= e(date('d/m/Y', strtotime((string) $goal['deadline']))) ?>
              <?php else: ?>
                Tanpa tenggat
              <?php endif; ?>
            </p>
          </div>
          <span class="badge <?= $isActive ? 'badge-info' : 'badge-success' ?>"><?= $isActive ? 'Aktif' : 'Selesai' ?></span>
        </div>

        <div>
          <div class="progress-track" role="img" aria-label="Progres <?= e($goal['title']) ?> <?= $percent ?> persen">
            <div class="progress-fill<?= $fillClass ?>" style="width: <?= $percent ?>%"></div>
          </div>
          <div class="progress-meta">
            <span><?= e(format_rupiah($current)) ?> / <?= e(format_rupiah($target)) ?></span>
            <span><?= $percent ?>%</span>
          </div>
        </div>

        <?php if ($remaining > 0): ?>
          <p class="stat-meta"><?= icon('wallet') ?> Sisa <?= e(format_rupiah($remaining)) ?></p>
        <?php else: ?>
          <p class="stat-meta"><?= icon('check') ?> Target tercapai</p>
        <?php endif; ?>

        <?php if ($estimate['weekly'] > 0): ?>
          <p class="stat-meta">
            <?= icon('calendar') ?>
            Estimasi <?= e(format_rupiah((int) $estimate['weekly'])) ?>/minggu ·
            <?= e(format_rupiah((int) $estimate['monthly'])) ?>/bulan
            <?php if ($estimate['overdue']): ?>
              · Tenggat terlewat <?= abs((int) $estimate['days']) ?> hari
            <?php elseif ($estimate['days'] !== null): ?>
              · <?= (int) $estimate['days'] ?> hari tersisa
            <?php endif; ?>
          </p>
        <?php endif; ?>

        <?php if ($isActive): ?>
          <form class="goal-actions" method="post" action="<?= e(url('process/savings_process.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="topup">
            <input type="hidden" name="id" value="<?= $goalId ?>">
            <div class="field">
              <label class="sr-only" for="topup-<?= $goalId ?>">Nominal setor</label>
              <input id="topup-<?= $goalId ?>" name="amount" type="text" inputmode="numeric" placeholder="Setor Rp..." required>
            </div>
            <button class="btn btn-sm btn-accent" type="submit"><?= icon('arrow-up') ?> Setor</button>
          </form>

          <form class="goal-actions" method="post" action="<?= e(url('process/savings_process.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="withdraw">
            <input type="hidden" name="id" value="<?= $goalId ?>">
            <div class="field">
              <label class="sr-only" for="withdraw-<?= $goalId ?>">Nominal tarik</label>
              <input id="withdraw-<?= $goalId ?>" name="amount" type="text" inputmode="numeric" placeholder="Tarik Rp..." required>
            </div>
            <button class="btn btn-sm" type="submit"><?= icon('arrow-down') ?> Tarik</button>
          </form>
        <?php endif; ?>

        <div class="goal-actions">
          <?php if ($isActive): ?>
            <form method="post" action="<?= e(url('process/savings_process.php')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="complete">
              <input type="hidden" name="id" value="<?= $goalId ?>">
              <button class="btn btn-sm btn-primary" type="submit"><?= icon('check') ?> Tandai selesai</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= e(url('process/savings_process.php')) ?>" data-confirm="Hapus target tabungan ini?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $goalId ?>">
            <button class="btn btn-sm btn-danger" type="submit"><?= icon('trash') ?> Hapus</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="modal" id="goal-create" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="goal-create-title">
    <div class="modal-head">
      <h2 id="goal-create-title">Buat target tabungan</h2>
      <button class="icon-button" type="button" data-modal-close aria-label="Tutup"><?= icon('close') ?></button>
    </div>
    <div class="modal-body">
      <form method="post" action="<?= e(url('process/savings_process.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="field full">
            <label for="goal-title">Nama target</label>
            <input id="goal-title" name="title" type="text" maxlength="100" placeholder="Contoh: Dana Darurat" required>
          </div>
          <div class="field">
            <label for="goal-target">Target nominal</label>
            <input id="goal-target" name="target_amount" type="text" inputmode="numeric" placeholder="Rp1.000.000" required>
          </div>
          <div class="field">
            <label for="goal-initial">Nominal awal</label>
            <input id="goal-initial" name="initial_amount" type="text" inputmode="numeric" value="0">
            <span class="field-hint">Maksimal <?= e(format_rupiah($balance)) ?>.</span>
          </div>
          <div class="field full">
            <label for="goal-deadline">Tenggat waktu (opsional)</label>
            <input id="goal-deadline" name="deadline" type="date">
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= icon('check') ?> Simpan target</button>
          <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php
render_footer();
