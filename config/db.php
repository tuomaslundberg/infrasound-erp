<?php
// Load .env when running outside Docker (bare PHP on Plesk).
// In Docker, env vars are already injected; putenv is a no-op for set vars.
$_envFile = __DIR__ . '/../.env';
if (file_exists($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        if (str_starts_with(trim($_line), '#') || !str_contains($_line, '=')) continue;
        [$_k, $_v] = explode('=', $_line, 2);
        $_k = trim($_k);
        if (getenv($_k) === false) putenv($_k . '=' . trim($_v));
    }
    unset($_envFile, $_line, $_k, $_v);
}
unset($_envFile);

$host = getenv('DB_HOST');
$db   = getenv('MYSQL_DATABASE');
$user = getenv('MYSQL_USER');
$pass = getenv('MYSQL_PASSWORD');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    // Do not expose connection details in output
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Service unavailable.');
}
