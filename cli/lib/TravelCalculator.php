<?php
declare(strict_types=1);

require_once __DIR__ . '/RoutingHelper.php';

/**
 * Computes route-based travel costs for a gig based on assigned personnel.
 *
 * Car assignment is determined by users.transport_mode and users.default_car,
 * NOT by the gig role. The role describes what instrument someone plays; it has
 * no bearing on which car they travel in.
 *
 * Canonical flows:
 *
 * Car 1 (Caddy + trailer):
 *   transport_mode='car_owner', default_car=1 → driver (Tuomas)
 *   Trailer (Opettajankatu 9) always inserted as first stop after driver home.
 *   Remaining Car 1 passengers picked up in query-result order.
 *
 * Car 2 (own car):
 *   transport_mode='car_owner', default_car=2 → driver (Mortti, Maxwell)
 *   Car 2 passengers (default_car=2, not car_owner) picked up en route.
 *
 * Passengers:
 *   transport_mode='passenger', default_car=1 → Car 1 stop (Toni, Alina, Joni)
 *   transport_mode='passenger', default_car=2 → Car 2 stop (Lauri — Helsinki pickup)
 *
 * Local / unbilled:
 *   transport_mode='local' (or transport_override='local') → drives own car to
 *   venue, not billed; emits a warning and is excluded from both car routes.
 *
 * Per-gig overrides:
 *   gig_personnel.transport_override, when non-null, replaces transport_mode for
 *   that gig. default_car is always taken from users.default_car.
 *
 * Ferry: if venue.requires_ferry = 1, adds 2 × ferry_cost_estimate_cents × 2 ways.
 *
 * All km values are one-way route × 2 (round trip approximation).
 * Manual override: owner edits car1_distance_km / car2_distance_km on the gig form.
 */
class TravelCalculator
{
    // Opettajankatu 9, Turku — trailer storage; always first Car 1 waypoint after driver home.
    // Pre-geocoded from Nominatim. Update if storage location changes.
    private const TRAILER_LAT = 60.4481;
    private const TRAILER_LNG = 22.2547;

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
                    u.username, u.home_lat, u.home_lng, u.transport_mode, u.default_car
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
     * Each element must have:
     *   username, home_lat, home_lng, transport_mode, default_car, transport_override, role
     *
     * @param  array  $personnel
     * @param  float  $venueLat
     * @param  float  $venueLng
     * @param  array{requires_ferry: bool, cost_eur: float}  $ferry
     * @return array{car1_km: float|null, car2_km: float|null, ferry_costs_eur: float, warnings: string[], car1_route: array|null, car2_route: array|null}
     */
    public static function calculateFromPersonnel(
        array $personnel,
        float $venueLat,
        float $venueLng,
        array $ferry = ['requires_ferry' => false, 'cost_eur' => 0.0]
    ): array {
        $warnings   = [];
        // Each entry: ['lat' => float, 'lng' => float, 'label' => string]
        $car1Driver = null;
        $car1Stops  = [];
        $car2Driver = null;
        $car2Stops  = [];

        foreach ($personnel as $p) {
            // transport_override on the gig row overrides the user-level transport_mode.
            // default_car always comes from the user, never from the gig.
            $overrideMode  = $p['transport_override'];
            $baseMode      = $p['transport_mode'];
            $effectiveMode = $overrideMode ?? $baseMode;

            // transport_override='car_owner' means "drives own non-band car this gig, not billed"
            // (distinct from transport_mode='car_owner' = designated band car driver).
            // Normalise to 'local' so the exclusion logic below handles both identically.
            if ($overrideMode === 'car_owner') {
                $effectiveMode = 'local';
            }

            $defaultCar    = (int)($p['default_car'] ?? 1);

            $lat       = isset($p['home_lat']) ? (float)$p['home_lat'] : null;
            $lng       = isset($p['home_lng']) ? (float)$p['home_lng'] : null;
            $hasCoords = ($lat !== null && $lng !== null);

            if ($effectiveMode === 'local') {
                // Drives own car to venue, not billed. No pickup.
                $warnings[] = "{$p['username']} drives own car (local) — excluded from Car 1 and Car 2 routes.";
                continue;
            }

            if ($effectiveMode === 'public_transport') {
                // Travels independently by train/bus; no band car pickup needed or billed.
                continue;
            }

            if ($effectiveMode === 'car_owner') {
                // This person drives a band car.
                if ($defaultCar === 2) {
                    // Car 2 driver (Mortti, Maxwell).
                    if ($car2Driver !== null) {
                        $warnings[] = "Duplicate Car 2 driver: {$p['username']} ignored — {$car2Driver['label']} already assigned. Check users.default_car and transport_mode.";
                    } elseif (!$hasCoords) {
                        $warnings[] = "Car 2 driver ({$p['username']}) has no home coordinates — Car 2 route unavailable.";
                    } else {
                        $car2Driver = ['lat' => $lat, 'lng' => $lng, 'label' => "{$p['username']} (Car 2 driver)"];
                    }
                } else {
                    // Car 1 driver (Tuomas).
                    if ($car1Driver !== null) {
                        $warnings[] = "Duplicate Car 1 driver: {$p['username']} ignored — {$car1Driver['label']} already assigned. Check users.default_car and transport_mode.";
                    } elseif (!$hasCoords) {
                        $warnings[] = "Car 1 driver ({$p['username']}) has no home coordinates — Car 1 route unavailable.";
                    } else {
                        $car1Driver = ['lat' => $lat, 'lng' => $lng, 'label' => "{$p['username']} (Car 1 driver)"];
                    }
                }
            } else {
                // Passenger (transport_mode='passenger' or public_transport or unrecognised value).
                if ($defaultCar === 2) {
                    // Travels with Car 2; picked up en route (e.g. Lauri from Helsinki).
                    if ($hasCoords) {
                        $car2Stops[] = ['lat' => $lat, 'lng' => $lng, 'label' => "{$p['username']} (Car 2 passenger)"];
                    } else {
                        $warnings[] = "{$p['username']} (Car 2 passenger) has no home coordinates — pickup waypoint skipped.";
                    }
                } else {
                    // Travels with Car 1 (default).
                    if ($hasCoords) {
                        $car1Stops[] = ['lat' => $lat, 'lng' => $lng, 'label' => "{$p['username']} (Car 1 passenger)"];
                    } else {
                        $warnings[] = "{$p['username']} (Car 1 passenger) has no home coordinates — pickup waypoint skipped.";
                    }
                }
            }
        }

        $car1Result = self::computeCarRouteDetail($car1Driver, $car1Stops, $venueLat, $venueLng, true);
        $car2Result = self::computeCarRouteDetail($car2Driver, $car2Stops, $venueLat, $venueLng, false);
        $car1Km = $car1Result['km'];
        $car2Km = $car2Result['km'];

        if ($car1Driver !== null && $car1Km === null) {
            $warnings[] = 'Car 1 OSRM routing failed — check network and waypoint coordinates.';
        }
        if ($car2Driver !== null && $car2Km === null) {
            $warnings[] = 'Car 2 OSRM routing failed — check network and waypoint coordinates.';
        }
        if ($car2Driver === null && !empty($car2Stops)) {
            $stopNames = implode(', ', array_column($car2Stops, 'label'));
            $warnings[] = "Car 2 passenger(s) have no driver — not included in any route: $stopNames. Add a Car 2 driver or set transport_override='local'.";
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
            'car1_route'      => $car1Result['route'],
            'car2_route'      => $car2Result['route'],
        ];
    }

