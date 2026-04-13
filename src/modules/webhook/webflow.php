<?php
declare(strict_types=1);

/**
 * Webflow form-submission webhook handler.
 *
 * Receives a JSON POST from Webflow on every saturday.band form submission,
 * runs the agent pipeline (AI extraction or direct field mapping), and creates
 * a gig entity with status=inquiry, channel=saturday_band.
 *
 * Security: validated via a shared secret token in the query string.
 *   Configure in Webflow: Integrations → Webhooks → Trigger: Form submission
 *   URL: https://infrasound.fi/webhook/webflow?token=<WEBFLOW_WEBHOOK_SECRET>
 *
 * Returns JSON. 200 on success, 5xx on server error (Webflow retries), 4xx on bad request.
 */

require_once __DIR__ . '/../../../cli/lib/PriceCalculator.php';
require_once __DIR__ . '/../../../cli/lib/TravelCalculator.php';
require_once __DIR__ . '/../agent/lib/InquiryExtractor.php';
require_once __DIR__ . '/../agent/lib/GeocodingHelper.php';
require_once __DIR__ . '/../agent/lib/GigCreator.php';

header('Content-Type: application/json');

// --- Method check -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// --- Read raw body first (needed for signature validation) ------------------
$rawBody = file_get_contents('php://input');

// --- URL token check (first line of defence) --------------------------------
$expectedToken = getenv('WEBFLOW_WEBHOOK_SECRET') ?: '';
$providedToken = $_GET['token'] ?? '';

if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorised']);
    exit;
}

// --- Webflow signature validation (HMAC-SHA256) -----------------------------
// Webflow signs: HMAC-SHA256(key=WEBFLOW_SIGNING_SECRET, data="timestamp:rawBody")
// Headers: x-webflow-timestamp, x-webflow-signature
$signingSecret = getenv('WEBFLOW_SIGNING_SECRET') ?: '';
if ($signingSecret !== '') {
    $timestamp = $_SERVER['HTTP_X_WEBFLOW_TIMESTAMP'] ?? '';
    $signature = $_SERVER['HTTP_X_WEBFLOW_SIGNATURE'] ?? '';

    if ($timestamp === '' || $signature === '') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Missing Webflow signature headers']);
        exit;
    }

    // Reject payloads older than 5 minutes (replay attack protection).
    if (abs(time() - (int)$timestamp) > 300) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Webhook timestamp too old']);
        exit;
    }

    $expected = hash_hmac('sha256', $timestamp . ':' . $rawBody, $signingSecret);
    if (!hash_equals($expected, $signature)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
        exit;
    }
}

