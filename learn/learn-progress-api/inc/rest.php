<?php
/**
 * The learn-progress/v1 REST routes: GET /me and DELETE /token.
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Rest;

use WPOrg_Learn\Learn_Progress\Clients;
use WPOrg_Learn\Learn_Progress\Context;
use WPOrg_Learn\Learn_Progress\Grants;
use WPOrg_Learn\Learn_Progress\Rate;
use WPOrg_Learn\Learn_Progress\Response;
use WPOrg_Learn\Learn_Progress\Tokens;

defined( 'WPINC' ) || die();

const ROUTE_NAMESPACE = 'learn-progress/v1';

/**
 * Actions and filters.
 */
add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
add_filter( 'rest_post_dispatch', __NAMESPACE__ . '\no_store', 10, 3 );

/**
 * Register the routes.
 *
 * `/me` has no user parameter: the learner is the owner of the token's grant and nobody else.
 * A `user`, `user_id` or `author` sent anyway is not in `args`, and nothing here reads it.
 *
 * `DELETE /token` uses `__return_true` as its permission callback because delete_token()
 * validates the Bearer token directly without counting toward the hourly read limit and accepts
 * grants that have already been marked as revoked.
 *
 * @return void
 */
function register_routes() {
	register_rest_route(
		ROUTE_NAMESPACE,
		'/me',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\get_me',
			'permission_callback' => __NAMESPACE__ . '\authenticate',
			'args'                => array(
				'courses' => array(
					'description' => 'Comma-separated course IDs that narrow the whitelist.',
					'type'        => 'string',
					'default'     => '',
				),
			),
		)
	);

	register_rest_route(
		ROUTE_NAMESPACE,
		'/token',
		array(
			'methods'             => 'DELETE',
			'callback'            => __NAMESPACE__ . '\delete_token',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Extract a well-formed 43-character Bearer token from the request's Authorization header.
 *
 * Falls back to HTTP_AUTHORIZATION and REDIRECT_HTTP_AUTHORIZATION for web servers that move the
 * header before PHP runs (risk R-08).
 *
 * @param \WP_REST_Request $request The request.
 *
 * @return string The 43-character token, or an empty string when missing or malformed.
 */
function bearer( \WP_REST_Request $request ): string {
	$header = (string) $request->get_header( 'authorization' );

	if ( '' === $header && isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by the strict regex below.
		$header = (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
	}

	if ( '' === $header && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by the strict regex below.
		$header = (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
	}

	return preg_match( '/^Bearer ([A-Za-z0-9_-]{43})$/D', trim( $header ), $matches ) ? $matches[1] : '';
}

/**
 * Authenticate a `GET /me` request with its Bearer token.
 *
 * Never calls wp_set_current_user() and never hooks determine_current_user (technical guide
 * section 6), so a Learn Link token grants no capability on any other route.
 *
 * @param \WP_REST_Request $request The request.
 *
 * @return true|\WP_Error
 */
function authenticate( \WP_REST_Request $request ) {
	Context\set( null );

	$grant = Tokens\find_grant_by_token( bearer( $request ) );

	if ( ! $grant ) {
		return error( 'learn_progress_invalid_token', 401 );
	}

	if ( (int) get_post_meta( $grant, '_revoked_at', true ) > 0 ) {
		return error( 'learn_progress_revoked', 403 );
	}

	$wait = Rate\hit( $grant );

	if ( $wait > 0 ) {
		return error( 'learn_progress_slow_down', 429, array( 'retry_after' => $wait ) );
	}

	Context\set( $grant );

	return true;
}

/**
 * Answer `GET /me` with the progress of the learner who owns the authenticated grant.
 *
 * @param \WP_REST_Request $request The request.
 *
 * @return \WP_REST_Response|\WP_Error
 */
function get_me( \WP_REST_Request $request ) {
	// The reader calls Sensei's public functions, so without Sensei the first call would be fatal.
	// Answer the contract's 503 instead: Learn cannot read progress right now (contract section 2).
	if ( ! class_exists( 'Sensei_Course' ) ) {
		return error( 'learn_progress_unavailable', 503 );
	}

	$grant = Context\get();

	if ( ! $grant ) {
		return error( 'learn_progress_invalid_token', 401 );
	}

	$user = get_userdata( (int) get_post_field( 'post_author', $grant ) );

	if ( ! $user ) {
		return error( 'learn_progress_invalid_token', 401 );
	}

	$client_id = (string) get_post_meta( $grant, '_client_id', true );
	$client    = Clients\get( $client_id );

	if ( ! $client || empty( $client['courses'] ) ) {
		return error( 'learn_progress_unavailable', 503 );
	}

	$granted_courses   = array_map( 'intval', (array) get_post_meta( $grant, '_courses', true ) );
	$client['courses'] = array_values( array_intersect( $client['courses'], $granted_courses ) );

	update_post_meta( $grant, '_last_used', time() );

	$body = Response\build_body( $user, $client, (string) $request->get_param( 'courses' ) );

	if ( is_wp_error( $body ) ) {
		return error( 'learn_progress_unavailable', 503 );
	}

	return rest_ensure_response( $body );
}

/**
 * Answer `DELETE /token` by deleting the grant that owns the Bearer token.
 *
 * Does not count against the hourly read limit and accepts grants that already carry `_revoked_at`
 * so a withdrawal removes the grant and answers 204 (contract section 4).
 *
 * @param \WP_REST_Request $request The request.
 *
 * @return \WP_REST_Response|\WP_Error
 */
function delete_token( \WP_REST_Request $request ) {
	$grant = Tokens\find_grant_by_token( bearer( $request ) );

	if ( ! $grant || ! Grants\delete( $grant ) ) {
		return error( 'learn_progress_invalid_token', 401 );
	}

	return new \WP_REST_Response( null, 204 );
}

/**
 * One of the contract's errors (contract section 2).
 *
 * The messages are the ones in contract/example-error-*.json. The Dashboard decides by `code` alone
 * and shows its own words, so they are not translated.
 *
 * @param string $code   The contract's error code.
 * @param int    $status The HTTP status.
 * @param array  $data   Extra error data. `status` always wins.
 *
 * @return \WP_Error
 */
function error( $code, $status, array $data = array() ) {
	$messages = array(
		'learn_progress_invalid_token'   => 'The token is not valid.',
		'learn_progress_revoked'         => 'This connection was withdrawn.',
		'learn_progress_slow_down'       => 'Too many requests. Try again later.',
		'learn_progress_unavailable'     => 'Learn cannot read course progress right now.',
		'learn_progress_invalid_grant'   => 'The code is not valid.',
		'learn_progress_invalid_client'  => 'The client is not known.',
		'learn_progress_invalid_request' => 'The request is missing a parameter or has one that is not valid.',
	);

	return new \WP_Error(
		$code,
		isset( $messages[ $code ] ) ? $messages[ $code ] : $code,
		array_merge( $data, array( 'status' => (int) $status ) )
	);
}

/**
 * Add `Cache-Control: no-store` (and `Retry-After` when `data.retry_after` is present) to every
 * learn-progress response, success or error.
 *
 * Contract section 2. Done here, after dispatch, so it also covers errors raised before the
 * callback runs: a refused permission check, an invalid parameter, or a rate-limited token exchange.
 *
 * @param \WP_HTTP_Response $result  The response.
 * @param \WP_REST_Server   $server  The server.
 * @param \WP_REST_Request  $request The request.
 *
 * @return \WP_HTTP_Response
 */
function no_store( $result, $server, $request ) {
	$route = $request->get_route();

	if ( $result instanceof \WP_HTTP_Response && ( '/' . ROUTE_NAMESPACE === $route || 0 === strpos( $route, '/' . ROUTE_NAMESPACE . '/' ) ) ) {
		$result->header( 'Cache-Control', 'no-store' );

		$data = $result->get_data();

		if ( is_array( $data ) && isset( $data['data']['retry_after'] ) && is_numeric( $data['data']['retry_after'] ) && (int) $data['data']['retry_after'] > 0 ) {
			$result->header( 'Retry-After', (string) (int) $data['data']['retry_after'] );
		}
	}

	return $result;
}
