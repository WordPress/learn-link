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

$GLOBALS['wpcpm_learn_test_settings'] = array();
$GLOBALS['wpcpm_learn_test_actions']  = array();

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

/**
 * Record registered actions.
 *
 * @param string   $hook     Hook name.
 * @param callable $callback Hook callback.
 */
function add_action( $hook, $callback ) {
	$GLOBALS['wpcpm_learn_test_actions'][ $hook ] = $callback;
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

wpcpm_learn_check( 'disabled by default', WPCPM_Learn_Progress::enabled(), false );
wpcpm_learn_check(
	'default settings',
	WPCPM_Learn_Progress::settings(),
	array(
		'learn_enabled'     => false,
		'learn_base_url'    => 'https://learn.wordpress.org',
		'learn_client_id'   => 'wpcredits',
		'learn_courses'     => '297853',
		'learn_cache_hours' => 6,
	)
);

ob_start();
WPCPM_Learn_Progress::init();
$wpcpm_learn_disabled_output = ob_get_clean();
wpcpm_learn_check( 'disabled prints nothing', $wpcpm_learn_disabled_output, '' );
wpcpm_learn_check( 'disabled registers no handlers', $GLOBALS['wpcpm_learn_test_actions'], array() );

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

WPCPM_Learn_Progress::init();
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
wpcpm_learn_check( 'module does not extend another class', false, $wpcpm_learn_parent );

if ( 0 !== $wpcpm_learn_failures ) {
	echo "\n{$wpcpm_learn_failures} FAILED ({$wpcpm_learn_checks} checks)\n";
	exit( 1 );
}

echo "\nALL PASS ({$wpcpm_learn_checks} checks)\n";
