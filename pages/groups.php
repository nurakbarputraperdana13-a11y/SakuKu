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

render_header('Patungan', 'groups');
?>

<div class="page-head">
  <div>
    <h1>Patungan</h1>
    <p>Kelola tagihan bareng tanpa mengubah riwayat transaksi pribadi.</p>
  </div>
  <span class="badge badge-info"><?= count($groups) ?> grup</span>
</div>

<div class="grid grid-2" style="margin-bottom: var(--space-5);">
  <section class="card">
    <div class="card-header">
      <h2><?= icon('plus') ?> Buat grup</h2>
    </div>
    <div class="card-body">
      <form method="post" action="<?= e(url('process/group_process.php')) ?>">
        <input type="hidden" name="action" value="create">
        <?= csrf_field() ?>
        <div class="form-grid cols-1">
          <div class="field">
            <label for="group-name">Nama grup</label>
            <input id="group-name" name="name" type="text" maxlength="100" placeholder="Mis. Kos Rw 04" required>
          </div>
          <div class="field">
            <label for="group-description">Deskripsi</label>
            <textarea id="group-description" name="description" maxlength="255" placeholder="Tujuan penggunaan grup"></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= icon('users') ?> Buat grup</button>
        </div>
      </form>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <h2><?= icon('key') ?> Gabung grup</h2>
    </div>
    <div class="card-body">
      <form method="post" action="<?= e(url('process/group_process.php')) ?>">
        <input type="hidden" name="action" value="join">
        <?= csrf_field() ?>
        <div class="form-grid cols-1">
          <div class="field">
            <label for="invite-code">Kode undangan</label>
            <input id="invite-code" name="invite_code" type="text" maxlength="20" autocomplete="off" placeholder="ABCDEFGH" required>
            <span class="field-hint">Kode diberikan oleh admin grup.</span>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn" type="submit"><?= icon('check') ?> Gabung</button>
        </div>
      </form>
    </div>
  </section>
</div>

<section class="card">
  <div class="card-header">
    <h2><?= icon('users') ?> Grup saya</h2>
  </div>
  <div class="card-body">
    <?php if (!$groups): ?>
      <div class="empty-state">
        <?= icon('users') ?>
        <strong>Belum ada grup</strong>
        <span>Buat grup baru atau masukkan kode undangan.</span>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Grup</th>
              <th>Kode undangan</th>
              <th class="num">Anggota</th>
              <th class="num">Tagihan</th>
              <th>Role</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($groups as $group): ?>
              <?php $groupId = (int) $group['id']; ?>
              <tr>
                <td>
                  <span class="cell-main"><?= e($group['name']) ?></span>
                  <?php if (!empty($group['description'])): ?>
                    <span class="cell-sub"><?= e($group['description']) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="invite-code">
                    <span><?= e($group['invite_code']) ?></span>
                    <button class="btn btn-sm btn-icon" type="button" data-copy="<?= e($group['invite_code']) ?>" aria-label="Salin kode undangan">
                      <?= icon('copy') ?>
                    </button>
                  </div>
                </td>
                <td class="num"><?= (int) $group['member_count'] ?></td>
                <td class="num"><?= (int) $group['expense_count'] ?></td>
                <td>
                  <span class="badge <?= $group['role'] === 'admin' ? 'badge-info' : '' ?>">
                    <?= $group['role'] === 'admin' ? 'Admin' : 'Anggota' ?>
                  </span>
                </td>
                <td>
                  <a class="btn btn-sm" href="<?= e(url('pages/group_detail.php?id=' . $groupId)) ?>">
                    <?= icon('eye') ?> Buka
                  </a>
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
