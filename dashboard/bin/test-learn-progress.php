<?php
/**
 * Learn Link module settings and feature switch.
 *
 * Run from the plugin root: php bin/test-learn-progress.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPCPM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['wpcpm_learn_test_settings'] = array();
$GLOBALS['wpcpm_learn_test_actions']  = array();
$GLOBALS['wpcpm_learn_sync_calls']    = 0;
$GLOBALS['wpcpm_learn_test_meta']     = array();
$GLOBALS['wpcpm_learn_test_filters']  = array();
$GLOBALS['wpcpm_learn_test_events']   = array();
$GLOBALS['wpcpm_learn_test_flash']    = array();
$GLOBALS['wpcpm_learn_test_transient'] = array();
$GLOBALS['wpcpm_learn_test_redirects'] = array();
$GLOBALS['wpcpm_learn_test_user']      = null;
$GLOBALS['wpcpm_learn_test_is_student'] = false;
$GLOBALS['wpcpm_learn_test_page_url']   = 'https://dashboard.test/student-dashboard/';
$GLOBALS['wpcpm_learn_test_nonce']      = '';
$GLOBALS['wpcpm_learn_test_exchange']   = null;
$GLOBALS['wpcpm_learn_exchange_calls']  = array();
$GLOBALS['wpcpm_learn_revoke_calls']    = array();
$GLOBALS['wpcpm_learn_refresh_calls']   = array();
$GLOBALS['wpcpm_learn_refresh_result']  = true;
$GLOBALS['wpcpm_learn_seal_result']     = null;
$GLOBALS['wpcpm_learn_auth_redirects']  = 0;

class WP_User {
	public $ID;

	public function __construct( $user_id ) {
		$this->ID = (int) $user_id;
	}
}

class WPCPM_Learn_Test_Redirect extends Exception {}
class WPCPM_Learn_Test_Auth_Redirect extends Exception {}

class WP_Error {
	private $code;

	public function __construct( $code ) {
		$this->code = (string) $code;
	}

	public function get_error_code() {
		return $this->code;
	}
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

/**
 * Minimal settings stand-in for this isolated module test.
 */
class WPCPM_Settings {
	/**
	 * Return one test setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value used when the setting is absent.
	 * @return mixed
	 */
	public static function get_value( $key, $fallback = null ) {
		return array_key_exists( $key, $GLOBALS['wpcpm_learn_test_settings'] )
			? $GLOBALS['wpcpm_learn_test_settings'][ $key ]
			: $fallback;
	}
}

class WPCPM_Flash {
	public static function set( $channel, $value ) {
		$GLOBALS['wpcpm_learn_test_flash'][ (string) $channel ] = $value;
	}

	public static function take( $channel ) {
		$channel = (string) $channel;
		$value   = isset( $GLOBALS['wpcpm_learn_test_flash'][ $channel ] )
			? $GLOBALS['wpcpm_learn_test_flash'][ $channel ]
			: '';

		unset( $GLOBALS['wpcpm_learn_test_flash'][ $channel ] );

		return $value;
	}
}

class WPCPM_Students_Dashboard {
	public static function is_student( $user = null ) {
		$GLOBALS['wpcpm_learn_test_events'][] = 'student';

		return $user instanceof WP_User && $GLOBALS['wpcpm_learn_test_is_student'];
	}

	public static function page_url() {
		return (string) $GLOBALS['wpcpm_learn_test_page_url'];
	}
}

/**
 * Record whether the optional sync module starts.
 */
class WPCPM_Learn_Progress_Sync {
	/**
	 * Record one initialization.
	 */
	public static function init() {
		++$GLOBALS['wpcpm_learn_sync_calls'];
	}

	public static function refresh( $student_id, $force = false ) {
		$GLOBALS['wpcpm_learn_refresh_calls'][] = array( (int) $student_id, (bool) $force );

		return $GLOBALS['wpcpm_learn_refresh_result'];
	}
}

class WPCPM_Learn_Client {
	public static function exchange( $code, $verifier ) {
		$GLOBALS['wpcpm_learn_exchange_calls'][] = array( (string) $code, (string) $verifier );

		return $GLOBALS['wpcpm_learn_test_exchange'];
	}

	public static function revoke( $token ) {
		$GLOBALS['wpcpm_learn_revoke_calls'][] = (string) $token;

		return true;
	}
}

class WPCPM_Secret {
	public static function seal_for_option( $token ) {
		if ( null !== $GLOBALS['wpcpm_learn_seal_result'] ) {
			return $GLOBALS['wpcpm_learn_seal_result'];
		}

		return 'sealed:' . (string) $token;
	}
}

/**
 * Record registered actions.
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Hook callback.
 */
function add_action( $hook, $callback ) {
	$GLOBALS['wpcpm_learn_test_actions'][ $hook ] = $callback;
}

function add_filter( $hook, $callback ) {
	$GLOBALS['wpcpm_learn_test_filters'][ (string) $hook ] = $callback;
}

function remove_filter( $hook, $callback ) {
	if ( isset( $GLOBALS['wpcpm_learn_test_filters'][ (string) $hook ] ) && $GLOBALS['wpcpm_learn_test_filters'][ (string) $hook ] === $callback ) {
		unset( $GLOBALS['wpcpm_learn_test_filters'][ (string) $hook ] );
	}
}

function admin_url( $path = '' ) {
	return 'https://dashboard.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, "/\\" );
}

function add_query_arg( $args, $url ) {
	$pairs = array();

	foreach ( (array) $args as $key => $value ) {
		$pairs[] = rawurlencode( (string) $key ) . '=' . (string) $value;
	}

	return (string) $url . '?' . implode( '&', $pairs );
}

