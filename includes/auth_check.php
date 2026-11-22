<?php
declare(strict_types=1);

/** Page guard. Requires $pdo, url(), set_flash() from bootstrap.php. */
if (empty($_SESSION['user_id'])) {
    set_flash('info', 'Silakan masuk untuk melanjutkan.');
    redirect('pages/login.php');
}

$stmt = $pdo->prepare('SELECT id, name, email, role, status, session_version FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (!$currentUser) {
    $_SESSION = [];
    session_destroy();
    redirect('pages/login.php');
}

if (($currentUser['status'] ?? 'active') !== 'active') {
    unset($_SESSION['user_id'], $_SESSION['session_version'], $_SESSION['role']);
    set_flash('error', 'Akun Anda sedang dinonaktifkan oleh Administrator.');
    redirect('pages/login.php');
}

$_SESSION['role'] = (string) $currentUser['role'];

$sessionVersion = (int) ($_SESSION['session_version'] ?? 0);
if ($sessionVersion === 0 || $sessionVersion !== (int) $currentUser['session_version']) {
    $_SESSION = [];
    session_destroy();
    redirect('pages/login.php');
}
