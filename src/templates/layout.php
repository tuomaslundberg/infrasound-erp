<?php
/**
 * Shared page layout.
 *
 * Controllers call render_layout($title, $content) to produce a full HTML page.
 * $content is a callable that outputs the page body HTML.
 */
declare(strict_types=1);

function render_layout(string $title, callable $content): void
{
?>
<!doctype html>
<html lang="fi">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?> — infrasound ERP</title>
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
        crossorigin="anonymous">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="/">infrasound ERP</a>
    <div class="collapse navbar-collapse">
      <ul class="navbar-nav me-auto">
        <li class="nav-item">
          <a class="nav-link <?= str_starts_with($_SERVER['REQUEST_URI'], '/gigs') ? 'active' : '' ?>"
             href="/gigs">Gigs</a>
        </li>
      </ul>
    </div>
  </div>
</nav>

<main class="container-fluid px-4">
  <?php $content(); ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}
