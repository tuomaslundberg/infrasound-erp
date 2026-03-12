<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';

$currentUser = auth_user();
$userId = (int)$currentUser['id'];
$gigId  = isset($routeParams[0]) ? (int)$routeParams[0] : 0;

// Fetch gig — only if the logged-in user is in gig_personnel for it
$stmt = $pdo->prepare(
    "SELECT g.id, g.gig_date, g.status, g.customer_type, g.order_description,
            v.name AS venue_name, v.address_line AS venue_address, v.city AS venue_city,
            ct.first_name AS contact_first_name, ct.last_name AS contact_last_name,
            ct.phone AS contact_phone,
            p.role AS my_role, p.fee_cents AS my_fee_cents
     FROM   gigs g
     LEFT JOIN venues v ON v.id = g.venue_id
     LEFT JOIN contacts ct ON ct.id = g.contact_id
     JOIN   gig_personnel p ON p.gig_id = g.id AND p.user_id = :uid
     WHERE  g.id = :gid AND g.deleted_at IS NULL"
);
$stmt->execute([':uid' => $userId, ':gid' => $gigId]);
$gig = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gig) {
    http_response_code(404);
    require __DIR__ . '/../../templates/error.php';
    exit;
}

// Fetch song requests for this gig
$srStmt = $pdo->prepare(
    "SELECT artist, title, is_first_dance
     FROM   song_requests
     WHERE  gig_id = ?
     ORDER  BY sort_order ASC, id ASC"
);
$srStmt->execute([$gigId]);
$songRequests = $srStmt->fetchAll(PDO::FETCH_ASSOC);

render_layout($gig['gig_date'], function () use ($gig, $songRequests) {
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= htmlspecialchars($gig['gig_date']) ?></h2>
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header">Gig details</div>
        <div class="card-body">
          <dl class="row mb-0">
            <dt class="col-sm-4">Date</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($gig['gig_date']) ?></dd>

            <dt class="col-sm-4">Order</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($gig['order_description'] ?? '—') ?></dd>

            <dt class="col-sm-4">My role</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($gig['my_role']) ?></dd>

            <dt class="col-sm-4">My fee</dt>
            <dd class="col-sm-8">
              <?= $gig['my_fee_cents'] !== null
                  ? number_format((int)$gig['my_fee_cents'] / 100, 2, ',', ' ') . ' €'
                  : '—' ?>
            </dd>
          </dl>
        </div>
      </div>
    </div>

    <?php if ($gig['venue_name']): ?>
    <div class="col-md-6">
      <div class="card h-100">
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

    <?php if ($gig['contact_first_name'] || $gig['contact_phone']): ?>
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header">Stage contact</div>
        <div class="card-body">
          <dl class="row mb-0">
            <?php if ($gig['contact_first_name'] || $gig['contact_last_name']): ?>
            <dt class="col-sm-4">Name</dt>
            <dd class="col-sm-8">
              <?= htmlspecialchars(trim(($gig['contact_first_name'] ?? '') . ' ' . ($gig['contact_last_name'] ?? ''))) ?>
            </dd>
            <?php endif; ?>

            <?php if ($gig['contact_phone']): ?>
            <dt class="col-sm-4">Phone</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($gig['contact_phone']) ?></dd>
            <?php endif; ?>
          </dl>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($songRequests)): ?>
    <div class="col-12">
      <div class="card">
        <div class="card-header">Song requests</div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr>
                <th>Artist</th>
                <th>Title</th>
                <th>First dance</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($songRequests as $sr): ?>
              <tr>
                <td><?= htmlspecialchars($sr['artist']) ?></td>
                <td><?= htmlspecialchars($sr['title']) ?></td>
                <td><?= $sr['is_first_dance'] ? '💍 Yes' : '' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="mt-3">
    <a href="/musician/gigs" class="btn btn-link btn-sm px-0">← Back to my gigs</a>
  </div>
<?php
});
