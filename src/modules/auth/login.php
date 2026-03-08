<?php
declare(strict_types=1);

require_once __DIR__ . '/../../templates/layout.php';
require_once __DIR__ . '/../../../config/auth.php';

auth_start();

// Already logged in — redirect home
if (auth_user() !== null) {
    header('Location: /');
    exit;
}

$error    = null;
$next     = $_GET['next'] ?? '/';
// Sanitise redirect target: must be a relative path
if (!preg_match('#^/#', $next)) {
    $next = '/';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $next     = $_POST['next'] ?? '/';
    if (!preg_match('#^/#', $next)) $next = '/';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, username, password_hash, role
             FROM   users
             WHERE  username = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && password_verify($password, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id'       => $row['id'],
                'username' => $row['username'],
                'role'     => $row['role'],
            ];
            header('Location: ' . $next);
            exit;
        }

        // Constant-time failure path (password_verify already handles timing,
        // but we avoid leaking whether the username exists)
        $error = 'Invalid username or password.';
    }
}

render_layout('Login', function () use ($error, $next) {
?>
  <div class="row justify-content-center mt-5">
    <div class="col-sm-10 col-md-6 col-lg-4">
      <h2 class="mb-4">Sign in</h2>

      <?php if ($error): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="post" action="/login">
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

        <div class="mb-3">
          <label class="form-label" for="username">Username</label>
          <input type="text" id="username" name="username" class="form-control"
                 autocomplete="username" autofocus required>
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <input type="password" id="password" name="password" class="form-control"
                 autocomplete="current-password" required>
        </div>

        <button type="submit" class="btn btn-primary w-100">Sign in</button>
      </form>
    </div>
  </div>
<?php
});
