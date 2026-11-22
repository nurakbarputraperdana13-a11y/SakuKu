<?php
declare(strict_types=1);

/**
 * Admin system middleware. Load after bootstrap.php in every admin page and endpoint.
 * Requires an authenticated user with users.role = 'admin' and active status.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth_check.php';

$adminRole = (string) ($_SESSION['role'] ?? '');
$databaseRole = (string) ($currentUser['role'] ?? '');

if ($adminRole !== 'admin' || $databaseRole !== 'admin') {
    http_response_code(403);
    exit('Akses ditolak. Hanya Administrator Sistem yang bisa membuka halaman ini.');
}

$_SESSION['role'] = 'admin';
