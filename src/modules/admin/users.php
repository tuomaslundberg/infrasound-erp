<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';

$users = $pdo->query(
    "SELECT id, username, email, role, created_at
     FROM   users
     WHERE  deleted_at IS NULL
     ORDER  BY role DESC, username ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$notice = $_GET['notice'] ?? null;

$roleBadge = [
    'developer' => 'danger',
    'admin'     => 'warning',
    'owner'     => 'primary',
    'musician'  => 'secondary',
];

render_layout('Users', function () use ($users, $notice, $roleBadge) {
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Users</h2>
    <a href="/admin/users/new" class="btn btn-primary btn-sm">+ New user</a>
  </div>

  <?php if ($notice === 'created'): ?>
  <div class="alert alert-success">User created.</div>
  <?php elseif ($notice === 'updated'): ?>
  <div class="alert alert-success">User updated.</div>
  <?php elseif ($notice === 'deleted'): ?>
  <div class="alert alert-success">User removed.</div>
  <?php endif; ?>

  <table class="table table-sm table-bordered">
    <thead class="table-light">
      <tr>
        <th>Username</th>
        <th>Email</th>
        <th>Role</th>
        <th>Created</th>
        <th style="width:130px"></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['username']) ?></td>
        <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
        <td>
          <span class="badge bg-<?= $roleBadge[$u['role']] ?? 'secondary' ?>">
            <?= htmlspecialchars($u['role']) ?>
          </span>
        </td>
        <td class="small text-muted"><?= htmlspecialchars(substr($u['created_at'], 0, 10)) ?></td>
        <td>
          <a href="/admin/users/<?= $u['id'] ?>/edit" class="btn btn-sm btn-outline-secondary">Edit</a>
          <?php if (auth_user()['id'] !== (int)$u['id']): ?>
          <form method="post" action="/admin/users/<?= $u['id'] ?>/delete" class="d-inline"
                onsubmit="return confirm('Remove <?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?>?')">
            <button class="btn btn-sm btn-outline-danger">Remove</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php
});