    /**
     * Build and query one car's route, returning km and labelled route detail.
     * Route: driver home → [trailer if Car 1] → stops[] → venue → back to driver home (× 2 approx).
     *
     * @param array|null $driver  ['lat', 'lng', 'label'] or null
     * @param array      $stops   additional pickup waypoints [['lat','lng','label'], ...]
     * @param float      $venueLat
     * @param float      $venueLng
     * @param bool       $isCar1  if true, inserts trailer waypoint after driver home
     * @return array{km: float|null, route: array|null}
     */
    private static function computeCarRouteDetail(
        ?array $driver,
        array  $stops,
        float  $venueLat,
        float  $venueLng,
        bool   $isCar1
    ): array {
        if ($driver === null) {
            return ['km' => null, 'route' => null];
        }

        // Labelled waypoints for display
        $labelledWaypoints = [
            ['label' => $driver['label'], 'lat' => $driver['lat'], 'lng' => $driver['lng']],
        ];

        if ($isCar1) {
            $labelledWaypoints[] = ['label' => 'Trailer (Opettajankatu 9)', 'lat' => self::TRAILER_LAT, 'lng' => self::TRAILER_LNG];
        }

        foreach ($stops as $stop) {
            $labelledWaypoints[] = ['label' => $stop['label'], 'lat' => $stop['lat'], 'lng' => $stop['lng']];
        }

        $labelledWaypoints[] = ['label' => 'Venue', 'lat' => $venueLat, 'lng' => $venueLng];

        // Flat [lat, lng] pairs for OSRM
        $coords = array_map(fn($w) => [$w['lat'], $w['lng']], $labelledWaypoints);

        $detail = RoutingHelper::waypointRouteDetail($coords);
        if ($detail === null) {
            return ['km' => null, 'route' => null];
        }

        $oneWayKm    = $detail['total_km'];
        $roundTripKm = round($oneWayKm * 2, 1);

        return [
            'km'    => $roundTripKm,
            'route' => [
                'waypoints'   => $labelledWaypoints,
                'one_way_km'  => $oneWayKm,
                'legs_km'     => $detail['legs_km'],
            ],
        ];
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
