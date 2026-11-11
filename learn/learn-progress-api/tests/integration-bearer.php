<?php
/**
 * Bearer authentication on GET /learn-progress/v1/me, DELETE /learn-progress/v1/token,
 * and the 60-reads-per-hour rate limit (P13 / ISS-LL-13).
 *
 * Needs the plugin active, the local `wpcredits` client, and the seeded learners loaded.
 * Creates its own test grants with Grants\create() and Tokens\mint(), deletes every grant
 * and transient it creates, and never prints a token or code.
 *
 * Run: studio wp eval-file wp-content/plugins/learn-progress-api/tests/integration-bearer.php
 *
 * @package WPOrg_Learn\Learn_Progress
 */

use WPOrg_Learn\Learn_Progress\Clients;
use WPOrg_Learn\Learn_Progress\Grants;
use WPOrg_Learn\Learn_Progress\Rate;
use WPOrg_Learn\Learn_Progress\Rest;
use WPOrg_Learn\Learn_Progress\Tokens;

require_once __DIR__ . '/lib.php';

const LL_BEARER_CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

/**
 * Dispatch a REST request and run `rest_post_dispatch` the way WP_REST_Server::serve_request() does.
 *
 * @param string $method  HTTP method.
 * @param string $route   REST route.
 * @param array  $params  Query parameters.
 * @param array  $headers Request headers.
 *
 * @return array `status`, `headers`, `no_store`, `retry_after` and `body`.
 */
function bearer_dispatch( string $method, string $route, array $params = array(), array $headers = array() ): array {
	$request = new WP_REST_Request( $method, $route );
	$request->set_query_params( $params );

	foreach ( $headers as $name => $value ) {
		$request->set_header( $name, $value );
	}

	$response = apply_filters(
		'rest_post_dispatch',
		rest_ensure_response( rest_do_request( $request ) ),
		rest_get_server(),
		$request
	);
	$res_headers = $response->get_headers();

	return array(
		'status'      => $response->get_status(),
		'headers'     => $res_headers,
		'no_store'    => isset( $res_headers['Cache-Control'] ) && 'no-store' === $res_headers['Cache-Control'],
		'retry_after' => isset( $res_headers['Retry-After'] ) ? (int) $res_headers['Retry-After'] : null,
		'body'        => rest_get_server()->response_to_data( $response, false ),
	);
}

/**
 * Mint a grant and token for a user and client without printing either secret.
 *
 * @param int    $user_id   Learner user ID.
 * @param string $client_id Client ID.
 * @param array  $courses   Approved course IDs.
 *
 * @return array{grant: int, token: string}
 */
