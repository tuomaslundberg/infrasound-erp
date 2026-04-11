<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';
require_once __DIR__ . '/../../../cli/lib/RoutingHelper.php';

$results = [];
$ran     = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(0); // Geocoding is rate-limited to 1 req/s; disable PHP timeout.
    $ran  = true;
    $stmt = $pdo->query(
        "SELECT id, username, home_address FROM users
         WHERE home_address IS NOT NULL
           AND (home_lat IS NULL OR home_lng IS NULL)
           AND deleted_at IS NULL
         ORDER BY id ASC"
    );
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($users) {
        $update = $pdo->prepare('UPDATE users SET home_lat = ?, home_lng = ? WHERE id = ?');
        foreach ($users as $u) {
            $coords = RoutingHelper::geocode($u['home_address'] . ' Finland');
            if ($coords === null) {
                $results[] = ['username' => $u['username'], 'address' => $u['home_address'],
                              'ok' => false, 'detail' => 'Nominatim returned no result'];
            } else {
                $update->execute([$coords['lat'], $coords['lng'], $u['id']]);
                $results[] = ['username' => $u['username'], 'address' => $u['home_address'],
                              'ok' => true,
                              'detail' => "lat={$coords['lat']}, lng={$coords['lng']}"];
            }
            sleep(1); // Nominatim ToS: 1 req/s
        }
    }
}

// Summary of current state for display.
$allUsers = $pdo->query(
    "SELECT username, home_address, home_lat, home_lng FROM users
     WHERE deleted_at IS NULL AND home_address IS NOT NULL
     ORDER BY username ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$pendingCount = count(array_filter($allUsers, fn($u) => $u['home_lat'] === null || $u['home_lng'] === null));

render_layout('Geocode musicians', function () use ($allUsers, $results, $ran, $pendingCount) {
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Geocode musician addresses</h2>
  </div>
  <p class="text-muted">
    Resolves <code>home_address → home_lat/home_lng</code> via Nominatim for musicians
    missing coordinates. Required for TravelCalculator to produce accurate km estimates.
    Rate-limited to 1 request/second.
  </p>

  <?php if ($ran && empty($results)): ?>
  <div class="alert alert-info">No musicians with missing coordinates — nothing to do.</div>
  <?php elseif ($ran): ?>
  <div class="alert <?= array_filter($results, fn($r) => !$r['ok']) ? 'alert-warning' : 'alert-success' ?>">
    <?= count(array_filter($results, fn($r) => $r['ok'])) ?> geocoded,
    <?= count(array_filter($results, fn($r) => !$r['ok'])) ?> failed.
  </div>
  <table class="table table-sm table-bordered mb-4">
    <thead class="table-light"><tr><th>Username</th><th>Address</th><th>Result</th></tr></thead>
    <tbody>
    <?php foreach ($results as $r): ?>
      <tr class="<?= $r['ok'] ? 'table-success' : 'table-danger' ?>">
        <td><?= htmlspecialchars($r['username']) ?></td>
        <td><?= htmlspecialchars($r['address']) ?></td>
        <td class="font-monospace small"><?= htmlspecialchars($r['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <?php if ($pendingCount > 0): ?>
  <form method="post" action="/admin/geocode-musicians"
        onsubmit="return confirm('Run geocoding for <?= $pendingCount ?> musician(s)? This will take ~<?= $pendingCount ?> seconds.')">
    <button class="btn btn-primary">Run geocoding (<?= $pendingCount ?> pending)</button>
  </form>
  <?php else: ?>
  <div class="alert alert-success">All musicians with home addresses have coordinates.</div>
  <?php endif; ?>

  <h5 class="mt-4">Current state</h5>
  <table class="table table-sm table-bordered">
    <thead class="table-light"><tr><th>Username</th><th>Address</th><th>Lat</th><th>Lng</th></tr></thead>
    <tbody>
    <?php foreach ($allUsers as $u): ?>
      <tr class="<?= ($u['home_lat'] === null || $u['home_lng'] === null) ? 'table-warning' : '' ?>">
        <td><?= htmlspecialchars($u['username']) ?></td>
        <td><?= htmlspecialchars($u['home_address'] ?? '—') ?></td>
        <td class="font-monospace small"><?= $u['home_lat'] === null ? '<span class="text-muted">—</span>' : htmlspecialchars((string) $u['home_lat']) ?></td>
        <td class="font-monospace small"><?= $u['home_lng'] === null ? '<span class="text-muted">—</span>' : htmlspecialchars((string) $u['home_lng']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php
});
