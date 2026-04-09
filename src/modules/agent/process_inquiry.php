<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';
require_once __DIR__ . '/../../../cli/lib/PriceCalculator.php';
require_once __DIR__ . '/../../../cli/lib/TravelCalculator.php';
require_once __DIR__ . '/lib/InquiryExtractor.php';
require_once __DIR__ . '/lib/GeocodingHelper.php';

$extractionError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $rawText = trim($_POST['raw_text'] ?? '');
    if ($rawText === '') {
        $extractionError = 'Paste the inquiry text before submitting.';
        goto render_form;
    }

    // --- AI extraction --------------------------------------------------------
    try {
        $fields = InquiryExtractor::extract($rawText);
    } catch (RuntimeException $e) {
        error_log('InquiryExtractor failed: ' . $e->getMessage());
        $extractionError = 'AI extraction failed: ' . $e->getMessage()
            . ' — try again, or use the <a href="/gigs/new">manual form</a>.';
        goto render_form;
    }

    // --- Parse extracted fields with safe fallbacks ---------------------------
    $customerName     = trim($fields['customer_name']      ?? '');
    $customerType     = $fields['customer_type']            ?? 'other';
    $gigDate          = $fields['gig_date']                 ?? null;
    $venueName        = trim($fields['venue_name']          ?? '');
    $venueAddress     = trim($fields['venue_address']       ?? '') ?: null;
    $venueCity        = trim($fields['venue_city']          ?? '') ?: null;
    $contactFirstName = trim($fields['contact_first_name']  ?? '');
    $contactLastName  = trim($fields['contact_last_name']   ?? '');
    $contactEmail     = trim($fields['contact_email']       ?? '') ?: null;
    $contactPhone     = trim($fields['contact_phone']       ?? '') ?: null;
    $orderDesc        = trim($fields['order_description']   ?? '') ?: null;
    $notes            = trim($fields['notes']               ?? '') ?: null;

    if (!in_array($customerType, ['wedding', 'company', 'other'], true)) {
        $customerType = 'other';
    }

    // --- Geocode venue → distance from Turku + lat/lng for TravelCalculator ---
    // Sanitize: reject placeholder strings the model may emit instead of null.
    $placeholderPattern = '/^[<(]?\s*(unknown|ei tiedossa|n\/a|tbd|null)\s*[>)]?$/i';
    if ($venueAddress && preg_match($placeholderPattern, $venueAddress)) {
        $venueAddress = null;
    }
    if ($venueCity && preg_match($placeholderPattern, $venueCity)) {
        $venueCity = null;
    }
    if ($venueName && preg_match($placeholderPattern, $venueName)) {
        $venueName = '';
    }

    $distFromTurku = null;
    $venueLat      = null;
    $venueLng      = null;
    // Try geocoding with address+city first; fall back to venue name alone if both are null.
    $geocodeQuery = ($venueAddress || $venueCity)
        ? ($venueAddress ?? '') . ' ' . ($venueCity ?? '')
        : $venueName;
    if ($geocodeQuery !== '' && $geocodeQuery !== ' ') {
        $venueGeo = GeocodingHelper::geocodeVenue(trim($geocodeQuery), '');
        if ($venueGeo !== null) {
            $distFromTurku = $venueGeo['distance_km'];
            $venueLat      = $venueGeo['lat'];
            $venueLng      = $venueGeo['lng'];
        }
    }

    // --- Travel costs: compute with default lineup via TravelCalculator -------
    // No gig_personnel rows exist yet at inquiry time; use named default users.
    $car1Km         = null;
    $car2Km         = null;
    $otherTravelCents = 0;

    if ($venueLat !== null && $venueLng !== null) {
        $defaultUsernames = [
            'tuomas.lundberg', 'toni.puttonen', 'joni.virtanen',
            'alina.kangas', 'lauri.lehtinen', 'mortti.markkanen',
        ];
        // Role assignments for synthetic default lineup
        $defaultRoles = [
            'tuomas.lundberg' => 'keyboards',
            'toni.puttonen'   => 'sound_engineering',
            'joni.virtanen'   => 'drums',
            'alina.kangas'    => 'vocals',
            'lauri.lehtinen'  => 'guitar',
            'mortti.markkanen'=> 'bass',
        ];
        $placeholders = implode(',', array_fill(0, count($defaultUsernames), '?'));
        $userStmt = $pdo->prepare(
            "SELECT username, home_lat, home_lng, transport_mode
             FROM   users WHERE username IN ($placeholders) AND deleted_at IS NULL"
        );
        $userStmt->execute($defaultUsernames);
        $defaultUsers = $userStmt->fetchAll(PDO::FETCH_ASSOC);

        $synthPersonnel = array_map(fn($u) => [
            'role'               => $defaultRoles[$u['username']] ?? 'other',
            'transport_override' => null,
            'username'           => $u['username'],
            'home_lat'           => $u['home_lat'],
            'home_lng'           => $u['home_lng'],
            'transport_mode'     => $u['transport_mode'],
        ], $defaultUsers);

        $travel = TravelCalculator::calculateFromPersonnel(
            $synthPersonnel,
            $venueLat,
            $venueLng
        );
        $car1Km           = $travel['car1_km'];
        $car2Km           = $travel['car2_km'] ?? 0;
        $otherTravelCents = (int)round($travel['ferry_costs_eur'] * 100);
    }

    // --- Price calculation with extracted inputs (baseline) -------------------
    $calculator   = new PriceCalculator();
    $price        = $calculator->calculate([
        'meta'  => ['channel' => 'mail'],
        'gig'   => ['distances' => [
            'from_turku_km'          => $distFromTurku ?? 0,
            'car1_trip_km'           => $car1Km ?? (($distFromTurku ?? 0) * 2),
            'car2_trip_km'           => $car2Km ?? 0,
            'other_travel_costs_eur' => $otherTravelCents / 100,
        ]],
        'order' => [
            'dynamic_pricing_tier1' => false,
            'dynamic_pricing_tier2' => false,
        ],
        'additional_services' => [
            'ennakkoroudaus'      => 0,
            'song_requests_extra' => 0,
            'extra_performances'  => 0,
            'background_music_h'  => 0,
            'live_album'          => 0,
            'discount_eur'        => 0,
        ],
    ]);
    $basePriceCents = (int)round($price['gross_total'] * 100);

    // --- Persist (transaction) ------------------------------------------------
    try {
        $pdo->beginTransaction();

        // Match existing customer by name; INSERT only if new.
        $custRow = $pdo->prepare(
            'SELECT id FROM customers WHERE LOWER(name) = LOWER(?) AND deleted_at IS NULL LIMIT 1'
        );
        $custRow->execute([$customerName ?: 'Unknown']);
        $existingCustomer = $custRow->fetch(PDO::FETCH_ASSOC);
        if ($existingCustomer) {
            $customerId = (int)$existingCustomer['id'];
        } else {
            $pdo->prepare('INSERT INTO customers (name, type) VALUES (?, ?)')
                ->execute([$customerName ?: 'Unknown', 'person']);
            $customerId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare('INSERT INTO contacts (first_name, last_name, email, phone) VALUES (?, ?, ?, ?)')
            ->execute([$contactFirstName, $contactLastName, $contactEmail, $contactPhone]);
        $contactId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO customer_contacts (customer_id, contact_id, is_primary) VALUES (?, ?, 1)')
            ->execute([$customerId, $contactId]);

        // Match existing venue by name + city; INSERT only if new.
        $venueRow = $pdo->prepare(
            'SELECT id, distance_from_turku_km FROM venues
             WHERE  LOWER(name) = LOWER(?)
             AND    (? IS NULL OR city IS NULL OR LOWER(city) = LOWER(?))
             AND    deleted_at IS NULL
             LIMIT  1'
        );
        $venueRow->execute([$venueName ?: 'Unknown', $venueCity, $venueCity]);
        $existingVenue = $venueRow->fetch(PDO::FETCH_ASSOC);
        if ($existingVenue) {
            $venueId = (int)$existingVenue['id'];
            // Store lat/lng if we geocoded successfully and they're not already set
            if ($venueLat !== null) {
                $pdo->prepare(
                    'UPDATE venues SET lat = ?, lng = ? WHERE id = ? AND lat IS NULL'
                )->execute([$venueLat, $venueLng, $venueId]);
            }
            // Fall back to stored distance if geocoding produced nothing.
            if ($distFromTurku === null && $existingVenue['distance_from_turku_km']) {
                $distFromTurku = (float)$existingVenue['distance_from_turku_km'];
            }
        } else {
            $pdo->prepare(
                'INSERT INTO venues (name, address_line, city, distance_from_turku_km, lat, lng)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$venueName ?: 'Unknown', $venueAddress, $venueCity, $distFromTurku, $venueLat, $venueLng]);
            $venueId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare(
            "INSERT INTO gigs
               (customer_id, contact_id, venue_id, gig_date, status, channel, customer_type,
                order_description, base_price_cents, quoted_price_cents,
                car1_distance_km, car2_distance_km, other_travel_costs_cents,
                pricing_tier1, pricing_tier2,
                qty_ennakkoroudaus, qty_song_requests_extra, qty_extra_performances,
                qty_background_music_h, qty_live_album, discount_cents,
                notes)
             VALUES (?, ?, ?, ?, 'inquiry', 'mail', ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 0, 0, ?)"
        )->execute([
            $customerId, $contactId, $venueId, $gigDate,
            $customerType, $orderDesc,
            $basePriceCents, $basePriceCents,
            $car1Km, $car2Km ?? 0, $otherTravelCents,
            $notes,
        ]);
        $gigId = (int)$pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Agent inquiry save failed: ' . $e->getMessage());
        $extractionError = 'Database error — could not save. Check the server error log.';
        goto render_form;
    }

    header('Location: /gigs/' . $gigId . '?notice=inquiry_created');
    exit;
}

render_form:
render_layout('New inquiry (AI)', function () use ($extractionError) {
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">New inquiry (AI)</h2>
    <a href="/gigs" class="btn btn-link btn-sm px-0">← Gigs</a>
  </div>
  <p class="text-muted mb-3">
    Paste a raw inquiry email or message. The AI extracts structured fields and
    creates the gig with status <em>inquiry</em>. Review and correct the result
    on the gig detail page before generating a quote.
  </p>

  <?php if ($extractionError !== null): ?>
  <div class="alert alert-danger"><?= $extractionError ?></div>
  <?php endif; ?>

  <form method="post" action="/agent/process-inquiry">
    <div class="mb-3">
      <label for="raw_text" class="form-label fw-semibold">Inquiry text</label>
      <textarea id="raw_text" name="raw_text" class="form-control font-monospace"
                rows="18" placeholder="Paste the inquiry here…" required></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Extract and save</button>
    <a href="/gigs/new" class="btn btn-link">Manual form instead</a>
  </form>
<?php
});
