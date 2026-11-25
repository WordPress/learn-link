<?php
/**
 * The learner's connections, and their withdrawal by a Learn administrator.
 *
 * Contract section 3, step 2: Learn "lists the grant on the learner's Learn profile so they can
 * always see it exists". Contract section 4: "A Learn administrator may also withdraw a grant from
 * the learner's profile screen on Learn", and the Dashboard learns of it through
 * `403 learn_progress_revoked` on its next read. The learner has no withdraw button of their own:
 * a program Administrator withdraws a connection on the Dashboard.
 *
 * Nothing here ever shows a hash, a token or a code: every value comes from Grants\summary() and
 * the grant's `_revoked_at`.
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Connections;

use WPOrg_Learn\Learn_Progress\Authorize;
use WPOrg_Learn\Learn_Progress\Clients;
use WPOrg_Learn\Learn_Progress\Grants;

defined( 'WPINC' ) || die();

const REVOKE_ACTION = 'learn_progress_revoke_grant';
const NONCE_PREFIX  = 'learn_progress_revoke_';
const NOTICE_VAR    = 'learn_progress_withdrawal';
const CAPABILITY    = 'edit_users';

add_action( 'edit_user_profile', __NAMESPACE__ . '\profile_section' );
add_action( 'admin_footer-user-edit.php', __NAMESPACE__ . '\print_revoke_forms' );
add_action( 'admin_post_' . REVOKE_ACTION, __NAMESPACE__ . '\handle_revoke' );
add_action( 'admin_notices', __NAMESPACE__ . '\revoke_notice' );

/**
 * The "Your connections" page at /learn-link/authorize/?view=connections, for the signed-in learner.
 *
 * Authorize\respond_connections() asks the learner to sign in first, and sends this as the whole page.
 *
 * @param \WP_User $user The signed-in learner.
 *
 * @return string A whole HTML document, every piece escaped as it was built.
 */
function render_view( \WP_User $user ): string {
	$html = '<h1>' . esc_html__( 'Your connections', 'wporg-learn' ) . '</h1>';

	/* translators: %s: The learner's username. */
	$html   .= '<p>' . esc_html( sprintf( __( 'You are signed in as @%s.', 'wporg-learn' ), $user->user_login ) ) . '</p>';
	$entries = entries( (int) $user->ID );

	if ( ! $entries ) {
		$html .= '<p>' . esc_html__( 'You have not connected Learn to any program.', 'wporg-learn' ) . '</p>';
	} else {
		$html .= '<p>' . esc_html__( 'These programs can read your progress in the courses named under each one.', 'wporg-learn' ) . '</p>';

		foreach ( $entries as $entry ) {
			$html .= '<section><h2>' . esc_html( $entry['program'] ) . '</h2>';
			$html .= courses_list( $entry['courses'] );
			$html .= '<dl>' . details( $entry ) . '</dl></section>';
		}
	}

	$html .= '<p>' . esc_html__( 'Only a program Administrator can withdraw a connection. To stop sharing your progress, ask an Administrator of that program.', 'wporg-learn' ) . '</p>';

	return Authorize\document( 200, $html )['html'];
}

/**
 * What may be shown about each of a learner's grants. Never a hash.
 *
 * @param int $user_id The learner.
 *
 * @return array[] Each with `grant`, `program`, `courses` (titles), `created`, `last_used` and `revoked_at`.
 */
function entries( int $user_id ): array {
	$entries = array();

	foreach ( Grants\for_user( $user_id ) as $grant_id ) {
		$summary = Grants\summary( $grant_id );

		if ( ! $summary ) {
			continue;
		}

		$client    = Clients\get( $summary['client_id'] );
		$entries[] = array(
			'grant'      => (int) $grant_id,
			'program'    => $client ? $client['name'] : $summary['client_id'],
			'courses'    => array_map( __NAMESPACE__ . '\course_title', $summary['courses'] ),
			'created'    => $summary['created'],
			'last_used'  => $summary['last_used'],
			'revoked_at' => (int) get_post_meta( $grant_id, '_revoked_at', true ),
		);
	}

	return $entries;
}

/**
 * A course's title, or its number when the course is gone.
 *
 * @param mixed $course_id The course ID.
 *
 * @return string
 */
function course_title( $course_id ): string {
	$course_id = (int) $course_id;
	$title     = 'course' === get_post_type( $course_id ) ? get_the_title( $course_id ) : '';

	/* translators: %d: A course ID. */
	return '' !== $title ? $title : sprintf( __( 'Course %d', 'wporg-learn' ), $course_id );
}

/**
 * The course titles as a list.
 *
 * @param string[] $titles The titles.
 *
 * @return string
 */
function courses_list( array $titles ): string {
	if ( ! $titles ) {
		return '<p>' . esc_html__( 'No courses.', 'wporg-learn' ) . '</p>';
	}

	$html = '<ul>';

	foreach ( $titles as $title ) {
		$html .= '<li>' . esc_html( $title ) . '</li>';
	}

	return $html . '</ul>';
}

