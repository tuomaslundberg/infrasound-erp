<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$targetId = (int)($routeParams[0] ?? 0);

if ($targetId === 0) {
    http_response_code(400);
    exit;
}

// Cannot delete own account.
$currentUser = auth_user();
if ($targetId === (int)$currentUser['id']) {
    http_response_code(403);
    exit('Cannot delete your own account.');
}

$pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL')
    ->execute([$targetId]);

header('Location: /admin/users?notice=deleted');
exit;
