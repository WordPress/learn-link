<?php
/**
 * The consent screen, on the local Learn site with the seeded learners.
 *
 * Needs the plugin active, the local `wpcredits` client and the seeds loaded. For the HTTP checks,
 * the site must be running and its rewrite rules flushed (`studio wp rewrite flush`). It uses its
 * own client ID, deletes every grant it creates, and prints no code.
 *
 * Run: studio wp eval-file wp-content/plugins/learn-progress-api/tests/integration-authorize.php
 *
 * @package WPOrg_Learn\Learn_Progress
 */

use WPOrg_Learn\Learn_Progress\Authorize;
use WPOrg_Learn\Learn_Progress\Clients;
use WPOrg_Learn\Learn_Progress\Grants;

require_once __DIR__ . '/lib.php';

$client_id = 'test-integration-authorize';
$uri       = 'http://localhost:1/wp-admin/admin-post.php?action=wpcpm_learn_callback';
$learner   = get_user_by( 'login', 'learnlink-learner-07' );
$course    = get_posts(
	array(
		'post_type'   => 'course',
		'numberposts' => 1,
		'fields'      => 'ids',
	)
);
$register  = function ( $clients ) use ( $client_id, $uri, $course ) {
	$clients[ $client_id ] = array(
		'name'          => 'Test program',
		'redirect_uris' => array( $uri ),
		'courses'       => array( (int) $course[0] ),
	);

	return $clients;
};
add_filter( 'learn_progress_clients', $register );

$valid = array(
	'client_id'             => $client_id,
	'redirect_uri'          => $uri,
	'state'                 => 'test-state-0123456789',
	'code_challenge'        => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
	'code_challenge_method' => 'S256',
);

ck( 'preconditions: learner 07 exists and has no special capabilities', array( (bool) $learner, user_can( $learner, 'edit_posts' ), user_can( $learner, 'manage_options' ) ), array( true, false, false ) );

/**
 * The form fields for a POST, with a fresh nonce for the current user.
 *
 * @param array  $fields   The request fields.
 * @param string $decision `approve` or `deny`.
 *
 * @return array
 */
