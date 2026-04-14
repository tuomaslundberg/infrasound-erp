<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../cli/lib/TravelCalculator.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$gigId = isset($routeParams[0]) ? (int)$routeParams[0] : 0;
if (!$gigId) {
    http_response_code(400);
    exit;
}

// Fetch gig + venue coordinates
$stmt = $pdo->prepare(
    "SELECT g.id, v.lat, v.lng, v.distance_from_turku_km
     FROM   gigs g
     LEFT JOIN venues v ON v.id = g.venue_id
     WHERE  g.id = ? AND g.deleted_at IS NULL"
);
$stmt->execute([$gigId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    exit;
}

if ($row['lat'] === null || $row['lng'] === null) {
    // Venue not geocoded yet — cannot run TravelCalculator
    header('Location: /gigs/' . $gigId . '?error=travel_no_venue_coords');
    exit;
}

$result = TravelCalculator::calculate($pdo, $gigId, (float)$row['lat'], (float)$row['lng']);

if ($result['car1_km'] === null) {
    error_log('TravelCalculator failed for gig ' . $gigId . ': ' . implode('; ', $result['warnings']));
    header('Location: /gigs/' . $gigId . '?error=travel_failed');
    exit;
}

$pdo->prepare(
    "UPDATE gigs SET
        car1_distance_km          = ?,
        car2_distance_km          = ?,
        other_travel_costs_cents  = ?,
        car1_route_json           = ?,
        car2_route_json           = ?
     WHERE id = ?"
)->execute([
    $result['car1_km'],
    $result['car2_km'] ?? 0,
    (int)round($result['ferry_costs_eur'] * 100),
    isset($result['car1_route']) ? json_encode($result['car1_route']) : null,
    isset($result['car2_route']) ? json_encode($result['car2_route']) : null,
    $gigId,
]);

header('Location: /gigs/' . $gigId . '?notice=travel_recalculated');
exit;
