<?php
/**
 * Learn Link cached progress reads and error handling (#24).
 *
 * Run from the plugin root: php bin/test-learn-progress-sync.php
 *
 * @package WPCreditsProgramManager
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPCPM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

/**
 * Translation stand-in.
 *
 * @param string $text Text to translate.
 * @return string
 */
function __( $text ) {
	return $text;
}

/**
 * Minimal WordPress error stand-in.
 */
class WP_Error {
	/** @var string */
	private $code;

	/** @var mixed */
	private $data;

	/**
	 * Keep the error code and optional error data.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Optional error data.
	 */
	public function __construct( $code, $message = '', $data = '' ) {
		unset( $message );
		$this->code = $code;
		$this->data = $data;
	}

	/**
	 * Return the error code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->code;
	}

	/**
	 * Return the error data.
	 *
	 * @return mixed
	 */
	public function get_error_data() {
		return $this->data;
	}

	/**
	 * Store error data.
	 *
	 * @param mixed $data Error data.
	 */
	public function add_data( $data ) {
		$this->data = $data;
	}
}

/**
 * Whether a result is an error.
 *
 * @param mixed $thing Result to inspect.
 * @return bool
 */
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Remove trailing forward slashes and backslashes.
 *
 * @param string $string What to untrailingslash.
 * @return string
 */
function untrailingslashit( $string ) {
	return rtrim( (string) $string, '/\\' );
}

/**
 * Encode data as JSON.
 *
 * @param mixed $data Data to encode.
 * @return string|false
 */
function wp_json_encode( $data ) {
	return json_encode( $data );
}

/**
 * No-op action registration stand-in for WPCPM_Learn_Progress::init().
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Callback.
 */
function add_action( $hook, $callback ) {
	unset( $hook, $callback );
}

/**
 * Settings stand-in.
 */
class WPCPM_Settings {
	/** @var array<string, mixed> */
	public static $options = array();

	/**
	 * Retrieve a setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_value( $key, $default = '' ) {
		return array_key_exists( $key, self::$options ) ? self::$options[ $key ] : $default;
	}
}

/**
 * Students sync stand-in: the cached program row of each student.
 */
class WPCPM_Students_Sync {
	/** @var array<int, array> */
	public static $programs = array();

	/**
	 * A student's cached program row.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function get_program( $user_id ) {
		return isset( self::$programs[ (int) $user_id ] ) ? self::$programs[ (int) $user_id ] : array();
	}
}

/**
 * Program stand-in: the track status to Learn course map.
 */
class WPCPM_Program {
	/** @var array<string, int> */
	public static $course_ids = array();