function get_user_meta( $user_id, $key, $single = false ) {
	unset( $single );

	return isset( $GLOBALS['wpcpm_learn_test_meta'][ (int) $user_id ][ (string) $key ] )
		? $GLOBALS['wpcpm_learn_test_meta'][ (int) $user_id ][ (string) $key ]
		: '';
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['wpcpm_learn_test_meta'][ (int) $user_id ][ (string) $key ] = $value;

	return true;
}

function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['wpcpm_learn_test_meta'][ (int) $user_id ][ (string) $key ] );

	return true;
}

function check_admin_referer( $action ) {
	$GLOBALS['wpcpm_learn_test_events'][] = 'nonce';
	$GLOBALS['wpcpm_learn_test_nonce']    = (string) $action;
}

function wp_get_current_user() {
	return $GLOBALS['wpcpm_learn_test_user'];
}

function get_current_user_id() {
	$GLOBALS['wpcpm_learn_test_events'][] = 'current_user';

	return $GLOBALS['wpcpm_learn_test_user'] instanceof WP_User
		? (int) $GLOBALS['wpcpm_learn_test_user']->ID
		: 0;
}

function set_transient( $key, $value, $expiration ) {
	$GLOBALS['wpcpm_learn_test_transient'][ (string) $key ] = array(
		'value'      => $value,
		'expiration' => (int) $expiration,
	);
}

function get_transient( $key ) {
	$key = (string) $key;
	$GLOBALS['wpcpm_learn_test_events'][] = 'transient:get:' . $key;

	return isset( $GLOBALS['wpcpm_learn_test_transient'][ $key ] )
		? $GLOBALS['wpcpm_learn_test_transient'][ $key ]['value']
		: false;
}

function delete_transient( $key ) {
	$key = (string) $key;
	$GLOBALS['wpcpm_learn_test_events'][] = 'transient:delete:' . $key;
	unset( $GLOBALS['wpcpm_learn_test_transient'][ $key ] );

	return true;
}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( (string) $url, $component );
}

function home_url( $path = '' ) {
	return 'https://dashboard.test/' . ltrim( (string) $path, '/' );
}

function wp_safe_redirect( $url ) {
	$hosts = array( 'dashboard.test' );

	if ( isset( $GLOBALS['wpcpm_learn_test_filters']['allowed_redirect_hosts'] ) ) {
		$hosts = call_user_func( $GLOBALS['wpcpm_learn_test_filters']['allowed_redirect_hosts'], $hosts );
	}

	$GLOBALS['wpcpm_learn_test_allowed_hosts'] = $hosts;
	$GLOBALS['wpcpm_learn_test_redirects'][]   = (string) $url;

	throw new WPCPM_Learn_Test_Redirect( (string) $url );
}

function auth_redirect() {
	++$GLOBALS['wpcpm_learn_auth_redirects'];

	throw new WPCPM_Learn_Test_Auth_Redirect();
}

require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-progress.php';

$wpcpm_learn_failures = 0;
$wpcpm_learn_checks   = 0;

/**
 * Compare one result with its expected value.
 *
 * @param string $label Test label.
 * @param mixed  $got   Actual value.
 * @param mixed  $want  Expected value.
 */
function wpcpm_learn_check( $label, $got, $want ) {
	global $wpcpm_learn_failures, $wpcpm_learn_checks;

	++$wpcpm_learn_checks;
	if ( $got === $want ) {
		echo "OK: {$label}\n";
		return;
	}

	++$wpcpm_learn_failures;
	echo "FAIL: {$label}\n";
	echo '  got:  ' . var_export( $got, true ) . "\n";
	echo '  want: ' . var_export( $want, true ) . "\n";
}

function wpcpm_learn_connect_attempt( $user_id, $is_student, array $meta = array(), $page_url = 'https://dashboard.test/student-dashboard/' ) {
	$GLOBALS['wpcpm_learn_test_meta']          = array( (int) $user_id => $meta );
	$GLOBALS['wpcpm_learn_test_filters']       = array();
	$GLOBALS['wpcpm_learn_test_events']        = array();
	$GLOBALS['wpcpm_learn_test_flash']         = array();
	$GLOBALS['wpcpm_learn_test_transient']     = array();
	$GLOBALS['wpcpm_learn_test_redirects']     = array();
	$GLOBALS['wpcpm_learn_test_allowed_hosts'] = array();
	$GLOBALS['wpcpm_learn_test_user']          = new WP_User( $user_id );
	$GLOBALS['wpcpm_learn_test_is_student']    = (bool) $is_student;
	$GLOBALS['wpcpm_learn_test_page_url']      = (string) $page_url;
	$GLOBALS['wpcpm_learn_test_nonce']         = '';

	try {
		WPCPM_Learn_Progress::handle_connect();
	} catch ( WPCPM_Learn_Test_Redirect $redirect ) {
		unset( $redirect );
	}

	return array(
		'events'        => $GLOBALS['wpcpm_learn_test_events'],
		'flash'         => $GLOBALS['wpcpm_learn_test_flash'],
		'transient'     => $GLOBALS['wpcpm_learn_test_transient'],
		'redirects'     => $GLOBALS['wpcpm_learn_test_redirects'],
		'allowed_hosts' => $GLOBALS['wpcpm_learn_test_allowed_hosts'],
	);
}

