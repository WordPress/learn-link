<?php
/**
 * GET /learn-progress/v1/me with a session (Phase 1), against the seeded learners on the local Learn site.
 *
 * Needs the plugin active, the local mu-plugin with the `wpcredits` client, and the seeds loaded.
 *
 * Run: studio wp eval-file wp-content/plugins/learn-progress-api/tests/integration-me.php
 *
 * @package WPOrg_Learn\Learn_Progress
 */

use WPOrg_Learn\Learn_Progress\Clients;

$GLOBALS['ck_total'] = 0;
$GLOBALS['ck_fail']  = 0;

/**
 * One check: print ok or FAIL, and count it.
 *
 * The counters live in $GLOBALS because `wp eval-file` runs this file inside a function.
 */
function ck( $label, $actual, $expected ) {
	++$GLOBALS['ck_total'];

	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text to a terminal, not HTML.
	if ( $actual === $expected ) {
		echo "ok   $label\n";
		return;
	}

	++$GLOBALS['ck_fail'];
	echo "FAIL $label\n     expected: " . var_export( $expected, true ) . "\n     actual:   " . var_export( $actual, true ) . "\n";
	// phpcs:enable
}

/**
 * Ask /me as one user, the way a real request would be answered.
 *
 * The `rest_post_dispatch` filter runs in WP_REST_Server::serve_request() for every real request,
 * but rest_do_request() stops before it, so it is applied here too.
 */
function me( $user_id, array $params = array(), array $headers = array() ) {
	wp_set_current_user( $user_id );

	$request = new WP_REST_Request( 'GET', '/learn-progress/v1/me' );
	$request->set_query_params( $params );
	foreach ( $headers as $name => $value ) {
		$request->set_header( $name, $value );
	}

	$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $request ) ), rest_get_server(), $request );
	$headers  = $response->get_headers();

	return array(
		'status'   => $response->get_status(),
		'no_store' => isset( $headers['Cache-Control'] ) && 'no-store' === $headers['Cache-Control'],
		'body'     => rest_get_server()->response_to_data( $response, false ),
	);
}

/**
 * Every path in a JSON value with the types seen there, for comparing keys and types, not values.
 */
function shape( $value, $path = '$', array &$out = array() ) {
	if ( is_array( $value ) && array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
		$out[ $path ]['object'] = true;
		foreach ( $value as $key => $item ) {
			shape( $item, $path . '.' . $key, $out );
		}
	} elseif ( is_array( $value ) ) {
		$out[ $path ]['array'] = true;
		foreach ( $value as $item ) {
			shape( $item, $path . '[]', $out );
		}
	} else {
		$out[ $path ][ gettype( $value ) ] = true;
	}

	return $out;
}

/**
 * One of the contract's example files, decoded, or null when it cannot be read.
 */
function fixture( $name ) {
	$file = dirname( __DIR__, 3 ) . '/contract/' . $name;

	return is_readable( $file ) ? json_decode( file_get_contents( $file ), true ) : null;
}

$learner_07 = get_user_by( 'login', 'learnlink-learner-07' );
$learner_08 = get_user_by( 'login', 'learnlink-learner-08' );
$client     = Clients\get( 'wpcredits' );
$whitelist  = $client ? array_map( 'intval', $client['courses'] ) : array();
$error_401  = fixture( 'example-error-401-invalid-token.json' );
$error_503  = fixture( 'example-error-503-unavailable.json' );
$example    = fixture( 'example-in-progress.json' );

ck( 'preconditions: the seeded learners 07 and 08, the local client with 2 courses, the contract files', array( (bool) $learner_07, (bool) $learner_08, count( $whitelist ), $error_401 && $error_503 && $example ), array( true, true, 2, true ) );
ck( 'the route is registered', isset( rest_get_server()->get_routes()['/learn-progress/v1/me'] ), true );

// I-L03, SEC-21: signed out.
$r = me( 0 );
ck( 'I-L03 signed out: 401 with the contract error and no-store', array( $r['status'], $r['body']['code'], $r['body']['message'], $r['no_store'] ), array( 401, $error_401['code'], $error_401['message'], true ) );

