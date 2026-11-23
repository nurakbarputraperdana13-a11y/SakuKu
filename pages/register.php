<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$questions = [
    'Apa nama hewan peliharaan pertamamu?',
    'Di kota mana ibumu lahir?',
    'Apa nama sekolah dasarmu?',
    'Apa makanan favoritmu semasa kecil?',
];

$old = $_SESSION['register_old'] ?? [];
unset($_SESSION['register_old']);
if (!is_array($old)) {
    $old = [];
}

$messages = pull_flash();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Daftar akun SakuKu">
  <title>Daftar - SakuKu</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="<?= e(url('assets/js/tailwind.config.js')) ?>"></script>
  <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
  <script src="<?= e(url('assets/js/main.js')) ?>" defer></script>
</head>
<body class="min-h-screen bg-surface font-body-md text-on-surface antialiased">
  <main class="mx-auto w-full max-w-[1440px] px-margin py-space-xl md:px-margin-desktop">
    <div class="mx-auto grid w-full max-w-5xl grid-cols-1 overflow-hidden rounded-3xl border border-surface-container bg-surface-container-lowest shadow-[0_24px_64px_-24px_rgba(17,24,39,0.25)] lg:grid-cols-12">
      <aside class="bento-dark flex flex-col justify-between p-space-xl lg:col-span-4">
        <div>
          <div class="flex items-center gap-space-sm">
            <span class="grid h-11 w-11 place-items-center rounded-full bg-secondary-fixed text-[20px] font-bold text-on-secondary-fixed">S</span>
            <span class="font-headline-md text-headline-md">SakuKu</span>
          </div>
          <h2 class="mt-space-xl font-headline-xl text-headline-xl">Mulai dari satu catatan.</h2>
          <p class="mt-space-md font-body-md text-body-md text-[#99f6e4]">Satu akun untuk transaksi, anggaran, tabungan, dan patungan.</p>
        </div>
        <ul class="mt-space-xl flex flex-col gap-space-sm">
          <li class="flex items-center gap-space-sm rounded-2xl bg-white/10 px-space-md py-space-sm font-label-md text-label-md"><span class="material-symbols-outlined text-[18px] text-secondary-fixed" aria-hidden="true">check_circle</span> Catat pemasukan &amp; pengeluaran</li>
          <li class="flex items-center gap-space-sm rounded-2xl bg-white/10 px-space-md py-space-sm font-label-md text-label-md"><span class="material-symbols-outlined text-[18px] text-secondary-fixed" aria-hidden="true">check_circle</span> Pagu anggaran dengan peringatan</li>
          <li class="flex items-center gap-space-sm rounded-2xl bg-white/10 px-space-md py-space-sm font-label-md text-label-md"><span class="material-symbols-outlined text-[18px] text-secondary-fixed" aria-hidden="true">check_circle</span> Target tabungan terpisah</li>
        </ul>
      </aside>

      <section class="p-space-lg md:p-space-xl lg:col-span-8">
        <p class="font-label-lg text-label-lg uppercase tracking-wider text-primary">Pendaftaran Akun</p>
        <h1 class="mt-2 font-headline-xl text-headline-xl">Buat akun baru</h1>
        <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Isi data di bawah untuk mulai memakai SakuKu.</p>

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
          <input type="hidden" name="action" value="register">
          <?= csrf_field() ?>
          <div class="grid grid-cols-1 gap-space-md sm:grid-cols-2">
            <div class="field">
              <label for="name">Nama lengkap</label>
              <input id="name" name="name" type="text" autocomplete="name" maxlength="100" placeholder="Nama lengkapmu" value="<?= e($old['name'] ?? '') ?>" required>
            </div>
            <div class="field">
              <label for="email">Email</label>
              <input id="email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="100" placeholder="nama@email.com" value="<?= e($old['email'] ?? '') ?>" required>
            </div>
            <div class="field">
              <label for="password">Kata sandi</label>
              <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" placeholder="Minimal 8 karakter" required>
              <span class="field-hint">Minimal 8 karakter.</span>
            </div>
            <div class="field">
              <label for="password_confirmation">Konfirmasi kata sandi</label>
              <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" placeholder="Ulangi kata sandi" required>
            </div>
            <div class="field sm:col-span-2">
              <label for="security_question">Pertanyaan keamanan</label>
              <select id="security_question" name="security_question" required>
                <option value="">Pilih pertanyaan</option>
                <?php foreach ($questions as $question): ?>
                  <option value="<?= e($question) ?>" <?= ($old['security_question'] ?? '') === $question ? 'selected' : '' ?>><?= e($question) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="field-hint">Dipakai untuk memulihkan kata sandi tanpa email.</span>
            </div>
            <div class="field sm:col-span-2">
              <label for="security_answer">Jawaban keamanan</label>
              <input id="security_answer" name="security_answer" type="text" autocomplete="off" maxlength="255" placeholder="Jawabanmu" required>
              <span class="field-hint">Jawaban disimpan terenkripsi.</span>
            </div>
          </div>
          <button class="mt-2 inline-flex h-12 items-center justify-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary shadow-md transition-colors hover:bg-primary" type="submit">
            <span class="material-symbols-outlined text-[18px]" aria-hidden="true">arrow_forward</span>
            Daftar akun
          </button>
        </form>

        <p class="mt-space-lg text-center font-label-md text-label-md text-on-surface-variant">
          Sudah punya akun? <a class="font-bold text-primary hover:underline" href="<?= e(url('pages/login.php')) ?>">Masuk</a>
        </p>
      </section>
    </div>
  </main>
</body>
</html>