	/**
	 * The status whose course a student is on, or '' for none.
	 *
	 * @param string $status  Airtable status.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	public static function course_status( $status, $user_id = 0 ) {
		unset( $user_id );
		return isset( self::$course_ids[ (string) $status ] ) ? (string) $status : '';
	}

	/**
	 * The Learn course post ID for a status.
	 *
	 * @param string $status Airtable status.
	 * @return int
	 */
	public static function course_id( $status ) {
		return isset( self::$course_ids[ (string) $status ] ) ? (int) self::$course_ids[ (string) $status ] : 0;
	}
}

/**
 * Secret sealing stand-in.
 */
class WPCPM_Secret {
	/**
	 * Unseal a stored option value.
	 *
	 * @param mixed $sealed Sealed value.
	 * @return string|WP_Error
	 */
	public static function unseal_from_option( $sealed ) {
		if ( ! is_string( $sealed ) || 0 !== strpos( $sealed, 'sealed:' ) ) {
			return new WP_Error( 'wpcpm_private_cipher' );
		}

		return substr( $sealed, strlen( 'sealed:' ) );
	}
}

$GLOBALS['wpcpm_sync_test_meta']  = array();
$GLOBALS['wpcpm_sync_http_queue'] = array();
$GLOBALS['wpcpm_sync_http_calls'] = array();

/**
 * Read in-memory user meta.
 *
 * @param int    $user_id User ID.
 * @param string $key     Meta key.
 * @param bool   $single  Whether to return a single value.
 * @return mixed
 */
function get_user_meta( $user_id, $key = '', $single = false ) {
	unset( $single );
	$user_id = (int) $user_id;

	if ( ! isset( $GLOBALS['wpcpm_sync_test_meta'][ $user_id ] ) ) {
		return '';
	}

	return array_key_exists( $key, $GLOBALS['wpcpm_sync_test_meta'][ $user_id ] )
		? $GLOBALS['wpcpm_sync_test_meta'][ $user_id ][ $key ]
		: '';
}

/**
 * Write in-memory user meta.
 *
 * @param int    $user_id User ID.
 * @param string $key     Meta key.
 * @param mixed  $value   Meta value.
 * @return bool
 */
function update_user_meta( $user_id, $key, $value ) {
	$user_id = (int) $user_id;

	if ( ! isset( $GLOBALS['wpcpm_sync_test_meta'][ $user_id ] ) ) {
		$GLOBALS['wpcpm_sync_test_meta'][ $user_id ] = array();
	}

	$GLOBALS['wpcpm_sync_test_meta'][ $user_id ][ $key ] = $value;
	return true;
}

/**
 * Delete in-memory user meta.
 *
 * @param int    $user_id User ID.
 * @param string $key     Meta key.
 * @return bool
 */
function delete_user_meta( $user_id, $key ) {
	$user_id = (int) $user_id;
	unset( $GLOBALS['wpcpm_sync_test_meta'][ $user_id ][ $key ] );
	return true;
}

/**
 * Query in-memory user IDs matching a meta_key.
 *
 * @param array $args Query arguments.
 * @return int[]
 */
function get_users( array $args = array() ) {
	$meta_key = isset( $args['meta_key'] ) ? (string) $args['meta_key'] : '';
	if ( '' === $meta_key ) {
		return array_keys( $GLOBALS['wpcpm_sync_test_meta'] );
	}

	$ids = array();
	foreach ( $GLOBALS['wpcpm_sync_test_meta'] as $user_id => $meta ) {
		if ( is_array( $meta ) && array_key_exists( $meta_key, $meta ) && '' !== $meta[ $meta_key ] ) {
			$ids[] = (int) $user_id;
		}
	}

	return $ids;
}

/**
 * Mock wp_remote_get from a queued response table.
 *
 * @param string $url  Request URL.
 * @param array  $args Request arguments.
 * @return array|WP_Error
 */
function wp_remote_get( $url, array $args = array() ) {
	$GLOBALS['wpcpm_sync_http_calls'][] = array(
		'method' => 'GET',
		'url'    => $url,
		'args'   => $args,
	);

	if ( empty( $GLOBALS['wpcpm_sync_http_queue'] ) ) {
		return new WP_Error( 'http_request_failed' );
	}

	return array_shift( $GLOBALS['wpcpm_sync_http_queue'] );
}

/**
 * Mock wp_remote_request from a queued response table.
 *
 * @param string $url  Request URL.
 * @param array  $args Request arguments.
 * @return array|WP_Error
 */
function wp_remote_request( $url, array $args = array() ) {
	$GLOBALS['wpcpm_sync_http_calls'][] = array(
		'method' => isset( $args['method'] ) ? $args['method'] : 'REQUEST',
		'url'    => $url,
		'args'   => $args,
	);

	if ( empty( $GLOBALS['wpcpm_sync_http_queue'] ) ) {
		return new WP_Error( 'http_request_failed' );
	}

	return array_shift( $GLOBALS['wpcpm_sync_http_queue'] );
}

/**
 * Mock wp_remote_retrieve_response_code.
 *
 * @param array|WP_Error $response Response to inspect.
 * @return int|string
 */
function wp_remote_retrieve_response_code( $response ) {
	if ( is_wp_error( $response ) || ! is_array( $response ) || ! isset( $response['response']['code'] ) ) {
		return '';
	}
	return (int) $response['response']['code'];
}

/**
 * Mock wp_remote_retrieve_body.
 *
 * @param array|WP_Error $response Response to inspect.
 * @return string
 */
function wp_remote_retrieve_body( $response ) {
	if ( is_wp_error( $response ) || ! is_array( $response ) || ! isset( $response['body'] ) ) {
		return '';
	}
	return (string) $response['body'];
}

/**
 * Mock wp_remote_retrieve_header.
 *
 * @param array|WP_Error $response Response to inspect.
 * @param string         $header   Header name.
 * @return string
 */
function wp_remote_retrieve_header( $response, $header ) {
	if ( is_wp_error( $response ) || ! is_array( $response ) || ! isset( $response['headers'] ) || ! is_array( $response['headers'] ) ) {
		return '';
	}

	foreach ( $response['headers'] as $name => $value ) {
		if ( 0 === strcasecmp( (string) $name, (string) $header ) ) {
			return (string) $value;
		}
	}

	return '';
}

require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-progress.php';
require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-client.php';
require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-progress-sync.php';

$wpcpm_sync_failures = 0;
$wpcpm_sync_checks   = 0;

/**
 * Compare one result with its expected value.
 *
 * @param string $label Test label.
 * @param mixed  $got   Actual value.
 * @param mixed  $want  Expected value.
 */
function wpcpm_sync_check( $label, $got, $want ) {
	global $wpcpm_sync_failures, $wpcpm_sync_checks;

	++$wpcpm_sync_checks;
	if ( $got === $want ) {
		echo "OK: {$label}\n";
		return;
	}

	++$wpcpm_sync_failures;
	echo "FAIL: {$label}\n";
	echo '  got:  ' . var_export( $got, true ) . "\n";
	echo '  want: ' . var_export( $want, true ) . "\n";
}

/**
 * Load one progress fixture from dashboard/bin/fixtures/.
 *
 * @param string $name Fixture name without prefix or extension.
 * @return array
 */
function wpcpm_sync_fixture( $name ) {
	$path = __DIR__ . '/fixtures/learn-progress-' . $name . '.json';
	return json_decode( file_get_contents( $path ), true );
}

$wpcpm_sync_token        = 'ABCdefGHIjklMNOpqrSTUvwxYZ_0123456789-abcde';
$wpcpm_sync_sealed_token = 'sealed:' . $wpcpm_sync_token;
$wpcpm_sync_in_progress  = wpcpm_sync_fixture( 'in-progress' );

// 0. Disabled switch makes no request.
WPCPM_Settings::$options = array(
	'learn_enabled'     => false,
	'learn_base_url'    => 'https://learn.test',
	'learn_cache_hours' => 6,
);
$GLOBALS['wpcpm_sync_test_meta']  = array(
	10 => array(
		WPCPM_Learn_Progress::META_TOKEN    => $wpcpm_sync_sealed_token,
		WPCPM_Learn_Progress::META_USERNAME => 'adaexample',
	),
);
$GLOBALS['wpcpm_sync_http_calls'] = array();
$wpcpm_res                        = WPCPM_Learn_Progress_Sync::refresh( 10, true );
wpcpm_sync_check( 'disabled switch returns wpcpm_learn_disabled', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_disabled' );
wpcpm_sync_check( 'disabled switch makes no HTTP call', count( $GLOBALS['wpcpm_sync_http_calls'] ), 0 );

// Enable Learn Link for remaining tests.
WPCPM_Settings::$options['learn_enabled'] = true;

// 0b. courses= on every read (contract section 1.4, decision 4 in #2).
WPCPM_Program::$course_ids     = array( '150 Hour' => 297853 );
WPCPM_Students_Sync::$programs = array(
	20 => array( 'status' => '150 Hour' ),
	21 => array( 'status' => 'Paused' ),
);
foreach ( array( 20, 21 ) as $wpcpm_courses_user ) {
	$GLOBALS['wpcpm_sync_test_meta'][ $wpcpm_courses_user ] = array(
		WPCPM_Learn_Progress::META_TOKEN    => $wpcpm_sync_sealed_token,
		WPCPM_Learn_Progress::META_USERNAME => 'AdaExample',
	);
}
$wpcpm_courses_200 = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode( $wpcpm_sync_in_progress ),
);

$GLOBALS['wpcpm_sync_http_calls'] = array();
$GLOBALS['wpcpm_sync_http_queue'] = array( $wpcpm_courses_200 );
WPCPM_Learn_Progress_Sync::refresh( 20, true );
wpcpm_sync_check( 'courses= sends the student\'s track course', $GLOBALS['wpcpm_sync_http_calls'][0]['url'], 'https://learn.test/wp-json/learn-progress/v1/me?courses=297853' );

$GLOBALS['wpcpm_sync_http_calls'] = array();
$GLOBALS['wpcpm_sync_http_queue'] = array( $wpcpm_courses_200 );
WPCPM_Learn_Progress_Sync::refresh( 21, true );
wpcpm_sync_check( 'courses= is left out for a student on no course', $GLOBALS['wpcpm_sync_http_calls'][0]['url'], 'https://learn.test/wp-json/learn-progress/v1/me' );

WPCPM_Settings::$options['learn_courses'] = '57, 59';
$GLOBALS['wpcpm_sync_http_calls']         = array();
$GLOBALS['wpcpm_sync_http_queue']         = array( $wpcpm_courses_200 );
WPCPM_Learn_Progress_Sync::refresh( 20, true );
wpcpm_sync_check( 'courses= uses the learn_courses override when it is set', $GLOBALS['wpcpm_sync_http_calls'][0]['url'], 'https://learn.test/wp-json/learn-progress/v1/me?courses=57,59' );

unset( WPCPM_Settings::$options['learn_courses'] );
WPCPM_Program::$course_ids     = array();
WPCPM_Students_Sync::$programs = array();

// 1. I-D05: 200 valid response stores cache with fetched_at and clears any previous error.
$GLOBALS['wpcpm_sync_test_meta']  = array(
	11 => array(
		WPCPM_Learn_Progress::META_TOKEN    => $wpcpm_sync_sealed_token,
		WPCPM_Learn_Progress::META_USERNAME => 'AdaExample',
		WPCPM_Learn_Progress::META_ERROR    => array(
			'code'        => 'learn_progress_unavailable',
			'at'          => time() - 300,
			'retry_after' => 0,
		),
	),
);
$GLOBALS['wpcpm_sync_http_calls'] = array();
$GLOBALS['wpcpm_sync_http_queue'] = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( $wpcpm_sync_in_progress ),
	),
);

