<?php
/**
 * The learn-progress/v1 REST routes.
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Rest;

use WPOrg_Learn\Learn_Progress\Clients;
use WPOrg_Learn\Learn_Progress\Response;

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
 * `/me` has no user parameter: the learner is the authenticated one and nobody else. A `user`,
 * `user_id` or `author` sent anyway is not in `args`, and nothing here reads it.
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
}

/**
 * Let the request through only for a signed-in learner.
 *
 * Phase 1 only: the signed-in browser (cookie and `wp_rest` nonce), on a local site. Phase 2
 * replaces this with the Bearer token (P13); never deploy this version.
 *
 * @param \WP_REST_Request $request The request.
 *
 * @return true|\WP_Error
 */
function authenticate( \WP_REST_Request $request ) {
	return is_user_logged_in() ? true : error( 'learn_progress_invalid_token', 401 );
}

/**
 * Answer `GET /me` with the signed-in learner's progress.
 *
 * @param \WP_REST_Request $request The request.
 *
 * @return \WP_REST_Response|\WP_Error
 */
function get_me( \WP_REST_Request $request ) {
	// Phase 1: the one local client. Phase 2: the client of the token's grant (P13).
	$client = Clients\get( 'wpcredits' );

	if ( ! $client || empty( $client['courses'] ) ) {
		return error( 'learn_progress_unavailable', 503 );
	}

	$body = Response\build_body( wp_get_current_user(), $client, (string) $request->get_param( 'courses' ) );

	if ( is_wp_error( $body ) ) {
		return error( 'learn_progress_unavailable', 503 );
	}

	return rest_ensure_response( $body );
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
		'learn_progress_invalid_token' => 'The token is not valid.',
		'learn_progress_revoked'       => 'This connection was withdrawn.',
		'learn_progress_slow_down'     => 'Too many requests. Try again later.',
		'learn_progress_unavailable'   => 'Learn cannot read course progress right now.',
	);

	return new \WP_Error(
		$code,
		isset( $messages[ $code ] ) ? $messages[ $code ] : $code,
		array_merge( $data, array( 'status' => (int) $status ) )
	);
}

/**
 * Add `Cache-Control: no-store` to every learn-progress response, success or error.
 *
 * Contract section 2. Done here, after dispatch, so it also covers errors raised before the
 * callback runs: a refused permission check, an invalid parameter, a bad nonce.
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
	}

	return $result;
}
