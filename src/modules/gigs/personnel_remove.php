<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$gigId  = isset($routeParams[0]) ? (int)$routeParams[0] : 0;
$userId = isset($routeParams[1]) ? (int)$routeParams[1] : 0;

if (!$gigId || !$userId) {
    http_response_code(400);
    exit;
}

$pdo->prepare('DELETE FROM gig_personnel WHERE gig_id = ? AND user_id = ?')
    ->execute([$gigId, $userId]);

header('Location: /gigs/' . $gigId . '?notice=personnel_removed');
exit;
