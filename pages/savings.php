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
<section class="flex flex-col gap-space-md md:flex-row md:items-end md:justify-between">
  <div>
    <p class="font-label-lg text-label-lg uppercase tracking-wider text-primary">Target Tabungan</p>
    <h1 class="mt-1 font-headline-xl text-headline-xl text-on-surface md:font-display-lg md:text-display-lg">Brankas sasaran</h1>
    <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Kunci dana untuk tujuan tertentu tanpa mengubah catatan arus kas.</p>
  </div>
  <button class="inline-flex h-12 items-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary shadow-md transition-colors hover:bg-primary" type="button" data-modal-open="goal-create">
    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add_circle</span>
    Buat target
  </button>
</section>

<section class="mt-space-lg grid grid-cols-1 gap-gutter sm:grid-cols-3 md:gap-gutter-desktop">
  <article class="bento-card">
    <span class="font-label-lg text-label-lg text-on-surface-variant">Saldo tersedia</span>
    <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah($balance)) ?></p>
    <span class="pill pill-neutral mt-space-md w-fit">setelah dikurangi tabungan</span>
  </article>
  <article class="bento-card">
    <span class="font-label-lg text-label-lg text-on-surface-variant">Target aktif</span>
    <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= $activeCount ?></p>
    <span class="pill pill-lime mt-space-md w-fit">sedang berjalan</span>
  </article>
  <article class="bento-card">
    <span class="font-label-lg text-label-lg text-on-surface-variant">Dana tertahan</span>
    <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah($activeTotal)) ?></p>
    <span class="pill pill-neutral mt-space-md w-fit">tidak termasuk saldo tersedia</span>
  </article>
</section>

<?php if ($goals === []): ?>
  <section class="bento-card mt-space-lg">
    <div class="flex flex-col items-center justify-center gap-2 rounded-2xl bg-surface-container-low px-4 py-14 text-center">
      <span class="material-symbols-outlined text-[32px] text-outline-variant" aria-hidden="true">savings</span>
      <p class="font-headline-lg text-headline-lg text-on-surface">Belum ada target tabungan</p>
      <p class="max-w-md font-body-md text-body-md text-on-surface-variant">Buat sasaran tabungan untuk dana darurat, laptop kuliah, atau kebutuhan lain dengan alokasi terpisah.</p>
      <button class="mt-2 inline-flex h-11 items-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary hover:bg-primary" type="button" data-modal-open="goal-create">Buat target pertama</button>
    </div>
  </section>