function wpcpm_learn_callback_attempt( $user_id, array $request, $held = false, array $meta = array(), $exchange = null, $seal = null, $refresh = true ) {
	$state = isset( $request['state'] ) ? (string) $request['state'] : '';
	$key   = WPCPM_Learn_Progress::STATE_PREFIX . $state;

	$_GET                                      = $request;
	$GLOBALS['wpcpm_learn_test_meta']          = array( (int) $user_id => $meta );
	$GLOBALS['wpcpm_learn_test_events']        = array();
	$GLOBALS['wpcpm_learn_test_flash']         = array();
	$GLOBALS['wpcpm_learn_test_transient']     = array();
	$GLOBALS['wpcpm_learn_test_redirects']     = array();
	$GLOBALS['wpcpm_learn_test_user']          = new WP_User( $user_id );
	$GLOBALS['wpcpm_learn_test_page_url']      = 'https://dashboard.test/student-dashboard/';
	$GLOBALS['wpcpm_learn_test_exchange']      = $exchange;
	$GLOBALS['wpcpm_learn_exchange_calls']     = array();
	$GLOBALS['wpcpm_learn_revoke_calls']       = array();
	$GLOBALS['wpcpm_learn_refresh_calls']      = array();
	$GLOBALS['wpcpm_learn_refresh_result']     = $refresh;
	$GLOBALS['wpcpm_learn_seal_result']        = $seal;

	if ( false !== $held && '' !== $state ) {
		$GLOBALS['wpcpm_learn_test_transient'][ $key ] = array(
			'value'      => $held,
			'expiration' => 15 * MINUTE_IN_SECONDS,
		);
	}

	try {
		WPCPM_Learn_Progress::handle_callback();
	} catch ( WPCPM_Learn_Test_Redirect $redirect ) {
		unset( $redirect );
	}

	return array(
		'events'         => $GLOBALS['wpcpm_learn_test_events'],
		'flash'          => $GLOBALS['wpcpm_learn_test_flash'],
		'transient'      => $GLOBALS['wpcpm_learn_test_transient'],
		'meta'           => isset( $GLOBALS['wpcpm_learn_test_meta'][ (int) $user_id ] )
			? $GLOBALS['wpcpm_learn_test_meta'][ (int) $user_id ]
			: array(),
		'exchange_calls' => $GLOBALS['wpcpm_learn_exchange_calls'],
		'revoke_calls'   => $GLOBALS['wpcpm_learn_revoke_calls'],
		'refresh_calls'  => $GLOBALS['wpcpm_learn_refresh_calls'],
		'redirects'      => $GLOBALS['wpcpm_learn_test_redirects'],
	);
}

wpcpm_learn_check( 'disabled by default', WPCPM_Learn_Progress::enabled(), false );

// WordPress stores a transient's value and expiration as separate options.
$wpcpm_learn_state_key = WPCPM_Learn_Progress::STATE_PREFIX . 'test-state';
wpcpm_learn_check(
	'state value is covered by the uninstall Learn sweep',
	0 === strpos( '_transient_' . $wpcpm_learn_state_key, '_transient_wpcpm_learn_' ),
	true
);
wpcpm_learn_check(
	'state timeout is covered by the uninstall Learn sweep',
	0 === strpos( '_transient_timeout_' . $wpcpm_learn_state_key, '_transient_timeout_wpcpm_learn_' ),
	true
);
wpcpm_learn_check(
	'default settings',
	WPCPM_Learn_Progress::settings(),
	array(
		'learn_enabled'     => false,
		'learn_base_url'    => 'https://learn.wordpress.org',
		'learn_client_id'   => 'wpcredits',
		'learn_courses'     => '',
		'learn_cache_hours' => 6,
	)
);

ob_start();
WPCPM_Learn_Progress::init();
$wpcpm_learn_disabled_output = ob_get_clean();
wpcpm_learn_check( 'disabled prints nothing', $wpcpm_learn_disabled_output, '' );
wpcpm_learn_check( 'disabled registers no handlers', $GLOBALS['wpcpm_learn_test_actions'], array() );
wpcpm_learn_check( 'disabled does not start sync', $GLOBALS['wpcpm_learn_sync_calls'], 0 );

$GLOBALS['wpcpm_learn_test_settings'] = array(
	'learn_enabled'     => true,
	'learn_base_url'    => 'http://localhost:8882',
	'learn_client_id'   => 'dashboard-local',
	'learn_courses'     => '297853,301001',
	'learn_cache_hours' => '12',
);

wpcpm_learn_check(
	'settings use stable types',
	WPCPM_Learn_Progress::settings(),
	array(
		'learn_enabled'     => true,
		'learn_base_url'    => 'http://localhost:8882',
		'learn_client_id'   => 'dashboard-local',
		'learn_courses'     => '297853,301001',
		'learn_cache_hours' => 12,
	)
);

$GLOBALS['wpcpm_learn_test_settings']['learn_courses'] = array( 297853, 301001 );
wpcpm_learn_check( 'a saved course ID list becomes stable text', WPCPM_Learn_Progress::settings()['learn_courses'], '297853,301001' );

$wpcpm_learn_state    = WPCPM_Learn_Progress::random_b64url( 32 );
$wpcpm_learn_verifier = WPCPM_Learn_Progress::random_b64url( 48 );

wpcpm_learn_check( '32 random bytes become 43 Base64URL characters', strlen( $wpcpm_learn_state ), 43 );
wpcpm_learn_check( '48 random bytes become 64 Base64URL characters', strlen( $wpcpm_learn_verifier ), 64 );
wpcpm_learn_check( 'random values use the Base64URL alphabet', 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $wpcpm_learn_state . $wpcpm_learn_verifier ), true );
wpcpm_learn_check(
	'RFC 7636 verifier produces the S256 challenge',
	WPCPM_Learn_Progress::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ),
	'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'
);

