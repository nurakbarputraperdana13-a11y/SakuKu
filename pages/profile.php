<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int) $currentUser['id'];
$balance = available_balance($pdo, $userId);

$stmt = $pdo->prepare('SELECT created_at FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$createdAt = (string) ($stmt->fetchColumn() ?: '');
render_header('Profil Saya', 'profile');
?>
<section class="flex flex-col gap-space-md md:flex-row md:items-end md:justify-between">
  <div>
    <p class="font-label-lg text-label-lg uppercase tracking-wider text-primary">Akun / Profil</p>
    <h1 class="mt-1 font-headline-xl text-headline-xl text-on-surface md:font-display-lg md:text-display-lg">Profil saya</h1>
    <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Kelola identitas akun dan kata sandi SakuKu-mu.</p>
  </div>
  <div class="flex items-center gap-space-sm rounded-full bg-surface-container-low px-space-md py-space-xs shadow-sm">
    <span class="grid h-8 w-8 place-items-center rounded-full bg-primary-container text-[14px] font-bold text-on-primary" aria-hidden="true"><?= e(mb_substr((string) $currentUser['name'], 0, 1)) ?></span>
    <span class="font-label-lg text-label-lg font-semibold text-on-surface"><?= e($currentUser['name']) ?></span>
  </div>
</section>

<section class="mt-space-lg grid grid-cols-1 gap-gutter md:grid-cols-2 md:gap-gutter-desktop">
  <div class="bento-card">
    <div class="flex items-center gap-space-sm">
      <span class="grid h-10 w-10 place-items-center rounded-full bg-surface-container-low text-primary"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">person</span></span>
      <div>
        <h2 class="font-headline-md text-headline-md text-on-surface">Data pribadi</h2>
        <p class="font-label-sm text-label-sm text-on-surface-variant">Nama dan email akun SakuKu.</p>
      </div>
    </div>
    <form class="mt-space-lg flex flex-col gap-space-md" action="<?= e(url('process/auth_process.php')) ?>" method="post" novalidate>
      <input type="hidden" name="action" value="profile_info">
      <?= csrf_field() ?>
      <div class="field">
        <label for="name">Nama lengkap</label>
        <input id="name" name="name" type="text" autocomplete="name" maxlength="100" value="<?= e($currentUser['name']) ?>" required>
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="100" value="<?= e($currentUser['email']) ?>" required>
        <span class="field-hint">Email harus unik di SakuKu.</span>
      </div>
      <button class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary transition-colors hover:bg-primary" type="submit">
        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">check</span> Simpan perubahan
      </button>
    </form>
  </div>

  <div class="bento-card">
    <div class="flex items-center gap-space-sm">
      <span class="grid h-10 w-10 place-items-center rounded-full bg-surface-container-low text-primary"><span class="material-symbols-outlined text-[20px]" aria-hidden="true">lock</span></span>
      <div>
        <h2 class="font-headline-md text-headline-md text-on-surface">Ubah kata sandi</h2>
        <p class="font-label-sm text-label-sm text-on-surface-variant">Minimal 8 karakter.</p>
      </div>
    </div>
    <form class="mt-space-lg flex flex-col gap-space-md" action="<?= e(url('process/auth_process.php')) ?>" method="post" novalidate>
      <input type="hidden" name="action" value="profile_password">
      <?= csrf_field() ?>
      <div class="field">
        <label for="current_password">Kata sandi saat ini</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" placeholder="Masukkan kata sandi saat ini" required>
      </div>
      <div class="field">
        <label for="new_password">Kata sandi baru</label>
        <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" placeholder="Minimal 8 karakter" required>
      </div>
      <div class="field">
        <label for="new_password_confirmation">Konfirmasi kata sandi baru</label>
        <input id="new_password_confirmation" name="new_password_confirmation" type="password" autocomplete="new-password" minlength="8" placeholder="Ulangi kata sandi baru" required>
      </div>
      <button class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary transition-colors hover:bg-primary" type="submit">
        <span class="material-symbols-outlined text-[18px]" aria-hidden="true">key</span> Perbarui kata sandi
      </button>
    </form>
  </div>
</section>

<section class="mt-space-lg grid grid-cols-1 gap-gutter sm:grid-cols-2 md:gap-gutter-desktop">
  <article class="bento-card">
    <span class="font-label-lg text-label-lg text-on-surface-variant">Saldo tersedia</span>
    <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e(format_rupiah($balance)) ?></p>
    <span class="pill pill-neutral mt-space-md w-fit">dari seluruh catatan keuanganmu</span>
  </article>
  <article class="bento-card">
    <span class="font-label-lg text-label-lg text-on-surface-variant">Bergabung sejak</span>
    <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e($createdAt === '' ? '-' : date('d M Y', strtotime($createdAt))) ?></p>
    <span class="pill pill-lime mt-space-md w-fit">akun SakuKu aktif</span>
  </article>
</section>

<?php
render_footer();
