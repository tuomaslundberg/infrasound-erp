<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';
require_once __DIR__ . '/../../../config/gig_states.php';

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

$transitions = gig_valid_transitions($gig['status']);

// Personnel: current lineup
$personnelStmt = $pdo->prepare(
    "SELECT gp.user_id, u.username, gp.role, gp.fee_cents, gp.confirmed_at
     FROM   gig_personnel gp
     JOIN   users u ON u.id = gp.user_id
     WHERE  gp.gig_id = ?
     ORDER BY u.username ASC"
);
$personnelStmt->execute([$gigId]);
$personnel = $personnelStmt->fetchAll(PDO::FETCH_ASSOC);

// Available users for add-musician form (non-deleted, musician or higher)
$usersStmt = $pdo->prepare(
    "SELECT id, username, role
     FROM   users
     WHERE  deleted_at IS NULL
       AND  role IN ('musician', 'owner', 'admin', 'developer')
     ORDER BY username ASC"
);
$usersStmt->execute();
$availableUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Flash notices via query string
$notice = $_GET['notice'] ?? null;
$error  = $_GET['error']  ?? null;

render_layout($gig['customer_name'], function () use ($gig, $transitions, $personnel, $availableUsers, $notice, $error) {
?>
  <?php if ($notice === 'notes_saved'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    Notes saved.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php elseif ($error === 'notes_too_long'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    Notes too long — maximum 10 000 characters.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center gap-2">
      <h2 class="mb-0"><?= htmlspecialchars($gig['customer_name']) ?></h2>
      <?php $badge = GIG_STATUS_BADGES[$gig['status']] ?? 'secondary'; ?>
      <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($gig['status']) ?></span>
    </div>
    <div class="d-flex gap-2">
      <a href="/gigs/<?= (int)$gig['id'] ?>/quote" class="btn btn-primary btn-sm">Quote email</a>
      <a href="/gigs/<?= (int)$gig['id'] ?>/edit" class="btn btn-outline-secondary btn-sm">Edit</a>
    </div>
  </div>

  <?php if ($notice === 'personnel_added'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    Musician added to lineup.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php elseif ($notice === 'personnel_removed'): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    Musician removed from lineup.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php elseif ($error === 'duplicate_personnel'): ?>
  <div class="alert alert-warning alert-dismissible fade show" role="alert">
    That musician is already assigned to this gig.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php elseif ($error === 'invalid_input'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    Invalid input — please check the form and try again.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php elseif ($error === 'db_error'): ?>
  <div class="alert alert-danger alert-dismissible fade show" role="alert">
    A database error occurred — please try again.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php endif; ?>

  <?php if ($transitions): ?>
  <div class="d-flex gap-2 mb-3">
    <?php foreach ($transitions as $next): ?>
    <form method="post" action="/gigs/<?= (int)$gig['id'] ?>/transition">
      <input type="hidden" name="status" value="<?= htmlspecialchars($next) ?>">
      <button type="submit" class="btn btn-sm btn-<?= GIG_TRANSITION_STYLES[$next] ?? 'outline-secondary' ?>">
        <?= htmlspecialchars(GIG_TRANSITION_LABELS[$next] ?? $next) ?>
      </button>
    </form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header">Gig details</div>
        <div class="card-body">
          <dl class="row mb-0">
            <dt class="col-sm-4">Date</dt>
            <dd class="col-sm-8"><?= htmlspecialchars($gig['gig_date']) ?></dd>

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

    <div class="col-12">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          Notes
          <a href="#" class="btn btn-link btn-sm py-0" id="notes-edit-toggle">Edit</a>
        </div>
        <div class="card-body">
          <div id="notes-display">
            <?php if ($gig['notes']): ?>
              <p class="mb-0"><?= nl2br(htmlspecialchars($gig['notes'])) ?></p>
            <?php else: ?>
              <p class="mb-0 text-muted">No notes yet.</p>
            <?php endif; ?>
          </div>
          <form id="notes-form" method="post" action="/gigs/<?= (int)$gig['id'] ?>/notes" class="d-none">
            <textarea name="notes" class="form-control mb-2" rows="4"><?= htmlspecialchars($gig['notes'] ?? '') ?></textarea>
            <button type="submit" class="btn btn-primary btn-sm">Save</button>
            <a href="#" class="btn btn-link btn-sm" id="notes-cancel">Cancel</a>
          </form>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card">
        <div class="card-header">Personnel</div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0">
            <thead>
              <tr>
                <th>Username</th>
                <th>Role</th>
                <th>Fee</th>
                <th>Confirmed</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if ($personnel): ?>
                <?php foreach ($personnel as $p): ?>
                <tr>
                  <td><?= htmlspecialchars($p['username']) ?></td>
                  <td><?= htmlspecialchars($p['role']) ?></td>
                  <td><?= number_format($p['fee_cents'] / 100, 2, ',', ' ') ?> €</td>
                  <td><?= $p['confirmed_at'] ? htmlspecialchars($p['confirmed_at']) : '—' ?></td>
                  <td>
                    <form method="post" action="/gigs/<?= (int)$gig['id'] ?>/personnel/<?= (int)$p['user_id'] ?>/remove"
                          class="remove-personnel-form"
                          data-username="<?= htmlspecialchars($p['username']) ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm">Remove</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" class="text-muted text-center py-3">No musicians assigned yet.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($availableUsers): ?>
        <div class="card-footer">
          <form method="post" action="/gigs/<?= (int)$gig['id'] ?>/personnel" class="row g-2 align-items-end">
            <div class="col-auto">
              <label class="form-label form-label-sm mb-1">Musician</label>
              <select name="user_id" class="form-select form-select-sm" required>
                <option value="">— select —</option>
                <?php foreach ($availableUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-auto">
              <label class="form-label form-label-sm mb-1">Role</label>
              <select name="role" class="form-select form-select-sm" required>
                <option value="">— select —</option>
                <option value="vocalist">Vocalist</option>
                <option value="guitarist">Guitarist</option>
                <option value="bassist">Bassist</option>
                <option value="drummer">Drummer</option>
                <option value="keyboardist">Keyboardist</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-auto">
              <label class="form-label form-label-sm mb-1">Fee (€)</label>
              <input type="number" name="fee" class="form-control form-control-sm" min="0" step="0.01" value="0" style="width:90px">
            </div>
            <div class="col-auto">
              <button type="submit" class="btn btn-primary btn-sm">Add</button>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <div class="mt-3">
    <a href="/gigs" class="btn btn-link btn-sm px-0">← Back to gig list</a>
  </div>

<script>
document.querySelectorAll('.remove-personnel-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        var name = form.getAttribute('data-username');
        if (!confirm('Remove ' + name + ' from lineup?')) e.preventDefault();
    });
});
</script>
  <script>
    (function () {
      var toggle  = document.getElementById('notes-edit-toggle');
      var cancel  = document.getElementById('notes-cancel');
      var display = document.getElementById('notes-display');
      var form    = document.getElementById('notes-form');

      toggle.addEventListener('click', function (e) {
        e.preventDefault();
        display.classList.add('d-none');
        form.classList.remove('d-none');
      });

      cancel.addEventListener('click', function (e) {
        e.preventDefault();
        form.classList.add('d-none');
        display.classList.remove('d-none');
      });
    })();
  </script>
<?php
});
