<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';
require_once __DIR__ . '/../../../cli/lib/PriceCalculator.php';

// ---------------------------------------------------------------------------
// Mode: create vs edit
// ---------------------------------------------------------------------------
$gigId  = isset($routeParams[0]) ? (int)$routeParams[0] : 0;
$isEdit = $gigId > 0;
$errors = [];

// DB defaults — populated on GET edit; empty on GET new; ignored on POST
// (POST values always win over DB values in the $v() helper below)
$db = [];

// Defined here (before any goto render_form) so they are always in scope.
// $db is captured by reference so the GET edit block can populate it after.
$v = function(string $key, mixed $fallback = '') use (&$db): string {
    return htmlspecialchars((string)($_POST[$key] ?? $db[$key] ?? $fallback));
};
$chk = function(string $key) use (&$db): string {
    return ($_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST[$key]) : (bool)($db[$key] ?? false))
        ? 'checked' : '';
};

// ---------------------------------------------------------------------------
// POST handler — save, recalculate price, redirect
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Collect and cast inputs -------------------------------------------
    $channel           = $_POST['channel']            ?? 'mail';
    $customerType      = $_POST['customer_type']      ?? 'wedding';
    $status            = $_POST['status']             ?? 'inquiry';
    $customerName      = trim($_POST['customer_name'] ?? '');
    $contactFirstName  = trim($_POST['contact_first_name'] ?? '');
    $contactLastName   = trim($_POST['contact_last_name']  ?? '');
    $contactEmail      = trim($_POST['contact_email']      ?? '') ?: null;
    $contactPhone      = trim($_POST['contact_phone']      ?? '') ?: null;
    $venueName         = trim($_POST['venue_name']         ?? '');
    $venueAddress      = trim($_POST['venue_address']      ?? '') ?: null;
    $venueCity         = trim($_POST['venue_city']         ?? '') ?: null;
    $venuePostal       = trim($_POST['venue_postal']       ?? '') ?: null;
    $distFromTurku     = (float)($_POST['dist_from_turku'] ?? 0);
    $gigDate           = $_POST['gig_date']           ?? '';
    $orderDesc         = trim($_POST['order_desc']    ?? '') ?: null;
    $car1Km            = (float)($_POST['car1_km']    ?? 0);
    $car2Km            = (float)($_POST['car2_km']    ?? 0);
    $otherTravelEur    = (float)($_POST['other_travel_eur'] ?? 0);
    $pricingTier       = $_POST['pricing_tier'] ?? 'none';
    $tier1             = in_array($pricingTier, ['tier1', 'tier1_tier2'], true);
    $tier2             = $pricingTier === 'tier1_tier2';
    $ennakkoroudaus    = (int)($_POST['ennakkoroudaus']       ?? 0);
    $songRequestsExtra = (int)($_POST['song_requests_extra']  ?? 0);
    $extraPerformances = (int)($_POST['extra_performances']   ?? 0);
    $backgroundMusicH  = (int)($_POST['background_music_h']  ?? 0);
    $liveAlbum         = (int)($_POST['live_album']           ?? 0);
    $discountEur       = (float)($_POST['discount_eur']       ?? 0);
    $quotedOverrideEur = trim($_POST['quoted_price_override'] ?? '');
    $notes             = trim($_POST['notes']                 ?? '') ?: null;

    // --- Validation --------------------------------------------------------
    $validStatuses = ['inquiry', 'quoted', 'confirmed', 'delivered', 'cancelled', 'declined'];
    if ($customerName === '')  $errors[] = 'Customer name is required.';
    if ($contactFirstName === '') $errors[] = 'Contact first name is required.';
    if ($contactLastName === '')  $errors[] = 'Contact last name is required.';
    if ($venueName === '')    $errors[] = 'Venue name is required.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gigDate)) $errors[] = 'Gig date must be YYYY-MM-DD.';
    $validChannels = ['mail','buukkaa_bandi','saturday_band','venuu','haamusiikki',
                       'voodoolive','ohjelmanaiset','palkkaamuusikko','facebook','whatsapp','phone','other'];
    if (!in_array($channel, $validChannels, true)) $errors[] = 'Invalid channel.';
    if (!in_array($customerType, ['wedding', 'company', 'other'], true)) $errors[] = 'Invalid customer type.';
    if (!in_array($status, $validStatuses, true)) $errors[] = 'Invalid status.';

    if ($errors) {
        goto render_form;
    }

    // --- Price calculation -------------------------------------------------
    $calculator = new PriceCalculator();
    $calcData = [
        'meta'  => ['channel' => $channel],
        'gig'   => ['distances' => [
            'from_turku_km'          => $distFromTurku,
            'car1_trip_km'           => $car1Km,
            'car2_trip_km'           => $car2Km,
            'other_travel_costs_eur' => $otherTravelEur,
        ]],
        'order' => [
            'dynamic_pricing_tier1' => $tier1,
            'dynamic_pricing_tier2' => $tier2,
        ],
        'additional_services' => [
            'ennakkoroudaus'      => $ennakkoroudaus,
            'song_requests_extra' => $songRequestsExtra,
            'extra_performances'  => $extraPerformances,
            'background_music_h'  => $backgroundMusicH,
            'live_album'          => $liveAlbum,
            'discount_eur'        => $discountEur,
        ],
    ];
    $price            = $calculator->calculate($calcData);
    $basePriceCents   = (int)round($price['gross_total'] * 100);
    $quotedPriceCents = $quotedOverrideEur !== ''
        ? (int)round((float)$quotedOverrideEur * 100)
        : $basePriceCents;

    // --- Persist (transaction) --------------------------------------------
    try {
        $pdo->beginTransaction();

        if ($isEdit) {
            // Load the FK IDs we need for the UPDATE statements
            $ids = $pdo->prepare(
                'SELECT customer_id, contact_id, venue_id FROM gigs WHERE id = ? AND deleted_at IS NULL'
            );
            $ids->execute([$gigId]);
            $fk = $ids->fetch(PDO::FETCH_ASSOC);

            if (!$fk) {
                $pdo->rollBack();
                $errors[] = 'Gig not found or already deleted.';
                goto render_form;
            }

            $pdo->prepare('UPDATE customers SET name = ? WHERE id = ?')
                ->execute([$customerName, $fk['customer_id']]);

            if ($fk['contact_id']) {
                $pdo->prepare(
                    'UPDATE contacts SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?'
                )->execute([$contactFirstName, $contactLastName, $contactEmail, $contactPhone, $fk['contact_id']]);
            }

            if ($fk['venue_id']) {
                $pdo->prepare(
                    'UPDATE venues SET name = ?, address_line = ?, city = ?, postal_code = ?,
                                      distance_from_turku_km = ? WHERE id = ?'
                )->execute([$venueName, $venueAddress, $venueCity, $venuePostal, $distFromTurku, $fk['venue_id']]);
            }

            $pdo->prepare(
                'UPDATE gigs SET gig_date = ?, status = ?, channel = ?, customer_type = ?,
                                 order_description = ?, base_price_cents = ?, quoted_price_cents = ?,
                                 car1_distance_km = ?, car2_distance_km = ?,
                                 other_travel_costs_cents = ?,
                                 pricing_tier1 = ?, pricing_tier2 = ?,
                                 qty_ennakkoroudaus = ?, qty_song_requests_extra = ?,
                                 qty_extra_performances = ?, qty_background_music_h = ?,
                                 qty_live_album = ?, discount_cents = ?,
                                 notes = ?
                 WHERE id = ?'
            )->execute([
                $gigDate, $status, $channel, $customerType, $orderDesc,
                $basePriceCents, $quotedPriceCents,
                $car1Km, $car2Km, (int)round($otherTravelEur * 100),
                $tier1 ? 1 : 0, $tier2 ? 1 : 0,
                $ennakkoroudaus, $songRequestsExtra, $extraPerformances,
                $backgroundMusicH, $liveAlbum, (int)round($discountEur * 100),
                $notes, $gigId,
            ]);

        } else {
            $pdo->prepare('INSERT INTO customers (name, type) VALUES (?, ?)')
                ->execute([$customerName, 'person']);
            $customerId = (int)$pdo->lastInsertId();

            $pdo->prepare('INSERT INTO contacts (first_name, last_name, email, phone) VALUES (?, ?, ?, ?)')
                ->execute([$contactFirstName, $contactLastName, $contactEmail, $contactPhone]);
            $contactId = (int)$pdo->lastInsertId();

            $pdo->prepare('INSERT INTO customer_contacts (customer_id, contact_id, is_primary) VALUES (?, ?, 1)')
                ->execute([$customerId, $contactId]);

            $pdo->prepare(
                'INSERT INTO venues (name, address_line, city, postal_code, distance_from_turku_km)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$venueName, $venueAddress, $venueCity, $venuePostal, $distFromTurku]);
            $venueId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                "INSERT INTO gigs
                   (customer_id, contact_id, venue_id, gig_date, status, channel, customer_type,
                    order_description, base_price_cents, quoted_price_cents,
                    car1_distance_km, car2_distance_km, other_travel_costs_cents,
                    pricing_tier1, pricing_tier2,
                    qty_ennakkoroudaus, qty_song_requests_extra, qty_extra_performances,
                    qty_background_music_h, qty_live_album, discount_cents,
                    notes)
                 VALUES (?, ?, ?, ?, 'inquiry', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute([
                $customerId, $contactId, $venueId, $gigDate, $channel, $customerType,
                $orderDesc, $basePriceCents, $quotedPriceCents,
                $car1Km, $car2Km, (int)round($otherTravelEur * 100),
                $tier1 ? 1 : 0, $tier2 ? 1 : 0,
                $ennakkoroudaus, $songRequestsExtra, $extraPerformances,
                $backgroundMusicH, $liveAlbum, (int)round($discountEur * 100),
                $notes,
            ]);
            $gigId = (int)$pdo->lastInsertId();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Gig save failed: ' . $e->getMessage());
        $errors[] = 'Database error — could not save. Check the server error log.';
        goto render_form;
    }

    header('Location: /gigs/' . $gigId);
    exit;
}

// ---------------------------------------------------------------------------
// GET — load DB values for edit, used as defaults in the $v() helper
// ---------------------------------------------------------------------------
if ($isEdit) {
    $stmt = $pdo->prepare(
        "SELECT g.gig_date, g.status, g.channel, g.customer_type, g.order_description,
                g.car1_distance_km, g.car2_distance_km, g.other_travel_costs_cents,
                g.quoted_price_cents, g.notes,
                g.pricing_tier1, g.pricing_tier2,
                g.qty_ennakkoroudaus, g.qty_song_requests_extra, g.qty_extra_performances,
                g.qty_background_music_h, g.qty_live_album, g.discount_cents,
                c.name  AS customer_name,
                co.first_name AS contact_first_name, co.last_name AS contact_last_name,
                co.email AS contact_email, co.phone AS contact_phone,
                v.name  AS venue_name, v.address_line AS venue_address,
                v.city  AS venue_city, v.postal_code AS venue_postal,
                v.distance_from_turku_km AS dist_from_turku
         FROM   gigs g
         JOIN   customers c  ON c.id  = g.customer_id
         LEFT JOIN contacts co ON co.id = g.contact_id
         LEFT JOIN venues   v  ON v.id  = g.venue_id
         WHERE  g.id = ? AND g.deleted_at IS NULL"
    );
    $stmt->execute([$gigId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        require __DIR__ . '/../../templates/error.php';
        exit;
    }

    // Map DB row to the same keys used by POST fields so $v() can pick them up
    $db = [
        'gig_date'             => $row['gig_date'],
        'status'               => $row['status'],
        'channel'              => $row['channel'],
        'customer_type'        => $row['customer_type'],
        'order_desc'           => $row['order_description'] ?? '',
        'car1_km'              => rtrim(rtrim((string)$row['car1_distance_km'], '0'), '.'),
        'car2_km'              => rtrim(rtrim((string)$row['car2_distance_km'], '0'), '.'),
        'other_travel_eur'     => number_format($row['other_travel_costs_cents'] / 100, 2, '.', ''),
        // Pre-fill override with current quoted price so it's preserved unless user changes it
        'quoted_price_override'=> $row['quoted_price_cents'] !== null
            ? number_format($row['quoted_price_cents'] / 100, 2, '.', '')
            : '',
        'notes'                => $row['notes'] ?? '',
        'customer_name'        => $row['customer_name'],
        'contact_first_name'   => $row['contact_first_name'],
        'contact_last_name'    => $row['contact_last_name'],
        'contact_email'        => $row['contact_email'] ?? '',
        'contact_phone'        => $row['contact_phone'] ?? '',
        'venue_name'           => $row['venue_name'] ?? '',
        'venue_address'        => $row['venue_address'] ?? '',
        'venue_city'           => $row['venue_city'] ?? '',
        'venue_postal'         => $row['venue_postal'] ?? '',
        'dist_from_turku'      => $row['dist_from_turku'] ?? '0',
        'pricing_tier'         => $row['pricing_tier2'] ? 'tier1_tier2' : ($row['pricing_tier1'] ? 'tier1' : 'none'),
        'ennakkoroudaus'       => (string)($row['qty_ennakkoroudaus'] ?? 0),
        'song_requests_extra'  => (string)($row['qty_song_requests_extra'] ?? 0),
        'extra_performances'   => (string)($row['qty_extra_performances'] ?? 0),
        'background_music_h'   => (string)($row['qty_background_music_h'] ?? 0),
        'live_album'           => (string)($row['qty_live_album'] ?? 0),
        'discount_eur'         => number_format(($row['discount_cents'] ?? 0) / 100, 2, '.', ''),
    ];
}

render_form:
$pageTitle  = $isEdit ? 'Edit — ' . ($db['customer_name'] ?? 'gig') : 'New inquiry';
$formAction = $isEdit ? '/gigs/' . $gigId . '/edit' : '/gigs/new';

render_layout($pageTitle, function () use ($errors, $v, $chk, $isEdit, $gigId, $formAction, $pageTitle) {
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h2>
    <a href="<?= $isEdit ? '/gigs/' . $gigId : '/gigs' ?>"
       class="btn btn-link btn-sm px-0">← <?= $isEdit ? 'Back to gig' : 'Cancel' ?></a>
  </div>

  <?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <form method="post" action="<?= $formAction ?>">
    <div class="row g-3">

      <!-- Channel, type, status, date, order -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">Inquiry</div>
          <div class="card-body row g-2">
            <div class="col-sm-6">
              <label class="form-label">Channel</label>
              <select name="channel" class="form-select">
                <option value="mail"          <?= $v('channel','mail') === 'mail'          ? 'selected' : '' ?>>Mail</option>
                <option value="buukkaa_bandi" <?= $v('channel','mail') === 'buukkaa_bandi' ? 'selected' : '' ?>>Buukkaa-bandi</option>
              </select>
            </div>
            <div class="col-sm-6">
              <label class="form-label">Customer type</label>
              <select name="customer_type" class="form-select">
                <option value="wedding" <?= $v('customer_type','wedding') === 'wedding' ? 'selected' : '' ?>>Wedding</option>
                <option value="company" <?= $v('customer_type','wedding') === 'company' ? 'selected' : '' ?>>Company</option>
                <option value="other"   <?= $v('customer_type','wedding') === 'other'   ? 'selected' : '' ?>>Other</option>
              </select>
            </div>
            <?php if ($isEdit): ?>
            <div class="col-sm-6">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach (['inquiry','quoted','confirmed','delivered','cancelled','declined'] as $s): ?>
                <option value="<?= $s ?>" <?= $v('status','inquiry') === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-6">
            <?php else: ?>
            <div class="col-12">
            <?php endif; ?>
              <label class="form-label">Gig date <span class="text-danger">*</span></label>
              <input type="date" name="gig_date" class="form-control" value="<?= $v('gig_date') ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Order description</label>
              <input type="text" name="order_desc" class="form-control"
                     placeholder="e.g. 3 × 45 min + ennakkoroudaus"
                     value="<?= $v('order_desc') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Customer & contact -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">Customer / contact</div>
          <div class="card-body row g-2">
            <div class="col-12">
              <label class="form-label">Customer name <span class="text-danger">*</span></label>
              <input type="text" name="customer_name" class="form-control" value="<?= $v('customer_name') ?>" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label">First name <span class="text-danger">*</span></label>
              <input type="text" name="contact_first_name" class="form-control" value="<?= $v('contact_first_name') ?>" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label">Last name <span class="text-danger">*</span></label>
              <input type="text" name="contact_last_name" class="form-control" value="<?= $v('contact_last_name') ?>" required>
            </div>
            <div class="col-sm-6">
              <label class="form-label">Email</label>
              <input type="text" name="contact_email" class="form-control" value="<?= $v('contact_email') ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label">Phone</label>
              <input type="tel" name="contact_phone" class="form-control" value="<?= $v('contact_phone') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Venue -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">Venue</div>
          <div class="card-body row g-2">
            <div class="col-12">
              <label class="form-label">Venue name <span class="text-danger">*</span></label>
              <input type="text" name="venue_name" class="form-control" value="<?= $v('venue_name') ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Address</label>
              <input type="text" name="venue_address" class="form-control" value="<?= $v('venue_address') ?>">
            </div>
            <div class="col-sm-8">
              <label class="form-label">City</label>
              <input type="text" name="venue_city" class="form-control" value="<?= $v('venue_city') ?>">
            </div>
            <div class="col-sm-4">
              <label class="form-label">Postal code</label>
              <input type="text" name="venue_postal" class="form-control" value="<?= $v('venue_postal') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Driving distance from Turku (km)</label>
              <input type="number" name="dist_from_turku" class="form-control" step="0.1" min="0"
                     value="<?= $v('dist_from_turku', '0') ?>">
              <div class="form-text">Used for the distance premium in pricing.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Travel -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">Travel</div>
          <div class="card-body row g-2">
            <div class="col-sm-4">
              <label class="form-label">Car 1 trip (km)</label>
              <input type="number" name="car1_km" class="form-control" step="0.1" min="0"
                     value="<?= $v('car1_km', '0') ?>">
              <div class="form-text">0.81 €/km</div>
            </div>
            <div class="col-sm-4">
              <label class="form-label">Car 2 trip (km)</label>
              <input type="number" name="car2_km" class="form-control" step="0.1" min="0"
                     value="<?= $v('car2_km', '0') ?>">
              <div class="form-text">0.55 €/km</div>
            </div>
            <div class="col-sm-4">
              <label class="form-label">Other travel (€)</label>
              <input type="number" name="other_travel_eur" class="form-control" step="0.01" min="0"
                     value="<?= $v('other_travel_eur', '0') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Dynamic pricing -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">Dynamic pricing<?= $isEdit ? ' <small class="text-muted fw-normal">(re-enter to recalculate)</small>' : '' ?></div>
          <div class="card-body">
            <div class="form-check mb-2">
              <input class="form-check-input" type="radio" name="pricing_tier" id="pricing_tier_none"
                     value="none" <?= $v('pricing_tier', 'none') === 'none' ? 'checked' : '' ?>>
              <label class="form-check-label" for="pricing_tier_none">
                No dynamic pricing <span class="text-muted">(off-season / low-demand)</span>
              </label>
            </div>
            <div class="form-check mb-2">
              <input class="form-check-input" type="radio" name="pricing_tier" id="pricing_tier_tier1"
                     value="tier1" <?= $v('pricing_tier') === 'tier1' ? 'checked' : '' ?>>
              <label class="form-check-label" for="pricing_tier_tier1">
                Tier 1 — on-season Saturday (May–Sep) <span class="text-muted">+50 € net</span>
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="pricing_tier" id="pricing_tier_tier1_tier2"
                     value="tier1_tier2" <?= $v('pricing_tier') === 'tier1_tier2' ? 'checked' : '' ?>>
              <label class="form-check-label" for="pricing_tier_tier1_tier2">
                Tier 1 + Tier 2 — on-season Saturday, high-demand <span class="text-muted">+125 € net</span>
              </label>
            </div>
          </div>
        </div>
      </div>

      <!-- Additional services -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">Additional services <small class="text-muted fw-normal">(gross prices)</small></div>
          <div class="card-body row g-2">
            <div class="col-sm-4">
              <label class="form-label">Ennakkoroudaus <small class="text-muted">×200 €</small></label>
              <input type="number" name="ennakkoroudaus" class="form-control" min="0" value="<?= $v('ennakkoroudaus', '0') ?>">
            </div>
            <div class="col-sm-4">
              <label class="form-label">Toivekappaleet (extra) <small class="text-muted">×100 €</small></label>
              <input type="number" name="song_requests_extra" class="form-control" min="0" value="<?= $v('song_requests_extra', '0') ?>">
            </div>
            <div class="col-sm-4">
              <label class="form-label">Ohjelmanumerot (extra) <small class="text-muted">×100 €</small></label>
              <input type="number" name="extra_performances" class="form-control" min="0" value="<?= $v('extra_performances', '0') ?>">
            </div>
            <div class="col-sm-4">
              <label class="form-label">Taustamusiikki (h) <small class="text-muted">×300 €</small></label>
              <input type="number" name="background_music_h" class="form-control" min="0" value="<?= $v('background_music_h', '0') ?>">
            </div>
            <div class="col-sm-4">
              <label class="form-label">Live-albumi <small class="text-muted">×300 €</small></label>
              <input type="number" name="live_album" class="form-control" min="0" value="<?= $v('live_album', '0') ?>">
            </div>
            <div class="col-sm-4">
              <label class="form-label">Alennus (€ gross)</label>
              <input type="number" name="discount_eur" class="form-control" step="0.01" min="0" value="<?= $v('discount_eur', '0') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Price override & notes -->
      <div class="col-12">
        <div class="card">
          <div class="card-header">Price &amp; notes</div>
          <div class="card-body row g-2">
            <div class="col-md-3">
              <label class="form-label">Quoted price override (€ gross)</label>
              <input type="number" name="quoted_price_override" class="form-control" step="0.01" min="0"
                     value="<?= $v('quoted_price_override') ?>">
              <div class="form-text">
                <?= $isEdit
                    ? 'Pre-filled with current price. Clear to use recalculated price.'
                    : 'Leave blank to use the calculated price.' ?>
              </div>
            </div>
            <div class="col-md-9">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="3"><?= $v('notes') ?></textarea>
            </div>
          </div>
        </div>
      </div>

    </div><!-- .row -->

    <div class="mt-3 d-flex gap-2">
      <button type="submit" class="btn btn-primary">
        <?= $isEdit ? 'Save changes' : 'Save inquiry' ?>
      </button>
      <a href="<?= $isEdit ? '/gigs/' . $gigId : '/gigs' ?>"
         class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
<?php
});
