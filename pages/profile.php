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
?>
<?php render_header('Profil Saya', 'profile'); ?>

  <div class="page-head">
    <div>
      <h1>Profil Saya</h1>
      <p>Kelola identitas akun dan kata sandi SakuKu-mu.</p>
    </div>
  </div>

  <div class="grid grid-2">
    <section class="card">
      <div class="card-header">
        <h2><?= icon('user') ?> Data pribadi</h2>
      </div>
      <div class="card-body">
        <form action="<?= e(url('process/auth_process.php')) ?>" method="post" novalidate>
          <input type="hidden" name="action" value="profile_info">
          <?= csrf_field() ?>
          <div class="form-grid">
            <div class="field">
              <label for="name">Nama lengkap</label>
              <input id="name" name="name" type="text" autocomplete="name" maxlength="100" value="<?= e($currentUser['name']) ?>" required>
            </div>
            <div class="field">
              <label for="email">Email</label>
              <input id="email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="100" value="<?= e($currentUser['email']) ?>" required>
              <span class="field-hint">Email harus unik di SakuKu.</span>
            </div>
          </div>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('check') ?> Simpan perubahan</button>
          </div>
        </form>
      </div>
    </section>

    <section class="card">
      <div class="card-header">
        <h2><?= icon('lock') ?> Ubah kata sandi</h2>
      </div>
      <div class="card-body">
        <form action="<?= e(url('process/auth_process.php')) ?>" method="post" novalidate>
          <input type="hidden" name="action" value="profile_password">
          <?= csrf_field() ?>
          <div class="form-grid">
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
          </div>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= icon('key') ?> Perbarui kata sandi</button>
          </div>
        </form>
      </div>
    </section>
  </div>

  <div class="grid grid-stats mt-4">
    <div class="stat-card">
      <p class="stat-label">Saldo tersedia</p>
      <p class="stat-value"><?= e(format_rupiah($balance)) ?></p>
      <div class="stat-meta"><?= icon('wallet') ?> Dari seluruh catatan keuanganmu.</div>
    </div>
    <div class="stat-card">
      <p class="stat-label">Bergabung sejak</p>
      <p class="stat-value"><?= e($createdAt === '' ? '-' : date('d M Y', strtotime($createdAt))) ?></p>
      <div class="stat-meta"><?= icon('calendar') ?> Akun SakuKu aktif.</div>
    </div>
  </div>

<?php render_footer(); ?>