function posted( array $fields, $decision ) {
	return array_merge(
		$fields,
		array(
			'_wpnonce' => wp_create_nonce( Authorize\NONCE_ACTION ),
			'decision' => $decision,
		)
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
function has( $haystack, $needle ) {
	return false !== strpos( $haystack, $needle );
}

/**
 * Whether a text starts with another.
 *
 * @param string $haystack The text.
 * @param string $needle   What to look for.
 *
 * @return bool
 */
function starts( $haystack, $needle ) {
	return 0 === strpos( $haystack, $needle );
}

/**
 * The query arguments of a redirect.
 *
 * @param array $response The response.
 *
 * @return array
 */
function location_args( array $response ) {
	$args = array();
	parse_str( (string) wp_parse_url( (string) $response['location'], PHP_URL_QUERY ), $args );

	return $args;
}

// SEC-09, SEC-10: a bad request is refused before anything is shown, signed in or not.
$bad = array(
	'another client'                  => array( 'client_id' => 'unknown' ),
	'the client ID in capitals'       => array( 'client_id' => strtoupper( $client_id ) ),
	'a client ID as an array'         => array( 'client_id' => array( $client_id ) ),
	'a trailing slash on the address' => array( 'redirect_uri' => $uri . '/' ),
	'another port'                    => array( 'redirect_uri' => str_replace( ':1/', ':2/', $uri ) ),
	'a foreign host'                  => array( 'redirect_uri' => 'https://evil.example/' ),
	'method plain'                    => array( 'code_challenge_method' => 'plain' ),
	'a missing challenge'             => array( 'code_challenge' => '' ),
	'a short challenge'               => array( 'code_challenge' => str_repeat( 'a', 42 ) ),
	'a challenge with a newline'      => array( 'code_challenge' => str_repeat( 'a', 43 ) . "\n" ),
	'a missing state'                 => array( 'state' => '' ),
	'a state of 257 characters'       => array( 'state' => str_repeat( 'a', 257 ) ),
	'a state as an array'             => array( 'state' => array( 'x' ) ),
);
foreach ( array( 0, $learner->ID ) as $user_id ) {
	wp_set_current_user( $user_id );
	foreach ( $bad as $label => $change ) {
		$r = Authorize\respond( 'GET', array_merge( $valid, $change ) );
		ck( "user {$user_id}: {$label} gives 400 and no redirect", array( $r['status'], $r['location'], has( $r['html'], 'This connection request is not valid.' ) ), array( 400, null, true ) );
	}
}

// I-L05: signed out, the learner goes to the login screen and back to this request.
wp_set_current_user( 0 );
$r    = Authorize\respond( 'GET', $valid );
$back = location_args( $r )['redirect_to'] ?? '';
ck( 'I-L05 signed out: a redirect to the login screen', array( $r['status'], $r['external'], starts( $r['location'], wp_login_url() ) ), array( 302, false, true ) );
$back_args = array();
parse_str( (string) wp_parse_url( $back, PHP_URL_QUERY ), $back_args );
ck( 'I-L05 signed out: login returns to the same request', $back_args, $valid );

// The form.
wp_set_current_user( $learner->ID );
$r    = Authorize\respond( 'GET', $valid );
$html = $r['html'];
ck( 'the form answers 200, with no redirect', array( $r['status'], $r['location'] ), array( 200, null ) );
$words = array(
	'Test program (localhost:1) asks to see your progress',
	esc_html( get_the_title( $course[0] ) ),
	'course progress, lesson status and quiz grades for these courses, and nothing else',
	'you and program Administrators see the full results',
	'A mentor, if you have one, sees only a one-line summary',
	'the length of the program',
	'A program Administrator can withdraw it',
	'You are signed in as @learnlink-learner-07.',
	'value="approve"',
	'value="deny"',
	'name="_wpnonce"',
);
foreach ( $words as $word ) {
	ck( "the form says: {$word}", false !== strpos( $html, $word ), true );
}
ck( 'showing the form creates no grant', Grants\for_client( $learner->ID, $client_id ), array() );

// SEC-12: POST.
$refused_nonces = array(
	'no nonce'      => '',
	'a wrong nonce' => 'not-a-nonce',
);
foreach ( $refused_nonces as $label => $nonce ) {
	$fields = array_merge( posted( $valid, 'approve' ), array( '_wpnonce' => $nonce ) );
	$r      = Authorize\respond( 'POST', $fields );
	ck( "SEC-12 POST with {$label}: 403 and no redirect", array( $r['status'], $r['location'] ), array( 403, null ) );
}
ck( 'SEC-12 no grant after a refused POST', Grants\for_client( $learner->ID, $client_id ), array() );

$r = Authorize\respond( 'POST', posted( array_merge( $valid, array( 'redirect_uri' => 'https://evil.example/' ) ), 'approve' ) );
ck( 'a POST validates again: a changed address gives 400', array( $r['status'], $r['location'] ), array( 400, null ) );

wp_set_current_user( 0 );
$r = Authorize\respond( 'POST', array_merge( $valid, array( 'decision' => 'approve' ) ) );
ck( 'a signed-out POST goes to the login screen', array( $r['status'], $r['external'] ), array( 302, false ) );
wp_set_current_user( $learner->ID );

// Deny.
$r    = Authorize\respond( 'POST', posted( $valid, 'deny' ) );
$args = location_args( $r );
ck( 'I-L05 Deny: 302 to the client with access_denied and the same state', array( $r['status'], $r['external'], $args['error'], $args['state'], $args['action'] ), array( 302, true, 'access_denied', $valid['state'], 'wpcpm_learn_callback' ) );
ck( 'I-L05 Deny: no code and no grant', array( isset( $args['code'] ), Grants\for_client( $learner->ID, $client_id ) ), array( false, array() ) );

$r = Authorize\respond( 'POST', posted( $valid, 'anything else' ) );
ck( 'an unknown decision creates no grant', array( location_args( $r )['error'], Grants\for_client( $learner->ID, $client_id ) ), array( 'access_denied', array() ) );

// A failure never redirects with an empty code.
add_filter( 'wp_insert_post_empty_content', '__return_true' );
$r = Authorize\respond( 'POST', posted( $valid, 'approve' ) );
remove_filter( 'wp_insert_post_empty_content', '__return_true' );
ck( 'a failed grant shows an error page and never redirects', array( $r['status'], $r['location'] ), array( 500, null ) );

// Approve, as a learner with no special capabilities.
$r      = Authorize\respond( 'POST', posted( $valid, 'approve' ) );
$args   = location_args( $r );
$grants = Grants\for_client( $learner->ID, $client_id );
ck( 'I-L05 Approve: 302 to the exact client address', array( $r['status'], $r['external'], starts( $r['location'], $uri . '&' ) ), array( 302, true, true ) );
ck( 'I-L05 Approve: a 43-character code and the same state', array( 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $args['code'] ), $args['state'] ), array( true, $valid['state'] ) );
ck( 'I-L05 Approve: one grant, holding the challenge and the courses', array( count( $grants ), get_post_meta( $grants[0], '_code_challenge', true ), get_post_meta( $grants[0], '_courses', true ) ), array( 1, $valid['code_challenge'], array( (int) $course[0] ) ) );

Authorize\respond( 'POST', posted( $valid, 'approve' ) );
ck( 'a second approval still leaves one grant', count( Grants\for_client( $learner->ID, $client_id ) ), 1 );

foreach ( Grants\for_client( $learner->ID, $client_id ) as $grant ) {
	Grants\delete( $grant );
}

// The real page, over HTTP, with the local client from the mu-plugin.
wp_set_current_user( 0 );
remove_filter( 'learn_progress_clients', $register );
$local = Clients\get( 'wpcredits' );

if ( ! $local ) {
	echo "skip HTTP checks: the local wpcredits client is not configured\n";
} else {
	$http_fields = array_merge(
		$valid,
		array(
			'client_id'    => 'wpcredits',
			'redirect_uri' => $local['redirect_uris'][0],
		)
	);
	$url         = add_query_arg( array_map( 'rawurlencode', $http_fields ), home_url( Authorize\PATH ) );
	$expires     = time() + 5 * MINUTE_IN_SECONDS;
	$session     = WP_Session_Tokens::get_instance( $learner->ID );
	$token       = $session->create( $expires );
	$cookies     = array( LOGGED_IN_COOKIE => wp_generate_auth_cookie( $learner->ID, $expires, 'logged_in', $token ) );
	$options     = array(
		'redirection' => 0,
		'timeout'     => 20,
	);

	$out = wp_remote_get( $url, $options );

	if ( is_wp_error( $out ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text to a terminal.
		echo 'skip HTTP checks: the site is not reachable from this process (' . $out->get_error_message() . ")\n";
	} else {
		ck( 'HTTP signed out: 302 to the login screen', array( wp_remote_retrieve_response_code( $out ), false !== strpos( wp_remote_retrieve_header( $out, 'location' ), 'wp-login.php' ) ), array( 302, true ) );

		$form = wp_remote_get( $url, $options + array( 'cookies' => $cookies ) );
		ck( 'HTTP form: 200 with the form', array( wp_remote_retrieve_response_code( $form ), false !== strpos( wp_remote_retrieve_body( $form ), 'name="_wpnonce"' ) ), array( 200, true ) );
		ck( 'HTTP form: X-Frame-Options DENY and Referrer-Policy no-referrer', array( wp_remote_retrieve_header( $form, 'x-frame-options' ), wp_remote_retrieve_header( $form, 'referrer-policy' ) ), array( 'DENY', 'no-referrer' ) );
		ck( 'HTTP form: not cacheable', false !== strpos( wp_remote_retrieve_header( $form, 'cache-control' ), 'no-store' ), true );

		$bad_request = wp_remote_get( add_query_arg( 'client_id', 'unknown', $url ), $options );
		ck( 'HTTP bad request: 400, no Location, and the frame header', array( wp_remote_retrieve_response_code( $bad_request ), wp_remote_retrieve_header( $bad_request, 'location' ), wp_remote_retrieve_header( $bad_request, 'x-frame-options' ) ), array( 400, '', 'DENY' ) );

		$no_nonce = wp_remote_post(
			home_url( Authorize\PATH ),
			$options + array(
				'cookies' => $cookies,
				'body'    => array_merge( $http_fields, array( 'decision' => 'approve' ) ),
			)
		);
		ck( 'HTTP POST without a nonce: 403', wp_remote_retrieve_response_code( $no_nonce ), 403 );
	}

	$session->destroy( $token );
	ck( 'HTTP leaves no grant for the local client', Grants\for_client( $learner->ID, 'wpcredits' ), array() );
}

// Delegation to the Phase 3 view: only when that function exists.
$without = Authorize\respond( 'GET', array( 'view' => 'connections' ) );
ck( 'view=connections without the Phase 3 view is an invalid request', $without['status'], 400 );
// phpcs:ignore Squiz.PHP.Eval.Discouraged -- A namespaced stand-in, which a file with global code cannot declare.
eval( 'namespace WPOrg_Learn\Learn_Progress\Connections; function render_view( \WP_User $user ): string { return "view of " . $user->user_login; }' );
$signed_out = Authorize\respond( 'GET', array( 'view' => 'connections' ) );
ck( 'view=connections signed out goes to the login screen', array( $signed_out['status'], $signed_out['external'] ), array( 302, false ) );
wp_set_current_user( $learner->ID );
$signed_in = Authorize\respond( 'GET', array( 'view' => 'connections' ) );
ck( 'view=connections signed in returns the view for that learner', array( $signed_in['status'], $signed_in['html'] ), array( 200, 'view of learnlink-learner-07' ) );

wp_set_current_user( 0 );

test_summary();
