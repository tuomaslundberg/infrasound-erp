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

// Only developers may delete developer accounts.
$targetStmt = $pdo->prepare('SELECT role FROM users WHERE id = ? AND deleted_at IS NULL');
$targetStmt->execute([$targetId]);
$targetUser = $targetStmt->fetch(PDO::FETCH_ASSOC);
if ($targetUser !== false && $targetUser['role'] === 'developer' && $currentUser['role'] !== 'developer') {
    http_response_code(403);
    exit('Only developers can delete developer accounts.');
}

$pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL')
    ->execute([$targetId]);

header('Location: /admin/users?notice=deleted');
exit;
