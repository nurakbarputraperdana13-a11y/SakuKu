<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$email = '';
$messages = pull_flash();
?>
<!doctype html>
<html lang="id" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Masuk ke SakuKu">
  <title>Masuk - SakuKu</title>
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
      <h1>Selamat datang kembali</h1>
      <p>Masuk untuk mengelola keuanganmu.</p>

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
        <input type="hidden" name="action" value="login">
        <?= csrf_field() ?>
        <div class="form-grid">
          <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" autocomplete="email" inputmode="email" placeholder="nama@email.com" value="<?= e($email) ?>" required>
          </div>
          <div class="field">
            <label for="password">Kata sandi</label>
            <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Masukkan kata sandi" required>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= icon('key') ?> Masuk</button>
        </div>
      </form>

      <div class="auth-links">
        <a href="<?= e(url('pages/forgot_password.php')) ?>">Lupa kata sandi?</a>
        <a href="<?= e(url('pages/register.php')) ?>">Daftar akun</a>
      </div>
    </main>
  </div>
</body>
</html>
