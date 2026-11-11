<?php
/**
 * Per-grant hourly read limit for GET /learn-progress/v1/me.
 *
 * Technical guide section 6 and contract section 2: at most 60 reads an hour for one token;
 * read 61 answers 429 learn_progress_slow_down with Retry-After in seconds.
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Rate;

defined( 'WPINC' ) || die();

const MAX_READS        = 60;
const WINDOW           = HOUR_IN_SECONDS;
const TRANSIENT_PREFIX = 'learn_progress_rate_';

/**
 * Count one read for a grant and return the seconds to wait when the hourly limit is reached.
 *
 * @param int $grant Grant post ID.
 *
 * @return int 0 when the read is allowed, or seconds remaining in the current window (>= 1).
 */
function hit( int $grant ): int {
	$key   = TRANSIENT_PREFIX . $grant;
	$now   = time();
	$entry = get_transient( $key );

	if (
		! is_array( $entry )
		|| ! isset( $entry['start'], $entry['count'] )
		|| $now - (int) $entry['start'] >= WINDOW
	) {
		$entry = array(
			'start' => $now,
			'count' => 0,
		);
	}

	$left = max( 1, WINDOW - ( $now - (int) $entry['start'] ) );

	if ( (int) $entry['count'] >= MAX_READS ) {
		return $left;
	}

	$entry['count'] = (int) $entry['count'] + 1;
	set_transient( $key, $entry, $left );

	return 0;
}

/**
 * Clear the read counter for a grant.
 *
 * @param int $grant Grant post ID.
 *
 * @return void
 */
function reset( int $grant ): void {
	delete_transient( TRANSIENT_PREFIX . $grant );
}