/**
 * The dates of one grant, as description-list rows.
 *
 * @param array $entry One item from entries().
 *
 * @return string
 */
function details( array $entry ): string {
	$rows = array(
		__( 'Connected on', 'wporg-learn' ) => format_date( $entry['created'] ),
		__( 'Last read', 'wporg-learn' )    => $entry['last_used'] ? format_date( $entry['last_used'] ) : __( 'Not read yet', 'wporg-learn' ),
	);

	if ( $entry['revoked_at'] ) {
		$rows[ __( 'Withdrawn on Learn', 'wporg-learn' ) ] = format_date( $entry['revoked_at'] );
	}

	$html = '';

	foreach ( $rows as $label => $value ) {
		$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
	}

	return $html;
}

/**
 * A date in the site's format and time zone.
 *
 * @param int $timestamp A Unix timestamp.
 *
 * @return string
 */
function format_date( int $timestamp ): string {
	return $timestamp ? (string) wp_date( get_option( 'date_format' ), $timestamp ) : __( 'Unknown', 'wporg-learn' );
}

/**
 * The learner's grants on the profile screen of another user in wp-admin, with a Withdraw button each.
 *
 * Only for someone who may edit users. The profile screen is itself a form, and forms cannot nest,
 * so each button names its own form, which print_revoke_forms() prints after the profile form.
 *
 * @param \WP_User $user The user whose profile is shown.
 *
 * @return void
 */
