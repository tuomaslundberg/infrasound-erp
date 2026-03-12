<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$gigId = isset($routeParams[0]) ? (int)$routeParams[0] : 0;

$stmt = $pdo->prepare('SELECT 1 FROM gigs WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$gigId]);

if (!$stmt->fetch()) {
    http_response_code(404);
    exit;
}

$notes = (isset($_POST['notes']) && trim($_POST['notes']) !== '') ? $_POST['notes'] : null;

if ($notes !== null && strlen($notes) > 10000) {
    header('Location: /gigs/' . $gigId . '?error=notes_too_long');
    exit;
}

$pdo->prepare('UPDATE gigs SET notes = ? WHERE id = ?')
    ->execute([$notes, $gigId]);

header('Location: /gigs/' . $gigId . '?notice=notes_saved');
exit;
