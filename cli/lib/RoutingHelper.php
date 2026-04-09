<?php
declare(strict_types=1);

/**
 * Shared geocoding and OSRM routing primitives.
 *
 * Used by GeocodingHelper (venue distance-from-Turku) and TravelCalculator
 * (full personnel pickup routes).
 *
 * External services:
 *   Nominatim OSM  — geocoding (no API key; 1 req/s rate limit)
 *   OSRM public    — routing (no API key; reasonable use expected)
 */
class RoutingHelper
{
    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
    private const OSRM_URL      = 'https://router.project-osrm.org/route/v1/driving/';
    private const TIMEOUT       = 10;
    private const USER_AGENT    = 'infrasound-erp/1.0 (saturday@infrasound.fi)';

    /**
     * Geocode a free-form address string via Nominatim.
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function geocode(string $query): ?array
    {
        $url = self::NOMINATIM_URL . '?' . http_build_query([
            'q'      => $query,
            'format' => 'json',
            'limit'  => 1,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: ' . self::USER_AGENT],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        if (!$body) {
            return null;
        }
        $results = json_decode($body, true);
        if (empty($results[0]['lat']) || empty($results[0]['lon'])) {
            return null;
        }
        return [
            'lat' => (float)$results[0]['lat'],
            'lng' => (float)$results[0]['lon'],
        ];
    }

    /**
     * Compute total driving distance for an ordered sequence of waypoints via OSRM.
     *
     * @param  array<array{float, float}> $waypoints  Each element: [lat, lng]. Min 2.
     * @return float|null  Total route distance in km (1 decimal), or null on failure.
     */
    public static function waypointRouteKm(array $waypoints): ?float
    {
        if (count($waypoints) < 2) {
            return null;
        }

        // OSRM expects lon,lat pairs separated by semicolons
        $coords = implode(';', array_map(
            fn($p) => $p[1] . ',' . $p[0],  // [lat, lng] → "lng,lat"
            $waypoints
        ));

        $url = self::OSRM_URL . $coords . '?overview=false';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        if (!$body) {
            return null;
        }
        $data = json_decode($body, true);
        if (($data['code'] ?? '') !== 'Ok' || empty($data['routes'][0]['distance'])) {
            return null;
        }
        return round($data['routes'][0]['distance'] / 1000, 1);
    }
}
