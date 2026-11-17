<?php
declare(strict_types=1);

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    throw new RuntimeException('Chưa cài Composer: chạy composer install trước.');
}

if (is_file($root . '/.env')) {
    Dotenv\Dotenv::createImmutable($root)->safeLoad();
}

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_name('HUIT_SCHEDULE_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => filter_var($_ENV['SESSION_SECURE'] ?? 'false', FILTER_VALIDATE_BOOL),
        'samesite' => 'Lax',
    ]);
    session_start();
}
require_once $root . '/app/functions.php';
require_once $root . '/app/Conflict.php';
require_once $root . '/app/db.php';
