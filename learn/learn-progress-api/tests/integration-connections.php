<?php
/**
 * The learner's connections view and the withdrawal by a Learn administrator (#50), on the local
 * Learn site with the seeded learners.
 *
 * Needs the plugin active and the seeds loaded. It registers its own two clients, creates its own
 * grants, deletes every grant it creates, and never prints a token, a code or a hash.
 *
 * Run: studio wp eval-file wp-content/plugins/learn-progress-api/tests/integration-connections.php
 *
 * @package WPOrg_Learn\Learn_Progress
 */

use WPOrg_Learn\Learn_Progress\Authorize;
use WPOrg_Learn\Learn_Progress\Connections;
use WPOrg_Learn\Learn_Progress\Grants;
use WPOrg_Learn\Learn_Progress\Tokens;

require_once __DIR__ . '/lib.php';

const LL_CONNECTIONS_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

/**
 * Mint a grant and a token for a learner, as the code exchange would, without printing either.
 *
 * @param int    $user_id   The learner.
 * @param string $client_id The client.
 * @param array  $courses   The course IDs.
 *
 * @return array{grant: int, token: string}
 */
function connections_issue_grant( int $user_id, string $client_id, array $courses ): array {
	$code  = Grants\create( $user_id, $client_id, $courses, LL_CONNECTIONS_CHALLENGE );
	$grant = is_string( $code ) ? (int) Tokens\find_grant_by_code( $code ) : 0;
	$token = Tokens\mint();

	if ( $grant > 0 ) {
		delete_post_meta( $grant, '_code_hash' );
		update_post_meta( $grant, '_token_hash', Tokens\hash_token( $token ) );
		update_post_meta( $grant, '_last_used', 0 );
	}

	return array(
		'grant' => $grant,
		'token' => $token,
	);
}

/**
 * Whether a text contains another.
 *
 * @param string $haystack The text.
 * @param string $needle   What to look for.
 *
 * @return bool
 */
function connections_has( $haystack, $needle ) {
	return false !== strpos( (string) $haystack, (string) $needle );
}

/**
 * GET /learn-progress/v1/me with a bearer token.
 *
 * @param string $token The token.
 *
 * @return array `status` and `code`.
 */
function connections_read( string $token ): array {
	$request = new WP_REST_Request( 'GET', '/learn-progress/v1/me' );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$response = rest_ensure_response( rest_do_request( $request ) );
	$data     = rest_get_server()->response_to_data( $response, false );

	return array(
		'status' => $response->get_status(),
		'code'   => is_array( $data ) && isset( $data['code'] ) ? $data['code'] : null,
	);
}

wp_set_current_user( 0 );

$learner_07 = get_user_by( 'login', 'learnlink-learner-07' );
$learner_08 = get_user_by( 'login', 'learnlink-learner-08' );
$learner_09 = get_user_by( 'login', 'learnlink-learner-09' );
$admins     = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
$admin      = $admins ? $admins[0] : null;
$courses    = get_posts(
	array(
		'post_type'   => 'course',
		'post_status' => 'publish',
		'numberposts' => 2,
		'fields'      => 'ids',
	)
);

ck(
	'preconditions: learners 07, 08 and 09 exist, an administrator exists, and two published courses',
	array( (bool) $learner_07, (bool) $learner_08, (bool) $learner_09, (bool) $admin, count( $courses ) ),
	array( true, true, true, true, 2 )
);
ck(
	'preconditions: the learners cannot edit users, the administrator can',
	array( user_can( $learner_07, 'edit_users' ), user_can( $admin, 'edit_users' ) ),
	array( false, true )
);

$clients = function ( $registered ) use ( $courses ) {
	$registered['test-connections-a'] = array(
		'name'          => 'Test program Alpha',
		'redirect_uris' => array( 'http://localhost:1/alpha' ),
		'courses'       => array( (int) $courses[0], (int) $courses[1] ),
	);
	$registered['test-connections-b'] = array(
		'name'          => 'Test program Beta',
		'redirect_uris' => array( 'http://localhost:1/beta' ),
		'courses'       => array( (int) $courses[1] ),
	);

	return $registered;
};
add_filter( 'learn_progress_clients', $clients );

