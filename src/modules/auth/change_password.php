<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';

$currentUser = auth_user();
$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // Load current hash from DB to verify.
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([$currentUser['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($current, $row['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    }
    if (strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
    }

    if (empty($errors)) {
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_BCRYPT), $currentUser['id']]);
        $success = true;
    }
}

render_layout('Change password', function () use ($errors, $success) {
?>
  <div class="row justify-content-center mt-4">
    <div class="col-sm-10 col-md-6 col-lg-4">
      <h2 class="mb-4">Change password</h2>

      <?php if ($success): ?>
      <div class="alert alert-success">Password updated. <a href="/">Return home</a></div>
      <?php else: ?>

      <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <form method="post" action="/account/password">
        <div class="mb-3">
          <label class="form-label" for="current_password">Current password</label>
          <input type="password" id="current_password" name="current_password"
                 class="form-control" required autocomplete="current-password">
        </div>
        <div class="mb-3">
          <label class="form-label" for="new_password">New password</label>
          <input type="password" id="new_password" name="new_password"
                 class="form-control" required autocomplete="new-password" minlength="8">
        </div>
        <div class="mb-3">
          <label class="form-label" for="confirm_password">Confirm new password</label>
          <input type="password" id="confirm_password" name="confirm_password"
                 class="form-control" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary">Update password</button>
      </form>

      <?php endif; ?>
    </div>
  </div>
<?php
});