$wpcpm_learn_authorize_url = WPCPM_Learn_Progress::authorize_url( 'state value', 'challenge/value' );
$wpcpm_learn_query         = array();

parse_str( (string) parse_url( $wpcpm_learn_authorize_url, PHP_URL_QUERY ), $wpcpm_learn_query );

wpcpm_learn_check( 'authorize URL uses the Learn consent path', (string) parse_url( $wpcpm_learn_authorize_url, PHP_URL_PATH ), '/learn-link/authorize/' );
wpcpm_learn_check(
	'authorize URL has the five decoded parameters',
	$wpcpm_learn_query,
	array(
		'client_id'            => 'dashboard-local',
		'redirect_uri'          => 'https://dashboard.test/wp-admin/admin-post.php?action=wpcpm_learn_callback',
		'state'                 => 'state value',
		'code_challenge'        => 'challenge/value',
		'code_challenge_method' => 'S256',
	)
);
wpcpm_learn_check( 'authorize URL never contains a verifier', false === strpos( $wpcpm_learn_authorize_url, 'verifier' ), true );
wpcpm_learn_check( 'a student without a token is not connected', WPCPM_Learn_Progress::is_connected( 41 ), false );
$GLOBALS['wpcpm_learn_test_meta'][41][ WPCPM_Learn_Progress::META_TOKEN ] = 'sealed-token';
wpcpm_learn_check( 'a student with a sealed token is connected', WPCPM_Learn_Progress::is_connected( 41 ), true );

$GLOBALS['wpcpm_learn_test_settings']['learn_courses'] = '297853,301001';
$wpcpm_learn_not_started_cache                         = array(
	'fetched_at' => 200,
	'courses'    => array(
		297853 => array( 'status' => 'not-started' ),
		301001 => array( 'status' => 'not-started' ),
	),
);
$wpcpm_learn_in_progress_cache                         = array(
	'fetched_at' => 200,
	'courses'    => array(
		297853 => array( 'status' => 'in-progress' ),
		301001 => array( 'status' => 'complete' ),
	),
);
$wpcpm_learn_complete_cache                            = array(
	'fetched_at' => 200,
	'courses'    => array(
		297853 => array( 'status' => 'complete' ),
		301001 => array( 'status' => 'in-progress' ),
	),
);

$GLOBALS['wpcpm_learn_test_meta'][81] = array(
	WPCPM_Learn_Progress::META_WITHDRAWN_AT => 100,
	WPCPM_Learn_Progress::META_TOKEN        => 'sealed-token',
	WPCPM_Learn_Progress::META_CACHE        => $wpcpm_learn_complete_cache,
);
wpcpm_learn_check( 'withdrawal takes priority over connected progress', WPCPM_Learn_Progress::state_for( 81 ), 'withdrawn' );

$GLOBALS['wpcpm_learn_test_meta'][82] = array(
	WPCPM_Learn_Progress::META_CACHE => $wpcpm_learn_complete_cache,
);
wpcpm_learn_check( 'a student without a token has the not-connected state', WPCPM_Learn_Progress::state_for( 82 ), 'not_connected' );

$GLOBALS['wpcpm_learn_test_meta'][83] = array(
	WPCPM_Learn_Progress::META_TOKEN => 'sealed-token',
);
wpcpm_learn_check( 'a connected student without cached progress is unavailable', WPCPM_Learn_Progress::state_for( 83 ), 'unavailable' );

$wpcpm_learn_unavailable_codes = array(
	'learn_progress_unavailable',
	'wpcpm_learn_unreachable',
	'wpcpm_learn_bad_answer',
	'wpcpm_learn_identity',
	'wpcpm_learn_seal',
);

foreach ( $wpcpm_learn_unavailable_codes as $wpcpm_learn_error_index => $wpcpm_learn_error_code ) {
	$wpcpm_learn_error_student = 84 + $wpcpm_learn_error_index;
	$GLOBALS['wpcpm_learn_test_meta'][ $wpcpm_learn_error_student ] = array(
		WPCPM_Learn_Progress::META_TOKEN => 'sealed-token',
		WPCPM_Learn_Progress::META_CACHE => $wpcpm_learn_in_progress_cache,
		WPCPM_Learn_Progress::META_ERROR => array(
			'code' => $wpcpm_learn_error_code,
			'at'   => 201,
		),
	);
	wpcpm_learn_check(
		'a newer ' . $wpcpm_learn_error_code . ' error makes progress unavailable',
		WPCPM_Learn_Progress::state_for( $wpcpm_learn_error_student ),
		'unavailable'
	);
}

$GLOBALS['wpcpm_learn_test_meta'][89] = array(
	WPCPM_Learn_Progress::META_TOKEN => 'sealed-token',
	WPCPM_Learn_Progress::META_CACHE => $wpcpm_learn_in_progress_cache,
	WPCPM_Learn_Progress::META_ERROR => array(
		'code' => 'wpcpm_learn_unreachable',
		'at'   => 199,
	),
);
wpcpm_learn_check( 'an older operational error does not hide newer cached progress', WPCPM_Learn_Progress::state_for( 89 ), 'in_progress' );

$GLOBALS['wpcpm_learn_test_meta'][90] = array(
	WPCPM_Learn_Progress::META_TOKEN => 'sealed-token',
	WPCPM_Learn_Progress::META_CACHE => $wpcpm_learn_not_started_cache,
);
wpcpm_learn_check( 'all cached courses not started produce nothing-started', WPCPM_Learn_Progress::state_for( 90 ), 'nothing_started' );

