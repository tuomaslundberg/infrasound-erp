<?php
declare(strict_types=1);

/**
 * Geocodes a Finnish venue address to driving distance (km) from Turku city centre.
 *
 * Uses Nominatim (OSM) for geocoding and the OSRM public routing API for distance.
 * No API key required for either service.
 *
 * Returns the distance from Turku used for the distance premium in PriceCalculator.
 * This is NOT the same as mileage estimates (car1/car2 trip km), which depend on
 * the actual driven route including personnel pickups and must be set by the owner.
 */
class GeocodingHelper
{
    // Turku city centre
    private const TURKU_LAT = 60.4518;
    private const TURKU_LON = 22.2666;

    private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
    private const OSRM_URL      = 'https://router.project-osrm.org/route/v1/driving/';
    private const TIMEOUT       = 10;

    /**
     * Return driving distance in km from Turku city centre to the given address.
     * Returns null on geocoding or routing failure; owner enters distance manually.
     */
    public static function distanceFromTurku(string $address, string $city): ?float
    {
        $query  = trim($address . ' ' . $city . ' Finland');
        $coords = self::geocode($query);
        if ($coords === null) {
            return null;
        }
        return self::routeDistance(self::TURKU_LON, self::TURKU_LAT, $coords['lon'], $coords['lat']);
    }

    /**
     * @return array{lat: float, lon: float}|null
     */
    private static function geocode(string $query): ?array
    {
        $url = self::NOMINATIM_URL . '?' . http_build_query([
            'q'      => $query,
            'format' => 'json',
            'limit'  => 1,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['User-Agent: infrasound-erp/1.0 (saturday@infrasound.fi)'],
            CURLOPT_TIMEOUT        => self::TIMEOUT,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        if (!$body) {
            return null;
        }
        $results = json_decode($body, true);
        if (empty($results[0]['lat'])) {
            return null;
        }
        return ['lat' => (float)$results[0]['lat'], 'lon' => (float)$results[0]['lon']];
    }

    private static function routeDistance(
        float $fromLon, float $fromLat,
        float $toLon,   float $toLat
    ): ?float {
        $url = self::OSRM_URL . "{$fromLon},{$fromLat};{$toLon},{$toLat}?overview=false";

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
        // OSRM returns metres; convert to km, round to 1 decimal
        return round($data['routes'][0]['distance'] / 1000, 1);
    }
}
