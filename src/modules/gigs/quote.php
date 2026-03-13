<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';
require_once __DIR__ . '/../../../cli/lib/TemplateRenderer.php';

$gigId = isset($routeParams[0]) ? (int)$routeParams[0] : 0;

// Load gig with all related fields needed for the template
$stmt = $pdo->prepare(
    "SELECT g.id, g.gig_date, g.status, g.channel, g.customer_type,
            g.order_description, g.quoted_price_cents, g.base_price_cents,
            g.car1_distance_km, g.car2_distance_km, g.other_travel_costs_cents,
            g.venue_id,
            c.name  AS customer_name,
            co.first_name AS contact_first_name, co.last_name AS contact_last_name,
            v.name  AS venue_name, v.city AS venue_city
     FROM   gigs g
     JOIN   customers c  ON c.id  = g.customer_id
     LEFT JOIN contacts co ON co.id = g.contact_id
     LEFT JOIN venues   v  ON v.id  = g.venue_id
     WHERE  g.id = ? AND g.deleted_at IS NULL"
);
$stmt->execute([$gigId]);
$gig = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gig) {
    http_response_code(404);
    require __DIR__ . '/../../templates/error.php';
    exit;
}

// Discover available template types for this gig's channel + customer_type
$renderer  = new TemplateRenderer();
$allTypes  = [
    'quote', 'venue-familiar-quote', 'bidding', 'sorry-were-booked',
    'thank-you', 'declined-offer-response',
    'order-confirmation', 'venue-familiar-order-confirmation', 'signature',
];
$available = array_filter($allTypes, fn($t) => file_exists(
    $renderer->resolveTemplatePath('fi', $gig['channel'], $gig['customer_type'], $t)
));

// Smart default: check DB context to suggest the most appropriate template
$isAlreadyBooked = false;
$isVenueFamiliar = false;

$bookedStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM gigs
     WHERE  gig_date = ? AND status = ? AND deleted_at IS NULL AND id != ?'
);
$bookedStmt->execute([$gig['gig_date'], 'confirmed', $gigId]);
$isAlreadyBooked = (bool)$bookedStmt->fetchColumn();

if ($gig['venue_id']) {
    $familiarStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM gigs
         WHERE  venue_id = ? AND status = ? AND deleted_at IS NULL AND id != ?'
    );
    $familiarStmt->execute([$gig['venue_id'], 'delivered', $gigId]);
    $isVenueFamiliar = (bool)$familiarStmt->fetchColumn();
}

// Priority: sorry-were-booked > venue-familiar-quote > quote
$autoType = 'quote';
if ($isVenueFamiliar && in_array('venue-familiar-quote', $available, true)) {
    $autoType = 'venue-familiar-quote';
}
if ($isAlreadyBooked && in_array('sorry-were-booked', $available, true)) {
    $autoType = 'sorry-were-booked';
}

$selectedType = $_GET['type'] ?? $autoType;
if (!in_array($selectedType, $available, true)) {
    $selectedType = $available ? reset($available) : 'quote';
}

// Quoted price in euros (fall back to base_price if quoted not set)
$priceCents = $gig['quoted_price_cents'] ?? $gig['base_price_cents'] ?? 0;
$grossTotal = $priceCents / 100;

// Build the data structure TemplateRenderer expects
$data = [
    'meta'     => ['language' => 'fi', 'channel' => $gig['channel']],
    'customer' => ['name' => $gig['customer_name'], 'type' => $gig['customer_type']],
    'contact'  => ['name' => trim($gig['contact_first_name'] . ' ' . $gig['contact_last_name'])],
];

$rendered   = null;
$renderError = null;
try {
    $rendered = $renderer->render($data, $grossTotal, $selectedType);
} catch (RuntimeException $e) {
    $renderError = $e->getMessage();
}