$GLOBALS['wpcpm_learn_test_meta'][91] = array(
	WPCPM_Learn_Progress::META_TOKEN => 'sealed-token',
	WPCPM_Learn_Progress::META_CACHE => $wpcpm_learn_complete_cache,
);
wpcpm_learn_check( 'a complete first configured course produces complete', WPCPM_Learn_Progress::state_for( 91 ), 'complete' );

$GLOBALS['wpcpm_learn_test_meta'][92] = array(
	WPCPM_Learn_Progress::META_TOKEN => 'sealed-token',
	WPCPM_Learn_Progress::META_CACHE => $wpcpm_learn_in_progress_cache,
);
wpcpm_learn_check( 'a later complete course does not replace the first configured track', WPCPM_Learn_Progress::state_for( 92 ), 'in_progress' );

$GLOBALS['wpcpm_learn_test_settings']['learn_courses'] = '';
$GLOBALS['wpcpm_learn_test_meta'][93]                  = array(
	WPCPM_Learn_Progress::META_TOKEN => 'sealed-token',
	WPCPM_Learn_Progress::META_CACHE => $wpcpm_learn_complete_cache,
);
wpcpm_learn_check( 'an empty override uses the first cached track course', WPCPM_Learn_Progress::state_for( 93 ), 'complete' );
$GLOBALS['wpcpm_learn_test_settings']['learn_courses'] = '297853,301001';

$wpcpm_learn_manager = wpcpm_learn_connect_attempt( 51, false );
wpcpm_learn_check( 'Connect checks the nonce before student authorization', $wpcpm_learn_manager['events'], array( 'nonce', 'student' ) );
wpcpm_learn_check( 'an Administrator gets the only-student outcome', $wpcpm_learn_manager['flash'], array( 'learn' => 'learn_only_student' ) );
wpcpm_learn_check( 'an Administrator creates no transient', $wpcpm_learn_manager['transient'], array() );

$wpcpm_learn_withdrawn = wpcpm_learn_connect_attempt(
	52,
	true,
	array( WPCPM_Learn_Progress::META_WITHDRAWN_AT => gmmktime( 9, 0, 0, 11, 4, 2026 ) )
);
wpcpm_learn_check( 'a withdrawn student gets the withdrawn outcome', $wpcpm_learn_withdrawn['flash'], array( 'learn' => 'learn_withdrawn' ) );
wpcpm_learn_check( 'a withdrawn student creates no transient', $wpcpm_learn_withdrawn['transient'], array() );

$wpcpm_learn_connected = wpcpm_learn_connect_attempt(
	53,
	true,
	array( WPCPM_Learn_Progress::META_TOKEN => 'sealed-token' )
);
wpcpm_learn_check( 'a connected student gets the already-connected outcome', $wpcpm_learn_connected['flash'], array( 'learn' => 'learn_already_connected' ) );
wpcpm_learn_check( 'a connected student creates no transient', $wpcpm_learn_connected['transient'], array() );

$wpcpm_learn_valid = wpcpm_learn_connect_attempt( 54, true );
wpcpm_learn_check( 'a valid Connect verifies the expected nonce action', $GLOBALS['wpcpm_learn_test_nonce'], WPCPM_Learn_Progress::ACTION_CONNECT );
wpcpm_learn_check( 'a valid Connect creates one transient', count( $wpcpm_learn_valid['transient'] ), 1 );

if ( ! empty( $wpcpm_learn_valid['transient'] ) ) {
	$wpcpm_learn_transient_key  = array_key_first( $wpcpm_learn_valid['transient'] );
	$wpcpm_learn_transient      = $wpcpm_learn_valid['transient'][ $wpcpm_learn_transient_key ];
	$wpcpm_learn_transient_data = $wpcpm_learn_transient['value'];
	$wpcpm_learn_state          = substr( $wpcpm_learn_transient_key, strlen( WPCPM_Learn_Progress::STATE_PREFIX ) );
	$wpcpm_learn_redirect_query = array();

	parse_str( (string) parse_url( $wpcpm_learn_valid['redirects'][0], PHP_URL_QUERY ), $wpcpm_learn_redirect_query );

	wpcpm_learn_check( 'the transient uses the dedicated state prefix', 0 === strpos( $wpcpm_learn_transient_key, WPCPM_Learn_Progress::STATE_PREFIX ), true );
	wpcpm_learn_check( 'the state has 43 Base64URL characters', 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $wpcpm_learn_state ), true );
	wpcpm_learn_check( 'the transient belongs to the acting student', $wpcpm_learn_transient_data['user'], 54 );
	wpcpm_learn_check( 'the transient holds a 64-character verifier', 1 === preg_match( '/^[A-Za-z0-9_-]{64}$/', $wpcpm_learn_transient_data['verifier'] ), true );
	wpcpm_learn_check( 'the transient records its creation time', abs( time() - $wpcpm_learn_transient_data['at'] ) <= 2, true );
	wpcpm_learn_check( 'the transient lasts 15 minutes', $wpcpm_learn_transient['expiration'], 15 * MINUTE_IN_SECONDS );
	wpcpm_learn_check( 'the redirect state identifies the stored transient', $wpcpm_learn_redirect_query['state'], $wpcpm_learn_state );
	wpcpm_learn_check( 'the redirect challenge belongs to the stored verifier', $wpcpm_learn_redirect_query['code_challenge'], WPCPM_Learn_Progress::challenge( $wpcpm_learn_transient_data['verifier'] ) );
	wpcpm_learn_check( 'the verifier never travels in the redirect URL', false === strpos( implode( '', $wpcpm_learn_valid['redirects'] ), $wpcpm_learn_transient_data['verifier'] ), true );
}

