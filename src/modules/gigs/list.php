<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';

// ── Input validation ──────────────────────────────────────────────────────────
$allowedStatuses = ['all', 'inquiry', 'quoted', 'confirmed', 'delivered', 'cancelled', 'declined'];
$allowedSortCols = ['gig_date', 'customer_name', 'quoted_price_cents'];

$status  = in_array($_GET['status'] ?? '', $allowedStatuses, true) ? $_GET['status'] : 'all';
$sort    = in_array($_GET['sort']   ?? '', $allowedSortCols,  true) ? $_GET['sort']   : 'gig_date';
$dir     = strtolower($_GET['dir']  ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
$q       = trim($_GET['q'] ?? '');
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = ['g.deleted_at IS NULL'];
$params = [];

if ($status !== 'all') {
    $where[]           = 'g.status = :status';
    $params[':status'] = $status;
}

if ($q !== '') {
    $where[]     = 'c.name LIKE :q';
    $params[':q'] = '%' . $q . '%';
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// ── Sort column (safe: whitelisted above, never interpolated from raw input) ──
$sortColSQL = match ($sort) {
    'customer_name'      => 'c.name',
    'quoted_price_cents' => 'g.quoted_price_cents',
    default              => 'g.gig_date',
};

// ── Count query ───────────────────────────────────────────────────────────────
$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM   gigs g
     JOIN   customers c ON c.id = g.customer_id
     LEFT JOIN venues v ON v.id = g.venue_id
     $whereSQL"
);
$countStmt->execute($params);
$total      = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ── Data query ────────────────────────────────────────────────────────────────
$dataStmt = $pdo->prepare(
    "SELECT g.id, g.gig_date, g.status, g.customer_type, g.channel,
            g.order_description, g.quoted_price_cents,
            c.name AS customer_name,
            v.name AS venue_name, v.city AS venue_city
     FROM   gigs g
     JOIN   customers c ON c.id = g.customer_id
     LEFT JOIN venues v ON v.id = g.venue_id
     $whereSQL
     ORDER  BY $sortColSQL $dir
     LIMIT  :limit OFFSET :offset"
);
foreach ($params as $key => $val) {
    $dataStmt->bindValue($key, $val);
}
$dataStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$dataStmt->execute();
$gigs = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Status badge colour map ───────────────────────────────────────────────────
$statusColour = [
    'inquiry'   => 'secondary',
    'quoted'    => 'info',
    'confirmed' => 'success',
    'delivered' => 'dark',
    'cancelled' => 'danger',
    'declined'  => 'warning',
];

// ── URL builder: preserves current state, overrides given keys ───────────────
$buildUrl = function (array $override) use ($status, $sort, $dir, $q, $page): string {
    $p = [
        'status' => $status,
        'sort'   => $sort,
        'dir'    => strtolower($dir),
        'q'      => $q,
        'page'   => (string) $page,
    ];
    foreach ($override as $k => $v) {
        $p[$k] = $v;
    }
    // Strip defaults to keep URLs clean
    if ($p['status'] === 'all') {
        unset($p['status']);
    }
    if ($p['sort'] === 'gig_date' && $p['dir'] === 'asc') {
        unset($p['sort'], $p['dir']);
    }
    if ($p['q'] === '') {
        unset($p['q']);
    }
    if ($p['page'] === '1') {
        unset($p['page']);
    }
    return '/gigs' . (empty($p) ? '' : '?' . http_build_query($p));
};

render_layout('Gigs', function () use ($gigs, $statusColour, $status, $sort, $dir, $q, $page, $total, $totalPages, $perPage, $offset, $buildUrl) {
    $statuses = ['all', 'inquiry', 'quoted', 'confirmed', 'delivered', 'cancelled', 'declined'];
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Gigs</h2>
    <a href="/gigs/new" class="btn btn-primary btn-sm">+ New inquiry</a>
  </div>

  <!-- Controls row: status filter + customer search -->
  <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <!-- Status filter buttons -->
    <div class="btn-group btn-group-sm" role="group" aria-label="Status filter">
      <?php foreach ($statuses as $s): ?>
        <a href="<?= htmlspecialchars($buildUrl(['status' => $s, 'page' => '1'])) ?>"
           class="btn btn-<?= $status === $s ? 'secondary' : 'outline-secondary' ?>">
          <?= htmlspecialchars($s) ?>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Customer search -->
    <form method="get" action="/gigs" class="d-flex gap-1 ms-auto">
      <?php if ($status !== 'all'): ?>
        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
      <?php endif; ?>
      <?php if ($sort !== 'gig_date'): ?>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
      <?php endif; ?>
      <?php if ($dir !== 'ASC'): ?>
        <input type="hidden" name="dir" value="<?= htmlspecialchars(strtolower($dir)) ?>">
      <?php endif; ?>
      <input type="text" name="q" class="form-control form-control-sm"
             placeholder="Search customer…" value="<?= htmlspecialchars($q) ?>">
      <button type="submit" class="btn btn-outline-secondary btn-sm">Search</button>
      <?php if ($q !== ''): ?>
        <a href="<?= htmlspecialchars($buildUrl(['q' => '', 'page' => '1'])) ?>"
           class="btn btn-outline-danger btn-sm">✕</a>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($total === 0): ?>
    <p class="text-muted">No gigs found.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle">
      <thead class="table-light">
        <tr>
          <?php
          // Sortable column header helper
          $th = function (string $col, string $label, string $thClass = '') use ($sort, $dir, $buildUrl): void {
              $nextDir = ($sort === $col && $dir === 'ASC') ? 'desc' : 'asc';
              $arrow   = $sort === $col ? ($dir === 'ASC' ? ' ▲' : ' ▼') : '';
              $url     = htmlspecialchars($buildUrl(['sort' => $col, 'dir' => $nextDir, 'page' => '1']));
              $cls     = $thClass ? ' class="' . $thClass . '"' : '';
              echo "<th$cls><a href=\"$url\" class=\"text-decoration-none text-dark\">"
                   . htmlspecialchars($label) . $arrow . "</a></th>\n";
          };
          ?>
          <?php $th('gig_date', 'Date') ?>
          <?php $th('customer_name', 'Customer') ?>
          <th>Venue</th>
          <th>Order</th>
          <?php $th('quoted_price_cents', 'Quoted (€)', 'text-end') ?>
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

  <!-- Pagination -->
  <div class="d-flex justify-content-between align-items-center mt-2">
    <small class="text-muted">
      Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $total)) ?>
      of <?= number_format($total) ?>
    </small>
    <nav aria-label="Gig list pagination">
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link"
             href="<?= htmlspecialchars($buildUrl(['page' => (string) ($page - 1)])) ?>">← Prev</a>
        </li>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
          <a class="page-link"
             href="<?= htmlspecialchars($buildUrl(['page' => (string) ($page + 1)])) ?>">Next →</a>
        </li>
      </ul>
    </nav>
  </div>
  <?php endif; ?>
<?php
});
