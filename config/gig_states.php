<?php
declare(strict_types=1);

/**
 * Gig status state machine.
 *
 * Only the transitions listed here are permitted through the normal UI.
 * The edit form's status dropdown is an admin-level override and bypasses
 * this guard intentionally (owner+ role required to reach the edit form).
 *
 * Valid flow:
 *   inquiry → quoted → confirmed → delivered
 *   any non-terminal state → cancelled | declined
 */
const GIG_TRANSITIONS = [
    'inquiry'   => ['quoted',     'cancelled', 'declined'],
    'quoted'    => ['confirmed',  'cancelled', 'declined'],
    'confirmed' => ['delivered',  'cancelled'],
    'delivered' => [],
    'cancelled' => [],
    'declined'  => [],
];

/** Human-readable button labels for each target status. */
const GIG_TRANSITION_LABELS = [
    'quoted'    => 'Mark as quoted',
    'confirmed' => 'Confirm booking',
    'delivered' => 'Mark as delivered',
    'cancelled' => 'Cancel booking',
    'declined'  => 'Decline',
];

/** Bootstrap button variant for each target status. */
const GIG_TRANSITION_STYLES = [
    'quoted'    => 'outline-primary',
    'confirmed' => 'success',
    'delivered' => 'outline-success',
    'cancelled' => 'outline-danger',
    'declined'  => 'outline-secondary',
];

/** Bootstrap badge bg colour for each current status. */
const GIG_STATUS_BADGES = [
    'inquiry'   => 'secondary',
    'quoted'    => 'primary',
    'confirmed' => 'success',
    'delivered' => 'dark',
    'cancelled' => 'danger',
    'declined'  => 'warning',
];

function gig_valid_transitions(string $currentStatus): array
{
    return GIG_TRANSITIONS[$currentStatus] ?? [];
}

function gig_can_transition(string $from, string $to): bool
{
    return in_array($to, GIG_TRANSITIONS[$from] ?? [], true);
}
