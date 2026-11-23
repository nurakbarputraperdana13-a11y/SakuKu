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
<section class="flex flex-col gap-space-md md:flex-row md:items-end md:justify-between">
  <div>
    <p class="font-label-lg text-label-lg uppercase tracking-wider text-primary">Administrasi / Akses</p>
    <h1 class="mt-1 font-headline-xl text-headline-xl text-on-surface md:font-display-lg md:text-display-lg">Manajemen pengguna</h1>
    <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Kelola akun, peran, dan status akses seluruh pengguna SakuKu.</p>
  </div>
  <button class="inline-flex h-12 items-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary shadow-md transition-colors hover:bg-primary" type="button" data-modal-open="admin-create-user">
    <span class="material-symbols-outlined text-[18px]" aria-hidden="true">person_add</span> Tambah pengguna
  </button>
</section>

<section class="mt-space-lg grid grid-cols-1 gap-gutter sm:grid-cols-3 md:gap-gutter-desktop">
  <article class="bento-card">
    <span class="font-label-lg text-label-lg text-on-surface-variant">Total akun terdaftar</span>
    <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e((int) $stats['total']) ?></p>
    <span class="pill pill-neutral mt-space-md w-fit">seluruh akun</span>
  </article>
  <article class="bento-card">
    <span class="font-label-lg text-label-lg text-on-surface-variant">Akun aktif</span>
    <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e((int) $stats['active']) ?></p>
    <span class="pill pill-lime mt-space-md w-fit">bisa mengakses aplikasi</span>
  </article>
  <article class="bento-card">
    <span class="font-label-lg text-label-lg text-on-surface-variant">Akun nonaktif</span>
    <p class="mt-space-md font-metric-numeral-md text-metric-numeral-md tabular-nums text-on-surface"><?= e((int) $stats['inactive']) ?></p>
    <span class="pill pill-red mt-space-md w-fit">akses login diblokir</span>
  </article>
</section>

<form class="bento-card mt-space-lg grid grid-cols-1 gap-space-md sm:grid-cols-2 lg:grid-cols-5" method="get" action="<?= e(url('pages/admin_users.php')) ?>">
  <div class="field lg:col-span-2">
    <label for="admin-search">Cari nama atau email</label>
    <input id="admin-search" name="q" type="search" value="<?= e($search) ?>" placeholder="Nama atau email">
  </div>
  <div class="field lg:col-span-1">
    <label for="admin-status">Status</label>
    <select id="admin-status" name="status">
      <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Semua</option>
      <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Aktif</option>
      <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
    </select>
  </div>
  <div class="field lg:col-span-1">
    <label for="admin-role">Peran</label>
    <select id="admin-role" name="role">
      <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>Semua</option>
      <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
      <option value="user" <?= $roleFilter === 'user' ? 'selected' : '' ?>>User</option>
    </select>
  </div>
  <div class="flex items-end gap-space-sm lg:col-span-1">
    <button class="inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary transition-colors hover:bg-primary" type="submit">
      <span class="material-symbols-outlined text-[18px]" aria-hidden="true">search</span> Terapkan
    </button>
    <a class="inline-flex h-11 items-center rounded-xl border border-on-surface/10 px-4 font-label-lg text-label-lg text-on-surface-variant transition-colors hover:bg-surface-container-low" href="<?= e(url('pages/admin_users.php')) ?>">Reset</a>
  </div>
</form>

