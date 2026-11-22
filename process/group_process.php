<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_post();
verify_csrf();

function gp_positive_int(mixed $raw): int
{
    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? 0 : (int) $value;
}

function gp_group_redirect(int $groupId): never
{
    redirect('pages/group_detail.php?id=' . $groupId);
}

function gp_membership_or_redirect(PDO $pdo, int $groupId, int $userId): array
{
    $membership = group_membership($pdo, $groupId, $userId);
    if ($membership === null) {
        set_flash('error', 'Grup tidak ditemukan atau Anda bukan anggota grup ini.');
        redirect('pages/groups.php');
    }
    return $membership;
}

function gp_require_admin(PDO $pdo, int $groupId, int $userId): array
{
    $membership = gp_membership_or_redirect($pdo, $groupId, $userId);
    if (($membership['role'] ?? '') !== 'admin') {
        set_flash('error', 'Hanya admin grup yang bisa melakukan aksi ini.');
        gp_group_redirect($groupId);
    }
    return $membership;
}

function gp_ensure_settled(PDO $pdo, int $groupId, int $targetUserId, string $action): void
{
    $balance = (int) (group_net_balances($pdo, $groupId)[$targetUserId] ?? 0);
    if ($balance === 0) {
        return;
    }

    $kind = $balance < 0 ? 'utang' : 'piutang';
    set_flash(
        'error',
        'Tidak bisa ' . $action . ' karena anggota masih memiliki ' . $kind . ' '
        . format_rupiah(abs($balance)) . '. Selesaikan tagihan terlebih dahulu.'
    );
    gp_group_redirect($groupId);
}

function gp_is_member(PDO $pdo, int $groupId, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM group_members WHERE group_id = :group_id AND user_id = :user_id LIMIT 1');
    $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);
    return $stmt->fetchColumn() !== false;
}

