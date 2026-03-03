<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

// ---------------------------------------------------------------------------
// Route table
// Each entry: [pattern, controller_file, is_public]
// pattern  — regex matched against the URI path (without query string)
// is_public — if false, the route requires an authenticated session (Phase 3)
// ---------------------------------------------------------------------------
$routes = [
    ['#^/$#',                           'modules/gigs/list.php',          false],
    ['#^/gigs$#',                       'modules/gigs/list.php',          false],
    ['#^/gigs/new$#',                   'modules/gigs/form.php',          false],
    ['#^/gigs/(\d+)$#',                 'modules/gigs/detail.php',        false],
    ['#^/gigs/(\d+)/edit$#',            'modules/gigs/form.php',          false],
];

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------
$uri    = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

foreach ($routes as [$pattern, $controller, $isPublic]) {
    if (preg_match($pattern, $uri, $matches)) {
        // Pass any capture groups (e.g. gig ID) to the controller
        $routeParams = array_slice($matches, 1);

        // Auth guard — placeholder until Phase 3 auth is built
        // When session auth is added, enforce $isPublic here.

        $controllerPath = __DIR__ . '/' . $controller;
        if (!file_exists($controllerPath)) {
            http_response_code(501);
            require __DIR__ . '/templates/error.php';
            exit;
        }

        require $controllerPath;
        exit;
    }
}

http_response_code(404);
require __DIR__ . '/templates/error.php';
