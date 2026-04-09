<?php
declare(strict_types=1);

require_once __DIR__ . '/RoutingHelper.php';

/**
 * Computes route-based travel costs for a gig based on assigned personnel.
 *
 * Canonical flows (see AGENTS.md §travel-flows):
 *
 * Car 1 (Caddy + trailer, Tuomas driving):
 *   Vilhonkatu 9 → Opettajankatu 9 (trailer, always) → [Stålarminkatu if Toni]
 *   → Puutarhakatu 18 (Alina) → Kirkkotie 2 (Joni) → Venue → reverse
 *
 * Car 2 (bassist, own car):
 *   Bassist home → Adolf Lindforsintie 3 (Lauri, unless local override)
 *   → [Kaarina if Valtteri assigned and not driving own car] → Venue → reverse
 *
 * Ferry: if venue.requires_ferry = 1, adds 2 × ferry_cost_estimate_cents × 2 ways.
 *
 * Role allocation:
 *   keyboards        → Car 1 driver (always Tuomas in typical lineups)
 *   drums, vocals    → Car 1 passenger
 *   guitar           → Car 2 pickup from home (Lauri, Helsinki); skip if transport_override='local'
 *   bass             → Car 2 driver
 *   sound_engineering → transport_mode='passenger' → Car 1 (Toni)
 *                       transport_mode='car_owner'  → Car 2 pickup (Valtteri default)
 *                       transport_override='car_owner' → drives own car (not billed; warning emitted)
 *
 * All km values are one-way route × 2 (round trip approximation).
 * Manual override: owner edits car1_distance_km / car2_distance_km on the gig form after calculation.
 */
class TravelCalculator
{
    // Opettajankatu 9, Turku — trailer storage; always first Car 1 waypoint after driver home.
    // Pre-geocoded from Nominatim. Update if storage location changes.
    private const TRAILER_LAT = 60.4481;
    private const TRAILER_LNG = 22.2547;

    // Turku city centre reference (matches GeocodingHelper)
    private const TURKU_LAT = 60.4518;
    private const TURKU_LNG = 22.2666;

    /**
     * Calculate travel costs using personnel assigned to the gig in the database.
     *
     * @param  PDO    $pdo
     * @param  int    $gigId
     * @param  float  $venueLat
     * @param  float  $venueLng
     * @return array{car1_km: float|null, car2_km: float|null, ferry_costs_eur: float, warnings: string[]}
     */
    public static function calculate(PDO $pdo, int $gigId, float $venueLat, float $venueLng): array
    {
        $stmt = $pdo->prepare(
            "SELECT gp.role, gp.transport_override,
                    u.username, u.home_lat, u.home_lng, u.transport_mode
             FROM   gig_personnel gp
             JOIN   users u ON u.id = gp.user_id
             WHERE  gp.gig_id = ?"
        );
        $stmt->execute([$gigId]);
        $personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ferry = self::fetchFerry($pdo, $gigId);

        return self::calculateFromPersonnel($personnel, $venueLat, $venueLng, $ferry);
    }

