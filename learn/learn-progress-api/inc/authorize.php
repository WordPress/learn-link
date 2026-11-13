<?php
/**
 * The consent screen at /learn-link/authorize/, where a learner approves or denies sharing their progress.
 *
 * A front-end page rather than wp-admin, so a learner with no special rights can use it (contract
 * section 3, step 2). Modeled on WordPress core's authorize-application.php.
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Authorize;

use WPOrg_Learn\Learn_Progress\Clients;
use WPOrg_Learn\Learn_Progress\Grants;

defined( 'WPINC' ) || die();

const QUERY_VAR    = 'learn_link_authorize';
const NONCE_ACTION = 'learn_progress_authorize';
const PATH         = '/learn-link/authorize/';

add_action( 'init', __NAMESPACE__ . '\register_route' );
add_filter( 'query_vars', __NAMESPACE__ . '\add_query_var' );
// Before redirect_canonical (priority 10), so nothing rewrites the address before it is validated.
add_action( 'template_redirect', __NAMESPACE__ . '\maybe_render', 1 );
register_activation_hook( LEARN_PROGRESS_API_FILE, __NAMESPACE__ . '\activate' );

/**
 * Add the rewrite rule.
 *
 * @return void
 */
function register_route() {
	add_rewrite_rule( '^learn-link/authorize/?$', 'index.php?' . QUERY_VAR . '=1', 'top' );
}

/**
 * Let WordPress keep the route's query var.
 *
 * @param string[] $vars The public query vars.
 *
 * @return string[]
 */
function add_query_var( $vars ) {
	$vars[] = QUERY_VAR;

	return $vars;
}

/**
 * Add the rule, then flush. On activation `init` has already run, so the rule is not registered yet.
 *
 * @return void
 */
function activate() {
	register_route();
	flush_rewrite_rules();
}

/**
 * Answer the request when it is for this page.
 *
 * @return void
 */
