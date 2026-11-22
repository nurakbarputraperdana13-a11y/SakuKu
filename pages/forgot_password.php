<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$messages = pull_flash();
$resetEmail = '';
$resetQuestion = '';

/** Store only hashes: no raw email or IP is persisted with failed attempts. */
function sk_reset_attempt_keys(string $email): array
{
    return [
        hash('sha256', mb_strtolower(trim($email))),
        hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')),
    ];
}

function sk_reset_attempts_load(PDO $pdo, string $emailHash, string $ipHash): array
{
    $stmt = $pdo->prepare(
        'SELECT attempt_count, window_start FROM security_answer_attempts
         WHERE email_hash = :email_hash AND ip_hash = :ip_hash LIMIT 1'
    );
    $stmt->execute([':email_hash' => $emailHash, ':ip_hash' => $ipHash]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['count' => 0, 'started' => time()];
    }

    $started = strtotime((string) $row['window_start']) ?: time();
    if (time() - $started > 900) {
        sk_reset_attempts_clear($pdo, $emailHash, $ipHash);
        return ['count' => 0, 'started' => time()];
    }

    return ['count' => (int) $row['attempt_count'], 'started' => $started];
}

function sk_reset_attempts_fail(PDO $pdo, string $emailHash, string $ipHash): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO security_answer_attempts (email_hash, ip_hash, attempt_count, window_start)
         VALUES (:email_hash, :ip_hash, 1, NOW())
         ON DUPLICATE KEY UPDATE attempt_count = attempt_count + 1'
    );
    $stmt->execute([':email_hash' => $emailHash, ':ip_hash' => $ipHash]);
}

function sk_reset_attempts_clear(PDO $pdo, string $emailHash, string $ipHash): void
{
    $stmt = $pdo->prepare(
        'DELETE FROM security_answer_attempts WHERE email_hash = :email_hash AND ip_hash = :ip_hash'
    );
    $stmt->execute([':email_hash' => $emailHash, ':ip_hash' => $ipHash]);
}

