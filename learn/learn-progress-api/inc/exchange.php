<?php
/**
 * POST /learn-progress/v1/token: exchange a one-time code and its verifier for a token, server to server.
 *
 * Contract section 3, step 3. The route lives here, apart from rest.php, because WordPress combines
 * methods registered separately on one route, and #21 edits rest.php at the same time.
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Exchange;

use WPOrg_Learn\Learn_Progress\Clients;
use WPOrg_Learn\Learn_Progress\Grants;
use WPOrg_Learn\Learn_Progress\Rest;
use WPOrg_Learn\Learn_Progress\Tokens;

defined( 'WPINC' ) || die();

const MAX_ATTEMPTS = 10;
const WINDOW       = MINUTE_IN_SECONDS;
const FORGET_HOOK  = 'learn_progress_forget_used_code';

add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
add_filter( 'rest_request_before_callbacks', __NAMESPACE__ . '\contract_error_for_bad_json', 10, 3 );
add_action( FORGET_HOOK, __NAMESPACE__ . '\forget_used_code' );

/**
 * Register the route.
 *
 * `__return_true` is deliberate: this call has no session, and the code and its verifier are what
 * authenticate it.
 *
 * @return void
 */
function register_routes() {
	register_rest_route(
		Rest\ROUTE_NAMESPACE,
		'/token',
		array(
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\post_token',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Answer a broken JSON body with the contract's error.
 *
 * WordPress refuses it with `rest_invalid_json` before the callback runs, and the Dashboard treats
 * a code it does not know as a bad answer.
 *
 * @param mixed            $response The response so far.
 * @param array            $handler  The route handler.
 * @param \WP_REST_Request $request  The request.
 *
 * @return mixed
 */
function contract_error_for_bad_json( $response, $handler, $request ) {
	$is_token_route = '/' . Rest\ROUTE_NAMESPACE . '/token' === $request->get_route();

	if ( $is_token_route && is_wp_error( $response ) && 'rest_invalid_json' === $response->get_error_code() ) {
		return Rest\error( 'learn_progress_invalid_request', 400 );
	}

	return $response;
}

/**
 * Exchange a code for a token.
 *
 * @param \WP_REST_Request $request The request.
 *
 * @return \WP_REST_Response|\WP_Error
 */
function post_token( \WP_REST_Request $request ) {
	$wait = attempt_wait();

	if ( $wait ) {
		return Rest\error( 'learn_progress_slow_down', 429, array( 'retry_after' => $wait ) );
	}

	$params = read_params( $request );

	if ( ! $params ) {
		return Rest\error( 'learn_progress_invalid_request', 400 );
	}

	// Compared as sent: Clients\get() needs the exact ID, so no sanitizing can turn one ID into another.
	if ( ! Clients\get( $params['client_id'] ) ) {
		return Rest\error( 'learn_progress_invalid_client', 401 );
	}

	$grant = Tokens\find_grant_by_code( $params['code'] );

	if ( ! $grant ) {
		delete_grant_of_reused_code( $params['code'] );

		return invalid_grant();
	}

	if ( time() > (int) get_post_meta( $grant, '_code_expires', true ) ) {
		return invalid_grant();
	}

	if ( get_post_meta( $grant, '_client_id', true ) !== $params['client_id'] ) {
		return Rest\error( 'learn_progress_invalid_client', 401 );
	}

	if ( ! Tokens\verify_pkce( $params['code_verifier'], (string) get_post_meta( $grant, '_code_challenge', true ) ) ) {
		burn_code( $grant, $params['code'] );

		return invalid_grant();
	}

	// Only one request can win this. The loser must not count as a reuse, or it would delete the winner's token.
	if ( ! burn_code( $grant, $params['code'] ) ) {
		return invalid_grant();
	}

	$user = get_userdata( (int) get_post_field( 'post_author', $grant ) );

	if ( ! $user ) {
		return invalid_grant();
	}

	$token = Tokens\mint();

	if ( ! update_post_meta( $grant, '_token_hash', Tokens\hash_token( $token ) ) ) {
		return Rest\error( 'learn_progress_unavailable', 503 );
	}

	update_post_meta( $grant, '_last_used', 0 );

	return rest_ensure_response(
		array(
			'token'   => $token,
			'learner' => array(
				'wporg_username'     => $user->user_login,
				'wporg_profile_slug' => $user->user_nicename,
			),
		)
	);
}

/**
 * The contract's error for any code that cannot be exchanged, whatever the reason.
 *
 * @return \WP_Error
 */
function invalid_grant() {
	return Rest\error( 'learn_progress_invalid_grant', 400 );
}

/**
 * The request's fields, when all of them are present and well formed.
 *
 * @param \WP_REST_Request $request The request.
 *
 * @return array|null `client_id`, `code` and `code_verifier`, or null.
 */
function read_params( \WP_REST_Request $request ) {
	$body   = $request->get_json_params();
	$fields = array();

	if ( ! is_array( $body ) ) {
		return null;
	}

	foreach ( array( 'grant_type', 'client_id', 'code', 'code_verifier' ) as $name ) {
		if ( ! isset( $body[ $name ] ) || ! is_string( $body[ $name ] ) || '' === $body[ $name ] ) {
			return null;
		}

		$fields[ $name ] = $body[ $name ];
	}

	$valid = 'authorization_code' === $fields['grant_type']
		&& preg_match( '/^[A-Za-z0-9_-]{43}$/D', $fields['code'] )
		&& preg_match( '/^[A-Za-z0-9._~-]{43,128}$/D', $fields['code_verifier'] );

	return $valid ? $fields : null;
}

/**
 * Count an attempt from this address.
 *
 * @return int Seconds to wait, or 0 when the attempt may go on.
 */
function attempt_wait() {
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key   = 'learn_progress_tok_' . md5( $ip );
	$now   = time();
	$entry = get_transient( $key );

	if ( ! is_array( $entry ) || $now - $entry['start'] >= WINDOW ) {
		$entry = array(
			'start' => $now,
			'count' => 0,
		);
	}

	$left = max( 1, WINDOW - ( $now - $entry['start'] ) );

	if ( $entry['count'] >= MAX_ATTEMPTS ) {
		return $left;
	}

	$entry['count']++;
	set_transient( $key, $entry, $left );

	return 0;
}

/**
 * Burn a code: it can never be exchanged again.
 *
 * Its hash is kept apart until the code would have expired, so a reuse can be recognized in that
 * time (Maciej's answer in #20).
 *
 * @param int    $grant The grant.
 * @param string $code  The code.
 *
 * @return bool False when another request burned it first.
 */
function burn_code( $grant, $code ) {
	if ( ! delete_post_meta( $grant, '_code_hash' ) ) {
		return false;
	}

	update_post_meta( $grant, '_code_used_hash', Tokens\hash_token( $code ) );
	wp_schedule_single_event( (int) get_post_meta( $grant, '_code_expires', true ) + 1, FORGET_HOOK, array( $grant ) );

	return true;
}

/**
 * When a used code comes back, delete its grant, and with it the token issued from that code.
 *
 * A second use is a sign the code was stolen (RFC 6749, section 4.1.2; contract section 3, step 3).
 *
 * @param string $code The code.
 *
 * @return void
 */
function delete_grant_of_reused_code( $code ) {
	$grant = Tokens\find_grant( '_code_used_hash', $code );

	if ( $grant && time() <= (int) get_post_meta( $grant, '_code_expires', true ) ) {
		Grants\delete( $grant );
	}
}

/**
 * Forget a used code's hash once the code would have expired.
 *
 * @param int $grant The grant.
 *
 * @return void
 */
function forget_used_code( $grant ) {
	delete_post_meta( (int) $grant, '_code_used_hash' );
}