$wpcpm_res   = WPCPM_Learn_Progress_Sync::refresh( 11 );
$wpcpm_cache = get_user_meta( 11, WPCPM_Learn_Progress::META_CACHE, true );
wpcpm_sync_check( 'I-D05 200 valid response returns true', $wpcpm_res, true );
wpcpm_sync_check( 'I-D05 200 makes exactly one GET request', count( $GLOBALS['wpcpm_sync_http_calls'] ), 1 );
wpcpm_sync_check( 'I-D05 200 sets redirection to 0', $GLOBALS['wpcpm_sync_http_calls'][0]['args']['redirection'], 0 );
wpcpm_sync_check( 'I-D05 200 stores normalized courses and integer fetched_at', is_array( $wpcpm_cache ) && isset( $wpcpm_cache['courses'], $wpcpm_cache['fetched_at'] ) && is_int( $wpcpm_cache['fetched_at'] ), true );
wpcpm_sync_check( 'I-D05 200 clears META_ERROR', get_user_meta( 11, WPCPM_Learn_Progress::META_ERROR, true ), '' );
wpcpm_sync_check( 'I-D05 cache never contains the token', false === strpos( wp_json_encode( $wpcpm_cache ), $wpcpm_sync_token ), true );

// 2. Fresh cache without $force makes no HTTP call.
$GLOBALS['wpcpm_sync_http_calls'] = array();
$wpcpm_res                        = WPCPM_Learn_Progress_Sync::refresh( 11, false );
wpcpm_sync_check( 'fresh cache without $force returns true', $wpcpm_res, true );
wpcpm_sync_check( 'fresh cache without $force makes no HTTP request', count( $GLOBALS['wpcpm_sync_http_calls'] ), 0 );