    /**
     * Calculate travel costs with an explicit personnel array (used at agent inquiry time
     * before gig_personnel rows exist).
     *
     * @param  array  $personnel  Rows shaped like the gig_personnel JOIN users query above.
     * @param  float  $venueLat
     * @param  float  $venueLng
     * @param  array{requires_ferry: bool, cost_eur: float}  $ferry
     * @return array{car1_km: float|null, car2_km: float|null, ferry_costs_eur: float, warnings: string[]}
     */
    public static function calculateFromPersonnel(
        array $personnel,
        float $venueLat,
        float $venueLng,
        array $ferry = ['requires_ferry' => false, 'cost_eur' => 0.0]
    ): array {
        $warnings   = [];
        $car1Driver = null;   // [lat, lng]
        $car1Stops  = [];     // ordered waypoints added between driver home and venue
        $car2Driver = null;   // [lat, lng]
        $car2Stops  = [];     // ordered waypoints added between driver home and venue

        foreach ($personnel as $p) {
            $effectiveMode     = $p['transport_override'] ?? $p['transport_mode'];
            $lat               = isset($p['home_lat']) ? (float)$p['home_lat'] : null;
            $lng               = isset($p['home_lng']) ? (float)$p['home_lng'] : null;
            $hasCoords         = ($lat !== null && $lng !== null);

            switch ($p['role']) {
                case 'keyboards':
                    // Car 1 driver — always Tuomas
                    if (!$hasCoords) {
                        $warnings[] = "Car 1 driver ({$p['username']}) has no home coordinates — Car 1 route unavailable.";
                    } else {
                        $car1Driver = [$lat, $lng];
                    }
                    break;

                case 'drums':
                case 'vocals':
                    // Always Car 1 passenger (Joni, Alina — Turku-based)
                    if ($hasCoords) {
                        $car1Stops[] = [$lat, $lng];
                    } else {
                        $warnings[] = "{$p['username']} ({$p['role']}) has no home coordinates — pickup waypoint skipped.";
                    }
                    break;

                case 'guitar':
                    // Car 2 pickup (Lauri, Helsinki) — unless locally overridden
                    if ($effectiveMode === 'local') {
                        // Already at or near venue area; no pickup needed
                        break;
                    }
                    if ($hasCoords) {
                        $car2Stops[] = [$lat, $lng];
                    } else {
                        $warnings[] = "{$p['username']} (guitar) has no home coordinates — Car 2 pickup skipped.";
                    }
                    break;

                case 'bass':
                    // Car 2 driver
                    if (!$hasCoords) {
                        $warnings[] = "Car 2 driver ({$p['username']}) has no home coordinates — Car 2 route unavailable.";
                    } else {
                        $car2Driver = [$lat, $lng];
                    }
                    break;

                case 'sound_engineering':
                    if ($effectiveMode === 'car_owner') {
                        // Drives own car (Valtteri when override set, or explicit own-car flag)
                        $warnings[] = "{$p['username']} (sound engineering) drives own car — not billed in Car 1 or Car 2.";
                    } elseif ($effectiveMode === 'passenger') {
                        // Car 1 passenger (Toni — Turku-based passenger)
                        if ($hasCoords) {
                            $car1Stops[] = [$lat, $lng];
                        } else {
                            $warnings[] = "{$p['username']} (sound engineering, Car 1) has no home coordinates — pickup waypoint skipped.";
                        }
                    } else {
                        // car_owner without override = Car 2 pickup (Valtteri typical case)
                        if ($hasCoords) {
                            $car2Stops[] = [$lat, $lng];
                        } else {
                            $warnings[] = "{$p['username']} (sound engineering, Car 2 pickup) has no home coordinates — waypoint skipped.";
                        }
                    }
                    break;
            }
        }

        $car1Km = self::computeCarRoute($car1Driver, $car1Stops, $venueLat, $venueLng, true);
        $car2Km = self::computeCarRoute($car2Driver, $car2Stops, $venueLat, $venueLng, false);

        if ($car1Driver !== null && $car1Km === null) {
            $warnings[] = 'Car 1 OSRM routing failed — check network and waypoint coordinates.';
        }
        if ($car2Driver !== null && $car2Km === null) {
            $warnings[] = 'Car 2 OSRM routing failed — check network and waypoint coordinates.';
        }

        $ferryCosts = 0.0;
        if ($ferry['requires_ferry'] && $ferry['cost_eur'] > 0.0) {
            $ferryCosts = $ferry['cost_eur'] * 2 * 2; // 2 vehicles × 2 ways
        }

        return [
            'car1_km'         => $car1Km,
            'car2_km'         => $car2Km,
            'ferry_costs_eur' => $ferryCosts,
            'warnings'        => $warnings,
        ];
    }

    /**
     * Build and query one car's route.
     * Route: driver home → [trailer if Car 1] → stops[] → venue → back to driver home (× 2 approx).
     *
     * @param array|null $driver  [lat, lng] or null
     * @param array      $stops   additional pickup waypoints [[lat,lng], ...]
     * @param float      $venueLat
     * @param float      $venueLng
     * @param bool       $isCar1  if true, inserts trailer waypoint after driver home
     * @return float|null  round-trip km or null if no driver / OSRM failure
     */
    private static function computeCarRoute(
        ?array $driver,
        array  $stops,
        float  $venueLat,
        float  $venueLng,
        bool   $isCar1
    ): ?float {
        if ($driver === null) {
            return null;
        }

        $waypoints = [$driver];

        if ($isCar1) {
            $waypoints[] = [self::TRAILER_LAT, self::TRAILER_LNG];
        }

        foreach ($stops as $stop) {
            $waypoints[] = $stop;
        }

        $waypoints[] = [$venueLat, $venueLng];

        $oneWayKm = RoutingHelper::waypointRouteKm($waypoints);
        if ($oneWayKm === null) {
            return null;
        }
        return round($oneWayKm * 2, 1); // round trip
    }

    /**
     * Fetch ferry data from the venue attached to the given gig.
     *
     * @return array{requires_ferry: bool, cost_eur: float}
     */
    private static function fetchFerry(PDO $pdo, int $gigId): array
    {
        $stmt = $pdo->prepare(
            "SELECT v.requires_ferry, v.ferry_cost_estimate_cents
             FROM   gigs g
             JOIN   venues v ON v.id = g.venue_id
             WHERE  g.id = ? AND g.venue_id IS NOT NULL"
        );
        $stmt->execute([$gigId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !$row['requires_ferry']) {
            return ['requires_ferry' => false, 'cost_eur' => 0.0];
        }
        return [
            'requires_ferry' => true,
            'cost_eur'       => ($row['ferry_cost_estimate_cents'] ?? 0) / 100,
        ];
    }
}
