<?php
/**
 * POST /learn-progress/v1/token, on the local Learn site with the seeded learners.
 *
 * Needs the plugin active, the local `wpcredits` client and the seeds loaded. For the HTTP checks,
 * the site must be running. It deletes every grant it creates, and prints no token or code.
 *
 * Run: studio wp eval-file wp-content/plugins/learn-progress-api/tests/integration-token.php
 *
 * @package WPOrg_Learn\Learn_Progress
 */

use WPOrg_Learn\Learn_Progress\Clients;
use WPOrg_Learn\Learn_Progress\Exchange;
use WPOrg_Learn\Learn_Progress\Grants;
use WPOrg_Learn\Learn_Progress\Tokens;

require_once __DIR__ . '/lib.php';

const LL_TEST_CLIENT    = 'test-integration-token';
const LL_OTHER_CLIENT   = 'test-integration-token-2';
const LL_TEST_VERIFIER  = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
const LL_TEST_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
const LL_TEST_ADDRESS   = '203.0.113.7';

$learner = get_user_by( 'login', 'learnlink-learner-07' );
$course  = 12;

// `wp eval-file` runs this file inside a function, so new_grant() reads the learner from here.
$GLOBALS['ll_test_learner_id'] = $learner ? (int) $learner->ID : 0;

$register = function ( $clients ) use ( $course ) {
	foreach ( array( LL_TEST_CLIENT, LL_OTHER_CLIENT ) as $id ) {
		$clients[ $id ] = array(
			'name'          => 'Test program',
			'redirect_uris' => array( 'http://localhost:1/callback' ),
			'courses'       => array( $course ),
		);
	}

	return $clients;
};
add_filter( 'learn_progress_clients', $register );

$_SERVER['REMOTE_ADDR'] = LL_TEST_ADDRESS;

ck( 'preconditions: learner 07 exists, and the local rate counter is clear', array( (bool) $learner, reset_attempts() ), array( true, true ) );

/**
 * Clear the rate counter of the test address.
 *
 * @return bool
 */
function reset_attempts() {
	delete_transient( 'learn_progress_tok_' . md5( LL_TEST_ADDRESS ) );

	return true;
}

/**
 * Send a raw body to the route, the way a real request would be answered.
 *
 * @param string $raw          The body.
 * @param string $content_type The content type.
 *
 * @return array `status`, `code`, `data` and `no_store`.
 */
function post_raw( $raw, $content_type = 'application/json' ) {
	$request = new WP_REST_Request( 'POST', '/learn-progress/v1/token' );
	$request->set_header( 'Content-Type', $content_type );
	$request->set_body( $raw );

	$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $request ) ), rest_get_server(), $request );
	$headers  = $response->get_headers();
	$data     = rest_get_server()->response_to_data( $response, false );

	return array(
		'status'   => $response->get_status(),
		'code'     => isset( $data['code'] ) ? $data['code'] : null,
		'data'     => $data,
		'no_store' => isset( $headers['Cache-Control'] ) && 'no-store' === $headers['Cache-Control'],
	);
}

/**
 * Exchange a code, with the rate counter cleared first.
 *
 * @param string $code   The code.
 * @param array  $change Fields to change or add.
 *
 * @return array The response.
 */
function exchange( $code, array $change = array() ) {
	reset_attempts();

	return post_raw( wp_json_encode( exchange_fields( $code, $change ) ) );
}

/**
 * The fields of a valid exchange.
 *
 * @param string $code   The code.
 * @param array  $change Fields to change or add.
 *
 * @return array
 */
function exchange_fields( $code, array $change = array() ) {
	return array_merge(
		array(
			'grant_type'    => 'authorization_code',
			'client_id'     => LL_TEST_CLIENT,
			'code'          => $code,
			'code_verifier' => LL_TEST_VERIFIER,
		),
		$change
	);
}

/**
 * A new grant for learner 07.
 *
 * @param string $client_id The client.
 *
 * @return array The code and the grant ID.
 */
function new_grant( $client_id = LL_TEST_CLIENT ) {
	$code = Grants\create( $GLOBALS['ll_test_learner_id'], $client_id, array( 12 ), LL_TEST_CHALLENGE );

	return array( $code, Tokens\find_grant_by_code( $code ) );
}

/**
 * Whether a value has the shape of a token.
 *
 * @param mixed $value The value.
 *
 * @return bool
 */
function is_token_shaped( $value ) {
	return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $value );
}

$invalid_grant = array( 400, 'learn_progress_invalid_grant', true );
$invalid_client = array( 401, 'learn_progress_invalid_client', true );

