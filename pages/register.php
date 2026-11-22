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
<html lang="id" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Daftar akun SakuKu">
  <title>Daftar - SakuKu</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>">
  <script src="<?= e(url('assets/js/main.js')) ?>" defer></script>
</head>
<body>
  <div class="auth-shell">
    <main class="auth-card">
      <div class="auth-brand">
        <span class="brand-mark" aria-hidden="true"><?= icon('wallet') ?></span>
        <span class="brand-text">Saku<span>Ku</span></span>
      </div>
      <h1>Buat akun baru</h1>
      <p>Satu tempat untuk catatan keuanganmu.</p>

      <?php if ($messages): ?>
        <div class="flash-stack" aria-live="polite">
          <?php foreach ($messages as $message): ?>
            <div class="flash flash-<?= e($message['type']) ?>">
              <?= icon($message['type'] === 'success' ? 'check' : ($message['type'] === 'error' ? 'alert' : 'info')) ?>
              <span><?= e($message['message']) ?></span>
              <button class="flash-close" type="button" data-flash-close aria-label="Tutup pesan"><?= icon('close') ?></button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form action="<?= e(url('process/auth_process.php')) ?>" method="post" novalidate>
        <input type="hidden" name="action" value="register">
        <?= csrf_field() ?>
        <div class="form-grid">
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
          <div class="field">
            <label for="security_question">Pertanyaan keamanan</label>
            <select id="security_question" name="security_question" required>
              <option value="">Pilih pertanyaan</option>
              <?php foreach ($questions as $question): ?>
                <option value="<?= e($question) ?>" <?= ($old['security_question'] ?? '') === $question ? 'selected' : '' ?>><?= e($question) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="field-hint">Dipakai untuk memulihkan kata sandi tanpa email.</span>
          </div>
          <div class="field">
            <label for="security_answer">Jawaban keamanan</label>
            <input id="security_answer" name="security_answer" type="text" autocomplete="off" maxlength="255" placeholder="Jawabanmu" required>
            <span class="field-hint">Jawaban disimpan terenkripsi.</span>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= icon('check') ?> Daftar</button>
        </div>
      </form>

      <div class="auth-links">
        <a href="<?= e(url('pages/login.php')) ?>">Sudah punya akun? Masuk</a>
      </div>
    </main>
  </div>
</body>
</html>
