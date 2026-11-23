<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int) $currentUser['id'];

$stmt = $pdo->prepare(
    'SELECT g.id, g.name, g.description, g.invite_code, gm.role,
            (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) AS member_count,
            (SELECT COUNT(*) FROM group_expenses WHERE group_id = g.id) AS expense_count
     FROM group_members gm
     JOIN `groups` g ON g.id = gm.group_id
     WHERE gm.user_id = :user_id
     ORDER BY gm.joined_at DESC, g.id DESC'
);
$stmt->execute([':user_id' => $userId]);
$groups = $stmt->fetchAll();

render_header('Split Bill', 'groups');
?>
<section class="flex flex-col gap-space-md md:flex-row md:items-end md:justify-between">
  <div>
    <p class="font-label-lg text-label-lg uppercase tracking-wider text-primary">Wealth Operations / Split Bill</p>
    <h1 class="mt-1 font-headline-xl text-headline-xl text-on-surface md:font-display-lg md:text-display-lg">Patungan &amp; penyelesaian utang</h1>
    <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Kelola tagihan bareng tanpa mengubah riwayat transaksi pribadi.</p>
  </div>
  <span class="pill pill-lime"><?= count($groups) ?> grup aktif</span>
</section>

<section class="mt-space-lg grid grid-cols-1 gap-gutter md:grid-cols-2 md:gap-gutter-desktop">
  <div class="bento-card">
    <div class="flex items-center gap-space-sm">
      <span class="grid h-10 w-10 place-items-center rounded-full bg-surface-container-low text-primary"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">group_add</span></span>
      <div>
        <h2 class="font-headline-md text-headline-md text-on-surface">Buat grup baru</h2>
        <p class="font-label-sm text-label-sm text-on-surface-variant">Undang teman lewat kode undangan.</p>
      </div>
    </div>
    <form class="mt-space-lg flex flex-col gap-space-md" method="post" action="<?= e(url('process/group_process.php')) ?>">
      <input type="hidden" name="action" value="create">
      <?= csrf_field() ?>
      <div class="field"><label for="group-name">Nama grup</label><input id="group-name" name="name" type="text" maxlength="100" placeholder="Mis. Kos Rw 04" required></div>
      <div class="field"><label for="group-description">Deskripsi</label><textarea id="group-description" name="description" maxlength="255" placeholder="Tujuan penggunaan grup"></textarea></div>
      <button class="inline-flex h-11 items-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary transition-colors hover:bg-primary" type="submit">
        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">add</span> Buat grup
      </button>
    </form>
  </div>

  <div class="bento-card">
    <div class="flex items-center gap-space-sm">
      <span class="grid h-10 w-10 place-items-center rounded-full bg-surface-container-low text-primary"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">key</span></span>
      <div>
        <h2 class="font-headline-md text-headline-md text-on-surface">Gabung grup</h2>
        <p class="font-label-sm text-label-sm text-on-surface-variant">Masukkan kode dari admin grup.</p>
      </div>
    </div>
    <form class="mt-space-lg flex flex-col gap-space-md" method="post" action="<?= e(url('process/group_process.php')) ?>">
      <input type="hidden" name="action" value="join">
      <?= csrf_field() ?>
      <div class="field">
        <label for="invite-code">Kode undangan</label>
        <input id="invite-code" name="invite_code" type="text" maxlength="20" autocomplete="off" placeholder="ABCDEFGH" required>
        <span class="field-hint">Kode diberikan oleh admin grup.</span>
      </div>
      <button class="inline-flex h-11 items-center gap-2 rounded-xl border border-on-surface/10 px-5 font-label-lg text-label-lg text-on-surface transition-colors hover:bg-surface-container-low" type="submit">
        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">check</span> Gabung
      </button>
    </form>
  </div>
</section>

<section class="bento-card mt-space-lg">
  <div class="flex flex-wrap items-center justify-between gap-space-sm">
    <div>
      <h2 class="font-headline-lg text-headline-lg text-on-surface">Grup saya</h2>
      <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Pilih grup untuk melihat sesi patungan.</p>
    </div>
    <span class="pill pill-neutral"><?= count($groups) ?> grup</span>
  </div>
  <div class="mt-space-lg">
    <?php if (!$groups): ?>
      <div class="flex flex-col items-center justify-center gap-2 rounded-2xl bg-surface-container-low px-4 py-12 text-center">
        <span class="material-symbols-outlined text-[28px] text-outline-variant" aria-hidden="true">group</span>
        <p class="font-headline-md text-headline-md text-on-surface">Belum ada grup</p>
        <p class="max-w-sm font-body-md text-body-md text-on-surface-variant">Buat grup baru atau masukkan kode undangan.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-left font-body-md text-body-md">
          <thead>
            <tr class="border-b border-surface-container text-on-surface-variant">
              <th class="py-2 font-label-md text-label-md">Grup</th>
              <th class="py-2 font-label-md text-label-md">Kode undangan</th>
              <th class="py-2 text-right font-label-md text-label-md">Anggota</th>
              <th class="py-2 text-right font-label-md text-label-md">Tagihan</th>
              <th class="py-2 font-label-md text-label-md">Role</th>
              <th class="py-2 text-right font-label-md text-label-md">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groups as $group): ?>
              <?php $groupId = (int) $group['id']; ?>
              <tr class="border-b border-surface-container/70 transition-colors hover:bg-surface-container-low">
                <td class="py-3">
                  <span class="block font-semibold text-on-surface"><?= e($group['name']) ?></span>
                  <?php if (!empty($group['description'])): ?><span class="font-label-sm text-label-sm text-on-surface-variant"><?= e($group['description']) ?></span><?php endif; ?>
                </td>
                <td class="py-3">
                  <div class="inline-flex items-center gap-space-xs rounded-full bg-surface-container-low px-space-md py-space-xs font-label-md text-label-md tabular-nums text-on-surface">
                    <span><?= e($group['invite_code']) ?></span>
                    <button class="text-primary hover:underline" type="button" data-copy="<?= e($group['invite_code']) ?>" aria-label="Salin kode undangan">Salin</button>
                  </div>
                </td>
                <td class="py-3 text-right tabular-nums text-on-surface"><?= (int) $group['member_count'] ?></td>
                <td class="py-3 text-right tabular-nums text-on-surface"><?= (int) $group['expense_count'] ?></td>
                <td class="py-3"><span class="pill <?= $group['role'] === 'admin' ? 'pill-lime' : 'pill-neutral' ?>"><?= $group['role'] === 'admin' ? 'Admin' : 'Anggota' ?></span></td>
                <td class="py-3 text-right">
                  <a class="inline-flex h-9 items-center rounded-full bg-primary-container px-space-md font-label-md text-label-md text-on-primary hover:bg-primary" href="<?= e(url('pages/group_detail.php?id=' . $groupId)) ?>">Buka</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php
render_footer();