function bearer_issue_grant( int $user_id, string $client_id, array $courses ): array {
	$code  = Grants\create( $user_id, $client_id, $courses, LL_BEARER_CHALLENGE );
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

wp_set_current_user( 0 );

$learner_07 = get_user_by( 'login', 'learnlink-learner-07' );
$learner_08 = get_user_by( 'login', 'learnlink-learner-08' );
$client     = Clients\get( 'wpcredits' );
$whitelist  = $client ? array_map( 'intval', $client['courses'] ) : array();

ck(
	'preconditions: learners 07 and 08 exist and the wpcredits client has 2 courses',
	array( (bool) $learner_07, (bool) $learner_08, count( $whitelist ) ),
	array( true, true, 2 )
);

$routes = rest_get_server()->get_routes();
ck(
	'GET /me and DELETE /token are registered under learn-progress/v1',
	array(
		isset( $routes['/learn-progress/v1/me'] ),
		isset( $routes['/learn-progress/v1/token'] ),
	),
	array( true, true )
);

// Contract error messages added in R2.2 for token exchange errors.
$err_grant   = Rest\error( 'learn_progress_invalid_grant', 400 );
$err_client  = Rest\error( 'learn_progress_invalid_client', 401 );
$err_request = Rest\error( 'learn_progress_invalid_request', 400 );
ck(
	'Rest\\error() carries the exact contract messages for token exchange codes',
	array(
		$err_grant->get_error_message(),
		$err_client->get_error_message(),
		$err_request->get_error_message(),
	),
	array(
		'The code is not valid.',
		'The client is not known.',
		'The request is missing a parameter or has one that is not valid.',
	)
);

// SEC-01, SEC-02, SEC-03: missing, Basic, short Bearer, and unknown 43-char token all answer 401.
$unknown_token = Tokens\mint();
$auth_failures = array(
	'missing Authorization header' => array(),
	'Basic header'                 => array( 'Authorization' => 'Basic dXNlcjpwYXNz' ),
	'short Bearer token'           => array( 'Authorization' => 'Bearer abc' ),
	'42-character Bearer token'    => array( 'Authorization' => 'Bearer ' . substr( $unknown_token, 0, 42 ) ),
	'unknown 43-character token'   => array( 'Authorization' => 'Bearer ' . $unknown_token ),
);

foreach ( $auth_failures as $label => $headers ) {
	$res = bearer_dispatch( 'GET', '/learn-progress/v1/me', array(), $headers );
	ck(
		"SEC-01/02/03 {$label}: 401 learn_progress_invalid_token and no-store",
		array( $res['status'], $res['body']['code'], $res['body']['message'], $res['no_store'] ),
		array( 401, 'learn_progress_invalid_token', 'The token is not valid.', true )
	);
}

// A logged-in WordPress session without a Bearer token no longer authenticates /me.
wp_set_current_user( $learner_07->ID );
$session_only = bearer_dispatch( 'GET', '/learn-progress/v1/me' );
wp_set_current_user( 0 );
ck(
	'a signed-in session without a Bearer token is refused with 401',
	array( $session_only['status'], $session_only['body']['code'], $session_only['no_store'] ),
	array( 401, 'learn_progress_invalid_token', true )
);

// Issue a valid grant and token for learner 07.
$issued_07 = bearer_issue_grant( (int) $learner_07->ID, 'wpcredits', $whitelist );
$grant_07  = $issued_07['grant'];
$token_07  = $issued_07['token'];
$auth_07   = array( 'Authorization' => 'Bearer ' . $token_07 );

ck( 'a test grant is created and _last_used starts at 0', array( $grant_07 > 0, (int) get_post_meta( $grant_07, '_last_used', true ) ), array( true, 0 ) );

// I-L07: valid token returns 200 with learner 07's data and updates _last_used.
$res_07 = bearer_dispatch( 'GET', '/learn-progress/v1/me', array(), $auth_07 );
ck(
	'I-L07 valid Bearer token: 200, contract learn-progress/1, no-store',
	array( $res_07['status'], $res_07['body']['contract'], $res_07['no_store'] ),
	array( 200, 'learn-progress/1', true )
);
ck(
	'I-L07 valid Bearer token: returns the grant owner username and profile slug',
	$res_07['body']['learner'],
	array(
		'wporg_username'     => 'learnlink-learner-07',
		'wporg_profile_slug' => $learner_07->user_nicename,
	)
);
ck(
	'I-L07 valid Bearer token: returns the whitelisted courses in order',
	wp_list_pluck( $res_07['body']['courses'], 'course_id' ),
	$whitelist
);
ck(
	'I-L07 valid Bearer token: updates _last_used on the grant',
	(int) get_post_meta( $grant_07, '_last_used', true ) > 0,
	true
);

// R-08: fallback to REDIRECT_HTTP_AUTHORIZATION when the server moves the header.
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $token_07;
$redirect_header_res                    = bearer_dispatch( 'GET', '/learn-progress/v1/me' );
unset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
ck(
	'R-08 REDIRECT_HTTP_AUTHORIZATION fallback authenticates when request header is absent',
	array( $redirect_header_res['status'], $redirect_header_res['body']['learner']['wporg_username'] ),
	array( 200, 'learnlink-learner-07' )
);

// SEC-06: token of learner 07 with ?user_id=<learner 08> still returns learner 07's data.
$res_sec06 = bearer_dispatch(
	'GET',
	'/learn-progress/v1/me',
	array(
		'user_id' => $learner_08->ID,
		'user'    => $learner_08->user_login,
		'author'  => $learner_08->ID,
	),
	$auth_07
);
ck(
	'SEC-06 token for learner 07 with ?user_id=learner_08 still returns learner 07',
	$res_sec06['body']['learner']['wporg_username'],
	'learnlink-learner-07'
);

// SEC-07: a valid Learn Link token does not authenticate /wp/v2/users/me or set current user.
wp_set_current_user( 0 );
$wp_users_me = bearer_dispatch( 'GET', '/wp/v2/users/me', array(), $auth_07 );
ck(
	'SEC-07 valid Learn Link token on /wp/v2/users/me answers 401 and leaves current user 0',
	array( $wp_users_me['status'], get_current_user_id() ),
	array( 401, 0 )
);

// SEC-08: ?courses=<outside whitelist> is ignored; narrowing to one course returns only that course.
$narrowed_res = bearer_dispatch( 'GET', '/learn-progress/v1/me', array( 'courses' => (string) $whitelist[1] ), $auth_07 );
$ignored_res  = bearer_dispatch( 'GET', '/learn-progress/v1/me', array( 'courses' => '999999' ), $auth_07 );
ck(
	'SEC-08 ?courses narrows to a whitelisted course and ignores unknown IDs',
	array(
		wp_list_pluck( $narrowed_res['body']['courses'], 'course_id' ),
		wp_list_pluck( $ignored_res['body']['courses'], 'course_id' ),
	),
	array(
		array( $whitelist[1] ),
		$whitelist,
	)
);

// Whitelist intersection: if the client whitelist is reduced after grant approval, only common courses are returned.
$narrow_client_filter = function ( $clients ) use ( $whitelist ) {
	$clients['wpcredits']['courses'] = array( $whitelist[1] );
	return $clients;
};
add_filter( 'learn_progress_clients', $narrow_client_filter, 99 );
$intersect_res = bearer_dispatch( 'GET', '/learn-progress/v1/me', array(), $auth_07 );
remove_filter( 'learn_progress_clients', $narrow_client_filter, 99 );
ck(
	'a client whitelist reduced after approval answers only the courses in common',
	wp_list_pluck( $intersect_res['body']['courses'], 'course_id' ),
	array( $whitelist[1] )
);

// Also if the grant itself only approved one course, only that course is returned.
update_post_meta( $grant_07, '_courses', array( $whitelist[0] ) );
$grant_subset_res = bearer_dispatch( 'GET', '/learn-progress/v1/me', array(), $auth_07 );
update_post_meta( $grant_07, '_courses', $whitelist );
ck(
	'a grant with a subset of courses answers only the courses approved in that grant',
	wp_list_pluck( $grant_subset_res['body']['courses'], 'course_id' ),
	array( $whitelist[0] )
);

// SEC-16: 60 reads succeed in one hour; the 61st read answers 429 learn_progress_slow_down with Retry-After > 0.
Rate\reset( $grant_07 );
$statuses_60 = array();
for ( $i = 1; $i <= Rate\MAX_READS; $i++ ) {
	$statuses_60[] = bearer_dispatch( 'GET', '/learn-progress/v1/me', array( 'courses' => (string) $whitelist[0] ), $auth_07 )['status'];
}
$read_61 = bearer_dispatch( 'GET', '/learn-progress/v1/me', array( 'courses' => (string) $whitelist[0] ), $auth_07 );
ck(
	'SEC-16 first 60 reads in the hour all answer 200',
	array_unique( $statuses_60 ),
	array( 200 )
);
ck(
	'SEC-16 61st read answers 429 learn_progress_slow_down with Retry-After header and no-store',
	array(
		$read_61['status'],
		$read_61['body']['code'],
		$read_61['body']['message'],
		$read_61['no_store'],
		is_int( $read_61['retry_after'] ) && $read_61['retry_after'] > 0 && $read_61['retry_after'] <= Rate\WINDOW,
		isset( $read_61['body']['data']['retry_after'] ) && $read_61['body']['data']['retry_after'] === $read_61['retry_after'],
	),
	array(
		429,
		'learn_progress_slow_down',
		'Too many requests. Try again later.',
		true,
		true,
		true,
	)
);
Rate\reset( $grant_07 );

// SEC-05: a grant with _revoked_at answers 403 learn_progress_revoked on GET /me.
Grants\revoke( $grant_07 );
$revoked_read = bearer_dispatch( 'GET', '/learn-progress/v1/me', array(), $auth_07 );
ck(
	'SEC-05 a revoked grant answers 403 learn_progress_revoked with no-store',
	array( $revoked_read['status'], $revoked_read['body']['code'], $revoked_read['body']['message'], $revoked_read['no_store'] ),
	array( 403, 'learn_progress_revoked', 'This connection was withdrawn.', true )
);

// SEC-04: DELETE /token on a revoked grant still deletes the grant and answers 204.
$del_revoked = bearer_dispatch( 'DELETE', '/learn-progress/v1/token', array(), $auth_07 );
ck(
	'SEC-04 DELETE /token on a revoked grant answers 204 with no-store and removes the grant',
	array( $del_revoked['status'], $del_revoked['no_store'], Tokens\find_grant_by_token( $token_07 ) ),
	array( 204, true, null )
);

// SEC-04: active grant -> DELETE /token (even when rate limit is full) -> 204; subsequent GET /me -> 401; repeated DELETE -> 401.
$issued_del = bearer_issue_grant( (int) $learner_07->ID, 'wpcredits', $whitelist );
$grant_del  = $issued_del['grant'];
$token_del  = $issued_del['token'];
$auth_del   = array( 'Authorization' => 'Bearer ' . $token_del );

set_transient(
	Rate\TRANSIENT_PREFIX . $grant_del,
	array(
		'start' => time(),
		'count' => Rate\MAX_READS,
	),
	Rate\WINDOW
);

$first_delete  = bearer_dispatch( 'DELETE', '/learn-progress/v1/token', array(), $auth_del );
$read_after    = bearer_dispatch( 'GET', '/learn-progress/v1/me', array(), $auth_del );
$second_delete = bearer_dispatch( 'DELETE', '/learn-progress/v1/token', array(), $auth_del );
Rate\reset( $grant_del );

ck(
	'SEC-04 DELETE /token answers 204 even when the hourly read limit is exhausted',
	array( $first_delete['status'], $first_delete['no_store'] ),
	array( 204, true )
);
ck(
	'SEC-04 a read after DELETE /token answers 401 learn_progress_invalid_token',
	array( $read_after['status'], $read_after['body']['code'], $read_after['no_store'] ),
	array( 401, 'learn_progress_invalid_token', true )
);
ck(
	'SEC-04 a repeated DELETE /token answers 401 learn_progress_invalid_token',
	array( $second_delete['status'], $second_delete['body']['code'], $second_delete['no_store'] ),
	array( 401, 'learn_progress_invalid_token', true )
);

// Simulated reader / client failure -> 503 learn_progress_unavailable with no-store.
$issued_503 = bearer_issue_grant( (int) $learner_07->ID, 'wpcredits', $whitelist );
$grant_503  = $issued_503['grant'];
$token_503  = $issued_503['token'];
$auth_503   = array( 'Authorization' => 'Bearer ' . $token_503 );

add_filter( 'learn_progress_clients', '__return_empty_array', 99 );
$res_503 = bearer_dispatch( 'GET', '/learn-progress/v1/me', array(), $auth_503 );
remove_filter( 'learn_progress_clients', '__return_empty_array', 99 );

Grants\delete( $grant_503 );
Rate\reset( $grant_503 );

ck(
	'unavailable client or reader answers 503 learn_progress_unavailable with no-store',
	array( $res_503['status'], $res_503['body']['code'], $res_503['body']['message'], $res_503['no_store'] ),
	array( 503, 'learn_progress_unavailable', 'Learn cannot read course progress right now.', true )
);

wp_set_current_user( 0 );

test_summary();