function profile_section( $user ) {
	if ( ! $user instanceof \WP_User || ! may_withdraw( (int) $user->ID ) ) {
		return;
	}

	echo profile_table( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every piece was escaped as it was built.
}

/**
 * The profile section's markup. Sends nothing, so it can be tested.
 *
 * @param \WP_User $user The user whose profile is shown.
 *
 * @return string
 */
function profile_table( \WP_User $user ): string {
	$html    = '<h2 id="learn-progress-connections">' . esc_html__( 'Learn Link connections', 'wporg-learn' ) . '</h2>';
	$entries = entries( (int) $user->ID );

	if ( ! $entries ) {
		return $html . '<p>' . esc_html__( 'This learner has not connected Learn to any program.', 'wporg-learn' ) . '</p>';
	}

	$html .= '<p>' . esc_html__( 'Withdrawing stops the program from reading this learner\'s progress. The program sees the connection as withdrawn on its next read. No email is sent.', 'wporg-learn' ) . '</p>';
	$html .= '<table class="widefat striped"><thead><tr>';

	foreach ( array( __( 'Program', 'wporg-learn' ), __( 'Courses', 'wporg-learn' ), __( 'Connected on', 'wporg-learn' ), __( 'Last read', 'wporg-learn' ), __( 'Status', 'wporg-learn' ) ) as $heading ) {
		$html .= '<th scope="col">' . esc_html( $heading ) . '</th>';
	}

	$html .= '</tr></thead><tbody>';

	foreach ( $entries as $entry ) {
		$html .= '<tr><td>' . esc_html( $entry['program'] ) . '</td>';
		$html .= '<td>' . esc_html( implode( ', ', $entry['courses'] ) ) . '</td>';
		$html .= '<td>' . esc_html( format_date( $entry['created'] ) ) . '</td>';
		$html .= '<td>' . esc_html( $entry['last_used'] ? format_date( $entry['last_used'] ) : __( 'Not read yet', 'wporg-learn' ) ) . '</td><td>';

		if ( $entry['revoked_at'] ) {
			/* translators: %s: The date of the withdrawal. */
			$html .= esc_html( sprintf( __( 'Withdrawn on Learn on %s', 'wporg-learn' ), format_date( $entry['revoked_at'] ) ) );
		} else {
			$html .= '<button type="submit" class="button" form="' . esc_attr( form_id( $entry['grant'] ) ) . '">';
			/* translators: %s: The name of the program. */
			$html .= esc_html__( 'Withdraw', 'wporg-learn' ) . '<span class="screen-reader-text"> ' . esc_html( sprintf( __( 'the connection to %s', 'wporg-learn' ), $entry['program'] ) ) . '</span></button>';

			$GLOBALS['learn_progress_revoke_forms'][ $entry['grant'] ] = (int) $user->ID;
		}

		$html .= '</td></tr>';
	}

	return $html . '</tbody></table>';
}

/**
 * The id of the form that withdraws one grant.
 *
 * @param int $grant_id The grant.
 *
 * @return string
 */
function form_id( int $grant_id ): string {
	return 'learn-progress-revoke-' . $grant_id;
}

/**
 * Print the withdrawal forms after the profile form, one per button the profile section drew.
 *
 * @return void
 */
function print_revoke_forms() {
	echo revoke_forms(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every piece was escaped as it was built.
}

/**
 * The withdrawal forms' markup, each with its own nonce. Sends nothing, so it can be tested.
 *
 * @return string
 */
function revoke_forms(): string {
	$html  = '';
	$forms = isset( $GLOBALS['learn_progress_revoke_forms'] ) ? (array) $GLOBALS['learn_progress_revoke_forms'] : array();

	foreach ( $forms as $grant_id => $user_id ) {
		if ( ! may_withdraw( (int) $user_id ) ) {
			continue;
		}

		$html .= '<form id="' . esc_attr( form_id( (int) $grant_id ) ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden>';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( REVOKE_ACTION ) . '">';
		$html .= '<input type="hidden" name="grant" value="' . esc_attr( (string) (int) $grant_id ) . '">';
		$html .= '<input type="hidden" name="user_id" value="' . esc_attr( (string) (int) $user_id ) . '">';
		$html .= wp_nonce_field( NONCE_PREFIX . (int) $grant_id, '_wpnonce', false, false );
		$html .= '</form>';
	}

	return $html;
}

/**
 * Whether the current user may withdraw another user's grants.
 *
 * @param int $user_id The learner.
 *
 * @return bool
 */
function may_withdraw( int $user_id ): bool {
	return current_user_can( CAPABILITY ) && current_user_can( 'edit_user', $user_id );
}

/**
 * Decide a withdrawal request. Sends nothing, so every path can be tested.
 *
 * The capability first, then the nonce, then that the grant is a grant of that learner.
 *
 * @param array $source The unslashed request fields: `grant`, `user_id` and `_wpnonce`.
 *
 * @return string `forbidden`, `expired`, `not_found`, `already` or `withdrawn`.
 */
function revoke_request( array $source ): string {
	$grant_id = isset( $source['grant'] ) && is_scalar( $source['grant'] ) ? absint( $source['grant'] ) : 0;
	$user_id  = isset( $source['user_id'] ) && is_scalar( $source['user_id'] ) ? absint( $source['user_id'] ) : 0;
	$nonce    = isset( $source['_wpnonce'] ) && is_string( $source['_wpnonce'] ) ? $source['_wpnonce'] : '';

	if ( ! $user_id || ! may_withdraw( $user_id ) ) {
		return 'forbidden';
	}

	if ( ! $grant_id || ! wp_verify_nonce( $nonce, NONCE_PREFIX . $grant_id ) ) {
		return 'expired';
	}

	if ( ! Grants\is_grant( $grant_id ) || (int) get_post_field( 'post_author', $grant_id ) !== $user_id ) {
		return 'not_found';
	}

	if ( get_post_meta( $grant_id, '_revoked_at', true ) ) {
		return 'already';
	}

	Grants\revoke( $grant_id );

	return 'withdrawn';
}

/**
 * The admin-post handler for the Withdraw button.
 *
 * @return void
 */
function handle_revoke() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- revoke_request() checks the capability, then the nonce.
	$source  = wp_unslash( $_POST );
	$outcome = revoke_request( $source );

	if ( 'forbidden' === $outcome ) {
		wp_die( esc_html__( 'You are not allowed to withdraw this connection.', 'wporg-learn' ), '', array( 'response' => 403 ) );
	}

	if ( 'expired' === $outcome ) {
		wp_die( esc_html__( 'This page has expired. Go back to the profile and try again.', 'wporg-learn' ), '', array( 'response' => 403 ) );
	}

	$user_id = absint( $source['user_id'] );

	wp_safe_redirect( add_query_arg( NOTICE_VAR, $outcome, get_edit_user_link( $user_id ) ) . '#learn-progress-connections' );
	exit;
}

/**
 * The notice after a withdrawal, on the profile screen it returns to.
 *
 * @return void
 */
function revoke_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which fixed message to show.
	$outcome = isset( $_GET[ NOTICE_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ NOTICE_VAR ] ) ) : '';

	if ( ! $screen || 'user-edit' !== $screen->id || '' === $outcome ) {
		return;
	}

	echo notice_markup( $outcome ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in notice_markup().
}

/**
 * The notice's markup for an outcome, or nothing for an unknown one.
 *
 * @param string $outcome An outcome from revoke_request().
 *
 * @return string
 */
function notice_markup( string $outcome ): string {
	$messages = array(
		'withdrawn' => array( 'success', __( 'The connection was withdrawn. The program sees it as withdrawn on its next read.', 'wporg-learn' ) ),
		'already'   => array( 'info', __( 'That connection was already withdrawn.', 'wporg-learn' ) ),
		'not_found' => array( 'error', __( 'That connection does not belong to this learner, or no longer exists.', 'wporg-learn' ) ),
	);

	if ( ! isset( $messages[ $outcome ] ) ) {
		return '';
	}

	return '<div class="notice notice-' . esc_attr( $messages[ $outcome ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $outcome ][1] ) . '</p></div>';
}
