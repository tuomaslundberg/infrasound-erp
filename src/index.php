<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// ---------------------------------------------------------------------------
// Route table
// Each entry: [pattern, controller_file, min_role]
// min_role — null = public; otherwise the minimum role required (see config/auth.php)
// ---------------------------------------------------------------------------
$routes = [
    // Public
    ['#^/login$#',                      'modules/auth/login.php',         null],
    ['#^/logout$#',                     'modules/auth/logout.php',        null],

    // Protected — minimum role: owner
    ['#^/$#',                           'modules/gigs/list.php',          'owner'],
    ['#^/gigs$#',                       'modules/gigs/list.php',          'owner'],
    ['#^/gigs/new$#',                   'modules/gigs/form.php',          'owner'],
    ['#^/gigs/(\d+)$#',                 'modules/gigs/detail.php',        'owner'],
    ['#^/gigs/(\d+)/edit$#',            'modules/gigs/form.php',          'owner'],
    ['#^/gigs/(\d+)/quote$#',           'modules/gigs/quote.php',         'owner'],
];

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------
$uri = strtok($_SERVER['REQUEST_URI'], '?') ?: '/';

foreach ($routes as [$pattern, $controller, $minRole]) {
    if (preg_match($pattern, $uri, $matches)) {
        $routeParams = array_slice($matches, 1);

        if ($minRole !== null) {
            auth_require($minRole);
        }

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