if (is_post()) {
    require_post();
    verify_csrf();

    $step = (string) ($_POST['step'] ?? '');

    if ($step === '1') {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            set_flash('error', 'Masukkan alamat email yang valid.');
            redirect('pages/forgot_password.php');
        }

        $stmt = $pdo->prepare('SELECT id, security_question FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            set_flash('error', 'Email tidak ditemukan.');
            redirect('pages/forgot_password.php');
        }

        $_SESSION['password_reset_email'] = $email;
        $resetEmail = $email;
        $resetQuestion = (string) $user['security_question'];
    } elseif ($step === '2') {
        $email = (string) ($_SESSION['password_reset_email'] ?? '');

        if ($email === '') {
            set_flash('error', 'Sesi pemulihan berakhir. Mulai lagi dari email.');
            redirect('pages/forgot_password.php');
        }

        $stmt = $pdo->prepare('SELECT id, security_answer_hash FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            unset($_SESSION['password_reset_email']);
            set_flash('error', 'Akun tidak ditemukan.');
            redirect('pages/forgot_password.php');
        }

        [$emailHash, $ipHash] = sk_reset_attempt_keys($email);
        $attempts = sk_reset_attempts_load($pdo, $emailHash, $ipHash);
        if ((int) $attempts['count'] >= 5) {
            set_flash('error', 'Terlalu banyak percobaan jawaban. Tunggu 15 menit sebelum mencoba lagi.');
            redirect('pages/forgot_password.php');
        }

        $answer = mb_strtolower(trim((string) ($_POST['security_answer'] ?? '')));
        if ($answer === '' || !password_verify($answer, (string) $user['security_answer_hash'])) {
            sk_reset_attempts_fail($pdo, $emailHash, $ipHash);
            set_flash('error', 'Jawaban keamanan tidak benar.');
            redirect('pages/forgot_password.php');
        }

        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        if (mb_strlen($password) < 8) {
            set_flash('error', 'Kata sandi baru minimal 8 karakter.');
            redirect('pages/forgot_password.php');
        }
        if (!hash_equals($password, $confirmation)) {
            set_flash('error', 'Konfirmasi kata sandi tidak cocok.');
            redirect('pages/forgot_password.php');
        }

        $stmt = $pdo->prepare(
            'UPDATE users SET password_hash = :hash, session_version = session_version + 1 WHERE id = :id'
        );
        $stmt->execute([
            ':hash' => password_hash($password, PASSWORD_DEFAULT),
            ':id'   => $user['id'],
        ]);

        sk_reset_attempts_clear($pdo, $emailHash, $ipHash);
        unset($_SESSION['password_reset_email']);
        session_regenerate_id(true);
        unset($_SESSION['user_id'], $_SESSION['session_version']);
        set_flash('success', 'Kata sandi berhasil diubah. Silakan masuk.');
        redirect('pages/login.php');
    } else {
        set_flash('error', 'Langkah pemulihan tidak valid.');
        redirect('pages/forgot_password.php');
    }
} else {
    $pendingEmail = (string) ($_SESSION['password_reset_email'] ?? '');
    if ($pendingEmail !== '') {
        $stmt = $pdo->prepare('SELECT id, security_question FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $pendingEmail]);
        $user = $stmt->fetch();
        if ($user) {
            $resetEmail = $pendingEmail;
            $resetQuestion = (string) $user['security_question'];
        } else {
            unset($_SESSION['password_reset_email']);
        }
    }
}
?>
<!doctype html>
<html lang="id" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Pulihkan kata sandi SakuKu">
  <title>Lupa Kata Sandi - SakuKu</title>
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
      <h1><?= $resetQuestion === '' ? 'Lupa kata sandi' : 'Pulihkan kata sandi' ?></h1>
      <p><?= $resetQuestion === '' ? 'Masukkan email akunmu untuk melihat pertanyaan keamanan.' : 'Jawab pertanyaan keamananmu, lalu buat kata sandi baru.' ?></p>

      <?php if ($messages): ?>
        <div class="flash-stack" aria-live="polite">
          <?php foreach ($messages as $message): ?>
            <div class="flash flash-<?= e($message['type']) ?>">
              <?= icon($message['type'] === 'success' ? 'check' : (in_array($message['type'], ['error', 'warning'], true) ? 'alert' : 'info')) ?>
              <span><?= e($message['message']) ?></span>
              <button class="flash-close" type="button" data-flash-close aria-label="Tutup pesan"><?= icon('close') ?></button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($resetQuestion === ''): ?>
        <form action="<?= e(url('pages/forgot_password.php')) ?>" method="post" novalidate>
          <input type="hidden" name="step" value="1">
          <?= csrf_field() ?>
          <div class="form-grid">
            <div class="field">
              <label for="email">Email</label>
              <input id="email" name="email" type="email" autocomplete="email" inputmode="email" placeholder="nama@email.com" required>
              <span class="field-hint">Hanya pertanyaan keamanan yang akan ditampilkan.</span>
            </div>
          </div>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('arrow-up') ?> Lanjut</button>
          </div>
        </form>
      <?php else: ?>
        <div class="alert alert-info" role="status">
          <?= icon('shield') ?>
          <span><strong><?= e($resetQuestion) ?></strong><br>Akun: <?= e($resetEmail) ?></span>
        </div>
        <form action="<?= e(url('pages/forgot_password.php')) ?>" method="post" novalidate>
          <input type="hidden" name="step" value="2">
          <?= csrf_field() ?>
          <div class="form-grid">
            <div class="field">
              <label for="security_answer">Jawaban keamanan</label>
              <input id="security_answer" name="security_answer" type="text" autocomplete="off" maxlength="255" placeholder="Jawabanmu" required>
            </div>
            <div class="field">
              <label for="password">Kata sandi baru</label>
              <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" placeholder="Minimal 8 karakter" required>
            </div>
            <div class="field">
              <label for="password_confirmation">Konfirmasi kata sandi baru</label>
              <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" placeholder="Ulangi kata sandi baru" required>
            </div>
          </div>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('key') ?> Simpan kata sandi</button>
          </div>
        </form>
      <?php endif; ?>

      <div class="auth-links">
        <a href="<?= e(url('pages/login.php')) ?>">Kembali ke masuk</a>
        <a href="<?= e(url('pages/register.php')) ?>">Daftar akun</a>
      </div>
    </main>
  </div>
</body>
</html>
