<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/admin_check.php';

require_post();
verify_csrf();

$action = (string) ($_POST['action'] ?? '');
$redirect = static function (): never {
    redirect('pages/admin_users.php');
};

function au_positive_int(mixed $raw): int
{
    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false ? 0 : (int) $value;
}

function au_target(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, role, status FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function au_email_available(PDO $pdo, string $email, int $excludeId = 0): bool
{
    $sql = 'SELECT id FROM users WHERE email = :email';
    $params = [':email' => $email];
    if ($excludeId > 0) {
        $sql .= ' AND id <> :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetch() === false;
}

if ($action === 'create') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? 'user');
    $status = (string) ($_POST['status'] ?? 'active');
    $question = (string) ($_POST['security_question'] ?? '');
    $answer = mb_strtolower(trim((string) ($_POST['security_answer'] ?? '')));

    if ($name === '' || mb_strlen($name) > 100) {
        set_flash('error', 'Nama lengkap wajib diisi, maksimal 100 karakter.');
        $redirect();
    }
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 100) {
        set_flash('error', 'Masukkan alamat email yang valid.');
        $redirect();
    }
    if (mb_strlen($password) < 6) {
        set_flash('error', 'Password awal minimal 6 karakter.');
        $redirect();
    }
    if (!in_array($role, ['admin', 'user'], true) || !in_array($status, ['active', 'inactive'], true)) {
        set_flash('error', 'Peran atau status tidak valid.');
        $redirect();
    }
    if (!in_array($question, security_questions(), true)) {
        set_flash('error', 'Pilih pertanyaan keamanan yang tersedia.');
        $redirect();
    }
    if ($answer === '' || mb_strlen($answer) > 255) {
        set_flash('error', 'Jawaban keamanan wajib diisi, maksimal 255 karakter.');
        $redirect();
    }
    if (!au_email_available($pdo, $email)) {
        set_flash('error', 'Email sudah terdaftar. Gunakan email lain.');
        $redirect();
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, status, security_question, security_answer_hash)
             VALUES (:name, :email, :password_hash, :role, :status, :security_question, :security_answer_hash)'
        );
        $stmt->execute([
            ':name'                 => $name,
            ':email'                => $email,
            ':password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            ':role'                 => $role,
            ':status'               => $status,
            ':security_question'    => $question,
            ':security_answer_hash' => password_hash($answer, PASSWORD_DEFAULT),
        ]);
    } catch (PDOException $exception) {
        set_flash('error', 'Akun gagal dibuat. Email mungkin sudah terdaftar.');
        $redirect();
    }

    set_flash('success', 'Akun pengguna berhasil ditambahkan.');
    $redirect();
}

if ($action === 'update') {
    $id = au_positive_int($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? 'user');

    $target = $id > 0 ? au_target($pdo, $id) : null;
    if ($target === null) {
        set_flash('error', 'Akun pengguna tidak ditemukan.');
        $redirect();
    }
    if ($name === '' || mb_strlen($name) > 100) {
        set_flash('error', 'Nama lengkap wajib diisi, maksimal 100 karakter.');
        $redirect();
    }
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 100) {
        set_flash('error', 'Masukkan alamat email yang valid.');
        $redirect();
    }
    if ($password !== '' && mb_strlen($password) < 6) {
        set_flash('error', 'Password baru minimal 6 karakter, atau kosongkan untuk mempertahankan password lama.');
        $redirect();
    }
    if (!in_array($role, ['admin', 'user'], true)) {
        set_flash('error', 'Peran tidak valid.');
        $redirect();
    }
    if ($id === (int) $currentUser['id'] && $role !== 'admin') {
        set_flash('error', 'Anda tidak bisa menurunkan peran akun Anda sendiri.');
        $redirect();
    }
    if (!au_email_available($pdo, $email, $id)) {
        set_flash('error', 'Email sudah dipakai akun lain.');
        $redirect();
    }

    $fields = ['name = :name', 'email = :email', 'role = :role'];
    $params = [
        ':name'  => $name,
        ':email' => $email,
        ':role'  => $role,
        ':id'    => $id,
    ];

    $passwordChanged = $password !== '';
    if ($passwordChanged) {
        $fields[] = 'password_hash = :password_hash';
        $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    }

    $roleChanged = $role !== (string) $target['role'];
    $invalidateSessions = $passwordChanged || $roleChanged;
    if ($invalidateSessions) {
        $fields[] = 'session_version = session_version + 1';
    }

    try {
        $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($params);
    } catch (PDOException $exception) {
        set_flash('error', 'Data pengguna gagal disimpan. Email mungkin sudah dipakai.');
        $redirect();
    }

    if ($invalidateSessions && $id === (int) $currentUser['id']) {
        $stmt = $pdo->prepare('SELECT session_version FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        $_SESSION['session_version'] = (int) $stmt->fetchColumn();
        $_SESSION['role'] = $role;
    }

    set_flash('success', 'Data pengguna berhasil diperbarui.');
    $redirect();
}

