<?php
declare(strict_types=1);

/** Page guard. Requires $pdo, url(), set_flash() from bootstrap.php. */
if (empty($_SESSION['user_id'])) {
    set_flash('info', 'Silakan masuk untuk melanjutkan.');
    redirect('pages/login.php');
}

$stmt = $pdo->prepare('SELECT id, name, email, session_version FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $_SESSION['user_id']]);
$currentUser = $stmt->fetch();

if (!$currentUser) {
    $_SESSION = [];
    session_destroy();
    redirect('pages/login.php');
}

$sessionVersion = (int) ($_SESSION['session_version'] ?? 0);
if ($sessionVersion === 0 || $sessionVersion !== (int) $currentUser['session_version']) {
    $_SESSION = [];
    session_destroy();
    redirect('pages/login.php');
}
