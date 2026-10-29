<?php
/**
 * The `learn-progress/1` body: one learner, the client's whitelisted courses.
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Response;

use WPOrg_Learn\Learn_Progress\Reader;

defined( 'WPINC' ) || die();

/**
 * Build the body for one learner and one client.
 *
 * Sensei is read only through Reader\read_course(), the one place that touches its data.
 *
 * @param \WP_User $user          The learner. Always the authenticated one, never a parameter.
 * @param array    $client        The client, from Clients\get().
 * @param string   $courses_param The optional `courses` parameter, which can only narrow the whitelist.
 *
 * @return array|\WP_Error The body, or the reader's error for the first course it could not read.
 */
function build_body( \WP_User $user, array $client, $courses_param = '' ) {
	$courses = array();

	foreach ( narrow( $client['courses'], $courses_param ) as $course_id ) {
		$course = Reader\read_course( $user->ID, $course_id );

		if ( is_wp_error( $course ) ) {
			return $course;
		}

		$courses[] = $course;
	}

	return array(
		'contract'     => 'learn-progress/1',
		// On Learn the local account is the WordPress.org account, through single sign-on.
		'learner'      => array( 'wporg_username' => $user->user_login ),
		'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'courses'      => $courses,
	);
}

/**
 * The courses to answer for: the whitelist, narrowed by the `courses` parameter.
 *
 * The parameter is a comma-separated list of course IDs. Anything that is not a whole number, or
 * not in the whitelist, is ignored, never an error, so a probe learns nothing about the whitelist
 * (contract section 1). The whitelist's order is kept. When nothing is left, the whole whitelist is
 * answered (proposed for contract section 7, pending decision D-12).
 *
 * @param array  $whitelist The client's course IDs.
 * @param string $param     The `courses` parameter, possibly empty.
 *
 * @return int[]
 */
function narrow( array $whitelist, $param ) {
	$whitelist = array_values( array_unique( array_map( 'intval', $whitelist ) ) );
	$asked     = array();

	foreach ( explode( ',', (string) $param ) as $item ) {
		$item = trim( $item );

		if ( preg_match( '/^[0-9]+$/', $item ) ) {
			$asked[] = (int) $item;
		}
	}

	$narrowed = array_values( array_intersect( $whitelist, $asked ) );

	return $narrowed ? $narrowed : $whitelist;
}
