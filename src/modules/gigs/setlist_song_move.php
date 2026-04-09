<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$gigId     = isset($routeParams[0]) ? (int)$routeParams[0] : 0;
$ssId      = isset($routeParams[1]) ? (int)$routeParams[1] : 0;
$direction = $routeParams[2] ?? '';

if (!$gigId || !$ssId || !in_array($direction, ['up', 'down'], true)) {
    http_response_code(400);
    exit;
}

// Verify ownership and fetch current row's context
$stmt = $pdo->prepare(
    'SELECT sl.gig_id, ss.setlist_id, ss.sort_order
     FROM   setlist_songs ss
     JOIN   setlists sl ON sl.id = ss.setlist_id
     WHERE  ss.id = ?'
);
$stmt->execute([$ssId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || (int)$row['gig_id'] !== $gigId) {
    http_response_code(403);
    exit;
}

$setlistId = (int)$row['setlist_id'];
$sortOrder = (int)$row['sort_order'];

// Find the adjacent row to swap with
if ($direction === 'up') {
    $targetStmt = $pdo->prepare(
        'SELECT id, sort_order FROM setlist_songs
         WHERE setlist_id = ? AND sort_order < ?
         ORDER BY sort_order DESC LIMIT 1'
    );
} else {
    $targetStmt = $pdo->prepare(
        'SELECT id, sort_order FROM setlist_songs
         WHERE setlist_id = ? AND sort_order > ?
         ORDER BY sort_order ASC LIMIT 1'
    );
}
$targetStmt->execute([$setlistId, $sortOrder]);
$target = $targetStmt->fetch(PDO::FETCH_ASSOC);

if ($target) {
    $pdo->prepare('UPDATE setlist_songs SET sort_order = ? WHERE id = ?')
        ->execute([$target['sort_order'], $ssId]);
    $pdo->prepare('UPDATE setlist_songs SET sort_order = ? WHERE id = ?')
        ->execute([$sortOrder, (int)$target['id']]);
}

header('Location: /gigs/' . $gigId);
exit;
