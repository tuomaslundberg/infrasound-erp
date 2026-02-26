<?php
/**
 * Feature flags for incremental ERP migration.
 *
 * Set a flag to true only when the corresponding ERP module is ready
 * to replace the legacy workflow. Once permanently enabled, remove
 * the old code path and the flag itself (no permanent dual systems).
 *
 * See AGENTS.md §6 for the full migration strategy.
 */

define('USE_ERP_CUSTOMERS',  false);
define('USE_ERP_INVOICING',  false);
define('USE_ERP_ACCOUNTING', false);