$formatter = new TemplateRenderer(); // for formatPrice helper
render_layout('Quote — ' . $gig['customer_name'], function ()
    use ($gig, $gigId, $available, $selectedType, $rendered, $renderError, $grossTotal, $formatter,
         $isAlreadyBooked, $isVenueFamiliar, $autoType)
{
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Quote — <?= htmlspecialchars($gig['customer_name']) ?></h2>
    <a href="/gigs/<?= $gigId ?>" class="btn btn-link btn-sm px-0">← Back to gig</a>
  </div>

  <?php if ($isAlreadyBooked): ?>
  <div class="alert alert-warning">
    <strong>Already booked:</strong> a confirmed gig exists on <?= htmlspecialchars($gig['gig_date']) ?>.
    Consider using the <em>sorry-were-booked</em> template.
  </div>
  <?php elseif ($isVenueFamiliar && $autoType === 'venue-familiar-quote'): ?>
  <div class="alert alert-info alert-dismissible fade show">
    Venue recognised from a previous delivered gig — using <em>venue-familiar-quote</em> template.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <div class="row g-3">

    <!-- Sidebar: gig info + template selector -->
    <div class="col-md-3">
      <div class="card mb-3">
        <div class="card-header">Gig</div>
        <div class="card-body small">
          <p class="mb-1"><strong><?= htmlspecialchars($gig['gig_date']) ?></strong></p>
          <?php if ($gig['venue_name']): ?>
          <p class="mb-1 text-muted"><?= htmlspecialchars($gig['venue_name']) ?><?= $gig['venue_city'] ? ', ' . htmlspecialchars($gig['venue_city']) : '' ?></p>
          <?php endif; ?>
          <p class="mb-0">
            <span class="badge bg-secondary"><?= htmlspecialchars(str_replace('_', '-', $gig['channel'])) ?></span>
            <span class="badge bg-light text-dark"><?= htmlspecialchars($gig['customer_type']) ?></span>
          </p>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header">Quoted price</div>
        <div class="card-body">
          <p class="mb-0 fs-5 fw-semibold">
            <?= $grossTotal > 0 ? htmlspecialchars($formatter->formatPrice($grossTotal)) : '—' ?>
          </p>
          <?php if ($gig['base_price_cents'] && $gig['quoted_price_cents'] !== $gig['base_price_cents']): ?>
          <p class="mb-0 text-muted small">Calculated: <?= $formatter->formatPrice($gig['base_price_cents'] / 100) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header">Template</div>
        <div class="card-body">
          <form method="get">
            <select name="type" class="form-select form-select-sm mb-2" onchange="this.form.submit()">
              <?php foreach ($available as $type): ?>
              <option value="<?= htmlspecialchars($type) ?>"
                      <?= $type === $selectedType ? 'selected' : '' ?>>
                <?= htmlspecialchars($type) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
      </div>
    </div>

    <!-- Email preview -->
    <div class="col-md-9">
      <?php if ($renderError): ?>
      <div class="alert alert-danger">
        <strong>Template not found</strong><br>
        <code><?= htmlspecialchars($renderError) ?></code>
      </div>
      <?php else: ?>
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span><?= htmlspecialchars($selectedType) ?></span>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="copyBtn">
            Copy
          </button>
        </div>
        <div class="card-body p-0">
          <textarea id="emailText" class="form-control border-0 font-monospace"
                    rows="28" readonly
                    style="resize:vertical; border-radius: 0 0 var(--bs-card-border-radius) var(--bs-card-border-radius);"
          ><?= htmlspecialchars($rendered ?? '') ?></textarea>
        </div>
      </div>

      <script>
        document.getElementById('copyBtn').addEventListener('click', function () {
          const text = document.getElementById('emailText');
          navigator.clipboard.writeText(text.value).then(() => {
            this.textContent = 'Copied!';
            setTimeout(() => { this.textContent = 'Copy'; }, 2000);
          });
        });
      </script>
      <?php endif; ?>
    </div>

  </div>
<?php
});
