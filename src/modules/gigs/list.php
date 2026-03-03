<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';

// Fetch all active gigs ordered by date ascending
$stmt = $pdo->query(
    "SELECT g.id, g.gig_date, g.status, g.customer_type, g.channel,
            g.order_description, g.quoted_price_cents,
            c.name AS customer_name,
            v.name AS venue_name, v.city AS venue_city
     FROM   gigs g
     JOIN   customers c ON c.id = g.customer_id
     LEFT JOIN venues v ON v.id = g.venue_id
     WHERE  g.deleted_at IS NULL
     ORDER  BY g.gig_date ASC"
);
$gigs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Status badge colour map
$statusColour = [
    'inquiry'   => 'secondary',
    'quoted'    => 'info',
    'confirmed' => 'success',
    'delivered' => 'dark',
    'cancelled' => 'danger',
    'declined'  => 'warning',
];

render_layout('Gigs', function () use ($gigs, $statusColour) {
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Gigs</h2>
    <a href="/gigs/new" class="btn btn-primary btn-sm">+ New inquiry</a>
  </div>

  <?php if (empty($gigs)): ?>
    <p class="text-muted">No gigs yet.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th>Date</th>
          <th>Customer</th>
          <th>Venue</th>
          <th>Order</th>
          <th class="text-end">Quoted (€)</th>
          <th>Channel</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($gigs as $g): ?>
        <tr>
          <td class="text-nowrap"><?= htmlspecialchars($g['gig_date']) ?></td>
          <td><?= htmlspecialchars($g['customer_name']) ?></td>
          <td>
            <?php if ($g['venue_name']): ?>
              <?= htmlspecialchars($g['venue_name']) ?>
              <small class="text-muted">(<?= htmlspecialchars($g['venue_city'] ?? '') ?>)</small>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($g['order_description'] ?? '—') ?></td>
          <td class="text-end text-nowrap">
            <?php if ($g['quoted_price_cents'] !== null): ?>
              <?= number_format($g['quoted_price_cents'] / 100, 2, ',', ' ') ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge bg-secondary">
              <?= htmlspecialchars(str_replace('_', '-', $g['channel'])) ?>
            </span>
          </td>
          <td>
            <span class="badge bg-<?= $statusColour[$g['status']] ?? 'secondary' ?>">
              <?= htmlspecialchars($g['status']) ?>
            </span>
          </td>
          <td>
            <a href="/gigs/<?= (int)$g['id'] ?>" class="btn btn-outline-secondary btn-sm">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
<?php
});