<section class="bento-card mt-space-lg">
  <div class="flex flex-wrap items-center justify-between gap-space-sm">
    <div>
      <h2 class="font-headline-lg text-headline-lg text-on-surface">Daftar pengguna</h2>
      <p class="mt-1 font-body-md text-body-md text-on-surface-variant">Status dan peran akun terdaftar.</p>
    </div>
    <span class="pill pill-neutral"><?= count($users) ?> data</span>
  </div>
  <div class="mt-space-lg">
    <?php if ($users === []): ?>
      <div class="flex flex-col items-center justify-center gap-2 rounded-2xl bg-surface-container-low px-4 py-12 text-center">
        <span class="material-symbols-outlined text-[28px] text-outline-variant" aria-hidden="true">group_off</span>
        <p class="font-headline-md text-headline-md text-on-surface">Belum ada pengguna</p>
        <p class="max-w-sm font-body-md text-body-md text-on-surface-variant">Ubah filter atau tambahkan akun baru.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-left font-body-md text-body-md">
          <thead>
            <tr class="border-b border-surface-container text-on-surface-variant">
              <th class="py-2 font-label-md text-label-md">ID</th>
              <th class="py-2 font-label-md text-label-md">Nama lengkap</th>
              <th class="py-2 font-label-md text-label-md">Email</th>
              <th class="py-2 font-label-md text-label-md">Peran</th>
              <th class="py-2 font-label-md text-label-md">Status</th>
              <th class="py-2 font-label-md text-label-md">Terdaftar</th>
              <th class="py-2 text-right font-label-md text-label-md">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user): ?>
              <?php $isSelf = (int) $user['id'] === $selfId; ?>
              <tr class="border-b border-surface-container/70 transition-colors hover:bg-surface-container-low">
                <td class="py-3 tabular-nums text-on-surface-variant"><?= e((int) $user['id']) ?></td>
                <td class="py-3">
                  <span class="block font-semibold text-on-surface"><?= e($user['name']) ?></span>
                  <?php if ($isSelf): ?><span class="pill pill-lime mt-1 w-fit">Anda</span><?php endif; ?>
                </td>
                <td class="py-3 text-on-surface-variant"><?= e($user['email']) ?></td>
                <td class="py-3"><span class="pill <?= ($user['role'] ?? 'user') === 'admin' ? 'pill-lime' : 'pill-neutral' ?>"><?= ($user['role'] ?? 'user') === 'admin' ? 'Admin' : 'User' ?></span></td>
                <td class="py-3"><span class="pill <?= ($user['status'] ?? 'active') === 'active' ? 'pill-lime' : 'pill-red' ?>"><?= ($user['status'] ?? 'active') === 'active' ? 'Aktif' : 'Nonaktif' ?></span></td>
                <td class="py-3 tabular-nums text-on-surface-variant"><?= e(date('d M Y', strtotime((string) $user['created_at']))) ?></td>
                <td class="py-3">
                  <div class="flex flex-wrap items-center justify-end gap-space-xs">
                    <button class="grid h-9 w-9 place-items-center rounded-full bg-surface-container-low text-primary transition-colors hover:bg-surface-container-high" type="button" data-modal-open="admin-edit-user" data-user-id="<?= e((int) $user['id']) ?>" data-user-name="<?= e($user['name']) ?>" data-user-email="<?= e($user['email']) ?>" data-user-role="<?= e($user['role']) ?>" aria-label="Edit <?= e($user['name']) ?>">
                      <span class="material-symbols-outlined text-[18px]" aria-hidden="true">edit</span>
                    </button>
                    <form method="post" action="<?= e(url('process/admin_user_process.php')) ?>">
                      <input type="hidden" name="action" value="toggle_status">
                      <input type="hidden" name="id" value="<?= e((int) $user['id']) ?>">
                      <?= csrf_field() ?>
                      <button class="inline-flex h-9 items-center rounded-full bg-surface-container-low px-space-md font-label-md text-label-md text-on-surface-variant transition-colors hover:bg-surface-container-high disabled:cursor-not-allowed disabled:opacity-40" type="submit" <?= $isSelf ? 'disabled title="Tidak bisa menonaktifkan akun sendiri"' : '' ?>>
                        <?= ($user['status'] ?? 'active') === 'active' ? 'Nonaktifkan' : 'Aktifkan' ?>
                      </button>
                    </form>
                    <button class="grid h-9 w-9 place-items-center rounded-full bg-red-50 text-red-600 transition-colors hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-40" type="button" data-modal-open="admin-delete-user" data-delete-id="<?= e((int) $user['id']) ?>" data-delete-name="<?= e($user['name']) ?>" <?= $isSelf ? 'disabled title="Tidak bisa menghapus akun sendiri"' : '' ?> aria-label="Hapus <?= e($user['name']) ?>">
                      <span class="material-symbols-outlined text-[18px]" aria-hidden="true">delete</span>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<div class="modal" id="admin-create-user" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="admin-create-title">
    <div class="flex items-center justify-between gap-space-sm border-b border-surface-container p-6">
      <h2 class="font-headline-md text-headline-md" id="admin-create-title">Tambah pengguna</h2>
      <button class="grid h-9 w-9 place-items-center rounded-full bg-surface-container-low text-on-surface-variant" type="button" data-modal-close aria-label="Tutup"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span></button>
    </div>
    <div class="max-h-[70vh] overflow-y-auto p-6">
      <form class="flex flex-col gap-space-md" method="post" action="<?= e(url('process/admin_user_process.php')) ?>">
        <input type="hidden" name="action" value="create">
        <?= csrf_field() ?>
        <div class="field"><label for="create-name">Nama lengkap</label><input id="create-name" name="name" type="text" maxlength="100" autocomplete="name" required></div>
        <div class="field"><label for="create-email">Email</label><input id="create-email" name="email" type="email" maxlength="100" autocomplete="email" required></div>
        <div class="field"><label for="create-password">Password awal</label><input id="create-password" name="password" type="password" minlength="6" autocomplete="new-password" required><span class="field-hint">Minimal 6 karakter.</span></div>
        <div class="grid grid-cols-1 gap-space-md sm:grid-cols-2">
          <div class="field">
            <label for="create-role">Peran</label>
            <select id="create-role" name="role"><option value="user" selected>User</option><option value="admin">Admin</option></select>
          </div>
          <div class="field">
            <label for="create-status">Status awal</label>
            <select id="create-status" name="status"><option value="active" selected>Aktif</option><option value="inactive">Nonaktif</option></select>
          </div>
        </div>
        <div class="field">
          <label for="create-question">Pertanyaan keamanan</label>
          <select id="create-question" name="security_question" required>
            <?php foreach ($questions as $question): ?><option value="<?= e($question) ?>"><?= e($question) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="field"><label for="create-answer">Jawaban keamanan</label><input id="create-answer" name="security_answer" type="text" maxlength="255" required><span class="field-hint">Dipakai untuk pemulihan kata sandi akun ini.</span></div>
        <div class="flex flex-wrap gap-space-sm pt-2">
          <button class="inline-flex h-11 items-center rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary transition-colors hover:bg-primary" type="submit">Tambah pengguna</button>
          <button class="inline-flex h-11 items-center rounded-xl px-5 font-label-lg text-label-lg text-on-surface-variant transition-colors hover:bg-surface-container-low" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal" id="admin-edit-user" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="admin-edit-title">
    <div class="flex items-center justify-between gap-space-sm border-b border-surface-container p-6">
      <h2 class="font-headline-md text-headline-md" id="admin-edit-title">Edit pengguna</h2>
      <button class="grid h-9 w-9 place-items-center rounded-full bg-surface-container-low text-on-surface-variant" type="button" data-modal-close aria-label="Tutup"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span></button>
    </div>
    <div class="max-h-[70vh] overflow-y-auto p-6">
      <form class="flex flex-col gap-space-md" id="admin-edit-form" method="post" action="<?= e(url('process/admin_user_process.php')) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="">
        <?= csrf_field() ?>
        <div class="field"><label for="edit-name">Nama lengkap</label><input id="edit-name" name="name" type="text" maxlength="100" required></div>
        <div class="field"><label for="edit-email">Email</label><input id="edit-email" name="email" type="email" maxlength="100" required></div>
        <div class="field"><label for="edit-password">Password baru</label><input id="edit-password" name="password" type="password" minlength="6" autocomplete="new-password"><span class="field-hint">Kosongkan untuk mempertahankan password lama.</span></div>
        <div class="field">
          <label for="edit-role">Peran</label>
          <select id="edit-role" name="role"><option value="user">User</option><option value="admin">Admin</option></select>
        </div>
        <div class="flex flex-wrap gap-space-sm pt-2">
          <button class="inline-flex h-11 items-center rounded-xl bg-primary-container px-5 font-label-lg text-label-lg text-on-primary transition-colors hover:bg-primary" type="submit">Simpan perubahan</button>
          <button class="inline-flex h-11 items-center rounded-xl px-5 font-label-lg text-label-lg text-on-surface-variant transition-colors hover:bg-surface-container-low" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal" id="admin-delete-user" aria-hidden="true">
  <div class="modal-backdrop" data-modal-close></div>
  <div class="modal-panel overflow-hidden rounded-3xl bg-white shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="admin-delete-title">
    <div class="flex items-center justify-between gap-space-sm border-b border-surface-container p-6">
      <h2 class="font-headline-md text-headline-md" id="admin-delete-title">Hapus pengguna</h2>
      <button class="grid h-9 w-9 place-items-center rounded-full bg-surface-container-low text-on-surface-variant" type="button" data-modal-close aria-label="Tutup"><span class="material-symbols-outlined text-[18px]" aria-hidden="true">close</span></button>
    </div>
    <div class="p-6">
      <p class="font-body-md text-body-md text-on-surface-variant">Hapus akun <strong id="admin-delete-name" class="text-on-surface"></strong> secara permanen? Riwayat keuangan akun ini ikut terhapus.</p>
      <form class="mt-space-lg flex flex-col gap-space-md" method="post" action="<?= e(url('process/admin_user_process.php')) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="admin-delete-id" value="">
        <?= csrf_field() ?>
        <div class="flex flex-wrap gap-space-sm">
          <button class="inline-flex h-11 items-center rounded-xl bg-red-600 px-5 font-label-lg text-label-lg text-white transition-colors hover:bg-red-700" type="submit">Hapus akun</button>
          <button class="inline-flex h-11 items-center rounded-xl px-5 font-label-lg text-label-lg text-on-surface-variant transition-colors hover:bg-surface-container-low" type="button" data-modal-close>Batal</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
render_footer();
