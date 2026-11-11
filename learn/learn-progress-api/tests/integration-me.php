<?php
/**
 * GET /learn-progress/v1/me with a session (Phase 1), against the seeded learners on the local Learn site.
 *
 * Needs the plugin active, the local mu-plugin with the `wpcredits` client, and the seeds loaded.
 *
 * Run: studio wp eval-file wp-content/plugins/learn-progress-api/tests/integration-me.php
 *
 * Exit status: 0 all pass, 1 a check failed, 2 skipped because the contract files were not found
 * (see contract_dir()).
 *
 * @package WPOrg_Learn\Learn_Progress
 */

use WPOrg_Learn\Learn_Progress\Clients;
use WPOrg_Learn\Learn_Progress\Response;

// The shared ck() and test_summary() (#13). Its counters live in $GLOBALS, so they work although
// `wp eval-file` runs this file inside a function.
require_once __DIR__ . '/lib.php';

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
 * A hash of every row of each table, so a changed value shows, not only a new or deleted row.
 *
 * The rows are sorted here rather than in SQL, so the hash does not depend on the database's order.
 */
function fingerprint( array $tables ) {
	global $wpdb;

	$out = array();
	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- A test reading whole tables; every name comes from $wpdb.
		$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_N );
		sort( $rows );
		$out[ $table ] = md5( wp_json_encode( $rows ) );
	}

	return $out;
}

/**
 * The learn-link `contract/` folder that holds the example files, or null when none is found.
 *
 * The LEARN_PROGRESS_CONTRACT_DIR constant wins, for a plugin folder copied into wp-content/plugins/
 * (define it in wp-config.php). Otherwise every folder above this file is tried, so a plugin
 * symlinked from the repository (Studio) finds the repository's `contract/`: __DIR__ has the
 * symlinks resolved.
 */
function contract_dir() {
	$candidates = defined( 'LEARN_PROGRESS_CONTRACT_DIR' ) ? array( rtrim( LEARN_PROGRESS_CONTRACT_DIR, '/\\' ) ) : array();

	for ( $dir = __DIR__; dirname( $dir ) !== $dir; $dir = dirname( $dir ) ) {
		$candidates[] = dirname( $dir ) . '/contract';
	}

	foreach ( $candidates as $candidate ) {
		if ( is_readable( $candidate . '/learn-progress-v1.md' ) ) {
			return $candidate;
		}
	}

	return null;
}

/**
 * One of the contract's example files, decoded, or null when it cannot be read.
 */
function fixture( $name ) {
	$file = $GLOBALS['contract_dir'] . '/' . $name;

	return is_readable( $file ) ? json_decode( file_get_contents( $file ), true ) : null;
}

$GLOBALS['contract_dir'] = contract_dir();

if ( null === $GLOBALS['contract_dir'] ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text to a terminal.
	echo "SKIP integration-me.php: the contract's example files were not found.\n     No contract/learn-progress-v1.md in any folder above " . __DIR__ . ".\n     Symlink the plugin from the learn-link repository, or define LEARN_PROGRESS_CONTRACT_DIR\n     in wp-config.php as the path of the repository's contract/ folder.\n\nSKIPPED (0 checks)\n";
	exit( 2 );
}

$learner_07 = get_user_by( 'login', 'learnlink-learner-07' );
$learner_08 = get_user_by( 'login', 'learnlink-learner-08' );
$client     = Clients\get( 'wpcredits' );
$whitelist  = $client ? array_map( 'intval', $client['courses'] ) : array();
$error_401  = fixture( 'example-error-401-invalid-token.json' );
$error_503  = fixture( 'example-error-503-unavailable.json' );
$example    = fixture( 'example-in-progress.json' );

ck( 'preconditions: the seeded learners 07 and 08, the local client with 2 courses, the contract files', array( (bool) $learner_07, (bool) $learner_08, count( $whitelist ), $error_401 && $error_503 && $example ), array( true, true, 2, true ) );
ck( 'the site is not production, so register_routes() adds the route', 'production' !== wp_get_environment_type(), true );
ck( 'the route is registered', isset( rest_get_server()->get_routes()['/learn-progress/v1/me'] ), true );

// I-L03, SEC-21: signed out.
$r = me( 0 );
ck( 'I-L03 signed out: 401 with the contract error and no-store', array( $r['status'], $r['body']['code'], $r['body']['message'], $r['no_store'] ), array( 401, $error_401['code'], $error_401['message'], true ) );

