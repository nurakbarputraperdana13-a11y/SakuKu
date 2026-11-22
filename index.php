<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['user_id'])) {
    redirect('pages/login.php');
}

redirect(($_SESSION['role'] ?? 'user') === 'admin' ? 'pages/admin_users.php' : 'pages/dashboard.php');