$userId = (int) $currentUser['id'];
$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    case 'create':
        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            set_flash('error', 'Nama grup wajib diisi dan maksimal 100 karakter.');
            redirect('pages/groups.php');
        }
        if (mb_strlen($description) > 255) {
            set_flash('error', 'Deskripsi grup maksimal 255 karakter.');
            redirect('pages/groups.php');
        }

        $groupId = 0;
        $created = false;

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = generate_invite_code(8);
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'INSERT INTO `groups` (name, description, invite_code, created_by)
                     VALUES (:name, :description, :code, :created_by)'
                );
                $stmt->execute([
                    ':name'        => $name,
                    ':description' => $description === '' ? null : $description,
                    ':code'        => $code,
                    ':created_by'  => $userId,
                ]);
                $groupId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    "INSERT INTO group_members (group_id, user_id, role)
                     VALUES (:group_id, :user_id, 'admin')"
                );
                $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);

                $pdo->commit();
                $created = true;
                break;
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ((string) $exception->getCode() !== '23000') {
                    throw $exception;
                }
            }
        }

        if (!$created) {
            set_flash('error', 'Gagal membuat kode undangan unik. Coba lagi.');
            redirect('pages/groups.php');
        }

        set_flash('success', 'Grup berhasil dibuat. Anda menjadi admin grup.');
        gp_group_redirect($groupId);

    case 'join':
        $code = strtoupper(trim((string) ($_POST['invite_code'] ?? '')));

        if ($code === '' || strlen($code) > 20) {
            set_flash('error', 'Kode undangan tidak valid.');
            redirect('pages/groups.php');
        }

        $stmt = $pdo->prepare('SELECT id FROM `groups` WHERE invite_code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $joinedGroupId = (int) $stmt->fetchColumn();

        if ($joinedGroupId === 0) {
            set_flash('error', 'Kode undangan tidak ditemukan.');
            redirect('pages/groups.php');
        }

        if (gp_is_member($pdo, $joinedGroupId, $userId)) {
            set_flash('info', 'Anda sudah menjadi anggota grup ini.');
            gp_group_redirect($joinedGroupId);
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO group_members (group_id, user_id, role)
                 VALUES (:group_id, :user_id, 'member')"
            );
            $stmt->execute([':group_id' => $joinedGroupId, ':user_id' => $userId]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                set_flash('info', 'Anda sudah menjadi anggota grup ini.');
                gp_group_redirect($joinedGroupId);
            }
            throw $exception;
        }

        set_flash('success', 'Anda berhasil bergabung dengan grup.');
        gp_group_redirect($joinedGroupId);

    case 'add_member':
        $groupId = gp_positive_int($_POST['group_id'] ?? 0);
        if ($groupId === 0) {
            set_flash('error', 'Grup tidak ditemukan.');
            redirect('pages/groups.php');
        }
        gp_require_admin($pdo, $groupId, $userId);

        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            set_flash('error', 'Masukkan email pengguna yang valid.');
            gp_group_redirect($groupId);
        }

        $stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $target = $stmt->fetch();
        if (!$target) {
            set_flash('error', 'Pengguna dengan email tersebut tidak ditemukan.');
            gp_group_redirect($groupId);
        }
        $targetId = (int) $target['id'];

        if (gp_is_member($pdo, $groupId, $targetId)) {
            set_flash('error', 'Pengguna tersebut sudah menjadi anggota grup.');
            gp_group_redirect($groupId);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO group_members (group_id, user_id, role)
             VALUES (:group_id, :user_id, 'member')"
        );
        $stmt->execute([':group_id' => $groupId, ':user_id' => $targetId]);

        set_flash('success', $target['name'] . ' ditambahkan ke grup.');
        gp_group_redirect($groupId);

    case 'change_role':
        $groupId = gp_positive_int($_POST['group_id'] ?? 0);
        $targetId = gp_positive_int($_POST['user_id'] ?? 0);
        $role = (string) ($_POST['role'] ?? '');

        if ($groupId === 0) {
            set_flash('error', 'Grup tidak ditemukan.');
            redirect('pages/groups.php');
        }
        gp_require_admin($pdo, $groupId, $userId);

        if ($targetId === 0 || !in_array($role, ['admin', 'member'], true)) {
            set_flash('error', 'Data perubahan role tidak valid.');
            gp_group_redirect($groupId);
        }
        if (!gp_is_member($pdo, $groupId, $targetId)) {
            set_flash('error', 'Pengguna tersebut bukan anggota grup.');
            gp_group_redirect($groupId);
        }

        $stmt = $pdo->prepare('UPDATE group_members SET role = :role WHERE group_id = :group_id AND user_id = :user_id');
        $stmt->execute([':role' => $role, ':group_id' => $groupId, ':user_id' => $targetId]);

        set_flash('success', 'Role anggota berhasil diperbarui.');
        gp_group_redirect($groupId);

    case 'kick':
        $groupId = gp_positive_int($_POST['group_id'] ?? 0);
        $targetId = gp_positive_int($_POST['user_id'] ?? 0);

        if ($groupId === 0) {
            set_flash('error', 'Grup tidak ditemukan.');
            redirect('pages/groups.php');
        }
        gp_require_admin($pdo, $groupId, $userId);

        if ($targetId === 0 || !gp_is_member($pdo, $groupId, $targetId)) {
            set_flash('error', 'Anggota tidak ditemukan.');
            gp_group_redirect($groupId);
        }

        gp_ensure_settled($pdo, $groupId, $targetId, 'mengeluarkan anggota');

        $stmt = $pdo->prepare('DELETE FROM group_members WHERE group_id = :group_id AND user_id = :user_id');
        $stmt->execute([':group_id' => $groupId, ':user_id' => $targetId]);

        set_flash('success', 'Anggota berhasil dikeluarkan dari grup.');
        gp_group_redirect($groupId);

    case 'leave':
        $groupId = gp_positive_int($_POST['group_id'] ?? 0);

        if ($groupId === 0) {
            set_flash('error', 'Grup tidak ditemukan.');
            redirect('pages/groups.php');
        }
        gp_membership_or_redirect($pdo, $groupId, $userId);
        gp_ensure_settled($pdo, $groupId, $userId, 'keluar dari grup');

        $stmt = $pdo->prepare('DELETE FROM group_members WHERE group_id = :group_id AND user_id = :user_id');
        $stmt->execute([':group_id' => $groupId, ':user_id' => $userId]);

        set_flash('success', 'Anda berhasil keluar dari grup.');
        redirect('pages/groups.php');

    case 'delete':
        $groupId = gp_positive_int($_POST['group_id'] ?? 0);

        if ($groupId === 0) {
            set_flash('error', 'Grup tidak ditemukan.');
            redirect('pages/groups.php');
        }
        gp_require_admin($pdo, $groupId, $userId);

        $stmt = $pdo->prepare('DELETE FROM `groups` WHERE id = :group_id');
        $stmt->execute([':group_id' => $groupId]);

        set_flash('success', 'Grup dan seluruh tagihannya berhasil dihapus.');
        redirect('pages/groups.php');
}

set_flash('error', 'Aksi grup tidak dikenal.');
redirect('pages/groups.php');
