<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$gigId  = isset($routeParams[0]) ? (int)$routeParams[0] : 0;
$userId = (int)($_POST['user_id'] ?? 0);
$role   = $_POST['role'] ?? '';
$feeRaw = str_replace(',', '.', $_POST['fee'] ?? '0');
$feeCents = (int)round((float)$feeRaw * 100);

$validRoles = ['vocalist', 'guitarist', 'bassist', 'drummer', 'keyboardist', 'other'];

if (!$gigId || !$userId || !in_array($role, $validRoles, true)) {
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

try {
    $pdo->prepare(
        'INSERT INTO gig_personnel (gig_id, user_id, role, fee_cents) VALUES (?, ?, ?, ?)'
    )->execute([$gigId, $userId, $role, $feeCents]);
} catch (PDOException $e) {
    // UNIQUE constraint violation — same user already assigned to this gig
    if ($e->getCode() === '23000') {
        header('Location: /gigs/' . $gigId . '?error=duplicate_personnel');
        exit;
    }
    error_log('personnel_add failed: ' . $e->getMessage());
    header('Location: /gigs/' . $gigId . '?error=db_error');
    exit;
}

header('Location: /gigs/' . $gigId . '?notice=personnel_added');
exit;