// 3. I-D05: 503 with previous cache keeps cache intact and records META_ERROR.
$wpcpm_previous_cache                                                    = $wpcpm_cache;
$wpcpm_previous_cache['fetched_at']                                      = time() - ( 7 * HOUR_IN_SECONDS );
$GLOBALS['wpcpm_sync_test_meta'][11][ WPCPM_Learn_Progress::META_CACHE ] = $wpcpm_previous_cache;
$GLOBALS['wpcpm_sync_http_calls']                                        = array();
$GLOBALS['wpcpm_sync_http_queue']                                        = array(
	array(
		'response' => array( 'code' => 503 ),
		'body'     => wp_json_encode( wpcpm_sync_fixture( 'error-503' ) ),
	),
);

$wpcpm_res = WPCPM_Learn_Progress_Sync::refresh( 11 );
$wpcpm_err = get_user_meta( 11, WPCPM_Learn_Progress::META_ERROR, true );
wpcpm_sync_check( 'I-D05 503 returns learn_progress_unavailable WP_Error', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'learn_progress_unavailable' );
wpcpm_sync_check( 'I-D05 503 keeps previous cache intact', get_user_meta( 11, WPCPM_Learn_Progress::META_CACHE, true ), $wpcpm_previous_cache );
wpcpm_sync_check( 'I-D05 503 records META_ERROR with code, at and retry_after', array( $wpcpm_err['code'], is_int( $wpcpm_err['at'] ), $wpcpm_err['retry_after'] ), array( 'learn_progress_unavailable', true, 0 ) );

