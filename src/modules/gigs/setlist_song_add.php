<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$gigId     = isset($routeParams[0]) ? (int)$routeParams[0] : 0;
$title     = trim($_POST['title']  ?? '');
$artist    = trim($_POST['artist'] ?? '');
$setNumber = (int)($_POST['set_number'] ?? 1);
$notes     = trim($_POST['notes']  ?? '') ?: null;

if (!$gigId || $title === '' || $artist === '' || $setNumber < 1 || $setNumber > 4) {
    header('Location: /gigs/' . $gigId . '?error=invalid_input');
    exit;
}

// Verify gig exists
$stmt = $pdo->prepare('SELECT id FROM gigs WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$gigId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit;
}

// Get or create song (case-insensitive dedup via LOWER on both sides)
$stmt = $pdo->prepare(
    'SELECT id FROM songs WHERE LOWER(title) = LOWER(?) AND LOWER(artist) = LOWER(?) AND deleted_at IS NULL LIMIT 1'
);
$stmt->execute([$title, $artist]);
$song = $stmt->fetch(PDO::FETCH_ASSOC);

if ($song) {
    $songId = (int)$song['id'];
} else {
    $pdo->prepare('INSERT INTO songs (title, artist) VALUES (?, ?)')->execute([$title, $artist]);
    $songId = (int)$pdo->lastInsertId();
}

// Get or create setlist for this gig + set_number
$stmt = $pdo->prepare('SELECT id FROM setlists WHERE gig_id = ? AND set_number = ? LIMIT 1');
$stmt->execute([$gigId, $setNumber]);
$setlist = $stmt->fetch(PDO::FETCH_ASSOC);

if ($setlist) {
    $setlistId = (int)$setlist['id'];
} else {
    $pdo->prepare('INSERT INTO setlists (gig_id, set_number) VALUES (?, ?)')->execute([$gigId, $setNumber]);
    $setlistId = (int)$pdo->lastInsertId();
}

// Next sort_order = current max + 1 (or 0 if empty)
$stmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM setlist_songs WHERE setlist_id = ?');
$stmt->execute([$setlistId]);
$sortOrder = (int)$stmt->fetchColumn();

$pdo->prepare(
    'INSERT INTO setlist_songs (setlist_id, song_id, sort_order, notes) VALUES (?, ?, ?, ?)'
)->execute([$setlistId, $songId, $sortOrder, $notes]);

header('Location: /gigs/' . $gigId . '?notice=song_added');
exit;
