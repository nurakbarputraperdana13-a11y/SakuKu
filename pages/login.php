<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$email = '';
$messages = pull_flash();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Masuk ke SakuKu">
  <title>Masuk - SakuKu</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="<?= e(url('assets/js/tailwind.config.js')) ?>"></script>
  <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=2">
  <script src="<?= e(url('assets/js/main.js')) ?>" defer></script>
</head>
<body class="min-h-screen bg-surface font-body-md text-on-surface antialiased">
  <main class="mx-auto flex min-h-screen w-full max-w-[1440px] items-center px-margin py-space-xl md:px-margin-desktop">
    <div class="grid w-full grid-cols-1 overflow-hidden rounded-3xl border border-surface-container bg-surface-container-lowest shadow-[0_24px_64px_-24px_rgba(17,24,39,0.25)] lg:grid-cols-12">
      <section class="bento-dark hidden flex-col justify-between p-space-xl lg:col-span-5 lg:flex">
        <div>
          <div class="flex items-center gap-space-sm">
            <span class="grid h-11 w-11 place-items-center rounded-full bg-secondary-fixed text-[20px] font-bold text-on-secondary-fixed">S</span>
            <span class="font-headline-md text-headline-md">SakuKu</span>
          </div>
          <h2 class="mt-space-xl font-display-lg text-display-lg">Keuangan mahasiswa, lebih terarah.</h2>
          <p class="mt-space-md max-w-sm font-body-lg text-body-lg text-[#99f6e4]">Catat transaksi, atur pagu anggaran, dan pantau target tabungan dalam satu ruang yang tenang.</p>
        </div>
        <div class="mt-space-xl grid grid-cols-2 gap-space-md">
          <div class="rounded-2xl bg-white/10 p-space-md">
            <span class="material-symbols-outlined text-[22px] text-secondary-fixed" aria-hidden="true">lock</span>
            <p class="mt-2 font-label-lg text-label-lg">Data privat</p>
            <p class="font-label-sm text-label-sm text-[#99f6e4]">Sesi dikelola aman di server.</p>
          </div>
          <div class="rounded-2xl bg-white/10 p-space-md">
            <span class="material-symbols-outlined text-[22px] text-secondary-fixed" aria-hidden="true">query_stats</span>
            <p class="mt-2 font-label-lg text-label-lg">Ringkasan cepat</p>
            <p class="font-label-sm text-label-sm text-[#99f6e4]">Lihat arus kas bulan ini.</p>
          </div>
        </div>
      </section>

      <section class="p-space-lg md:p-space-xl lg:col-span-7">
        <div class="mb-space-lg flex items-center gap-space-sm lg:hidden">
          <span class="grid h-10 w-10 place-items-center rounded-full bg-primary-container font-bold text-on-primary">S</span>
          <span class="font-headline-md text-headline-md">SakuKu</span>
        </div>

        <p class="font-label-lg text-label-lg uppercase tracking-wider text-primary">Portal Autentikasi</p>
        <h1 class="mt-2 font-headline-xl text-headline-xl text-on-surface">Masuk ke akunmu</h1>
        <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Kelola pemasukan, pengeluaran, dan target tabunganmu.</p>

        <?php if ($messages): ?>
          <div class="flash-stack mt-space-lg" aria-live="polite">
            <?php foreach ($messages as $message): ?>
              <div class="flash flash-<?= e($message['type']) ?>">
                <?= icon($message['type'] === 'success' ? 'check' : ($message['type'] === 'error' ? 'alert' : 'info')) ?>
                <span><?= e($message['message']) ?></span>
                <button class="flash-close" type="button" data-flash-close aria-label="Tutup pesan"><?= icon('close') ?></button>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form class="mt-space-lg flex flex-col gap-space-md" action="<?= e(url('process/auth_process.php')) ?>" method="post" novalidate>
          <input type="hidden" name="action" value="login">
          <?= csrf_field() ?>
          <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" inputmode="email" placeholder="nama@email.com" value="<?= e($email) ?>" required>
          </div>
          <div class="field">
            <label for="password">Kata sandi</label>
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Masukkan kata sandi" required>
          </div>
          <button class="mt-2 inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary shadow-md transition-colors hover:bg-primary" type="submit">
            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">arrow_forward</span>
            Masuk ke SakuKu
          </button>
        </form>

        <div class="mt-space-lg flex flex-wrap items-center justify-between gap-space-sm border-t border-surface-container pt-space-md font-label-md text-label-md">
          <a class="font-semibold text-primary hover:underline" href="<?= e(url('pages/forgot_password.php')) ?>">Lupa kata sandi?</a>
          <a class="font-semibold text-primary hover:underline" href="<?= e(url('pages/register.php')) ?>">Belum punya akun? Daftar</a>
        </div>
      </section>
    </div>
  </main>
</body>
</html>
