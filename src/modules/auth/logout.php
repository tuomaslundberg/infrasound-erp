<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/auth.php';

auth_start();
$_SESSION = [];
session_destroy();

header('Location: /login');
exit;