// 4. SEC-14: unknown vocabulary in 200 response -> wpcpm_learn_bad_answer and previous cache kept intact.
$wpcpm_unknown_vocab                             = $wpcpm_sync_in_progress;
$wpcpm_unknown_vocab['courses'][0]['status']     = 'archived-course-state';
$GLOBALS['wpcpm_sync_http_queue']                = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( $wpcpm_unknown_vocab ),
	),
);
$wpcpm_res = WPCPM_Learn_Progress_Sync::refresh( 11, true );
$wpcpm_err = get_user_meta( 11, WPCPM_Learn_Progress::META_ERROR, true );
wpcpm_sync_check( 'SEC-14 unknown vocabulary returns wpcpm_learn_bad_answer', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_bad_answer' );
wpcpm_sync_check( 'SEC-14 unknown vocabulary keeps previous cache intact', get_user_meta( 11, WPCPM_Learn_Progress::META_CACHE, true ), $wpcpm_previous_cache );
wpcpm_sync_check( 'SEC-14 unknown vocabulary records wpcpm_learn_bad_answer in META_ERROR', $wpcpm_err['code'], 'wpcpm_learn_bad_answer' );

// 5. SEC-13: different username in 200 response -> wpcpm_learn_identity and cache not overwritten.
$wpcpm_other_user                                 = $wpcpm_sync_in_progress;
$wpcpm_other_user['learner']['wporg_username']    = 'someone-else';
$GLOBALS['wpcpm_sync_http_queue']                 = array(
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( $wpcpm_other_user ),
	),
);
$wpcpm_res = WPCPM_Learn_Progress_Sync::refresh( 11, true );
$wpcpm_err = get_user_meta( 11, WPCPM_Learn_Progress::META_ERROR, true );
wpcpm_sync_check( 'SEC-13 different username returns wpcpm_learn_identity', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_identity' );
wpcpm_sync_check( 'SEC-13 different username keeps previous cache intact', get_user_meta( 11, WPCPM_Learn_Progress::META_CACHE, true ), $wpcpm_previous_cache );
wpcpm_sync_check( 'SEC-13 different username records wpcpm_learn_identity in META_ERROR', $wpcpm_err['code'], 'wpcpm_learn_identity' );

// 6. I-D05 / SEC-16: 429 records retry_after and blocks subsequent reads until retry_after expires.
$GLOBALS['wpcpm_sync_http_calls'] = array();
$GLOBALS['wpcpm_sync_http_queue'] = array(
	array(
		'response' => array( 'code' => 429 ),
		'headers'  => array( 'Retry-After' => '1800' ),
		'body'     => wp_json_encode( wpcpm_sync_fixture( 'error-429' ) ),
	),
);
$wpcpm_res = WPCPM_Learn_Progress_Sync::refresh( 11, true );
$wpcpm_err = get_user_meta( 11, WPCPM_Learn_Progress::META_ERROR, true );
wpcpm_sync_check( 'SEC-16 429 returns learn_progress_slow_down', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'learn_progress_slow_down' );
wpcpm_sync_check( 'SEC-16 429 preserves previous cache', get_user_meta( 11, WPCPM_Learn_Progress::META_CACHE, true ), $wpcpm_previous_cache );
wpcpm_sync_check( 'SEC-16 429 stores retry_after in META_ERROR', array( $wpcpm_err['code'], $wpcpm_err['retry_after'] ), array( 'learn_progress_slow_down', 1800 ) );

// Subsequent call before Retry-After elapses (even with $force = true) makes no HTTP request.
$GLOBALS['wpcpm_sync_http_calls'] = array();
$wpcpm_res_blocked                = WPCPM_Learn_Progress_Sync::refresh( 11, true );
wpcpm_sync_check( 'SEC-16 call during Retry-After cooldown returns learn_progress_slow_down', is_wp_error( $wpcpm_res_blocked ) ? $wpcpm_res_blocked->get_error_code() : null, 'learn_progress_slow_down' );
wpcpm_sync_check( 'SEC-16 call during Retry-After cooldown makes no HTTP request', count( $GLOBALS['wpcpm_sync_http_calls'] ), 0 );

// Clear the 429 error to test 401 and 403.
delete_user_meta( 11, WPCPM_Learn_Progress::META_ERROR );

// 7. I-D05: 401 deletes token and cache, and records learn_progress_invalid_token in META_ERROR.
$GLOBALS['wpcpm_sync_http_queue'] = array(
	array(
		'response' => array( 'code' => 401 ),
		'body'     => wp_json_encode( wpcpm_sync_fixture( 'error-401' ) ),
	),
);
$wpcpm_res = WPCPM_Learn_Progress_Sync::refresh( 11, true );
$wpcpm_err = get_user_meta( 11, WPCPM_Learn_Progress::META_ERROR, true );
wpcpm_sync_check( 'I-D05 401 returns learn_progress_invalid_token', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'learn_progress_invalid_token' );
wpcpm_sync_check( 'I-D05 401 deletes META_TOKEN', get_user_meta( 11, WPCPM_Learn_Progress::META_TOKEN, true ), '' );
wpcpm_sync_check( 'I-D05 401 deletes META_CACHE', get_user_meta( 11, WPCPM_Learn_Progress::META_CACHE, true ), '' );
wpcpm_sync_check( 'I-D05 401 records learn_progress_invalid_token in META_ERROR', $wpcpm_err['code'], 'learn_progress_invalid_token' );

// 8. I-D05: 403 deletes token and cache, and sets META_WITHDRAWN_AT and META_WITHDRAWN_BY = 0.
$GLOBALS['wpcpm_sync_test_meta'][12] = array(
	WPCPM_Learn_Progress::META_TOKEN    => $wpcpm_sync_sealed_token,
	WPCPM_Learn_Progress::META_USERNAME => 'adaexample',
	WPCPM_Learn_Progress::META_CACHE    => $wpcpm_previous_cache,
);
$GLOBALS['wpcpm_sync_http_queue']    = array(
	array(
		'response' => array( 'code' => 403 ),
		'body'     => wp_json_encode( wpcpm_sync_fixture( 'error-403' ) ),
	),
);
$wpcpm_res = WPCPM_Learn_Progress_Sync::refresh( 12, true );
wpcpm_sync_check( 'I-D05 403 returns learn_progress_revoked', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'learn_progress_revoked' );
wpcpm_sync_check( 'I-D05 403 deletes META_TOKEN', get_user_meta( 12, WPCPM_Learn_Progress::META_TOKEN, true ), '' );
wpcpm_sync_check( 'I-D05 403 deletes META_CACHE', get_user_meta( 12, WPCPM_Learn_Progress::META_CACHE, true ), '' );
wpcpm_sync_check( 'I-D05 403 sets META_WITHDRAWN_AT timestamp', (int) get_user_meta( 12, WPCPM_Learn_Progress::META_WITHDRAWN_AT, true ) > 0, true );
wpcpm_sync_check( 'I-D05 403 sets META_WITHDRAWN_BY to 0 (Learn)', get_user_meta( 12, WPCPM_Learn_Progress::META_WITHDRAWN_BY, true ), 0 );

// 9. Unseal failure records wpcpm_learn_seal and makes no HTTP request.
$GLOBALS['wpcpm_sync_test_meta'][13] = array(
	WPCPM_Learn_Progress::META_TOKEN    => 'corrupted-ciphertext',
	WPCPM_Learn_Progress::META_USERNAME => 'adaexample',
	WPCPM_Learn_Progress::META_CACHE    => $wpcpm_previous_cache,
);
$GLOBALS['wpcpm_sync_http_calls']    = array();
$wpcpm_res                           = WPCPM_Learn_Progress_Sync::refresh( 13, true );
$wpcpm_err                           = get_user_meta( 13, WPCPM_Learn_Progress::META_ERROR, true );
wpcpm_sync_check( 'unseal failure returns wpcpm_learn_seal', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_seal' );
wpcpm_sync_check( 'unseal failure makes no HTTP request', count( $GLOBALS['wpcpm_sync_http_calls'] ), 0 );
wpcpm_sync_check( 'unseal failure preserves previous cache', get_user_meta( 13, WPCPM_Learn_Progress::META_CACHE, true ), $wpcpm_previous_cache );
wpcpm_sync_check( 'unseal failure records wpcpm_learn_seal in META_ERROR', $wpcpm_err['code'], 'wpcpm_learn_seal' );

// ---------------------------------------------------------------------------
// 10. #25: retry_revocations()
// ---------------------------------------------------------------------------

// 10a. 204 clears META_REVOKE_PENDING and increments cleared.
$GLOBALS['wpcpm_sync_test_meta']  = array(
	21 => array(
		WPCPM_Learn_Progress::META_REVOKE_PENDING => $wpcpm_sync_sealed_token,
	),
);
$GLOBALS['wpcpm_sync_http_calls'] = array();
$GLOBALS['wpcpm_sync_http_queue'] = array(
	array(
		'response' => array( 'code' => 204 ),
		'body'     => '',
	),
);
$wpcpm_summary                    = WPCPM_Learn_Progress_Sync::retry_revocations();
wpcpm_sync_check( 'retry_revocations 204 returns retried=1 cleared=1 failed=0', $wpcpm_summary, array( 'retried' => 1, 'cleared' => 1, 'failed' => 0 ) );
wpcpm_sync_check( 'retry_revocations 204 deletes META_REVOKE_PENDING', get_user_meta( 21, WPCPM_Learn_Progress::META_REVOKE_PENDING, true ), '' );
wpcpm_sync_check( 'retry_revocations 204 sends DELETE request', $GLOBALS['wpcpm_sync_http_calls'][0]['method'], 'DELETE' );

// 10b. 401 learn_progress_invalid_token clears META_REVOKE_PENDING.
$GLOBALS['wpcpm_sync_test_meta']  = array(
	22 => array(
		WPCPM_Learn_Progress::META_REVOKE_PENDING => $wpcpm_sync_sealed_token,
	),
);
$GLOBALS['wpcpm_sync_http_queue'] = array(
	array(
		'response' => array( 'code' => 401 ),
		'body'     => wp_json_encode( wpcpm_sync_fixture( 'error-401' ) ),
	),
);
$wpcpm_summary                    = WPCPM_Learn_Progress_Sync::retry_revocations();
wpcpm_sync_check( 'retry_revocations 401 invalid_token returns cleared=1', $wpcpm_summary, array( 'retried' => 1, 'cleared' => 1, 'failed' => 0 ) );
wpcpm_sync_check( 'retry_revocations 401 invalid_token deletes META_REVOKE_PENDING', get_user_meta( 22, WPCPM_Learn_Progress::META_REVOKE_PENDING, true ), '' );

// 10c. 403 learn_progress_revoked clears META_REVOKE_PENDING.
$GLOBALS['wpcpm_sync_test_meta']  = array(
	23 => array(
		WPCPM_Learn_Progress::META_REVOKE_PENDING => $wpcpm_sync_sealed_token,
	),
);
$GLOBALS['wpcpm_sync_http_queue'] = array(
	array(
		'response' => array( 'code' => 403 ),
		'body'     => wp_json_encode( wpcpm_sync_fixture( 'error-403' ) ),
	),
);
$wpcpm_summary                    = WPCPM_Learn_Progress_Sync::retry_revocations();
wpcpm_sync_check( 'retry_revocations 403 revoked returns cleared=1', $wpcpm_summary, array( 'retried' => 1, 'cleared' => 1, 'failed' => 0 ) );
wpcpm_sync_check( 'retry_revocations 403 revoked deletes META_REVOKE_PENDING', get_user_meta( 23, WPCPM_Learn_Progress::META_REVOKE_PENDING, true ), '' );

// 10d. 503, 429, and network error retain META_REVOKE_PENDING and increment failed.
$GLOBALS['wpcpm_sync_test_meta']  = array(
	24 => array(
		WPCPM_Learn_Progress::META_REVOKE_PENDING => $wpcpm_sync_sealed_token,
	),
);
$GLOBALS['wpcpm_sync_http_queue'] = array(
	array(
		'response' => array( 'code' => 503 ),
		'body'     => wp_json_encode( wpcpm_sync_fixture( 'error-503' ) ),
	),
);
$wpcpm_summary                    = WPCPM_Learn_Progress_Sync::retry_revocations();
wpcpm_sync_check( 'retry_revocations 503 returns failed=1', $wpcpm_summary, array( 'retried' => 1, 'cleared' => 0, 'failed' => 1 ) );
wpcpm_sync_check( 'retry_revocations 503 preserves META_REVOKE_PENDING', get_user_meta( 24, WPCPM_Learn_Progress::META_REVOKE_PENDING, true ), $wpcpm_sync_sealed_token );

$GLOBALS['wpcpm_sync_http_queue'] = array(
	array(
		'response' => array( 'code' => 429 ),
		'body'     => wp_json_encode( wpcpm_sync_fixture( 'error-429' ) ),
	),
);
$wpcpm_summary                    = WPCPM_Learn_Progress_Sync::retry_revocations();
wpcpm_sync_check( 'retry_revocations 429 returns failed=1', $wpcpm_summary, array( 'retried' => 1, 'cleared' => 0, 'failed' => 1 ) );
wpcpm_sync_check( 'retry_revocations 429 preserves META_REVOKE_PENDING', get_user_meta( 24, WPCPM_Learn_Progress::META_REVOKE_PENDING, true ), $wpcpm_sync_sealed_token );

$GLOBALS['wpcpm_sync_http_queue'] = array(
	new WP_Error( 'http_request_failed', 'Connection timed out' ),
);
$wpcpm_summary                    = WPCPM_Learn_Progress_Sync::retry_revocations();
wpcpm_sync_check( 'retry_revocations network error returns failed=1', $wpcpm_summary, array( 'retried' => 1, 'cleared' => 0, 'failed' => 1 ) );
wpcpm_sync_check( 'retry_revocations network error preserves META_REVOKE_PENDING', get_user_meta( 24, WPCPM_Learn_Progress::META_REVOKE_PENDING, true ), $wpcpm_sync_sealed_token );

// 10e. Two pending users: first fails (503), second succeeds (204) -> processes both.
$GLOBALS['wpcpm_sync_test_meta']  = array(
	25 => array(
		WPCPM_Learn_Progress::META_REVOKE_PENDING => $wpcpm_sync_sealed_token,
	),
	26 => array(
		WPCPM_Learn_Progress::META_REVOKE_PENDING => $wpcpm_sync_sealed_token,
	),
);
$GLOBALS['wpcpm_sync_http_queue'] = array(
	array(
		'response' => array( 'code' => 503 ),
		'body'     => wp_json_encode( wpcpm_sync_fixture( 'error-503' ) ),
	),
	array(
		'response' => array( 'code' => 204 ),
		'body'     => '',
	),
);
$wpcpm_summary                    = WPCPM_Learn_Progress_Sync::retry_revocations();
wpcpm_sync_check( 'retry_revocations with two users returns retried=2 cleared=1 failed=1', $wpcpm_summary, array( 'retried' => 2, 'cleared' => 1, 'failed' => 1 ) );
wpcpm_sync_check( 'retry_revocations keeps first failed user meta', get_user_meta( 25, WPCPM_Learn_Progress::META_REVOKE_PENDING, true ), $wpcpm_sync_sealed_token );
wpcpm_sync_check( 'retry_revocations deletes second succeeded user meta', get_user_meta( 26, WPCPM_Learn_Progress::META_REVOKE_PENDING, true ), '' );

// 10f. Corrupted sealed value fails unseal, keeps meta, makes no HTTP request, increments failed.
$GLOBALS['wpcpm_sync_test_meta']  = array(
	27 => array(
		WPCPM_Learn_Progress::META_REVOKE_PENDING => 'corrupted-ciphertext',
	),
);
$GLOBALS['wpcpm_sync_http_calls'] = array();
$wpcpm_summary                    = WPCPM_Learn_Progress_Sync::retry_revocations();
wpcpm_sync_check( 'retry_revocations unseal failure returns retried=1 cleared=0 failed=1', $wpcpm_summary, array( 'retried' => 1, 'cleared' => 0, 'failed' => 1 ) );
wpcpm_sync_check( 'retry_revocations unseal failure makes no HTTP request', count( $GLOBALS['wpcpm_sync_http_calls'] ), 0 );
wpcpm_sync_check( 'retry_revocations unseal failure preserves META_REVOKE_PENDING', get_user_meta( 27, WPCPM_Learn_Progress::META_REVOKE_PENDING, true ), 'corrupted-ciphertext' );

// 10g. SEC-20: learn_enabled = false returns zeros and makes no HTTP calls.
WPCPM_Settings::$options['learn_enabled'] = false;
$GLOBALS['wpcpm_sync_test_meta']          = array(
	28 => array(
		WPCPM_Learn_Progress::META_REVOKE_PENDING => $wpcpm_sync_sealed_token,
	),
);
$GLOBALS['wpcpm_sync_http_calls']         = array();
$wpcpm_summary                            = WPCPM_Learn_Progress_Sync::retry_revocations();
wpcpm_sync_check( 'SEC-20 disabled switch returns all zero counts', $wpcpm_summary, array( 'retried' => 0, 'cleared' => 0, 'failed' => 0 ) );
wpcpm_sync_check( 'SEC-20 disabled switch makes no HTTP call', count( $GLOBALS['wpcpm_sync_http_calls'] ), 0 );
wpcpm_sync_check( 'SEC-20 disabled switch leaves META_REVOKE_PENDING untouched', get_user_meta( 28, WPCPM_Learn_Progress::META_REVOKE_PENDING, true ), $wpcpm_sync_sealed_token );

if ( 0 !== $wpcpm_sync_failures ) {
	echo "\n{$wpcpm_sync_failures} FAILED ({$wpcpm_sync_checks} checks)\n";
	exit( 1 );
}

echo "\nALL PASS ({$wpcpm_sync_checks} checks)\n";

