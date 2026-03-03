<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

$code    = http_response_code();
$message = match ($code) {
    404    => 'Page not found.',
    501    => 'This page is not yet implemented.',
    default => 'An unexpected error occurred.',
};

render_layout("Error $code", function () use ($code, $message) {
?>
  <div class="row justify-content-center mt-5">
    <div class="col-md-6 text-center">
      <h1 class="display-4"><?= $code ?></h1>
      <p class="lead"><?= htmlspecialchars($message) ?></p>
      <a href="/" class="btn btn-outline-secondary">Back to start</a>
    </div>
  </div>
<?php
});