// Leave each learner's other grants alone: this suite counts only its own.
$before_07 = Grants\for_user( $learner_07->ID );
$before_09 = Grants\for_user( $learner_09->ID );

$alpha_07 = connections_issue_grant( $learner_07->ID, 'test-connections-a', array( (int) $courses[0], (int) $courses[1] ) );
$beta_08  = connections_issue_grant( $learner_08->ID, 'test-connections-b', array( (int) $courses[1] ) );
$created  = array( $alpha_07['grant'], $beta_08['grant'] );

ck( 'two grants were created', count( array_filter( $created ) ), 2 );

$hashes = array_filter(
	array(
		(string) get_post_meta( $alpha_07['grant'], '_token_hash', true ),
		(string) get_post_meta( $alpha_07['grant'], '_code_challenge', true ),
		(string) get_post_meta( $beta_08['grant'], '_token_hash', true ),
		$alpha_07['token'],
		$beta_08['token'],
	)
);

/**
 * Whether a page shows any secret of this suite's grants.
 *
 * @param string $html    The page.
 * @param array  $secrets The hashes, challenges and tokens.
 *
 * @return bool
 */
function connections_leaks( $html, array $secrets ) {
	foreach ( $secrets as $secret ) {
		if ( connections_has( $html, $secret ) ) {
			return true;
		}
	}

	return connections_has( $html, '_token_hash' ) || connections_has( $html, '_code_hash' );
}

echo "\n=== The view ===\n";

$view_07 = Connections\render_view( $learner_07 );
ck( 'the view is a whole document titled "Your connections"', array( connections_has( $view_07, '<!doctype html>' ), connections_has( $view_07, '<h1>Your connections</h1>' ) ), array( true, true ) );
ck( 'it names the learner', connections_has( $view_07, '@learnlink-learner-07' ), true );
ck( 'it lists the learner\'s own program, with both course titles', array( connections_has( $view_07, 'Test program Alpha' ), connections_has( $view_07, esc_html( get_the_title( $courses[0] ) ) ), connections_has( $view_07, esc_html( get_the_title( $courses[1] ) ) ) ), array( true, true, true ) );
ck( 'it says when the learner connected, and that the program has not read yet', array( connections_has( $view_07, '<dt>Connected on</dt><dd>' . wp_date( get_option( 'date_format' ) ) . '</dd>' ), connections_has( $view_07, '<dt>Last read</dt><dd>Not read yet</dd>' ) ), array( true, true ) );
ck( 'SEC-06: it never lists another learner\'s grant', connections_has( $view_07, 'Test program Beta' ), false );
ck( 'no hash, token or challenge anywhere in the view', connections_leaks( $view_07, $hashes ), false );
ck( 'the learner has no withdraw button: no form and no button at all', array( connections_has( $view_07, '<form' ), connections_has( $view_07, '<button' ) ), array( false, false ) );
ck( 'it explains that a program Administrator withdraws a connection', connections_has( $view_07, 'Only a program Administrator can withdraw a connection.' ), true );
ck( 'nothing says withdrawn while the grant is active', connections_has( $view_07, 'Withdrawn on Learn' ), false );

update_post_meta( $alpha_07['grant'], '_last_used', strtotime( '2026-11-02 10:00:00 UTC' ) );
ck( 'after a read, "Last read" carries its date', connections_has( Connections\render_view( $learner_07 ), '<dt>Last read</dt><dd>' . wp_date( get_option( 'date_format' ), strtotime( '2026-11-02 10:00:00 UTC' ) ) . '</dd>' ), true );

$view_09 = Connections\render_view( $learner_09 );
ck( 'preconditions: learner 09 holds no grant', $before_09, array() );
ck( 'a learner with no grant is told so, and no program is listed', array( connections_has( $view_09, 'You have not connected Learn to any program.' ), connections_has( $view_09, '<h2>' ) ), array( true, false ) );

$hostile_name = '<script>alert(1)</script> Program';
$xss          = function ( $registered ) use ( $hostile_name ) {
	$registered['test-connections-a']['name'] = $hostile_name;

	return $registered;
};
add_filter( 'learn_progress_clients', $xss, 20 );
ck( 'a program name is escaped as text', array( connections_has( Connections\render_view( $learner_07 ), '<script>' ), connections_has( Connections\render_view( $learner_07 ), '&lt;script&gt;' ) ), array( false, true ) );
remove_filter( 'learn_progress_clients', $xss, 20 );

