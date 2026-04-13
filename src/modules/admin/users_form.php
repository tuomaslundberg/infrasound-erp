<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';

$currentUser = auth_user();
$editId = isset($routeParams[0]) ? (int)$routeParams[0] : null;
$isEdit = $editId !== null;

// Load existing user in edit mode.
$db = [];
if ($isEdit) {
    $stmt = $pdo->prepare(
        'SELECT id, username, email, role FROM users WHERE id = ? AND deleted_at IS NULL'
    );
    $stmt->execute([$editId]);
    $db = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$db) {
        http_response_code(404);
        require __DIR__ . '/../../templates/error.php';
        exit;
    }
    // Only developer can edit another developer account.
    if ($db['role'] === 'developer' && $currentUser['role'] !== 'developer') {
        http_response_code(403);
        exit('Forbidden.');
    }
}

// Roles the current user may assign.
$allowedRoles = ['musician', 'owner'];
if (auth_has_role('admin')) {
    $allowedRoles[] = 'admin';
}
// When editing a developer account, include 'developer' so the role is
// selectable and cannot be silently downgraded on save.
if ($isEdit && ($db['role'] ?? '') === 'developer') {
    $allowedRoles[] = 'developer';
}

$errors   = [];
$username = $db['username'] ?? '';
$email    = $db['email']    ?? '';
$role     = $db['role']     ?? 'musician';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email']    ?? '') ?: null;
    $role     = $_POST['role']          ?? 'musician';
    $password = $_POST['password']      ?? '';
    $confirm  = $_POST['confirm']       ?? '';

    if ($username === '') {
        $errors[] = 'Username is required.';
    }
    if (!in_array($role, $allowedRoles, true)) {
        $errors[] = 'Invalid role.';
    }
    if (!$isEdit && $password === '') {
        $errors[] = 'Password is required.';
    }
    if ($password !== '' && strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== '' && $password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    // Cannot change own role.
    if ($isEdit && $editId === $currentUser['id'] && $role !== $db['role']) {
        $errors[] = 'You cannot change your own role.';
    }

    if (empty($errors)) {
        // Check username uniqueness (exclude self in edit mode).
        $uniqStmt = $pdo->prepare(
            'SELECT id FROM users WHERE username = ? AND deleted_at IS NULL AND (? IS NULL OR id != ?)'
        );
        $uniqStmt->execute([$username, $editId, $editId]);
        if ($uniqStmt->fetch()) {
            $errors[] = 'Username already taken.';
        }
    }

    if (empty($errors)) {
        if ($isEdit) {
            $sets  = 'username = ?, email = ?, role = ?';
            $binds = [$username, $email, $role];
            if ($password !== '') {
                $sets    .= ', password_hash = ?';
                $binds[]  = password_hash($password, PASSWORD_BCRYPT);
            }
            $binds[] = $editId;
            $pdo->prepare("UPDATE users SET $sets WHERE id = ?")->execute($binds);
            header('Location: /admin/users?notice=updated');
        } else {
            $pdo->prepare(
                'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)'
            )->execute([$username, $email, password_hash($password, PASSWORD_BCRYPT), $role]);
            header('Location: /admin/users?notice=created');
        }
        exit;
    }
}

$pageTitle = $isEdit ? 'Edit user' : 'New user';

render_layout($pageTitle, function () use ($isEdit, $errors, $username, $email, $role, $allowedRoles, $editId) {
?>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0"><?= $isEdit ? 'Edit user' : 'New user' ?></h2>
    <a href="/admin/users" class="btn btn-link btn-sm px-0">← Users</a>
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

  <form method="post" action="<?= $isEdit ? "/admin/users/$editId/edit" : '/admin/users/new' ?>">
    <div class="mb-3">
      <label class="form-label" for="username">Username</label>
      <input type="text" id="username" name="username" class="form-control"
             value="<?= htmlspecialchars($username) ?>" required autocomplete="off">
    </div>
    <div class="mb-3">
      <label class="form-label" for="email">Email <span class="text-muted">(optional)</span></label>
      <input type="email" id="email" name="email" class="form-control"
             value="<?= htmlspecialchars($email ?? '') ?>" autocomplete="off">
    </div>
    <div class="mb-3">
      <label class="form-label" for="role">Role</label>
      <select id="role" name="role" class="form-select">
        <?php foreach ($allowedRoles as $r): ?>
        <option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= $r ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="mb-3">
      <label class="form-label" for="password">
        Password <?= $isEdit ? '<span class="text-muted">(leave blank to keep current)</span>' : '' ?>
      </label>
      <input type="password" id="password" name="password" class="form-control"
             autocomplete="new-password" minlength="8"
             <?= $isEdit ? '' : 'required' ?>>
    </div>
    <div class="mb-3">
      <label class="form-label" for="confirm">Confirm password</label>
      <input type="password" id="confirm" name="confirm" class="form-control"
             autocomplete="new-password">
    </div>
    <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
    <a href="/admin/users" class="btn btn-link">Cancel</a>
  </form>
<?php
});