wpcpm_learn_check( 'a valid Connect redirects once', count( $wpcpm_learn_valid['redirects'] ), 1 );
wpcpm_learn_check( 'the safe redirect allows only the configured Learn host in addition to the Dashboard', $wpcpm_learn_valid['allowed_hosts'], array( 'dashboard.test', 'localhost' ) );

$wpcpm_learn_manager = wpcpm_learn_connect_attempt( 55, false, array(), '' );
wpcpm_learn_check( 'a missing Student Dashboard page falls back home', $wpcpm_learn_manager['redirects'], array( 'https://dashboard.test/' ) );

$wpcpm_learn_missing_state = wpcpm_learn_callback_attempt( 61, array() );
wpcpm_learn_check( 'a callback without state gets the expired outcome', $wpcpm_learn_missing_state['flash'], array( 'learn' => 'learn_state' ) );
wpcpm_learn_check( 'a callback without state makes no exchange', $wpcpm_learn_missing_state['exchange_calls'], array() );

$wpcpm_learn_expired_state = wpcpm_learn_callback_attempt( 61, array( 'state' => 'expired-state' ) );
wpcpm_learn_check(
	'an expired state is read and deleted before the user check',
	$wpcpm_learn_expired_state['events'],
	array(
		'transient:get:wpcpm_learn_state_expired-state',
		'transient:delete:wpcpm_learn_state_expired-state',
		'current_user',
	)
);
wpcpm_learn_check( 'an expired state makes no exchange', $wpcpm_learn_expired_state['exchange_calls'], array() );

$wpcpm_learn_other_user = wpcpm_learn_callback_attempt(
	61,
	array( 'state' => 'another-user' ),
	array( 'user' => 99, 'verifier' => str_repeat( 'v', 64 ), 'at' => time() )
);
wpcpm_learn_check( 'another user gets the same expired outcome', $wpcpm_learn_other_user['flash'], array( 'learn' => 'learn_state' ) );
wpcpm_learn_check( 'another user state is consumed', $wpcpm_learn_other_user['transient'], array() );
wpcpm_learn_check( 'another user state makes no exchange', $wpcpm_learn_other_user['exchange_calls'], array() );

$wpcpm_learn_withdrawn_callback = wpcpm_learn_callback_attempt(
	610,
	array( 'state' => 'withdrawn-callback', 'code' => 'withdrawn-code' ),
	array( 'user' => 610, 'verifier' => str_repeat( 'w', 64 ), 'at' => time() ),
	array(
		'wpcpm_student_program'                   => array( 'username' => 'adaexample' ),
		WPCPM_Learn_Progress::META_WITHDRAWN_AT => 123,
		WPCPM_Learn_Progress::META_WITHDRAWN_BY => 7,
	),
	array( 'token' => str_repeat( 'T', 43 ), 'username' => 'AdaExample' )
);
wpcpm_learn_check( 'a withdrawn callback consumes the state', $wpcpm_learn_withdrawn_callback['transient'], array() );
wpcpm_learn_check( 'a withdrawn callback gets the withdrawn outcome', $wpcpm_learn_withdrawn_callback['flash'], array( 'learn' => 'learn_withdrawn' ) );
wpcpm_learn_check( 'a withdrawn callback makes no exchange', $wpcpm_learn_withdrawn_callback['exchange_calls'], array() );
wpcpm_learn_check( 'a withdrawn callback keeps the withdrawal date', $wpcpm_learn_withdrawn_callback['meta'][ WPCPM_Learn_Progress::META_WITHDRAWN_AT ], 123 );
wpcpm_learn_check( 'a withdrawn callback keeps the withdrawing Administrator', $wpcpm_learn_withdrawn_callback['meta'][ WPCPM_Learn_Progress::META_WITHDRAWN_BY ], 7 );
wpcpm_learn_check( 'a withdrawn callback stores no token', isset( $wpcpm_learn_withdrawn_callback['meta'][ WPCPM_Learn_Progress::META_TOKEN ] ), false );

$wpcpm_learn_denied = wpcpm_learn_callback_attempt(
	62,
	array( 'state' => 'denied-state', 'error' => 'access_denied' ),
	array( 'user' => 62, 'verifier' => str_repeat( 'd', 64 ), 'at' => time() )
);
wpcpm_learn_check( 'denial consumes the state', $wpcpm_learn_denied['transient'], array() );
wpcpm_learn_check( 'denial gets its own outcome', $wpcpm_learn_denied['flash'], array( 'learn' => 'learn_denied' ) );
wpcpm_learn_check( 'denial makes no exchange', $wpcpm_learn_denied['exchange_calls'], array() );

$wpcpm_learn_reused_state = wpcpm_learn_callback_attempt( 62, array( 'state' => 'denied-state', 'error' => 'access_denied' ) );
wpcpm_learn_check( 'a reused state is expired even when denial is repeated', $wpcpm_learn_reused_state['flash'], array( 'learn' => 'learn_state' ) );

$wpcpm_learn_missing_code = wpcpm_learn_callback_attempt(
	63,
	array( 'state' => 'missing-code' ),
	array( 'user' => 63, 'verifier' => str_repeat( 'm', 64 ), 'at' => time() )
);
wpcpm_learn_check( 'a missing code gets the exchange outcome', $wpcpm_learn_missing_code['flash'], array( 'learn' => 'learn_exchange' ) );
wpcpm_learn_check( 'a missing code makes no request', $wpcpm_learn_missing_code['exchange_calls'], array() );