if ($action === 'delete') {
    $id = au_positive_int($_POST['id'] ?? 0);
    $target = $id > 0 ? au_target($pdo, $id) : null;

    if ($target === null) {
        set_flash('error', 'Akun pengguna tidak ditemukan.');
        $redirect();
    }
    if ($id === (int) $currentUser['id']) {
        set_flash('error', 'Anda tidak bisa menghapus akun Anda sendiri.');
        $redirect();
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM expense_splits s
         JOIN group_expenses ge ON ge.id = s.group_expense_id
         WHERE s.is_settled = 0 AND s.owed_amount > 0
           AND ((s.user_id = :debtor_id AND ge.paid_by_user_id <> :payer_id)
             OR (ge.paid_by_user_id = :payer2_id AND s.user_id <> :debtor2_id))'
    );
    $stmt->execute([
        ':debtor_id' => $id,
        ':payer_id'  => $id,
        ':payer2_id' => $id,
        ':debtor2_id' => $id,
    ]);
    if ((int) $stmt->fetchColumn() > 0) {
        set_flash('error', 'Akun masih memiliki utang atau piutang belum lunas di grup patungan. Selesaikan tagihan terlebih dahulu.');
        $redirect();
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM `groups` WHERE created_by = :id');
    $stmt->execute([':id' => $id]);
    if ((int) $stmt->fetchColumn() > 0) {
        set_flash('error', 'Akun masih menjadi pembuat grup patungan. Hapus grup terlebih dahulu.');
        $redirect();
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM group_expenses WHERE paid_by_user_id = :id');
    $stmt->execute([':id' => $id]);
    if ((int) $stmt->fetchColumn() > 0) {
        set_flash('error', 'Akun masih tercatat sebagai pembayar pengeluaran grup.');
        $redirect();
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
    } catch (PDOException $exception) {
        set_flash('error', 'Akun gagal dihapus. Data terkait masih digunakan sistem.');
        $redirect();
    }

    set_flash('success', 'Akun pengguna berhasil dihapus.');
    $redirect();
}

if ($action === 'toggle_status') {
    $id = au_positive_int($_POST['id'] ?? 0);
    $target = $id > 0 ? au_target($pdo, $id) : null;

    if ($target === null) {
        set_flash('error', 'Akun pengguna tidak ditemukan.');
        $redirect();
    }
    if ($id === (int) $currentUser['id']) {
        set_flash('error', 'Anda tidak bisa menonaktifkan akun Anda sendiri.');
        $redirect();
    }

    $newStatus = ($target['status'] ?? 'active') === 'active' ? 'inactive' : 'active';
    $stmt = $pdo->prepare(
        'UPDATE users SET status = :status, session_version = session_version + 1 WHERE id = :id'
    );
    $stmt->execute([':status' => $newStatus, ':id' => $id]);

    set_flash('success', $newStatus === 'active' ? 'Akun berhasil diaktifkan.' : 'Akun berhasil dinonaktifkan.');
    $redirect();
}

set_flash('error', 'Aksi tidak dikenal.');
$redirect();