function maybe_render() {
	if ( ! get_query_var( QUERY_VAR ) ) {
		return;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ? 'POST' : 'GET';
	// phpcs:ignore WordPress.Security.NonceVerification -- respond() verifies the nonce on a POST, and validates every field.
	$source = wp_unslash( 'POST' === $method ? $_POST : $_GET );

	emit( respond( $method, $source ) );
	exit;
}

/**
 * Decide the answer to a request. Sends nothing, so every path can be tested.
 *
 * @param string $method `GET` or `POST`.
 * @param array  $source The unslashed request fields.
 *
 * @return array `status`, `html`, `location`, and `external` when the location is the client's.
 */
function respond( $method, array $source ) {
	if ( 'GET' === $method && 'connections' === field( $source, 'view' ) && function_exists( 'WPOrg_Learn\Learn_Progress\Connections\render_view' ) ) {
		return respond_connections();
	}

	$request = validate_request( $source );

	if ( ! $request ) {
		return page( 400, __( 'This connection request is not valid.', 'wporg-learn' ) );
	}

	if ( ! is_user_logged_in() ) {
		return array(
			'status'   => 302,
			'html'     => '',
			'location' => wp_login_url( request_url( $request ) ),
			'external' => false,
		);
	}

	if ( 'POST' !== $method ) {
		return consent_page( $request );
	}

	if ( ! wp_verify_nonce( field( $source, '_wpnonce' ), NONCE_ACTION ) ) {
		return page( 403, __( 'This page has expired. Go back to your Student Report Card and try again.', 'wporg-learn' ) );
	}

	if ( 'approve' !== field( $source, 'decision' ) ) {
		return client_redirect(
			$request,
			array(
				'error' => 'access_denied',
				'state' => $request['state'],
			)
		);
	}

	$code = Grants\create( get_current_user_id(), $request['client_id'], published_courses( $request['client'] ), $request['code_challenge'] );

	if ( is_wp_error( $code ) ) {
		return page( 500, __( 'Your approval could not be saved. Go back to your Student Report Card and try again.', 'wporg-learn' ) );
	}

	return client_redirect(
		$request,
		array(
			'code'  => $code,
			'state' => $request['state'],
		)
	);
}

/**
 * The learner's connections view, whose content arrives in Phase 3.
 *
 * @return array The response.
 */
function respond_connections() {
	if ( ! is_user_logged_in() ) {
		return array(
			'status'   => 302,
			'html'     => '',
			'location' => wp_login_url( home_url( PATH . '?view=connections' ) ),
			'external' => false,
		);
	}

	return array(
		'status'   => 200,
		'html'     => \WPOrg_Learn\Learn_Progress\Connections\render_view( wp_get_current_user() ),
		'location' => null,
		'external' => false,
	);
}

/**
 * Check a request before anything is shown or stored.
 *
 * `redirect_uri` is compared exactly and never sanitized into a match (technical guide section 6).
 * The client ID is compared as sent for the same reason.
 *
 * @param array $source The unslashed request fields.
 *
 * @return array|null `client_id`, `client`, `redirect_uri`, `state` and `code_challenge`, or null when any check fails.
 */
function validate_request( array $source ) {
	$client_id = field( $source, 'client_id' );
	$client    = Clients\get( $client_id );
	$uri       = field( $source, 'redirect_uri' );
	$state     = field( $source, 'state' );
	$challenge = field( $source, 'code_challenge' );

	$valid = $client
		&& in_array( $uri, $client['redirect_uris'], true )
		&& 'S256' === field( $source, 'code_challenge_method' )
		&& preg_match( '/^[A-Za-z0-9_-]{43}$/D', $challenge )
		&& preg_match( '/^[A-Za-z0-9_-]{16,256}$/D', $state );

	if ( ! $valid ) {
		return null;
	}

	return array(
		'client_id'      => $client_id,
		'client'         => $client,
		'redirect_uri'   => $uri,
		'state'          => $state,
		'code_challenge' => $challenge,
	);
}

/**
 * One request field as a string. Anything else, such as `state[]=x`, counts as missing.
 *
 * @param array  $source The request fields.
 * @param string $name   The field.
 *
 * @return string
 */
function field( array $source, $name ) {
	return isset( $source[ $name ] ) && is_string( $source[ $name ] ) ? $source[ $name ] : '';
}

/**
 * The hidden fields that carry a validated request through the form.
 *
 * @param array $request The validated request.
 *
 * @return array Field names and values.
 */
function request_fields( array $request ) {
	return array(
		'client_id'             => $request['client_id'],
		'redirect_uri'          => $request['redirect_uri'],
		'state'                 => $request['state'],
		'code_challenge'        => $request['code_challenge'],
		'code_challenge_method' => 'S256',
	);
}

/**
 * This page's address for a validated request, so the learner returns to it after signing in.
 *
 * @param array $request The validated request.
 *
 * @return string
 */
function request_url( array $request ) {
	return add_query_arg( array_map( 'rawurlencode', request_fields( $request ) ), home_url( PATH ) );
}

/**
 * Send the browser back to the client.
 *
 * @param array $request The validated request.
 * @param array $args    The query arguments.
 *
 * @return array The response.
 */
function client_redirect( array $request, array $args ) {
	return array(
		'status'   => 302,
		'html'     => '',
		'location' => add_query_arg( $args, $request['redirect_uri'] ),
		'external' => true,
	);
}

/**
 * The client's courses that are published, in the client's order.
 *
 * The form names these and the grant holds these, so the learner shares exactly the courses they
 * were shown (contract section 3: "for the named courses, and nothing else").
 *
 * @param array $client The validated client.
 *
 * @return int[]
 */
function published_courses( array $client ) {
	return array_values(
		array_filter(
			$client['courses'],
			function ( $course_id ) {
				return 'publish' === get_post_status( $course_id );
			}
		)
	);
}

/**
 * The form, in the words of contract section 3, step 2.
 *
 * @param array $request The validated request.
 *
 * @return array The response.
 */
function consent_page( array $request ) {
	$uri  = wp_parse_url( $request['redirect_uri'] );
	$host = $uri['host'] . ( isset( $uri['port'] ) ? ':' . $uri['port'] : '' );
	$html = '<h1>' . esc_html__( 'Share your Learn progress?', 'wporg-learn' ) . '</h1>';

	/* translators: 1: The name of the program that asks. 2: Its web address. */
	$html .= '<p>' . esc_html( sprintf( __( '%1$s (%2$s) asks to see your progress in these courses:', 'wporg-learn' ), $request['client']['name'], $host ) ) . '</p><ul>';

	foreach ( published_courses( $request['client'] ) as $course_id ) {
		$html .= '<li>' . esc_html( get_the_title( $course_id ) ) . '</li>';
	}

	$html .= '</ul>';
	$html .= '<p>' . esc_html__( 'What it will see: course progress, lesson status and quiz grades for these courses, and nothing else.', 'wporg-learn' ) . '</p>';
	$html .= '<p>' . esc_html__( 'Who sees it: you and program Administrators see the full results. A mentor, if you have one, sees only a one-line summary.', 'wporg-learn' ) . '</p>';
	$html .= '<p>' . esc_html__( 'For how long: the length of the program. A program Administrator can withdraw it.', 'wporg-learn' ) . '</p>';

	/* translators: %s: The learner's username. */
	$html .= '<p>' . esc_html( sprintf( __( 'You are signed in as @%s.', 'wporg-learn' ), wp_get_current_user()->user_login ) ) . '</p>';
	$html .= '<form method="post" action="' . esc_url( home_url( PATH ) ) . '">';

	foreach ( request_fields( $request ) as $name => $value ) {
		$html .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
	}

	$html .= wp_nonce_field( NONCE_ACTION, '_wpnonce', false, false );
	$html .= '<button type="submit" name="decision" value="approve">' . esc_html__( 'Approve', 'wporg-learn' ) . '</button> ';
	$html .= '<button type="submit" name="decision" value="deny">' . esc_html__( 'Deny', 'wporg-learn' ) . '</button>';
	$html .= '</form>';

	return document( 200, $html );
}

/**
 * A page with one message.
 *
 * @param int    $status  The HTTP status.
 * @param string $message The message.
 *
 * @return array The response.
 */
function page( $status, $message ) {
	return document( $status, '<p>' . esc_html( $message ) . '</p>' );
}

/**
 * A whole HTML document of its own, without the theme, so no third-party script runs beside the form.
 *
 * @param int    $status The HTTP status.
 * @param string $body   The escaped body.
 *
 * @return array The response.
 */
function document( $status, $body ) {
	$html  = '<!doctype html><html ' . get_language_attributes() . '><head><meta charset="utf-8">';
	$html .= '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">';
	$html .= '<title>' . esc_html__( 'Learn WordPress', 'wporg-learn' ) . '</title>';
	$html .= '<style>body{font-family:sans-serif;line-height:1.5;max-width:36em;margin:2em auto;padding:0 1em}button{font:inherit;padding:.5em 1.5em}</style>';
	$html .= '</head><body>' . $body . '</body></html>';

	return array(
		'status'   => $status,
		'html'     => $html,
		'location' => null,
		'external' => false,
	);
}

/**
 * Send a response.
 *
 * @param array $response The response from respond().
 *
 * @return void
 */
function emit( array $response ) {
	nocache_headers();
	header( 'X-Frame-Options: DENY' );
	header( 'Referrer-Policy: no-referrer' );

	if ( $response['location'] && $response['external'] ) {
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The client's host is external by design, and validate_request() matched the whole address exactly.
		wp_redirect( $response['location'], 302 );
		return;
	}

	if ( $response['location'] ) {
		wp_safe_redirect( $response['location'], 302 );
		return;
	}

	status_header( $response['status'] );
	header( 'Content-Type: text/html; charset=utf-8' );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every piece was escaped as it was built.
	echo $response['html'];
}