$wpcpm_learn_exchange_error = wpcpm_learn_callback_attempt(
	64,
	array( 'state' => 'exchange-error', 'code' => 'one-time-code' ),
	array( 'user' => 64, 'verifier' => str_repeat( 'e', 64 ), 'at' => time() ),
	array( 'wpcpm_student_program' => array( 'username' => 'adaexample' ) ),
	new WP_Error( 'learn_progress_invalid_grant' )
);
wpcpm_learn_check(
	'the exchange receives the code and held verifier',
	$wpcpm_learn_exchange_error['exchange_calls'],
	array( array( 'one-time-code', str_repeat( 'e', 64 ) ) )
);
wpcpm_learn_check( 'an exchange error gets its own outcome', $wpcpm_learn_exchange_error['flash'], array( 'learn' => 'learn_exchange' ) );
wpcpm_learn_check( 'an exchange error stores no connection meta', array_keys( $wpcpm_learn_exchange_error['meta'] ), array( 'wpcpm_student_program' ) );

wpcpm_learn_check( 'identity accepts the same username', WPCPM_Learn_Progress::identity_matches( 'adaexample', 'adaexample' ), true );
wpcpm_learn_check( 'identity ignores username case', WPCPM_Learn_Progress::identity_matches( 'AdaExample', 'adaexample' ), true );
wpcpm_learn_check( 'identity accepts the matching profile slug', WPCPM_Learn_Progress::identity_matches( array( 'login-name', 'AdaExample' ), 'adaexample' ), true );
wpcpm_learn_check( 'identity refuses another username', WPCPM_Learn_Progress::identity_matches( 'otherperson', 'adaexample' ), false );
wpcpm_learn_check( 'identity refuses two different Learn names', WPCPM_Learn_Progress::identity_matches( array( 'login-name', 'other-profile' ), 'adaexample' ), false );
wpcpm_learn_check( 'identity refuses a missing known username', WPCPM_Learn_Progress::identity_matches( 'adaexample', '' ), false );

$wpcpm_learn_token = str_repeat( 'T', 43 );
$wpcpm_learn_identity_error = wpcpm_learn_callback_attempt(
	65,
	array( 'state' => 'identity-error', 'code' => 'identity-code' ),
	array( 'user' => 65, 'verifier' => str_repeat( 'i', 64 ), 'at' => time() ),
	array( 'wpcpm_student_program' => array( 'username' => 'adaexample' ) ),
	array( 'token' => $wpcpm_learn_token, 'username' => 'otherperson' )
);
wpcpm_learn_check( 'identity mismatch revokes once', $wpcpm_learn_identity_error['revoke_calls'], array( $wpcpm_learn_token ) );
wpcpm_learn_check(
	'identity mismatch keeps the two usernames for the contract message',
	$wpcpm_learn_identity_error['flash'],
	array(
		'learn' => array(
			'code'           => 'learn_identity',
			'learn_username' => 'otherperson',
			'known_username' => 'adaexample',
		),
	)
);
wpcpm_learn_check( 'identity mismatch stores no connection meta', array_keys( $wpcpm_learn_identity_error['meta'] ), array( 'wpcpm_student_program' ) );

$wpcpm_learn_missing_identity = wpcpm_learn_callback_attempt(
	66,
	array( 'state' => 'identity-missing', 'code' => 'identity-code' ),
	array( 'user' => 66, 'verifier' => str_repeat( 'k', 64 ), 'at' => time() ),
	array( 'wpcpm_student_program' => array() ),
	array( 'token' => $wpcpm_learn_token, 'username' => 'adaexample' )
);
wpcpm_learn_check( 'missing known identity revokes once', $wpcpm_learn_missing_identity['revoke_calls'], array( $wpcpm_learn_token ) );
wpcpm_learn_check( 'missing known identity gets its own safe outcome', $wpcpm_learn_missing_identity['flash'], array( 'learn' => 'learn_identity_missing' ) );

$wpcpm_learn_slug_identity = wpcpm_learn_callback_attempt(
	670,
	array( 'state' => 'slug-identity', 'code' => 'slug-code' ),
	array( 'user' => 670, 'verifier' => str_repeat( 'p', 64 ), 'at' => time() ),
	array( 'wpcpm_student_program' => array( 'username' => 'adaexample' ) ),
	array(
		'token'        => $wpcpm_learn_token,
		'username'     => 'login-name',
		'profile_slug' => 'AdaExample',
	)
);
wpcpm_learn_check( 'a matching profile slug connects the account', isset( $wpcpm_learn_slug_identity['meta'][ WPCPM_Learn_Progress::META_TOKEN ] ), true );
wpcpm_learn_check( 'a matching profile slug does not revoke', $wpcpm_learn_slug_identity['revoke_calls'], array() );
wpcpm_learn_check( 'a profile-slug match stores the primary Learn username', $wpcpm_learn_slug_identity['meta'][ WPCPM_Learn_Progress::META_USERNAME ], 'login-name' );

