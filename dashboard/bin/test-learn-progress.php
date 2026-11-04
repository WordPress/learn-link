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

class WP_User {
	public $ID;

	public function __construct( $user_id ) {
		$this->ID = (int) $user_id;
	}
}

class WPCPM_Learn_Test_Redirect extends Exception {}

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

function check_admin_referer( $action ) {
	$GLOBALS['wpcpm_learn_test_events'][] = 'nonce';
	$GLOBALS['wpcpm_learn_test_nonce']    = (string) $action;
}

function wp_get_current_user() {
	return $GLOBALS['wpcpm_learn_test_user'];
}

function set_transient( $key, $value, $expiration ) {
	$GLOBALS['wpcpm_learn_test_transient'][ (string) $key ] = array(
		'value'      => $value,
		'expiration' => (int) $expiration,
	);
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

WPCPM_Learn_Progress::init();
wpcpm_learn_check( 'enabled starts sync once', $GLOBALS['wpcpm_learn_sync_calls'], 1 );
wpcpm_learn_check(
	'enabled registers only its three handlers',
	array_keys( $GLOBALS['wpcpm_learn_test_actions'] ),
	array(
		'admin_post_wpcpm_learn_connect',
		'admin_post_wpcpm_learn_callback',
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
