<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

redirect(empty($_SESSION['user_id']) ? 'pages/login.php' : 'pages/dashboard.php');
