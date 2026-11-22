<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'logout') {
    require_post();
    verify_csrf();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    redirect('pages/login.php');
}

require_post();
verify_csrf();

$questions = security_questions();

/** Changing this version invalidates every older session for the account. */
function sk_bump_session_version(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('UPDATE users SET session_version = session_version + 1 WHERE id = :id');
    $stmt->execute([':id' => $userId]);

    $stmt = $pdo->prepare('SELECT session_version FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    return (int) $stmt->fetchColumn();
}

if ($action === 'register') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    $question = (string) ($_POST['security_question'] ?? '');
    $answer = mb_strtolower(trim((string) ($_POST['security_answer'] ?? '')));

    $_SESSION['register_old'] = [
        'name'              => $name,
        'email'             => $email,
        'security_question' => $question,
    ];

    if ($name === '' || mb_strlen($name) > 100) {
        set_flash('error', 'Nama lengkap wajib diisi, maksimal 100 karakter.');
        redirect('pages/register.php');
    }
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 100) {
        set_flash('error', 'Masukkan alamat email yang valid.');
        redirect('pages/register.php');
    }
    if (mb_strlen($password) < 8) {
        set_flash('error', 'Kata sandi minimal 8 karakter.');
        redirect('pages/register.php');
    }
    if (!hash_equals($password, $confirmation)) {
        set_flash('error', 'Konfirmasi kata sandi tidak cocok.');
        redirect('pages/register.php');
    }
    if (!in_array($question, $questions, true)) {
        set_flash('error', 'Pilih pertanyaan keamanan yang tersedia.');
        redirect('pages/register.php');
    }
    if ($answer === '' || mb_strlen($answer) > 255) {
        set_flash('error', 'Jawaban keamanan wajib diisi, maksimal 255 karakter.');
        redirect('pages/register.php');
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    if ($stmt->fetch()) {
        set_flash('error', 'Email sudah terdaftar. Gunakan email lain atau masuk.');
        redirect('pages/register.php');
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, status, security_question, security_answer_hash)
             VALUES (:name, :email, :password_hash, \'user\', \'active\', :security_question, :security_answer_hash)'
        );
        $stmt->execute([
            ':name'                 => $name,
            ':email'                => $email,
            ':password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            ':security_question'    => $question,
            ':security_answer_hash' => password_hash($answer, PASSWORD_DEFAULT),
        ]);
    } catch (PDOException $exception) {
        set_flash('error', 'Pendaftaran gagal. Email mungkin sudah terdaftar.');
        redirect('pages/register.php');
    }

    unset($_SESSION['register_old']);
    set_flash('success', 'Akun berhasil dibuat. Silakan masuk.');
    redirect('pages/login.php');
}

if ($action === 'login') {
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = $pdo->prepare(
        'SELECT id, name, email, password_hash, role, status, session_version FROM users WHERE email = :email LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        set_flash('error', 'Email atau kata sandi salah.');
        redirect('pages/login.php');
    }

    if (($user['status'] ?? 'active') !== 'active') {
        set_flash('error', 'Akun Anda sedang dinonaktifkan oleh Administrator.');
        redirect('pages/login.php');
    }

    $role = (string) $user['role'];
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['session_version'] = (int) $user['session_version'];
    $_SESSION['role'] = $role;
    set_flash('success', 'Selamat datang, ' . $user['name'] . '.');
    redirect($role === 'admin' ? 'pages/admin_users.php' : 'pages/dashboard.php');
}

if ($action === 'profile_info' || $action === 'profile_password') {
    require_once __DIR__ . '/../includes/auth_check.php';

    $userId = (int) $currentUser['id'];

    if ($action === 'profile_info') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));

        if ($name === '' || mb_strlen($name) > 100) {
            set_flash('error', 'Nama lengkap wajib diisi, maksimal 100 karakter.');
            redirect('pages/profile.php');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 100) {
            set_flash('error', 'Masukkan alamat email yang valid.');
            redirect('pages/profile.php');
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
        $stmt->execute([':email' => $email, ':id' => $userId]);
        if ($stmt->fetch()) {
            set_flash('error', 'Email sudah dipakai akun lain.');
            redirect('pages/profile.php');
        }

        try {
            $stmt = $pdo->prepare('UPDATE users SET name = :name, email = :email WHERE id = :id');
            $stmt->execute([':name' => $name, ':email' => $email, ':id' => $userId]);
        } catch (PDOException $exception) {
            set_flash('error', 'Profil gagal disimpan. Email mungkin sudah dipakai.');
            redirect('pages/profile.php');
        }

        set_flash('success', 'Profil berhasil diperbarui.');
        redirect('pages/profile.php');
    }

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['new_password_confirmation'] ?? '');

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $hash = (string) ($stmt->fetchColumn() ?: '');

    if ($hash === '' || !password_verify($currentPassword, $hash)) {
        set_flash('error', 'Kata sandi saat ini tidak benar.');
        redirect('pages/profile.php');
    }
    if (mb_strlen($newPassword) < 8) {
        set_flash('error', 'Kata sandi baru minimal 8 karakter.');
        redirect('pages/profile.php');
    }
    if (!hash_equals($newPassword, $confirmation)) {
        set_flash('error', 'Konfirmasi kata sandi baru tidak cocok.');
        redirect('pages/profile.php');
    }

    $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
    $stmt->execute([
        ':hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':id'   => $userId,
    ]);
    $version = sk_bump_session_version($pdo, $userId);
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['session_version'] = $version;

    set_flash('success', 'Kata sandi berhasil diperbarui.');
    redirect('pages/profile.php');
}

set_flash('error', 'Aksi tidak dikenal.');
redirect('pages/login.php');