<?php else: ?>
  <section class="mt-space-lg grid grid-cols-1 gap-gutter md:grid-cols-2 md:gap-gutter-desktop xl:grid-cols-3">
    <?php foreach ($goals as $goal): ?>
      <?php
        $goalId = (int) $goal['id'];
        $target = (int) $goal['target_amount'];
        $current = (int) $goal['current_amount'];
        $remaining = max(0, $target - $current);
        $percent = (int) min(100, (int) round($target > 0 ? $current / $target * 100 : 0));
        $estimate = saving_estimate($remaining, $goal['deadline'] === null ? null : (string) $goal['deadline']);
        $isActive = (string) $goal['status'] === 'active';
        $barColor = $estimate['overdue'] && $remaining > 0 ? 'bg-amber-500' : 'bg-primary-container';
      ?>
      <article class="bento-card flex flex-col gap-space-md">
        <div class="flex items-start justify-between gap-space-sm">
          <div>
            <h3 class="font-headline-md text-headline-md text-on-surface"><?= e($goal['title']) ?></h3>
            <p class="mt-1 font-label-md text-label-md text-on-surface-variant">
              <?php if ($goal['deadline'] !== null && $goal['deadline'] !== ''): ?>
                Tenggat <?= e(date('d/m/Y', strtotime((string) $goal['deadline']))) ?>
              <?php else: ?>
                Tanpa tenggat
              <?php endif; ?>
            </p>
          </div>
          <span class="pill <?= $isActive ? 'pill-lime' : 'pill-neutral' ?>"><?= $isActive ? 'Aktif' : 'Selesai' ?></span>
        </div>

        <div>
          <div class="h-3 w-full overflow-hidden rounded-full bg-surface-container" role="img" aria-label="Progres <?= e($goal['title']) ?> <?= $percent ?> persen">
            <div class="h-full rounded-full <?= $barColor ?>" style="width: <?= $percent ?>%"></div>
          </div>
          <div class="mt-1 flex items-center justify-between font-label-md text-label-md text-on-surface-variant">
            <span class="tabular-nums"><?= e(format_rupiah($current)) ?> / <?= e(format_rupiah($target)) ?></span>
            <span><?= $percent ?>%</span>
          </div>
        </div>

        <?php if ($remaining > 0): ?>
          <p class="font-label-md text-label-md text-on-surface-variant">Sisa <?= e(format_rupiah($remaining)) ?></p>
        <?php else: ?>
          <p class="pill pill-lime w-fit">Target tercapai</p>
        <?php endif; ?>

        <?php if ($estimate['weekly'] > 0): ?>
          <p class="font-label-sm text-label-sm text-on-surface-variant">
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
          <form class="flex flex-col gap-space-xs" method="post" action="<?= e(url('process/savings_process.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="topup">
            <input type="hidden" name="id" value="<?= $goalId ?>">
            <label class="sr-only" for="topup-<?= $goalId ?>">Nominal setor</label>
            <input id="topup-<?= $goalId ?>" name="amount" type="text" inputmode="numeric" placeholder="Setor Rp..." required>
            <button class="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl bg-secondary-fixed font-label-lg text-label-lg font-bold text-on-secondary-fixed transition-colors hover:bg-secondary-fixed-dim" type="submit">
              <span class="material-symbols-outlined text-[18px]" aria-hidden="true">arrow_downward</span> Setor dana
            </button>
          </form>
          <form class="flex flex-col gap-space-xs" method="post" action="<?= e(url('process/savings_process.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="withdraw">
            <input type="hidden" name="id" value="<?= $goalId ?>">
            <label class="sr-only" for="withdraw-<?= $goalId ?>">Nominal tarik</label>
            <input id="withdraw-<?= $goalId ?>" name="amount" type="text" inputmode="numeric" placeholder="Tarik Rp..." required>
            <button class="inline-flex h-10 items-center justify-center gap-1.5 rounded-xl border border-on-surface/10 font-label-lg text-label-lg text-on-surface transition-colors hover:bg-surface-container-low" type="submit">
              <span class="material-symbols-outlined text-[18px]" aria-hidden="true">arrow_upward</span> Tarik dana
            </button>
          </form>
        <?php endif; ?>

        <div class="flex flex-wrap items-center gap-space-xs border-t border-surface-container pt-space-md">
          <?php if ($isActive): ?>
            <form method="post" action="<?= e(url('process/savings_process.php')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="complete">
              <input type="hidden" name="id" value="<?= $goalId ?>">
              <button class="inline-flex h-9 items-center rounded-full bg-primary-container px-space-md font-label-md text-label-md text-on-primary hover:bg-primary" type="submit">Tandai selesai</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= e(url('process/savings_process.php')) ?>" data-confirm="Hapus target tabungan ini?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $goalId ?>">
            <button class="inline-flex h-9 items-center rounded-full bg-red-50 px-space-md font-label-md text-label-md text-red-600 hover:bg-red-100" type="submit">Hapus</button>
          </form>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<div class="modal" id="goal-create" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="goal-create-title">
    <div class="flex items-center justify-between gap-space-sm border-b border-surface-container p-6">
      <div>
        <h2 class="font-headline-md text-headline-md" id="goal-create-title">Buat target tabungan</h2>
        <p class="font-label-sm text-label-sm text-on-surface-variant">Alokasikan dana ke brankas sasaran</p>
      </div>
      <button class="grid h-9 w-9 place-items-center rounded-full bg-surface-container-low text-on-surface-variant" type="button" data-modal-close aria-label="Tutup"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span></button>
    </div>
    <div class="max-h-[70vh] overflow-y-auto p-6">
      <form class="flex flex-col gap-space-md" method="post" action="<?= e(url('process/savings_process.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="field"><label for="goal-title">Nama target</label><input id="goal-title" name="title" type="text" maxlength="100" placeholder="Contoh: Dana Darurat" required></div>
        <div class="grid grid-cols-1 gap-space-md sm:grid-cols-2">
          <div class="field"><label for="goal-target">Target nominal</label><input id="goal-target" name="target_amount" type="text" inputmode="numeric" placeholder="Rp1.000.000" required></div>
          <div class="field"><label for="goal-initial">Nominal awal</label><input id="goal-initial" name="initial_amount" type="text" inputmode="numeric" value="0"><span class="field-hint">Maksimal <?= e(format_rupiah($balance)) ?>.</span></div>
        </div>
        <div class="field"><label for="goal-deadline">Tenggat waktu (opsional)</label><input id="goal-deadline" name="deadline" type="date"></div>
        <div class="flex flex-wrap gap-space-sm pt-2">
          <button class="inline-flex h-11 items-center rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary hover:bg-primary" type="submit">Simpan target</button>
          <button class="inline-flex h-11 items-center rounded-xl px-5 font-label-lg text-label-lg text-on-surface-variant hover:bg-surface-container-low" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php
render_footer();
