<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';
require_once __DIR__ . '/../../../cli/lib/PriceCalculator.php';
require_once __DIR__ . '/../../../cli/lib/TravelCalculator.php';
require_once __DIR__ . '/lib/InquiryExtractor.php';
require_once __DIR__ . '/lib/GeocodingHelper.php';
require_once __DIR__ . '/lib/GigCreator.php';

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
        $extractionError = 'AI extraction failed: ' . htmlspecialchars($e->getMessage())
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

    // --- Persist via GigCreator -----------------------------------------------
    try {
        $gigId = GigCreator::create($pdo, [
            'customer_name'      => $customerName,
            'customer_type'      => $customerType,
            'gig_date'           => $gigDate,
            'venue_name'         => $venueName,
            'venue_address'      => $venueAddress,
            'venue_city'         => $venueCity,
            'contact_first_name' => $contactFirstName,
            'contact_last_name'  => $contactLastName,
            'contact_email'      => $contactEmail,
            'contact_phone'      => $contactPhone,
            'order_description'  => $orderDesc,
            'notes'              => $notes,
            'dist_from_turku'    => $distFromTurku,
            'venue_lat'          => $venueLat,
            'venue_lng'          => $venueLng,
            'car1_km'            => $car1Km,
            'car2_km'            => $car2Km,
            'other_travel_cents' => $otherTravelCents,
            'base_price_cents'   => $basePriceCents,
        ], 'mail');
    } catch (RuntimeException $e) {
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