/**
 * The parts of a response that the error checks compare.
 *
 * @param array $response The response.
 *
 * @return array
 */
function outcome( array $response ) {
	return array( $response['status'], $response['code'], $response['no_store'] );
}

// I-L06: a correct exchange.
list( $code, $grant ) = new_grant();
$r                    = exchange( $code );
$token                = isset( $r['data']['token'] ) ? $r['data']['token'] : null;
ck( 'I-L06 a correct exchange answers 200 with no-store', array( $r['status'], $r['no_store'] ), array( 200, true ) );
ck( 'I-L06 the token has 43 characters', is_token_shaped( $token ), true );
ck(
	'I-L06 the learner block has the username and the profile slug',
	$r['data']['learner'],
	array(
		'wporg_username'     => 'learnlink-learner-07',
		'wporg_profile_slug' => $learner->user_nicename,
	)
);
ck( 'I-L06 the body has only the token and the learner', array_keys( $r['data'] ), array( 'token', 'learner' ) );
ck( 'I-L06 the code is burned', get_post_meta( $grant, '_code_hash', true ), '' );
ck( 'I-L06 only the keyed hash of the token is stored', get_post_meta( $grant, '_token_hash', true ), Tokens\hash_token( $token ) );
ck( 'I-L06 the token finds its grant, and _last_used is 0', array( Tokens\find_grant_by_token( $token ), (int) get_post_meta( $grant, '_last_used', true ) ), array( $grant, 0 ) );
ck( 'the used code is remembered by its hash', get_post_meta( $grant, '_code_used_hash', true ), Tokens\hash_token( $code ) );
ck( 'forgetting it is scheduled for when the code would have expired', (bool) wp_next_scheduled( Exchange\FORGET_HOOK, array( $grant ) ), true );

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A test that must read the raw table.
$stored = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s OR meta_value LIKE %s", '%' . $wpdb->esc_like( $token ) . '%', '%' . $wpdb->esc_like( $code ) . '%' ) );
ck( 'no stored value contains the token or the code', $stored, 0 );

// SEC-11: a used code that comes back deletes the grant, and with it the token.
ck( 'SEC-11 the same code again: invalid_grant', outcome( exchange( $code ) ), $invalid_grant );
ck( 'SEC-11 the grant is deleted', Grants\is_grant( $grant ), false );
ck( 'SEC-11 the token no longer finds anything', Tokens\find_grant_by_token( $token ), null );

// The hash of a used code lasts only for the code's 10 minutes.
list( $code, $grant ) = new_grant();
$token                = exchange( $code )['data']['token'];
update_post_meta( $grant, '_code_expires', time() - 1 );
ck( 'a used code after its 10 minutes: invalid_grant', outcome( exchange( $code ) ), $invalid_grant );
ck( 'a used code after its 10 minutes leaves the grant and the token', Tokens\find_grant_by_token( $token ), $grant );
do_action( Exchange\FORGET_HOOK, $grant );
ck( 'the scheduled event forgets the hash', get_post_meta( $grant, '_code_used_hash', true ), '' );
Grants\delete( $grant );

// I-L06: a wrong verifier burns the code.
list( $code, $grant ) = new_grant();
$other_verifier       = str_repeat( 'a', 43 );
ck( 'I-L06 a wrong verifier: invalid_grant', outcome( exchange( $code, array( 'code_verifier' => $other_verifier ) ) ), $invalid_grant );
ck( 'I-L06 a wrong verifier burns the code, and issues no token', array( get_post_meta( $grant, '_code_hash', true ), get_post_meta( $grant, '_token_hash', true ) ), array( '', '' ) );
ck( 'the right verifier afterwards: invalid_grant', outcome( exchange( $code ) ), $invalid_grant );
Grants\delete( $grant );

// I-L06: an expired code.
list( $code, $grant ) = new_grant();
update_post_meta( $grant, '_code_expires', time() - 1 );
ck( 'I-L06 an expired code: invalid_grant', outcome( exchange( $code ) ), $invalid_grant );
ck( 'I-L06 an expired code issues no token', get_post_meta( $grant, '_token_hash', true ), '' );
Grants\delete( $grant );

// I-L06: clients.
list( $code, $grant ) = new_grant();
ck( 'I-L06 an unknown client: invalid_client', outcome( exchange( $code, array( 'client_id' => 'unknown' ) ) ), $invalid_client );
ck( 'the client ID in capitals: invalid_client', outcome( exchange( $code, array( 'client_id' => strtoupper( LL_TEST_CLIENT ) ) ) ), $invalid_client );
ck( 'I-L06 another registered client: invalid_client', outcome( exchange( $code, array( 'client_id' => LL_OTHER_CLIENT ) ) ), $invalid_client );
ck( 'a refused client does not burn the code', Tokens\find_grant_by_code( $code ), $grant );
Grants\delete( $grant );

