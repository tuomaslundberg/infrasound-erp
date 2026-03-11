<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';

$currentUser = auth_user();
$userId = (int)$currentUser['id'];

// Fetch upcoming gigs where the logged-in user has a gig_personnel row
$stmt = $pdo->prepare(
    "SELECT g.id, g.gig_date, g.status, g.customer_type,
            c.name AS customer_name,
            ct.first_name AS contact_first_name,
            v.name AS venue_name, v.city AS venue_city,
            p.role AS my_role
     FROM   gigs g
     JOIN   customers c ON c.id = g.customer_id
     LEFT JOIN contacts ct ON ct.id = g.contact_id
     LEFT JOIN venues v ON v.id = g.venue_id
     JOIN   gig_personnel p ON p.gig_id = g.id AND p.user_id = :uid
     WHERE  g.deleted_at IS NULL
       AND  g.gig_date >= CURDATE()
     ORDER  BY g.gig_date ASC"
);
$stmt->execute([':uid' => $userId]);
$gigs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusColour = [
    'inquiry'   => 'secondary',
    'quoted'    => 'info',
    'confirmed' => 'success',
    'delivered' => 'dark',
    'cancelled' => 'danger',
    'declined'  => 'warning',
];

render_layout('My Gigs', function () use ($gigs, $statusColour) {
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">My Upcoming Gigs</h2>
  </div>

  <?php if (empty($gigs)): ?>
    <p class="text-muted">No upcoming gigs assigned to you.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle">
      <thead class="table-light">
        <tr>
          <th>Date</th>
          <th>Customer</th>
          <th>Venue</th>
          <th>Role</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($gigs as $g): ?>
        <tr>
          <td class="text-nowrap"><?= htmlspecialchars($g['gig_date']) ?></td>
          <td>
            <?php if ($g['customer_type'] === 'wedding' && $g['contact_first_name'] !== null): ?>
              <?= htmlspecialchars($g['contact_first_name']) ?>
            <?php else: ?>
              <?= htmlspecialchars($g['customer_name']) ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($g['venue_name']): ?>
              <?= htmlspecialchars($g['venue_name']) ?>
              <small class="text-muted">(<?= htmlspecialchars($g['venue_city'] ?? '') ?>)</small>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($g['my_role']) ?></td>
          <td>
            <span class="badge bg-<?= $statusColour[$g['status']] ?? 'secondary' ?>">
              <?= htmlspecialchars($g['status']) ?>
            </span>
          </td>
          <td>
            <a href="/musician/gigs/<?= (int)$g['id'] ?>" class="btn btn-outline-secondary btn-sm">View</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
<?php
});