// I-L03, SEC-21: learner 07.
$r = me( $learner_07->ID );
ck( 'I-L03 learner 07: 200, the contract version, no-store', array( $r['status'], $r['body']['contract'], $r['no_store'] ), array( 200, 'learn-progress/1', true ) );
ck( 'I-L03 learner 07: the learner block is only the username, never the numeric ID', $r['body']['learner'], array( 'wporg_username' => 'learnlink-learner-07' ) );
ck( 'I-L03 learner 07: generated_at is RFC 3339 in UTC', (bool) preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $r['body']['generated_at'] ), true );
ck( "I-L03 learner 07: every whitelisted course, in the whitelist's order", wp_list_pluck( $r['body']['courses'], 'course_id' ), $whitelist );
ck( 'I-L03 learner 07: 50% and 33%', wp_list_pluck( $r['body']['courses'], 'progress_percent' ), array( 50, 33 ) );

// C-14: the same keys and types as the contract's example.
$real     = shape( $r['body'] );
$expected = shape( $example );
$extra    = array();
foreach ( $real as $key_path => $types ) {
	if ( ! isset( $expected[ $key_path ] ) || array_diff_key( $types, $expected[ $key_path ] ) ) {
		$extra[] = $key_path;
	}
}
ck( 'C-14 learner 07: the keys match example-in-progress.json', array_keys( $real ), array_keys( $expected ) );
ck( 'C-14 learner 07: every type is one the example uses at that key', $extra, array() );

// SEC-06: no user parameter, whatever is sent.
$own    = $r['body'];
$params = array(
	'user_id' => $learner_08->ID,
	'user'    => $learner_08->user_login,
	'author'  => $learner_08->ID,
);
$other  = me( $learner_07->ID, $params, array( 'X-User' => $learner_08->ID ) )['body'];
unset( $own['generated_at'], $other['generated_at'] );
ck( "SEC-06 learner 07 asking for learner 08's data gets their own", $other, $own );
ck( 'SEC-06 learner 08 gets their own username', me( $learner_08->ID )['body']['learner'], array( 'wporg_username' => 'learnlink-learner-08' ) );

// The courses parameter can only narrow (SEC-08).
$lesson = get_posts(
	array(
		'post_type'   => 'lesson',
		'numberposts' => 1,
		'fields'      => 'ids',
	)
);
ck( '?courses=<the second course> answers only that one', wp_list_pluck( me( $learner_07->ID, array( 'courses' => (string) $whitelist[1] ) )['body']['courses'], 'course_id' ), array( $whitelist[1] ) );
ck( '?courses=99999 answers the whole whitelist', wp_list_pluck( me( $learner_07->ID, array( 'courses' => '99999' ) )['body']['courses'], 'course_id' ), $whitelist );
ck( 'SEC-08 ?courses=<a post outside the whitelist> reveals nothing', wp_list_pluck( me( $learner_07->ID, array( 'courses' => (string) $lesson[0] ) )['body']['courses'], 'course_id' ), $whitelist );

// SEC-21: an error from the callback also carries no-store.
add_filter( 'learn_progress_clients', '__return_empty_array', 99 );
$r = me( $learner_07->ID );
remove_filter( 'learn_progress_clients', '__return_empty_array', 99 );
ck( 'no client configured: 503 with the contract error and no-store', array( $r['status'], $r['body']['code'], $r['body']['message'], $r['no_store'] ), array( 503, $error_503['code'], $error_503['message'], true ) );

// The no-store filter touches only learn-progress routes.
$request  = new WP_REST_Request( 'GET', '/wp/v2/types' );
$response = apply_filters( 'rest_post_dispatch', rest_do_request( $request ), rest_get_server(), $request );
ck( 'another route is left alone', isset( $response->get_headers()['Cache-Control'] ), false );

wp_set_current_user( 0 );

printf( "\n%s (%d checks)\n", $GLOBALS['ck_fail'] ? sprintf( '%d FAILURE(S)', $GLOBALS['ck_fail'] ) : 'ALL PASS', $GLOBALS['ck_total'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text to a terminal.
exit( $GLOBALS['ck_fail'] ? 1 : 0 );
