<?php
declare(strict_types=1);

/**
 * GigCreator — shared gig entity creation logic.
 *
 * Encapsulates the customer → contact → venue → gig database transaction so it
 * can be reused by both the manual agent form (process_inquiry.php) and the
 * Webflow webhook handler.
 *
 * $fields keys (all optional/nullable unless noted):
 *   customer_name        string        Hiring party name (default 'Unknown')
 *   customer_type        string        wedding|company|other (default 'other')
 *   gig_date             string|null   YYYY-MM-DD
 *   venue_name           string        (default 'Unknown')
 *   venue_address        string|null
 *   venue_city           string|null
 *   contact_first_name   string        (default '')
 *   contact_last_name    string        (default '')
 *   contact_email        string|null
 *   contact_phone        string|null
 *   order_description    string|null
 *   notes                string|null
 *   dist_from_turku      float|null    km from Turku (from geocoding)
 *   venue_lat            float|null
 *   venue_lng            float|null
 *   car1_km              float|null
 *   car2_km              float|null
 *   other_travel_cents   int           (default 0)
 *   base_price_cents     int           (default 0)
 */
class GigCreator
{
    /**
     * Persist a new gig entity and all related rows in a single transaction.
     *
     * @param  PDO    $pdo
     * @param  array  $fields  See class docblock for expected keys.
     * @param  string $channel ENUM value from gigs.channel (e.g. 'mail', 'saturday_band')
     * @param  string $status  ENUM value from gigs.status (default 'inquiry')
     * @return int             ID of the newly created gig row.
     * @throws RuntimeException on database failure.
     * @throws \InvalidArgumentException if $status is not a valid enum value.
     */
    public static function create(PDO $pdo, array $fields, string $channel, string $status = 'inquiry'): int
    {
        $customerName    = $fields['customer_name']      ?? 'Unknown';
        $customerType    = $fields['customer_type']      ?? 'other';
        $gigDate         = $fields['gig_date']           ?? null;
        $venueName       = $fields['venue_name']         ?? 'Unknown';
        $venueAddress    = $fields['venue_address']      ?? null;
        $venueCity       = $fields['venue_city']         ?? null;
        $firstName       = $fields['contact_first_name'] ?? '';
        $lastName        = $fields['contact_last_name']  ?? '';
        $contactEmail    = $fields['contact_email']      ?? null;
        $contactPhone    = $fields['contact_phone']      ?? null;
        $orderDesc       = $fields['order_description']  ?? null;
        $notes           = $fields['notes']              ?? null;
        $distFromTurku   = $fields['dist_from_turku']    ?? null;
        $venueLat        = $fields['venue_lat']          ?? null;
        $venueLng        = $fields['venue_lng']          ?? null;
        $car1Km          = $fields['car1_km']            ?? null;
        $car2Km          = $fields['car2_km']            ?? null;
        $otherTravelCents = (int)($fields['other_travel_cents'] ?? 0);
        $basePriceCents  = (int)($fields['base_price_cents']    ?? 0);

        if (!in_array($customerType, ['wedding', 'company', 'other'], true)) {
            $customerType = 'other';
        }
        if (!in_array($status, ['inquiry', 'quoted', 'confirmed', 'delivered', 'cancelled', 'declined'], true)) {
            throw new \InvalidArgumentException("Invalid gig status: $status");
        }
        if ($customerName === '') $customerName = 'Unknown';
        if ($venueName    === '') $venueName    = 'Unknown';

        try {
            $pdo->beginTransaction();

            // Customer — match existing by name or INSERT.
            $custRow = $pdo->prepare(
                'SELECT id FROM customers WHERE LOWER(name) = LOWER(?) AND deleted_at IS NULL LIMIT 1'
            );
            $custRow->execute([$customerName]);
            $existingCustomer = $custRow->fetch(PDO::FETCH_ASSOC);
            if ($existingCustomer) {
                $customerId = (int)$existingCustomer['id'];
            } else {
                $dbCustomerType = $customerType === 'company' ? 'company' : 'person';
                $pdo->prepare('INSERT INTO customers (name, type) VALUES (?, ?)')
                    ->execute([$customerName, $dbCustomerType]);
                $customerId = (int)$pdo->lastInsertId();
            }

            // Contact — always INSERT a fresh row.
            $pdo->prepare(
                'INSERT INTO contacts (first_name, last_name, email, phone) VALUES (?, ?, ?, ?)'
            )->execute([$firstName, $lastName, $contactEmail, $contactPhone]);
            $contactId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO customer_contacts (customer_id, contact_id, is_primary) VALUES (?, ?, 1)'
            )->execute([$customerId, $contactId]);

            // Venue — match by name + city or INSERT.
            $venueRow = $pdo->prepare(
                'SELECT id, distance_from_turku_km FROM venues
                 WHERE  LOWER(name) = LOWER(?)
                 AND    (? IS NULL OR city IS NULL OR LOWER(city) = LOWER(?))
                 AND    deleted_at IS NULL
                 LIMIT  1'
            );
            $venueRow->execute([$venueName, $venueCity, $venueCity]);
            $existingVenue = $venueRow->fetch(PDO::FETCH_ASSOC);
            if ($existingVenue) {
                $venueId = (int)$existingVenue['id'];
                if ($venueLat !== null) {
                    $pdo->prepare(
                        'UPDATE venues SET lat = ?, lng = ? WHERE id = ? AND lat IS NULL'
                    )->execute([$venueLat, $venueLng, $venueId]);
                }
                if ($distFromTurku === null && $existingVenue['distance_from_turku_km']) {
                    $distFromTurku = (float)$existingVenue['distance_from_turku_km'];
                }
            } else {
                $pdo->prepare(
                    'INSERT INTO venues (name, address_line, city, distance_from_turku_km, lat, lng)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$venueName, $venueAddress, $venueCity, $distFromTurku, $venueLat, $venueLng]);
                $venueId = (int)$pdo->lastInsertId();
            }

            // Gig.
            $pdo->prepare(
                "INSERT INTO gigs
                   (customer_id, contact_id, venue_id, gig_date, status, channel, customer_type,
                    order_description, base_price_cents, quoted_price_cents,
                    car1_distance_km, car2_distance_km, other_travel_costs_cents,
                    pricing_tier1, pricing_tier2,
                    qty_ennakkoroudaus, qty_song_requests_extra, qty_extra_performances,
                    qty_background_music_h, qty_live_album, discount_cents,
                    notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 0, 0, ?)"
            )->execute([
                $customerId, $contactId, $venueId, $gigDate,
                $status, $channel, $customerType, $orderDesc,
                $basePriceCents, $basePriceCents,
                $car1Km, $car2Km ?? 0, $otherTravelCents,
                $notes,
            ]);
            $gigId = (int)$pdo->lastInsertId();

            $pdo->commit();
            return $gigId;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw new RuntimeException('GigCreator::create failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