echo "\n=== Through the consent screen's address ===\n";

wp_set_current_user( 0 );
$signed_out = Authorize\respond( 'GET', array( 'view' => 'connections' ) );
ck( 'signed out, view=connections goes to the login screen and back', array( $signed_out['status'], connections_has( $signed_out['location'], 'view%3Dconnections' ) ), array( 302, true ) );
wp_set_current_user( $learner_07->ID );
$signed_in = Authorize\respond( 'GET', array( 'view' => 'connections' ) );
ck( 'signed in, view=connections answers this learner\'s view', array( $signed_in['status'], connections_has( $signed_in['html'], 'Test program Alpha' ), connections_has( $signed_in['html'], 'Test program Beta' ) ), array( 200, true, false ) );

echo "\n=== The profile screen ===\n";

ck( 'the section hooks the profile of another user only, and the handler is for signed-in users only', array( (bool) has_action( 'edit_user_profile', 'WPOrg_Learn\Learn_Progress\Connections\profile_section' ), (bool) has_action( 'show_user_profile', 'WPOrg_Learn\Learn_Progress\Connections\profile_section' ), (bool) has_action( 'admin_post_' . Connections\REVOKE_ACTION ), (bool) has_action( 'admin_post_nopriv_' . Connections\REVOKE_ACTION ) ), array( true, false, true, false ) );

$GLOBALS['learn_progress_revoke_forms'] = array();
wp_set_current_user( $learner_08->ID );
ob_start();
Connections\profile_section( $learner_07 );
ck( 'SEC-19: without edit_users the section prints nothing', ob_get_clean(), '' );
ck( 'and no withdrawal form is printed', Connections\revoke_forms(), '' );

wp_set_current_user( $admin->ID );
$table = Connections\profile_table( $learner_07 );
$forms = Connections\revoke_forms();
ck( 'an administrator sees the learner\'s grant, its courses and a Withdraw button', array( connections_has( $table, 'Test program Alpha' ), connections_has( $table, esc_html( get_the_title( $courses[0] ) ) ), connections_has( $table, 'form="learn-progress-revoke-' . $alpha_07['grant'] . '"' ) ), array( true, true, true ) );
ck( 'and not another learner\'s grant', connections_has( $table, 'Test program Beta' ), false );
ck( 'the button\'s form posts to admin-post with the action, the grant, the user and a nonce for that grant', array( connections_has( $forms, 'id="learn-progress-revoke-' . $alpha_07['grant'] . '"' ), connections_has( $forms, 'value="' . Connections\REVOKE_ACTION . '"' ), connections_has( $forms, 'name="grant" value="' . $alpha_07['grant'] . '"' ), connections_has( $forms, 'name="user_id" value="' . $learner_07->ID . '"' ), (bool) preg_match( '/name="_wpnonce" value="([^"]+)"/', $forms ) ), array( true, true, true, true, true ) );
ck( 'the forms are not nested in the table, so they can live outside the profile form', connections_has( $table, '<form' ), false );
ck( 'no hash, token or challenge on the profile screen', connections_leaks( $table . $forms, $hashes ), false );
preg_match( '/name="_wpnonce" value="([^"]+)"/', $forms, $match );
ck( 'the printed nonce verifies for that grant only', array( (bool) wp_verify_nonce( $match[1], Connections\NONCE_PREFIX . $alpha_07['grant'] ), (bool) wp_verify_nonce( $match[1], Connections\NONCE_PREFIX . $beta_08['grant'] ) ), array( true, false ) );

echo "\n=== The withdrawal ===\n";

$alpha_nonce = wp_create_nonce( Connections\NONCE_PREFIX . $alpha_07['grant'] );
$valid       = array(
	'grant'    => (string) $alpha_07['grant'],
	'user_id'  => (string) $learner_07->ID,
	'_wpnonce' => $alpha_nonce,
);

