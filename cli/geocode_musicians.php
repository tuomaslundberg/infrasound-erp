#!/usr/bin/env php
<?php
/**
 * One-time script: geocode musician home addresses and store lat/lng in users table.
 *
 * Reads home_address from users where home_lat IS NULL, queries Nominatim for
 * coordinates, and updates home_lat + home_lng.
 *
 * Run after: make seed-musician-addresses
 * Run via:   php cli/geocode_musicians.php
 *            make geocode-musicians     (dev)
 *            make geocode-musicians-prod (prod)
 *
 * Rate-limited to 1 request/second to comply with Nominatim ToS.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/lib/RoutingHelper.php';

$stmt = $pdo->query(
    "SELECT id, username, home_address FROM users
     WHERE home_address IS NOT NULL AND home_lat IS NULL AND deleted_at IS NULL
     ORDER BY id ASC"
);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$users) {
    echo "No users with home_address but missing home_lat — nothing to do.\n";
    exit(0);
}

$update = $pdo->prepare(
    'UPDATE users SET home_lat = ?, home_lng = ? WHERE id = ?'
);

foreach ($users as $u) {
    echo "Geocoding {$u['username']}: {$u['home_address']} ... ";
    $coords = RoutingHelper::geocode($u['home_address'] . ' Finland');
    if ($coords === null) {
        echo "FAILED (Nominatim returned no result)\n";
        continue;
    }
    $update->execute([$coords['lat'], $coords['lng'], $u['id']]);
    echo "lat={$coords['lat']}, lng={$coords['lng']}\n";
    sleep(1); // Nominatim rate limit: 1 req/s
}

echo "Done.\n";
