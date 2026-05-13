<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../cli/lib/TravelCalculator.php';
require_once __DIR__ . '/../agent/lib/GeocodingHelper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$gigId = isset($routeParams[0]) ? (int)$routeParams[0] : 0;
if (!$gigId) {
    http_response_code(400);
    exit;
}

// Fetch gig + venue coordinates and address fields for on-demand geocoding.
$stmt = $pdo->prepare(
    "SELECT g.id, v.id AS venue_id, v.lat, v.lng, v.distance_from_turku_km,
            v.address_line, v.city, v.name AS venue_name
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

// If venue has no coordinates, attempt on-demand geocoding from address/city/name.
// Legacy imported gigs have address data but were never run through the inquiry pipeline.
if (($row['lat'] === null || $row['lng'] === null) && $row['venue_id'] !== null) {
    $geocodeQuery = trim(($row['address_line'] ?? '') . ' ' . ($row['city'] ?? ''));
    if ($geocodeQuery === '') {
        $geocodeQuery = trim($row['venue_name'] ?? '');
    }

    $geo = $geocodeQuery !== '' ? GeocodingHelper::geocodeVenue($geocodeQuery, '') : null;

    if ($geo !== null) {
        $row['lat'] = $geo['lat'];
        $row['lng'] = $geo['lng'];
        // Persist coords; update distance_from_turku_km only if not already set.
        $pdo->prepare(
            "UPDATE venues SET lat = ?, lng = ?,
                distance_from_turku_km = COALESCE(distance_from_turku_km, ?)
             WHERE id = ?"
        )->execute([$geo['lat'], $geo['lng'], $geo['distance_km'], $row['venue_id']]);
    }
}

if ($row['lat'] === null || $row['lng'] === null) {
    // Venue has no coordinates and no address to geocode from.
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
