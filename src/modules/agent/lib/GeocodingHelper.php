<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/lib/RoutingHelper.php';

/**
 * Geocodes a Finnish venue address to driving distance (km) from Turku city centre,
 * and captures venue coordinates for TravelCalculator multi-waypoint routing.
 *
 * Uses RoutingHelper for Nominatim geocoding and OSRM routing.
 * No API key required for either service.
 */
class GeocodingHelper
{
    // Turku city centre
    private const TURKU_LAT = 60.4518;
    private const TURKU_LNG = 22.2666;

    /**
     * Return driving distance in km from Turku city centre to the given address.
     * Returns null on geocoding or routing failure; owner enters distance manually.
     */
    public static function distanceFromTurku(string $address, string $city): ?float
    {
        $result = self::geocodeVenue($address, $city);
        return $result ? $result['distance_km'] : null;
    }

    /**
     * Geocode a venue address and return coordinates + distance from Turku.
     *
     * @return array{lat: float, lng: float, distance_km: float}|null
     */
    public static function geocodeVenue(string $address, string $city): ?array
    {
        $query  = trim($address . ' ' . $city . ' Finland');
        $coords = RoutingHelper::geocode($query);
        if ($coords === null) {
            return null;
        }

        $distKm = RoutingHelper::waypointRouteKm([
            [self::TURKU_LAT, self::TURKU_LNG],
            [$coords['lat'], $coords['lng']],
        ]);
        if ($distKm === null) {
            return null;
        }

        return [
            'lat'         => $coords['lat'],
            'lng'         => $coords['lng'],
            'distance_km' => $distKm,
        ];
    }
}