$wpcpm_learn_seal_error = wpcpm_learn_callback_attempt(
	67,
	array( 'state' => 'seal-error', 'code' => 'seal-code' ),
	array( 'user' => 67, 'verifier' => str_repeat( 's', 64 ), 'at' => time() ),
	array( 'wpcpm_student_program' => array( 'username' => 'adaexample' ) ),
	array( 'token' => $wpcpm_learn_token, 'username' => 'AdaExample' ),
	new WP_Error( 'wpcpm_private_cipher' )
);
wpcpm_learn_check( 'seal failure revokes once', $wpcpm_learn_seal_error['revoke_calls'], array( $wpcpm_learn_token ) );
wpcpm_learn_check( 'seal failure gets its own outcome', $wpcpm_learn_seal_error['flash'], array( 'learn' => 'learn_seal' ) );
wpcpm_learn_check( 'seal failure stores no connection meta', array_keys( $wpcpm_learn_seal_error['meta'] ), array( 'wpcpm_student_program' ) );

$wpcpm_learn_success = wpcpm_learn_callback_attempt(
	68,
	array( 'state' => 'success-state', 'code' => 'success-code' ),
	array( 'user' => 68, 'verifier' => str_repeat( 'z', 64 ), 'at' => time() ),
	array( 'wpcpm_student_program' => array( 'username' => 'adaexample' ) ),
	array( 'token' => $wpcpm_learn_token, 'username' => 'AdaExample' )
);
wpcpm_learn_check( 'success stores a sealed token', $wpcpm_learn_success['meta'][ WPCPM_Learn_Progress::META_TOKEN ], 'sealed:' . $wpcpm_learn_token );
wpcpm_learn_check( 'success never stores the plain token', $wpcpm_learn_success['meta'][ WPCPM_Learn_Progress::META_TOKEN ] === $wpcpm_learn_token, false );
wpcpm_learn_check( 'success records the connection time', abs( time() - $wpcpm_learn_success['meta'][ WPCPM_Learn_Progress::META_CONNECTED ] ) <= 2, true );
wpcpm_learn_check( 'success stores the username Learn confirmed', $wpcpm_learn_success['meta'][ WPCPM_Learn_Progress::META_USERNAME ], 'AdaExample' );
wpcpm_learn_check( 'success refreshes once and forces the first read', $wpcpm_learn_success['refresh_calls'], array( array( 68, true ) ) );
wpcpm_learn_check( 'success does not revoke', $wpcpm_learn_success['revoke_calls'], array() );
wpcpm_learn_check( 'success gets the connected outcome', $wpcpm_learn_success['flash'], array( 'learn' => 'learn_connected' ) );

$wpcpm_learn_refresh_error = wpcpm_learn_callback_attempt(
	69,
	array( 'state' => 'refresh-error', 'code' => 'refresh-code' ),
	array( 'user' => 69, 'verifier' => str_repeat( 'r', 64 ), 'at' => time() ),
	array( 'wpcpm_student_program' => array( 'username' => 'adaexample' ) ),
	array( 'token' => $wpcpm_learn_token, 'username' => 'adaexample' ),
	null,
	new WP_Error( 'wpcpm_learn_unreachable' )
);
wpcpm_learn_check( 'refresh failure keeps the sealed connection', $wpcpm_learn_refresh_error['meta'][ WPCPM_Learn_Progress::META_TOKEN ], 'sealed:' . $wpcpm_learn_token );
wpcpm_learn_check( 'refresh failure still reports a connected account', $wpcpm_learn_refresh_error['flash'], array( 'learn' => 'learn_connected' ) );

$GLOBALS['wpcpm_learn_auth_redirects'] = 0;
try {
	WPCPM_Learn_Progress::handle_callback_nopriv();
} catch ( WPCPM_Learn_Test_Auth_Redirect $redirect ) {
	unset( $redirect );
}
wpcpm_learn_check( 'a signed-out callback uses the WordPress login flow', $GLOBALS['wpcpm_learn_auth_redirects'], 1 );

WPCPM_Learn_Progress::init();
wpcpm_learn_check( 'enabled starts sync once', $GLOBALS['wpcpm_learn_sync_calls'], 1 );
wpcpm_learn_check(
	'enabled registers only its four handlers',
	array_keys( $GLOBALS['wpcpm_learn_test_actions'] ),
	array(
		'admin_post_wpcpm_learn_connect',
		'admin_post_wpcpm_learn_callback',
		'admin_post_nopriv_wpcpm_learn_callback',
		'admin_post_wpcpm_learn_withdraw',
	)
);

wpcpm_learn_check(
	'connect callback',
	$GLOBALS['wpcpm_learn_test_actions']['admin_post_wpcpm_learn_connect'],
	array( 'WPCPM_Learn_Progress', 'handle_connect' )
);
wpcpm_learn_check(
	'callback callback',
	$GLOBALS['wpcpm_learn_test_actions']['admin_post_wpcpm_learn_callback'],
	array( 'WPCPM_Learn_Progress', 'handle_callback' )
);
wpcpm_learn_check(
	'signed-out callback callback',
	$GLOBALS['wpcpm_learn_test_actions']['admin_post_nopriv_wpcpm_learn_callback'],
	array( 'WPCPM_Learn_Progress', 'handle_callback_nopriv' )
);
wpcpm_learn_check(
	'withdraw callback',
	$GLOBALS['wpcpm_learn_test_actions']['admin_post_wpcpm_learn_withdraw'],
	array( 'WPCPM_Learn_Progress', 'handle_withdraw' )
);

$wpcpm_learn_parent = get_parent_class( 'WPCPM_Learn_Progress' );
wpcpm_learn_check( 'module does not extend another class', $wpcpm_learn_parent, false );

if ( 0 !== $wpcpm_learn_failures ) {
	echo "\n{$wpcpm_learn_failures} FAILED ({$wpcpm_learn_checks} checks)\n";
	exit( 1 );
}

echo "\nALL PASS ({$wpcpm_learn_checks} checks)\n";
