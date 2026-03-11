<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/gig_states.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$gigId    = isset($routeParams[0]) ? (int)$routeParams[0] : 0;
$toStatus = $_POST['status'] ?? '';

$stmt = $pdo->prepare('SELECT status FROM gigs WHERE id = ? AND deleted_at IS NULL');
$stmt->execute([$gigId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit;
}

if (!gig_can_transition($row['status'], $toStatus)) {
    // Redirect back; detail page will surface the current state correctly.
    header('Location: /gigs/' . $gigId . '?notice=invalid_transition');
    exit;
}

$pdo->prepare('UPDATE gigs SET status = ? WHERE id = ?')
    ->execute([$toStatus, $gigId]);

header('Location: /gigs/' . $gigId);
exit;