// I-L03, SEC-21: learner 07.
$r = me( $learner_07->ID );
ck( 'I-L03 learner 07: 200, the contract version, no-store', array( $r['status'], $r['body']['contract'], $r['no_store'] ), array( 200, 'learn-progress/1', true ) );
ck(
	'I-L03 learner 07: the learner block is only the username and the profile slug, never the numeric ID',
	$r['body']['learner'],
	array(
		'wporg_username'     => 'learnlink-learner-07',
		'wporg_profile_slug' => $learner_07->user_nicename,
	)
);

// An older login with a dot has a different slug, so each field must come from its own column
// (contract section 1.2). An in-memory copy of the user: nothing is saved.
$dotted                      = new WP_User( $learner_07->ID );
$dotted->data                = clone $learner_07->data;
$dotted->data->user_login    = 'learnlink.learner.07';
$dotted->data->user_nicename = 'learnlink-learner-07-slug';
$dotted_body                 = Response\build_body( $dotted, $client, (string) $whitelist[0] );
ck(
	'I-L03 a login that differs from its slug: each field comes from its own column',
	$dotted_body['learner'],
	array(
		'wporg_username'     => 'learnlink.learner.07',
		'wporg_profile_slug' => 'learnlink-learner-07-slug',
	)
);

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
ck(
	'SEC-06 learner 08 gets their own username and profile slug',
	me( $learner_08->ID )['body']['learner'],
	array(
		'wporg_username'     => 'learnlink-learner-08',
		'wporg_profile_slug' => $learner_08->user_nicename,
	)
);

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

// READ-ONLY: a GET changes nothing. The reader must not enroll, start a course or send mail
// (Maciej's note on is_user_enrolled() in #14). One learner from each seeded group, plus one whose
// stored enrollment result is stale, the case where Sensei would save the enrollment again.
global $wpdb;
$readers = array();
foreach ( array( '01', '04', '07', '11', '14', '17' ) as $number ) {
	$user = get_user_by( 'login', 'learnlink-learner-' . $number );
	if ( $user ) {
		$readers[ $number ] = (int) $user->ID;
	}
}
ck( 'READ-ONLY preconditions: learners 01, 04, 07, 11, 14 and 17', array_map( 'strval', array_keys( $readers ) ), array( '01', '04', '07', '11', '14', '17' ) );

// Learner 04 is enrolled with nothing done. Invalidating the stored result is itself a write, so it
// happens before the snapshot below.
if ( isset( $readers['04'] ) && class_exists( 'Sensei_Course_Enrolment' ) ) {
	Sensei_Course_Enrolment::get_course_instance( $whitelist[0] )->invalidate_learner_result( $readers['04'] );
}

$progress_tables = array( $wpdb->comments, $wpdb->commentmeta, $wpdb->usermeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships );
$progress_tables = array_merge( $progress_tables, $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'sensei_lms_' ) . '%' ) ) );
$before          = fingerprint( $progress_tables );

$writes = array();
$record = function ( $value = null ) use ( &$writes ) {
	$writes[] = current_filter();
	return $value; // wp_mail is a filter: hand its arguments back.
};
$watched = array( 'sensei_user_course_start', 'sensei_user_lesson_start', 'wp_mail', 'wp_insert_comment', 'edit_comment', 'delete_comment', 'added_comment_meta', 'updated_comment_meta', 'deleted_comment_meta', 'added_user_meta', 'updated_user_meta', 'deleted_user_meta', 'set_object_terms' );
foreach ( $watched as $hook ) {
	add_filter( $hook, $record );
}

$statuses = array();
foreach ( $readers as $user_id ) {
	$statuses[] = me( $user_id )['status'];
	$statuses[] = me( $user_id, array( 'courses' => (string) $whitelist[0] ) )['status'];
}

foreach ( $watched as $hook ) {
	remove_filter( $hook, $record );
}
$changed = array_keys( array_diff_assoc( fingerprint( $progress_tables ), $before ) );

ck( 'READ-ONLY six learners, twelve GETs: every answer is 200', array_unique( $statuses ), array( 200 ) );
ck( 'READ-ONLY no write hook fired (enrollment, course or lesson start, comment, meta, terms, mail)', array_values( array_unique( $writes ) ), array() );
ck( 'READ-ONLY every progress table is byte for byte the same, Sensei tables included', $changed, array() );