// --- Parse body -------------------------------------------------------------
$body = json_decode($rawBody, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

// Log full payload on first use to confirm Webflow's exact format.
error_log('Webflow webhook payload: ' . json_encode($body));

// Webflow V2 wraps submission data under payload.fields; V1 under data.
// Support both gracefully.
$formName = $body['payload']['nameAttr']
         ?? $body['payload']['name']
         ?? $body['formName']
         ?? $body['name']
         ?? '';

$data = $body['payload']['fields']
     ?? $body['payload']['data']
     ?? $body['data']
     ?? $body['fields']
     ?? [];

if (empty($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No form data in payload']);
    exit;
}

// --- Branch on form ---------------------------------------------------------
try {
    if ($formName === 'Tilauslomake') {
        $gigId = handleTilauslomake($pdo, $data);
    } else {
        // Default: treat as free-form inquiry (Email Form or unknown).
        $gigId = handleEmailForm($pdo, $data);
    }
} catch (Throwable $e) {
    error_log('Webflow webhook gig creation failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Internal server error']);
    exit;
}

echo json_encode(['ok' => true, 'gig_id' => $gigId]);
exit;


// ---------------------------------------------------------------------------
// Handlers
// ---------------------------------------------------------------------------

/**
 * Email Form: 3 free-form fields → AI extraction pipeline.
 */
function handleEmailForm(PDO $pdo, array $data): int
{
    $name    = trim($data['name']    ?? '');
    $email   = trim($data['email']   ?? '');
    $message = trim($data['Message'] ?? $data['message'] ?? '');

    $rawText = implode("\n", array_filter([
        $name    !== '' ? "Lähettäjä: $name"       : null,
        $email   !== '' ? "Sähköposti: $email"      : null,
        $message !== '' ? "\n$message"              : null,
    ]));

    if ($rawText === '') {
        throw new RuntimeException('Empty inquiry text from Email Form');
    }

    $fields = InquiryExtractor::extract($rawText);

    // Ensure contact email is filled even if AI didn't extract it.
    if (empty($fields['contact_email']) && $email !== '') {
        $fields['contact_email'] = $email;
    }

    return runPipelineAndCreate($pdo, $fields, 'saturday_band');
}

/**
 * Tilauslomake: structured fields → direct mapping (no AI needed for most fields).
 */
function handleTilauslomake(PDO $pdo, array $data): int
{
    $firstName = trim($data['Etunimi']        ?? '');
    $lastName  = trim($data['Sukunimi']       ?? '');
    $email     = trim($data['email']          ?? '');
    $phone     = trim($data['Phone']          ?? '');
    $isCompany = !empty($data['Yritys']);
    $orgName   = trim($data['Yhteis-n-nimi']  ?? '');
    $vatId     = trim($data['Y-tunnus']       ?? '');
    $dateRaw   = trim($data['Tilaisuuden-ajankohta'] ?? '');

    $customerName = $isCompany && $orgName !== '' ? $orgName : "$firstName $lastName";
    $customerType = $isCompany ? 'company' : 'other';

    // Parse Finnish date format DD.MM.YYYY; leave null if ambiguous.
    $gigDate = null;
    if (preg_match('#^(\d{1,2})\.(\d{1,2})\.(\d{4})$#', $dateRaw, $m)) {
        $gigDate = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    // Append VAT ID and raw date to notes if not parseable.
    $notesParts = [];
    if ($vatId !== '') {
        $notesParts[] = "Y-tunnus: $vatId";
    }
    if ($gigDate === null && $dateRaw !== '') {
        $notesParts[] = "Tilaisuuden ajankohta (ei parsittu): $dateRaw";
    }
    $notes = $notesParts ? implode("\n", $notesParts) : null;

    $fields = [
        'customer_name'      => $customerName,
        'customer_type'      => $customerType,
        'gig_date'           => $gigDate,
        'venue_name'         => '',
        'venue_address'      => null,
        'venue_city'         => null,
        'contact_first_name' => $firstName,
        'contact_last_name'  => $lastName,
        'contact_email'      => $email ?: null,
        'contact_phone'      => $phone ?: null,
        'order_description'  => null,
        'notes'              => $notes,
    ];

    return runPipelineAndCreate($pdo, $fields, 'saturday_band');
}

/**
 * Shared geocoding + travel + price calculation + GigCreator::create().
 */
function runPipelineAndCreate(PDO $pdo, array $fields, string $channel): int
{
    $venueName    = trim($fields['venue_name']    ?? '');
    $venueAddress = trim($fields['venue_address'] ?? '') ?: null;
    $venueCity    = trim($fields['venue_city']    ?? '') ?: null;

    // Geocode venue.
    $distFromTurku  = null;
    $venueLat       = null;
    $venueLng       = null;
    $geocodeQuery   = ($venueAddress || $venueCity)
        ? trim(($venueAddress ?? '') . ' ' . ($venueCity ?? ''))
        : $venueName;
    if ($geocodeQuery !== '') {
        $geo = GeocodingHelper::geocodeVenue($geocodeQuery, '');
        if ($geo !== null) {
            $distFromTurku = $geo['distance_km'];
            $venueLat      = $geo['lat'];
            $venueLng      = $geo['lng'];
        }
    }

    // Travel calculation with default lineup.
    $car1Km          = null;
    $car2Km          = null;
    $otherTravelCents = 0;

    if ($venueLat !== null && $venueLng !== null) {
        $defaultUsernames = [
            'tuomas.lundberg', 'toni.puttonen', 'joni.virtanen',
            'alina.kangas', 'lauri.lehtinen', 'mortti.markkanen',
        ];
        $defaultRoles = [
            'tuomas.lundberg'  => 'keyboards',
            'toni.puttonen'    => 'sound_engineering',
            'joni.virtanen'    => 'drums',
            'alina.kangas'     => 'vocals',
            'lauri.lehtinen'   => 'guitar',
            'mortti.markkanen' => 'bass',
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

        $travel = TravelCalculator::calculateFromPersonnel($synthPersonnel, $venueLat, $venueLng);
        $car1Km           = $travel['car1_km'];
        $car2Km           = $travel['car2_km'] ?? 0;
        $otherTravelCents = (int)round($travel['ferry_costs_eur'] * 100);
    }

    // Price calculation.
    $calculator = new PriceCalculator();
    $price      = $calculator->calculate([
        'meta'  => ['channel' => $channel],
        'gig'   => ['distances' => [
            'from_turku_km'          => $distFromTurku ?? 0,
            'car1_trip_km'           => $car1Km ?? (($distFromTurku ?? 0) * 2),
            'car2_trip_km'           => $car2Km ?? 0,
            'other_travel_costs_eur' => $otherTravelCents / 100,
        ]],
        'order' => ['dynamic_pricing_tier1' => false, 'dynamic_pricing_tier2' => false],
        'additional_services' => [
            'ennakkoroudaus' => 0, 'song_requests_extra' => 0,
            'extra_performances' => 0, 'background_music_h' => 0,
            'live_album' => 0, 'discount_eur' => 0,
        ],
    ]);
    $basePriceCents = (int)round($price['gross_total'] * 100);

    return GigCreator::create($pdo, array_merge($fields, [
        'dist_from_turku'    => $distFromTurku,
        'venue_lat'          => $venueLat,
        'venue_lng'          => $venueLng,
        'car1_km'            => $car1Km,
        'car2_km'            => $car2Km,
        'other_travel_cents' => $otherTravelCents,
        'base_price_cents'   => $basePriceCents,
    ]), $channel);
}
