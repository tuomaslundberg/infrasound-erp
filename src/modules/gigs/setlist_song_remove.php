<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$gigId = isset($routeParams[0]) ? (int)$routeParams[0] : 0;
$ssId  = isset($routeParams[1]) ? (int)$routeParams[1] : 0;

if (!$gigId || !$ssId) {
    http_response_code(400);
    exit;
}

// Verify ownership: ensure this setlist_song belongs to the given gig
$stmt = $pdo->prepare(
    'SELECT sl.id AS setlist_id, sl.gig_id
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

$pdo->prepare('DELETE FROM setlist_songs WHERE id = ?')->execute([$ssId]);

// Remove the parent setlist row if it is now empty
$pdo->prepare(
    'DELETE FROM setlists WHERE id = ? AND NOT EXISTS (SELECT 1 FROM setlist_songs WHERE setlist_id = ?)'
)->execute([$setlistId, $setlistId]);

header('Location: /gigs/' . $gigId . '?notice=song_removed');
exit;