ck( 'a well-formed code nobody issued: invalid_grant', outcome( exchange( Tokens\mint() ) ), $invalid_grant );

// I-L06: malformed requests.
$invalid_request = array( 400, 'learn_progress_invalid_request', true );
$fields          = exchange_fields( Tokens\mint() );
reset_attempts();
ck( 'I-L06 a form body: invalid_request', outcome( post_raw( http_build_query( $fields ), 'application/x-www-form-urlencoded' ) ), $invalid_request );
ck( 'broken JSON: invalid_request, not WordPress\'s own code', outcome( post_raw( '{"grant_type":' ) ), $invalid_request );
ck( 'a JSON value that is not an object: invalid_request', outcome( post_raw( '"text"' ) ), $invalid_request );
ck( 'an empty body: invalid_request', outcome( post_raw( '' ) ), $invalid_request );

$malformed = array(
	'another grant_type'      => array( 'grant_type' => 'client_credentials' ),
	'a code of 42 characters' => array( 'code' => str_repeat( 'a', 42 ) ),
	'a code with a newline'   => array( 'code' => str_repeat( 'a', 43 ) . "\n" ),
	'a short verifier'        => array( 'code_verifier' => str_repeat( 'a', 42 ) ),
	'a long verifier'         => array( 'code_verifier' => str_repeat( 'a', 129 ) ),
	'a verifier with a plus'  => array( 'code_verifier' => str_repeat( 'a', 42 ) . '+' ),
	'a code as an array'      => array( 'code' => array( 'x' ) ),
	'a client ID as a number' => array( 'client_id' => 7 ),
);
foreach ( $malformed as $label => $change ) {
	reset_attempts();
	ck( "{$label}: invalid_request", outcome( post_raw( wp_json_encode( array_merge( $fields, $change ) ) ) ), $invalid_request );
}
foreach ( array( 'grant_type', 'client_id', 'code', 'code_verifier' ) as $name ) {
	$missing = $fields;
	unset( $missing[ $name ] );
	reset_attempts();
	ck( "a missing {$name}: invalid_request", outcome( post_raw( wp_json_encode( $missing ) ) ), $invalid_request );
}

// Two requests at once: whoever finds the code already burned loses, and takes nothing with it.
list( $code, $grant ) = new_grant();
$lost_race            = function ( $check, $object_id, $meta_key ) {
	return '_code_hash' === $meta_key ? false : $check;
};
add_filter( 'delete_post_metadata', $lost_race, 10, 3 );
$r = exchange( $code );
remove_filter( 'delete_post_metadata', $lost_race, 10 );
ck( 'the loser of a race: invalid_grant', outcome( $r ), $invalid_grant );
ck( 'the loser issues no token and records no reuse', array( get_post_meta( $grant, '_token_hash', true ), get_post_meta( $grant, '_code_used_hash', true ), Grants\is_grant( $grant ) ), array( '', '', true ) );
Grants\delete( $grant );

// A token that cannot be stored must not be handed out.
list( $code, $grant ) = new_grant();
$refuse_token         = function ( $check, $object_id, $meta_key ) {
	return '_token_hash' === $meta_key ? false : $check;
};
add_filter( 'add_post_metadata', $refuse_token, 10, 3 );
$r = exchange( $code );
remove_filter( 'add_post_metadata', $refuse_token, 10 );
ck( 'a token that cannot be stored: 503 and no token', array( $r['status'], $r['code'], isset( $r['data']['token'] ) ), array( 503, 'learn_progress_unavailable', false ) );
Grants\delete( $grant );

// A grant whose author is gone answers for nobody.
list( $code, $grant ) = new_grant();
wp_update_post(
	array(
		'ID'          => $grant,
		'post_author' => 999999,
	)
);
ck( 'a grant with no user: invalid_grant', outcome( exchange( $code ) ), $invalid_grant );
Grants\delete( $grant );

// SEC-16: a class connecting at once shares the Dashboard's address, so good exchanges never count.
reset_attempts();
$statuses = array();
for ( $i = 0; $i < 12; $i++ ) {
	list( $code, $grant ) = new_grant();
	$statuses[]           = post_raw( wp_json_encode( exchange_fields( $code ) ) )['status'];
	Grants\delete( $grant );
}
ck( 'SEC-16 twelve good exchanges from one address in a minute: twelve 200s, no 429', $statuses, array_fill( 0, 12, 200 ) );

