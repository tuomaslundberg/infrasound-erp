<?php
declare(strict_types=1);

/**
 * Session-based authentication helpers.
 *
 * Role hierarchy (ascending privilege):
 *   musician < owner < admin < developer
 *
 * Usage:
 *   auth_start();                      // call once at top of every controller
 *   auth_require('owner');             // redirects to /login if not met
 *   $user = auth_user();               // returns session user array or null
 *   auth_has_role('admin');            // boolean check without redirect
 */

const ROLE_ORDER = ['musician' => 1, 'owner' => 2, 'admin' => 3, 'developer' => 4];

function auth_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Returns the authenticated user array, or null if not logged in.
 * Keys: id, username, role
 */
function auth_user(): ?array
{
    auth_start();
    return $_SESSION['user'] ?? null;
}

/**
 * Returns true if the current user's role meets the minimum required role.
 */
function auth_has_role(string $minRole): bool
{
    $user = auth_user();
    if ($user === null) return false;
    $userLevel = ROLE_ORDER[$user['role']] ?? 0;
    $minLevel  = ROLE_ORDER[$minRole]       ?? 999;
    return $userLevel >= $minLevel;
}

/**
 * Enforces a minimum role. Redirects to /login if not met.
 * Call this in the router (or at the top of a controller) for protected routes.
 */
function auth_require(string $minRole = 'musician'): void
{
    auth_start();
    if (!auth_has_role($minRole)) {
        $intended = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: /login?next=' . urlencode($intended));
        exit;
    }
}
