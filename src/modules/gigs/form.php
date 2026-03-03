<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';
require_once __DIR__ . '/../../../cli/lib/PriceCalculator.php';

$errors = [];

// ---------------------------------------------------------------------------
// POST handler — save inquiry, calculate price, redirect to detail
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Collect and cast inputs ----------------------------------------
    $channel           = $_POST['channel']            ?? 'mail';
    $customerType      = $_POST['customer_type']      ?? 'wedding';
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
    $tier1             = isset($_POST['tier1']);
    $tier2             = isset($_POST['tier2']);
    $ennakkoroudaus    = (int)($_POST['ennakkoroudaus']       ?? 0);
    $songRequestsExtra = (int)($_POST['song_requests_extra']  ?? 0);
    $extraPerformances = (int)($_POST['extra_performances']   ?? 0);
    $backgroundMusicH  = (int)($_POST['background_music_h']  ?? 0);
    $liveAlbum         = (int)($_POST['live_album']           ?? 0);
    $discountEur       = (float)($_POST['discount_eur']       ?? 0);
    $quotedOverrideEur = trim($_POST['quoted_price_override'] ?? '');
    $notes             = trim($_POST['notes']                 ?? '') ?: null;

    // --- Basic validation -------------------------------------------------
    $errors = [];
    if ($customerName === '') $errors[] = 'Customer name is required.';
    if ($contactFirstName === '') $errors[] = 'Contact first name is required.';
    if ($contactLastName === '') $errors[] = 'Contact last name is required.';
    if ($venueName === '') $errors[] = 'Venue name is required.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $gigDate)) $errors[] = 'Gig date must be YYYY-MM-DD.';
    if (!in_array($channel, ['mail', 'buukkaa_bandi'], true)) $errors[] = 'Invalid channel.';
    if (!in_array($customerType, ['wedding', 'company', 'other'], true)) $errors[] = 'Invalid customer type.';

    if ($errors) {
        // Fall through to render form with errors (POST vars preserved via $_POST)
        goto render_form;
    }

    // --- Price calculation -------------------------------------------------
    $calculator = new PriceCalculator();
    $calcData = [
        'meta'  => ['channel' => $channel],
        'gig'   => ['distances' => [
            'from_turku_km'         => $distFromTurku,
            'car1_trip_km'          => $car1Km,
            'car2_trip_km'          => $car2Km,
            'other_travel_costs_eur'=> $otherTravelEur,
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
    $price           = $calculator->calculate($calcData);
    $basePriceCents  = (int)round($price['gross_total'] * 100);
    $quotedPriceCents = $quotedOverrideEur !== ''
        ? (int)round((float)$quotedOverrideEur * 100)
        : $basePriceCents;

    // --- Persist (transaction) --------------------------------------------
    try {
        $pdo->beginTransaction();

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
            'INSERT INTO gigs
               (customer_id, contact_id, venue_id, gig_date, status, channel, customer_type,
                order_description, base_price_cents, quoted_price_cents,
                car1_distance_km, car2_distance_km, other_travel_costs_cents, notes)
             VALUES (?, ?, ?, ?, \'inquiry\', ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $customerId, $contactId, $venueId, $gigDate, $channel, $customerType,
            $orderDesc, $basePriceCents, $quotedPriceCents,
            $car1Km, $car2Km, (int)round($otherTravelEur * 100),
            $notes,
        ]);
        $gigId = (int)$pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Gig save failed: ' . $e->getMessage());
        $errors[] = 'Database error — the inquiry could not be saved. Check the server error log.';
        goto render_form;
    }

    header('Location: /gigs/' . $gigId);
    exit;
}

// ---------------------------------------------------------------------------
// GET — populate fields from $_POST on validation failure, else empty
// ---------------------------------------------------------------------------
$v = fn(string $key, mixed $default = '') => htmlspecialchars((string)($_POST[$key] ?? $default));

render_form:
render_layout('New inquiry', function () use ($errors, $v) {
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">New inquiry</h2>
    <a href="/gigs" class="btn btn-link btn-sm px-0">← Cancel</a>
  </div>

  <?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <form method="post" action="/gigs/new">
    <div class="row g-3">

      <!-- Channel & type -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">Inquiry</div>
          <div class="card-body row g-2">
            <div class="col-sm-6">
              <label class="form-label">Channel</label>
              <select name="channel" class="form-select">
                <option value="mail"         <?= $v('channel','mail') === 'mail'         ? 'selected' : '' ?>>Mail</option>
                <option value="buukkaa_bandi"<?= $v('channel','mail') === 'buukkaa_bandi'? 'selected' : '' ?>>Buukkaa-bandi</option>
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
            <div class="col-12">
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
              <input type="email" name="contact_email" class="form-control" value="<?= $v('contact_email') ?>">
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
              <label class="form-label">Straight-line distance from Turku (km)</label>
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
          <div class="card-header">Dynamic pricing</div>
          <div class="card-body">
            <div class="form-check mb-2">
              <input class="form-check-input" type="checkbox" name="tier1" id="tier1"
                     <?= isset($_POST['tier1']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="tier1">
                Tier 1 — on-season Saturday (May–Sep) <span class="text-muted">+50 € net</span>
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="tier2" id="tier2"
                     <?= isset($_POST['tier2']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="tier2">
                Tier 2 — high-demand date <span class="text-muted">+75 € net</span>
              </label>
            </div>
          </div>
        </div>
      </div>

      <!-- Additional services -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header">Additional services <small class="text-muted fw-normal">(gross prices: see PriceCalculator)</small></div>
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
              <div class="form-text">Leave blank to use the calculated price.</div>
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
      <button type="submit" class="btn btn-primary">Save inquiry</button>
      <a href="/gigs" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
<?php
});