wp_set_current_user( $learner_08->ID );
ck( 'SEC-19: a learner without edit_users is refused before anything else', Connections\revoke_request( $valid ), 'forbidden' );
wp_set_current_user( $learner_07->ID );
ck( 'SEC-19: the learner cannot withdraw their own grant here either', Connections\revoke_request( array_merge( $valid, array( '_wpnonce' => wp_create_nonce( Connections\NONCE_PREFIX . $alpha_07['grant'] ) ) ) ), 'forbidden' );

wp_set_current_user( $admin->ID );
ck( 'SEC-19: no nonce', Connections\revoke_request( array_diff_key( $valid, array( '_wpnonce' => true ) ) ), 'expired' );
ck( 'SEC-19: a nonce for another grant', Connections\revoke_request( array_merge( $valid, array( '_wpnonce' => wp_create_nonce( Connections\NONCE_PREFIX . $beta_08['grant'] ) ) ) ), 'expired' );
ck( 'SEC-19: a grant of another user', Connections\revoke_request( array_merge( $valid, array( 'user_id' => (string) $learner_08->ID ) ) ), 'not_found' );
ck(
	'SEC-19: a post that is not a grant',
	Connections\revoke_request(
		array(
			'grant'    => (string) $courses[0],
			'user_id'  => (string) $learner_07->ID,
			'_wpnonce' => wp_create_nonce( Connections\NONCE_PREFIX . $courses[0] ),
		)
	),
	'not_found'
);
ck( 'a refused request changes nothing', array( get_post_meta( $alpha_07['grant'], '_revoked_at', true ), get_post_meta( $beta_08['grant'], '_revoked_at', true ), get_post_type( $courses[0] ) ), array( '', '', 'course' ) );
ck( 'before the withdrawal, GET /me answers 200 for the token', connections_read( $alpha_07['token'] )['status'], 200 );

$revoked_answer = array(
	'status' => 403,
	'code'   => 'learn_progress_revoked',
);
ck( 'SEC-05: an administrator withdraws the grant', Connections\revoke_request( $valid ), 'withdrawn' );
ck( 'the grant is kept, marked with _revoked_at', array( Grants\is_grant( $alpha_07['grant'] ), (int) get_post_meta( $alpha_07['grant'], '_revoked_at', true ) > 0 ), array( true, true ) );
ck( 'SEC-05: GET /me with the old token answers 403 learn_progress_revoked', connections_read( $alpha_07['token'] ), $revoked_answer );
ck( 'the other learner\'s grant is untouched', array( get_post_meta( $beta_08['grant'], '_revoked_at', true ), connections_read( $beta_08['token'] )['status'] ), array( '', 200 ) );
ck( 'a second press is "already" and keeps the first date', array( Connections\revoke_request( $valid ), (int) get_post_meta( $alpha_07['grant'], '_revoked_at', true ) > 0 ), array( 'already', true ) );

$view_after = Connections\render_view( $learner_07 );
ck( 'the learner\'s view shows the withdrawal', connections_has( $view_after, '<dt>Withdrawn on Learn</dt>' ), true );
$GLOBALS['learn_progress_revoke_forms'] = array();

$table_after = Connections\profile_table( $learner_07 );
ck( 'the profile shows it as withdrawn, with no button and no form for it', array( connections_has( $table_after, 'Withdrawn on Learn on' ), connections_has( $table_after, '<button' ), Connections\revoke_forms() ), array( true, false, '' ) );

echo "\n=== The notice ===\n";

ck( 'each outcome the profile returns to has its notice, and an unknown one none', array( connections_has( Connections\notice_markup( 'withdrawn' ), 'notice-success' ), connections_has( Connections\notice_markup( 'already' ), 'notice-info' ), connections_has( Connections\notice_markup( 'not_found' ), 'notice-error' ), Connections\notice_markup( '<script>' ) ), array( true, true, true, '' ) );

// Clean up: only this suite's grants and filter.
foreach ( $created as $grant_id ) {
	Grants\delete( $grant_id ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- Grants\delete(), not PHP's alias of unlink().
}
remove_filter( 'learn_progress_clients', $clients );
wp_set_current_user( 0 );
$GLOBALS['learn_progress_revoke_forms'] = array();

ck( 'clean-up: learner 07 keeps exactly the grants it had before', Grants\for_user( $learner_07->ID ), $before_07 );

test_summary();