// SEC-21: an error from the callback also carries no-store.
add_filter( 'learn_progress_clients', '__return_empty_array', 99 );
$r = me( $learner_07->ID );
remove_filter( 'learn_progress_clients', '__return_empty_array', 99 );
ck( 'no client configured: 503 with the contract error and no-store', array( $r['status'], $r['body']['code'], $r['body']['message'], $r['no_store'] ), array( 503, $error_503['code'], $error_503['message'], true ) );

// SEC-21: a wrong X-WP-Nonce on a cookie session. WordPress refuses it before our permission check,
// with its own 403 rest_cookie_invalid_nonce, which is not one of the contract's codes (the Dashboard
// treats a 401 or 403 without a Learn code as a bad answer, contract section 2).
// First the steps WP_REST_Server::serve_request() takes, in this process: check_authentication(),
// the error turned into a response, then rest_post_dispatch, which it applies to errors too.
global $wp_rest_auth_cookie;
$saved_auth_cookie          = $wp_rest_auth_cookie;
$wp_rest_auth_cookie        = true; // What rest_cookie_collect_status() sets for a valid logged-in cookie.
$_SERVER['HTTP_X_WP_NONCE'] = 'not-a-nonce';
wp_set_current_user( $learner_07->ID );

$auth     = rest_get_server()->check_authentication();
$request  = new WP_REST_Request( 'GET', '/learn-progress/v1/me' );
$response = apply_filters( 'rest_post_dispatch', is_wp_error( $auth ) ? rest_convert_error_to_response( $auth ) : rest_ensure_response( $auth ), rest_get_server(), $request );
$headers  = $response->get_headers();

unset( $_SERVER['HTTP_X_WP_NONCE'] );
$wp_rest_auth_cookie = $saved_auth_cookie;
remove_filter( 'rest_send_nocache_headers', '__return_true', 20 );
$data = $response->get_data();
ck( 'SEC-21 wrong X-WP-Nonce: 403 rest_cookie_invalid_nonce, and our filter adds no-store', array( $response->get_status(), isset( $data['code'] ) ? $data['code'] : null, isset( $headers['Cache-Control'] ) ? $headers['Cache-Control'] : null ), array( 403, 'rest_cookie_invalid_nonce', 'no-store' ) );

// Then one real HTTP request to the site, as learner 07 with a wrong nonce. For this error WordPress
// also sends its no-cache headers, whose Cache-Control replaces ours and includes no-store.
// The session token is created for this request only, and destroyed right after it.
$expires = time() + 5 * MINUTE_IN_SECONDS;
$session = WP_Session_Tokens::get_instance( $learner_07->ID );
$token   = $session->create( $expires );
$http    = wp_remote_get(
	rest_url( 'learn-progress/v1/me' ),
	array(
		'headers'   => array( 'X-WP-Nonce' => 'not-a-nonce' ),
		'cookies'   => array( LOGGED_IN_COOKIE => wp_generate_auth_cookie( $learner_07->ID, $expires, 'logged_in', $token ) ),
		'timeout'   => 15,
		'sslverify' => false, // A local site's certificate, if any, is self-signed.
	)
);
$session->destroy( $token );

if ( is_wp_error( $http ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text to a terminal.
	echo 'skip SEC-21 wrong X-WP-Nonce over HTTP: the site is not reachable from this process (' . $http->get_error_message() . ")\n";
} else {
	$body = json_decode( wp_remote_retrieve_body( $http ), true );
	ck( 'SEC-21 wrong X-WP-Nonce over HTTP: 403 rest_cookie_invalid_nonce, and Cache-Control includes no-store', array( wp_remote_retrieve_response_code( $http ), isset( $body['code'] ) ? $body['code'] : null, false !== strpos( (string) wp_remote_retrieve_header( $http, 'cache-control' ), 'no-store' ) ), array( 403, 'rest_cookie_invalid_nonce', true ) );
}

// The no-store filter touches only learn-progress routes.
$request  = new WP_REST_Request( 'GET', '/wp/v2/types' );
$response = apply_filters( 'rest_post_dispatch', rest_do_request( $request ), rest_get_server(), $request );
ck( 'another route is left alone', isset( $response->get_headers()['Cache-Control'] ), false );

wp_set_current_user( 0 );

test_summary();
