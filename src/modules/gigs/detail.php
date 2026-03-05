<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';

$gigId = isset($routeParams[0]) ? (int)$routeParams[0] : 0;

$stmt = $pdo->prepare(
    "SELECT g.*, c.name AS customer_name,
            v.name AS venue_name, v.city AS venue_city, v.address_line AS venue_address
     FROM   gigs g
     JOIN   customers c ON c.id = g.customer_id
     LEFT JOIN venues v ON v.id = g.venue_id
     WHERE  g.id = ? AND g.deleted_at IS NULL"
);
$stmt->execute([$gigId]);
$gig = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gig) {
    http_response_code(404);
    require __DIR__ . '/../../templates/error.php';
    exit;
}

render_layout($gig['customer_name'], function () use ($gig) {
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= htmlspecialchars($gig['customer_name']) ?></h2>
    <div class="d-flex gap-2">
      <a href="/gigs/<?= (int)$gig['id'] ?>/quote" class="btn btn-primary btn-sm">Quote email</a>
      <a href="/gigs/<?= (int)$gig['id'] ?>/edit" class="btn btn-outline-secondary btn-sm">Edit</a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header">Gig details</div>
        <div class="card-body">
          <dl class="row mb-0">
            <dt class="col-sm-4">Date</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($gig['gig_date']) ?></dd>

            <dt class="col-sm-4">Status</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($gig['status']) ?></dd>

            <dt class="col-sm-4">Type</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($gig['customer_type']) ?></dd>

            <dt class="col-sm-4">Channel</dt>
            <dd class="col-sm-8"><?= htmlspecialchars(str_replace('_', '-', $gig['channel'])) ?></dd>

            <dt class="col-sm-4">Order</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($gig['order_description'] ?? '—') ?></dd>
          </dl>
        </div>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header">Pricing</div>
        <div class="card-body">
          <dl class="row mb-0">
            <dt class="col-sm-5">Quoted price</dt>
            <dd class="col-sm-7">
              <?= $gig['quoted_price_cents'] !== null
                  ? number_format($gig['quoted_price_cents'] / 100, 2, ',', ' ') . ' €'
                  : '—' ?>
            </dd>

            <dt class="col-sm-5">Distance (Turku)</dt>
            <dd class="col-sm-7">
              <?= $gig['venue_id']
                  ? '(see venue)'
                  : ($gig['car1_distance_km'] ? $gig['car1_distance_km'] . ' km (car 1)' : '—') ?>
            </dd>

            <dt class="col-sm-5">Car 1 trip</dt>
            <dd class="col-sm-7"><?= $gig['car1_distance_km'] ? $gig['car1_distance_km'] . ' km' : '—' ?></dd>

            <dt class="col-sm-5">Other travel</dt>
            <dd class="col-sm-7">
              <?= $gig['other_travel_costs_cents']
                  ? number_format($gig['other_travel_costs_cents'] / 100, 2, ',', ' ') . ' €'
                  : '—' ?>
            </dd>
          </dl>
        </div>
      </div>
    </div>

    <?php if ($gig['venue_name']): ?>
    <div class="col-md-6">
      <div class="card">
        <div class="card-header">Venue</div>
        <div class="card-body">
          <p class="mb-1"><strong><?= htmlspecialchars($gig['venue_name']) ?></strong></p>
          <p class="mb-0 text-muted">
            <?= htmlspecialchars($gig['venue_address'] ?? '') ?>,
            <?= htmlspecialchars($gig['venue_city'] ?? '') ?>
          </p>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($gig['notes']): ?>
    <div class="col-12">
      <div class="card">
        <div class="card-header">Notes</div>
        <div class="card-body">
          <p class="mb-0"><?= nl2br(htmlspecialchars($gig['notes'])) ?></p>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="mt-3">
    <a href="/gigs" class="btn btn-link btn-sm px-0">← Back to gig list</a>
  </div>
<?php
});
