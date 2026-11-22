<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/admin_check.php';
require_once __DIR__ . '/../includes/navbar.php';

$stmt = $pdo->query(
    'SELECT COUNT(*) AS total,
            COALESCE(SUM(status = \'active\'), 0) AS active,
            COALESCE(SUM(status = \'inactive\'), 0) AS inactive
     FROM users'
);
$stats = $stmt->fetch();

$search = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($search) > 100) {
    $search = mb_substr($search, 0, 100);
}
$statusFilter = (string) ($_GET['status'] ?? 'all');
$roleFilter = (string) ($_GET['role'] ?? 'all');
if (!in_array($statusFilter, ['all', 'active', 'inactive'], true)) {
    $statusFilter = 'all';
}
if (!in_array($roleFilter, ['all', 'admin', 'user'], true)) {
    $roleFilter = 'all';
}

$where = ['1 = 1'];
$params = [];
if ($search !== '') {
    $where[] = '(name LIKE :search_name OR email LIKE :search_email)';
    $params[':search_name'] = '%' . $search . '%';
    $params[':search_email'] = '%' . $search . '%';
}
if ($statusFilter !== 'all') {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
}
if ($roleFilter !== 'all') {
    $where[] = 'role = :role';
    $params[':role'] = $roleFilter;
}

$stmt = $pdo->prepare(
    'SELECT id, name, email, role, status, created_at
     FROM users
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY id DESC'
);
$stmt->execute($params);
$users = $stmt->fetchAll();

$questions = security_questions();
$selfId = (int) $currentUser['id'];

render_header('Manajemen Pengguna', 'admin_users');
?>

<div class="page-head">
  <div>
    <h1>Manajemen Pengguna</h1>
    <p>Kelola akun, peran, dan status akses seluruh pengguna SakuKu.</p>
  </div>
  <button class="btn btn-primary" type="button" data-modal-open="admin-create-user">
    <?= icon('plus') ?> Tambah pengguna
  </button>
</div>

<div class="grid grid-3">
  <div class="stat-card">
    <p class="stat-label">Total akun terdaftar</p>
    <p class="stat-value"><?= e((int) $stats['total']) ?></p>
    <div class="stat-meta"><?= icon('users') ?> seluruh akun</div>
    <span class="stat-icon"><?= icon('users') ?></span>
  </div>
  <div class="stat-card stat-income">
    <p class="stat-label">Total akun aktif</p>
    <p class="stat-value"><?= e((int) $stats['active']) ?></p>
    <div class="stat-meta"><?= icon('check') ?> bisa mengakses aplikasi</div>
    <span class="stat-icon"><?= icon('check') ?></span>
  </div>
  <div class="stat-card stat-saving">
    <p class="stat-label">Total akun nonaktif</p>
    <p class="stat-value"><?= e((int) $stats['inactive']) ?></p>
    <div class="stat-meta"><?= icon('lock') ?> akses login diblokir</div>
    <span class="stat-icon"><?= icon('lock') ?></span>
  </div>
</div>

<form class="filter-bar" method="get" action="<?= e(url('pages/admin_users.php')) ?>">
  <div class="field">
    <label for="admin-search">Cari nama atau email</label>
    <input id="admin-search" name="q" type="search" value="<?= e($search) ?>" placeholder="Nama atau email">
  </div>
  <div class="field">
    <label for="admin-status">Status</label>
    <select id="admin-status" name="status">
      <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Semua</option>
      <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Aktif</option>
      <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
    </select>
  </div>
  <div class="field">
    <label for="admin-role">Peran</label>
    <select id="admin-role" name="role">
      <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>Semua</option>
      <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
      <option value="user" <?= $roleFilter === 'user' ? 'selected' : '' ?>>User</option>
    </select>
  </div>
  <button class="btn btn-sm btn-primary" type="submit"><?= icon('search') ?> Terapkan</button>
  <a class="btn btn-sm btn-ghost" href="<?= e(url('pages/admin_users.php')) ?>">Reset</a>
</form>

<div class="card">
  <div class="card-header">
    <h2>Daftar pengguna</h2>
    <span class="badge"><?= count($users) ?> data</span>
  </div>
  <div class="card-body">
    <?php if ($users === []): ?>
      <div class="empty-state">
        <?= icon('users') ?>
        <strong>Belum ada pengguna</strong>
        <span>Ubah filter atau tambahkan akun baru.</span>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Nama Lengkap</th>
              <th>Email</th>
              <th>Peran</th>
              <th>Status</th>
              <th>Tanggal Terdaftar</th>
              <th class="num">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user): ?>
              <?php $isSelf = (int) $user['id'] === $selfId; ?>
              <tr>
                <td><?= e((int) $user['id']) ?></td>
                <td>
                  <strong><?= e($user['name']) ?></strong>
                  <?php if ($isSelf): ?><span class="badge badge-info">Anda</span><?php endif; ?>
                </td>
                <td><?= e($user['email']) ?></td>
                <td>
                  <?php if (($user['role'] ?? 'user') === 'admin'): ?>
                    <span class="badge badge-info">Admin</span>
                  <?php else: ?>
                    <span class="badge">User</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if (($user['status'] ?? 'active') === 'active'): ?>
                    <span class="badge badge-success">Aktif</span>
                  <?php else: ?>
                    <span class="badge badge-warning">Nonaktif</span>
                  <?php endif; ?>
                </td>
                <td><?= e(date('d M Y', strtotime((string) $user['created_at']))) ?></td>
                <td>
                  <div class="row-actions">
                    <button
                      class="btn btn-sm btn-ghost"
                      type="button"
                      data-modal-open="admin-edit-user"
                      data-user-id="<?= e((int) $user['id']) ?>"
                      data-user-name="<?= e($user['name']) ?>"
                      data-user-email="<?= e($user['email']) ?>"
                      data-user-role="<?= e($user['role']) ?>"
                      aria-label="Edit <?= e($user['name']) ?>"
                    ><?= icon('edit') ?></button>

                    <form method="post" action="<?= e(url('process/admin_user_process.php')) ?>">
                      <input type="hidden" name="action" value="toggle_status">
                      <input type="hidden" name="id" value="<?= e((int) $user['id']) ?>">
                      <?= csrf_field() ?>
                      <button
                        class="btn btn-sm btn-ghost"
                        type="submit"
                        <?= $isSelf ? 'disabled title="Tidak bisa menonaktifkan akun sendiri"' : '' ?>
                      ><?= ($user['status'] ?? 'active') === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                    </form>

                    <button
                      class="btn btn-sm btn-ghost"
                      type="button"
                      data-modal-open="admin-delete-user"
                      data-delete-id="<?= e((int) $user['id']) ?>"
                      data-delete-name="<?= e($user['name']) ?>"
                      <?= $isSelf ? 'disabled title="Tidak bisa menghapus akun sendiri"' : '' ?>
                      aria-label="Hapus <?= e($user['name']) ?>"
                    ><?= icon('trash') ?></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal" id="admin-create-user" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="admin-create-title">
    <div class="modal-head">
      <h2 id="admin-create-title">Tambah pengguna</h2>
      <button class="icon-button" type="button" data-modal-close aria-label="Tutup"><?= icon('close') ?></button>
    </div>
    <div class="modal-body">
      <form method="post" action="<?= e(url('process/admin_user_process.php')) ?>">
        <input type="hidden" name="action" value="create">
        <?= csrf_field() ?>
        <div class="form-grid cols-1">
          <div class="field">
            <label for="create-name">Nama lengkap</label>
            <input id="create-name" name="name" type="text" maxlength="100" autocomplete="name" required>
          </div>
          <div class="field">
            <label for="create-email">Email</label>
            <input id="create-email" name="email" type="email" maxlength="100" autocomplete="email" required>
          </div>
          <div class="field">
            <label for="create-password">Password awal</label>
            <input id="create-password" name="password" type="password" minlength="6" autocomplete="new-password" required>
            <span class="field-hint">Minimal 6 karakter.</span>
          </div>
          <div class="form-grid cols-2">
            <div class="field">
              <label for="create-role">Peran</label>
              <select id="create-role" name="role">
                <option value="user" selected>User</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <div class="field">
              <label for="create-status">Status awal</label>
              <select id="create-status" name="status">
                <option value="active" selected>Aktif</option>
                <option value="inactive">Nonaktif</option>
              </select>
            </div>
          </div>
          <div class="field">
            <label for="create-question">Pertanyaan keamanan</label>
            <select id="create-question" name="security_question" required>
              <?php foreach ($questions as $question): ?>
                <option value="<?= e($question) ?>"><?= e($question) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="create-answer">Jawaban keamanan</label>
            <input id="create-answer" name="security_answer" type="text" maxlength="255" required>
            <span class="field-hint">Dipakai untuk pemulihan kata sandi akun ini.</span>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
          <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Tambah pengguna</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal" id="admin-edit-user" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="admin-edit-title">
    <div class="modal-head">
      <h2 id="admin-edit-title">Edit pengguna</h2>
      <button class="icon-button" type="button" data-modal-close aria-label="Tutup"><?= icon('close') ?></button>
    </div>
    <div class="modal-body">
      <form id="admin-edit-form" method="post" action="<?= e(url('process/admin_user_process.php')) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="">
        <?= csrf_field() ?>
        <div class="form-grid cols-1">
          <div class="field">
            <label for="edit-name">Nama lengkap</label>
            <input id="edit-name" name="name" type="text" maxlength="100" required>
          </div>
          <div class="field">
            <label for="edit-email">Email</label>
            <input id="edit-email" name="email" type="email" maxlength="100" required>
          </div>
          <div class="field">
            <label for="edit-password">Password baru</label>
            <input id="edit-password" name="password" type="password" minlength="6" autocomplete="new-password">
            <span class="field-hint">Kosongkan untuk mempertahankan password lama.</span>
          </div>
          <div class="field">
            <label for="edit-role">Peran</label>
            <select id="edit-role" name="role">
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
          <button class="btn btn-primary" type="submit"><?= icon('check') ?> Simpan perubahan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal" id="admin-delete-user" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="admin-delete-title">
    <div class="modal-head">
      <h2 id="admin-delete-title">Hapus pengguna</h2>
      <button class="icon-button" type="button" data-modal-close aria-label="Tutup"><?= icon('close') ?></button>
    </div>
    <div class="modal-body">
      <p>Hapus akun <strong id="admin-delete-name"></strong> secara permanen? Riwayat keuangan akun ini ikut terhapus.</p>
      <form method="post" action="<?= e(url('process/admin_user_process.php')) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="admin-delete-id" value="">
        <?= csrf_field() ?>
        <div class="form-actions">
          <button class="btn btn-ghost" type="button" data-modal-close>Batal</button>
          <button class="btn btn-danger" type="submit"><?= icon('trash') ?> Hapus akun</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php render_footer(); ?>
