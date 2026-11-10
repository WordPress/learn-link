<?php
/**
 * Request-scoped grant context for authenticated learn-progress/v1 calls.
 *
 * The authenticated grant ID is held in server memory only, never in a request parameter that a
 * client could supply, and without calling wp_set_current_user() (technical guide section 6).
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Context;

defined( 'WPINC' ) || die();

/**
 * Store the authenticated grant ID for the current request.
 *
 * @param int|null $grant_id Grant post ID, or null to clear.
 *
 * @return void
 */
function set( ?int $grant_id = null ): void {
	$current = &slot();
	$current = null === $grant_id ? null : (int) $grant_id;
}

/**
 * Return the authenticated grant ID for the current request, or null.
 *
 * @return int|null
 */
function get(): ?int {
	$current = &slot();

	return $current;
}

/**
 * Shared static storage slot for set() and get().
 *
 * @return int|null
 */
function &slot(): ?int {
	static $grant_id = null;

	return $grant_id;
}

