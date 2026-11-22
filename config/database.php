<?php
declare(strict_types=1);

/**
 * Database connection and application path settings.
 * Edit DB_NAME, DB_USER, DB_PASS below to match your Laragon MySQL setup.
 */
const DB_HOST = '127.0.0.1';
const DB_NAME = 'saku_ku';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
} catch (PDOException $exception) {
    http_response_code(500);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database connection failed: " . $exception->getMessage() . PHP_EOL);
    } else {
        echo '<!doctype html><html lang="id"><meta charset="utf-8"><title>SakuKu - Database error</title>';
        echo '<div class="db-error"><h1>Database belum siap</h1><p>Import <code>sql/schema.sql</code> ke MySQL Laragon, lalu muat ulang halaman.</p>';
        echo '<pre>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre></div>';
    }
    exit;
}

/*
 * Resolve the public base path so redirects and assets work on both
 * http://localhost/sonn/ and a Laragon virtual host such as http://sonn.test/.
 */
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$scriptDir  = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
$rootPath   = preg_replace('#/(pages|process)$#', '', $scriptDir) ?: '';
if ($rootPath === '.') {
    $rootPath = '';
}
define('BASE_PATH', $rootPath);
define('BASE_URL', $rootPath === '' ? '' : $rootPath);