// A 503 is Learn's failure, not the client's, so it does not count either.
list( $code, $grant ) = new_grant();
add_filter( 'add_post_metadata', $refuse_token, 10, 3 );
$r = post_raw( wp_json_encode( exchange_fields( $code ) ) );
remove_filter( 'add_post_metadata', $refuse_token, 10 );
Grants\delete( $grant );
ck( 'SEC-16 a 503 is answered and leaves the budget alone', array( $r['status'], (int) ( get_transient( 'learn_progress_tok_' . md5( LL_TEST_ADDRESS ) )['count'] ?? 0 ) ), array( 503, 0 ) );

// SEC-16: eleven failed exchanges in a minute from one address, after the good ones above.
$body  = wp_json_encode( exchange_fields( Tokens\mint() ) );
$codes = array();
for ( $i = 0; $i < 10; $i++ ) {
	$codes[] = post_raw( $body )['code'];
}
$r = post_raw( $body );
ck( 'SEC-16 ten failed exchanges are answered, after twelve good ones', array_unique( $codes ), array( 'learn_progress_invalid_grant' ) );
ck( 'SEC-16 the eleventh: 429 with no-store', array( $r['status'], $r['code'], $r['no_store'] ), array( 429, 'learn_progress_slow_down', true ) );
ck( 'SEC-16 the eleventh says how long to wait', $r['data']['data']['retry_after'] > 0, true );
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
ck( 'SEC-16 another address is not limited', post_raw( $body )['code'], 'learn_progress_invalid_grant' );
delete_transient( 'learn_progress_tok_' . md5( '203.0.113.8' ) );
$_SERVER['REMOTE_ADDR'] = LL_TEST_ADDRESS;
reset_attempts();

// The real route, over HTTP, with the local client from the mu-plugin.
remove_filter( 'learn_progress_clients', $register );
$local = Clients\get( 'wpcredits' );

if ( ! $local ) {
	echo "skip HTTP checks: the local wpcredits client is not configured\n";
} else {
	list( $code, $grant ) = new_grant( 'wpcredits' );

	$options = array(
		'timeout' => 20,
		'headers' => array( 'Content-Type' => 'application/json' ),
	);
	$http    = wp_remote_post( rest_url( 'learn-progress/v1/token' ), $options + array( 'body' => wp_json_encode( exchange_fields( $code, array( 'client_id' => 'wpcredits' ) ) ) ) );

	if ( is_wp_error( $http ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text to a terminal.
		echo 'skip HTTP checks: the site is not reachable from this process (' . $http->get_error_message() . ")\n";
	} else {
		$data = json_decode( wp_remote_retrieve_body( $http ), true );
		ck( 'HTTP a correct exchange: 200 and a token', array( wp_remote_retrieve_response_code( $http ), is_token_shaped( $data['token'] ) ), array( 200, true ) );
		ck( 'HTTP the answer has no-store', has_no_store( wp_remote_retrieve_header( $http, 'cache-control' ) ), true );

		$broken = wp_remote_post( rest_url( 'learn-progress/v1/token' ), $options + array( 'body' => '{"grant_type":' ) );
		if ( is_wp_error( $broken ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text to a terminal.
			echo 'HTTP broken JSON got no answer: ' . $broken->get_error_message() . "\n";
		}
		$data   = (array) json_decode( wp_remote_retrieve_body( $broken ), true ) + array( 'code' => null );
		ck( 'HTTP broken JSON: the contract\'s error, with no-store', array( wp_remote_retrieve_response_code( $broken ), $data['code'], has_no_store( wp_remote_retrieve_header( $broken, 'cache-control' ) ) ), array( 400, 'learn_progress_invalid_request', true ) );
	}

	Grants\delete( $grant );
}

/**
 * Whether a Cache-Control value includes no-store.
 *
 * @param string $value The header.
 *
 * @return bool
 */
function has_no_store( $value ) {
	return false !== strpos( (string) $value, 'no-store' );
}

// Nothing stays behind: no scheduled event from this run, and no grant.
wp_unschedule_hook( Exchange\FORGET_HOOK );
foreach ( array( LL_TEST_CLIENT, LL_OTHER_CLIENT ) as $test_client ) {
	foreach ( Grants\for_client( $learner->ID, $test_client ) as $leftover ) {
		Grants\delete( $leftover );
	}
}

test_summary();
