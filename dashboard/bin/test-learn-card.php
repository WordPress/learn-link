<?php
/**
 * Learn progress card renderer.
 *
 * Run from the plugin root: php bin/test-learn-card.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPCPM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WPCPM_LEARN_FIXTURE', true );

$GLOBALS['wpcpm_card_settings']        = array();
$GLOBALS['wpcpm_card_current_user_id'] = 0;

/**
 * Minimal WordPress user for the isolated panel test.
 */
class WP_User {
	/** @var int User ID. */
	public $ID;

	/**
	 * @param int $user_id User ID.
	 */
	public function __construct( $user_id ) {
		$this->ID = (int) $user_id;
	}
}

/**
 * Minimal WordPress error for the parser.
 */
class WP_Error {
	/** @var string Error code. */
	private $code;

	/**
	 * @param string $code Error code.
	 */
	public function __construct( $code ) {
		$this->code = $code;
	}

	/**
	 * @return string
	 */
	public function get_error_code() {
		return $this->code;
	}
}

/**
 * Whether a value is a WordPress error.
 *
 * @param mixed $value Value to inspect.
 * @return bool
 */
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

/**
 * Minimal settings stand-in for the panel test.
 */
class WPCPM_Settings {
	/**
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public static function get_value( $key, $fallback = null ) {
		return array_key_exists( $key, $GLOBALS['wpcpm_card_settings'] )
			? $GLOBALS['wpcpm_card_settings'][ $key ]
			: $fallback;
	}
}

/**
 * Return the current test user ID.
 *
 * @return int
 */
function get_current_user_id() {
	return (int) $GLOBALS['wpcpm_card_current_user_id'];
}

/**
 * Minimal translation stand-in.
 *
 * @param string $text Source text.
 * @return string
 */
function __( $text ) {
	return $text;
}

/**
 * Escape text for HTML.
 *
 * @param mixed $text Source value.
 * @return string
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Translate and escape text for HTML.
 *
 * @param string $text Source text.
 * @return string
 */
function esc_html__( $text ) {
	return esc_html( __( $text ) );
}

/**
 * Escape an HTML attribute.
 *
 * @param mixed $text Source value.
 * @return string
 */
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * Escape a URL.
 *
 * @param mixed $url Source URL.
 * @return string
 */
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

/**
 * Return one test administration URL.
 *
 * @param string $path Relative administration path.
 * @return string
 */
function admin_url( $path = '' ) {
	return 'https://dashboard.test/wp-admin/' . ltrim( $path, '/' );
}

/**
 * Print a deterministic nonce field for the isolated renderer test.
 *
 * @param string $action Nonce action.
 */
function wp_nonce_field( $action ) {
	echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( 'nonce-' . $action ) . '">';
}

/**
 * Return one test option.
 *
 * @param string $key Option key.
 * @return mixed
 */
function get_option( $key ) {
	$options = array(
		'date_format' => 'F j, Y',
		'time_format' => 'H:i',
	);

	return isset( $options[ $key ] ) ? $options[ $key ] : false;
}

/**
 * Format a timestamp without depending on the machine's timezone.
 *
 * @param string $format    Date format.
 * @param int    $timestamp Unix timestamp.
 * @return string
 */
function wp_date( $format, $timestamp ) {
	return gmdate( $format, $timestamp );
}

/**
 * Return a non-negative integer.
 *
 * @param mixed $value Source value.
 * @return int
 */
function absint( $value ) {
	return abs( (int) $value );
}

require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-progress-card.php';
require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-client.php';
require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-progress.php';

$wpcpm_card_failures = 0;
$wpcpm_card_checks   = 0;

/**
 * Compare one result with its expected value.
 *
 * @param string $label Test label.
 * @param mixed  $got   Actual value.
 * @param mixed  $want  Expected value.
 */
function wpcpm_card_check( $label, $got, $want ) {
	global $wpcpm_card_failures, $wpcpm_card_checks;

	++$wpcpm_card_checks;
	if ( $got === $want ) {
		echo "OK: {$label}\n";
		return;
	}

	++$wpcpm_card_failures;
	echo "FAIL: {$label}\n";
	echo '  got:  ' . var_export( $got, true ) . "\n";
	echo '  want: ' . var_export( $want, true ) . "\n";
}

$wpcpm_card_html = WPCPM_Learn_Progress_Card::render(
	'in_progress',
	array(),
	array(),
	array()
);

wpcpm_card_check( 'renderer returns text', is_string( $wpcpm_card_html ), true );
wpcpm_card_check( 'card wrapper', false !== strpos( $wpcpm_card_html, 'class="wpcpm-learn"' ), true );
wpcpm_card_check( 'state attribute', false !== strpos( $wpcpm_card_html, 'data-state="in_progress"' ), true );
wpcpm_card_check( 'card title', false !== strpos( $wpcpm_card_html, '>Your progress on Learn</h4>' ), true );

$wpcpm_card_unsafe = WPCPM_Learn_Progress_Card::render(
	'"><script>alert(1)</script>',
	array(),
	array(),
	array()
);

wpcpm_card_check( 'state is escaped', false === strpos( $wpcpm_card_unsafe, '<script' ), true );

$wpcpm_card_owner = WPCPM_Learn_Progress_Card::render(
	'not_connected',
	array(),
	array(),
	array( 'is_owner' => true )
);

wpcpm_card_check( 'owner sees connection explanation', false !== strpos( $wpcpm_card_owner, 'Connect your Learn account to show your course progress here.' ), true );
wpcpm_card_check( 'owner form uses POST', false !== strpos( $wpcpm_card_owner, 'method="post"' ), true );
wpcpm_card_check( 'owner form uses admin-post', false !== strpos( $wpcpm_card_owner, 'action="https://dashboard.test/wp-admin/admin-post.php"' ), true );
wpcpm_card_check( 'owner form names the connect action', false !== strpos( $wpcpm_card_owner, 'name="action" value="wpcpm_learn_connect"' ), true );
wpcpm_card_check( 'owner form has a nonce', false !== strpos( $wpcpm_card_owner, 'value="nonce-wpcpm_learn_connect"' ), true );
wpcpm_card_check( 'owner sees connect button', false !== strpos( $wpcpm_card_owner, '>Connect your Learn account</button>' ), true );

$wpcpm_card_administrator = WPCPM_Learn_Progress_Card::render(
	'not_connected',
	array(),
	array(),
	array( 'is_owner' => false )
);

wpcpm_card_check( 'Administrator sees not connected', false !== strpos( $wpcpm_card_administrator, '>Not connected</p>' ), true );
wpcpm_card_check( 'Administrator cannot start a connection', false === strpos( $wpcpm_card_administrator, '<form' ), true );

$wpcpm_card_nothing_started = WPCPM_Learn_Progress_Card::render(
	'nothing_started',
	array(),
	array(),
	array( 'connected_at' => gmmktime( 0, 0, 0, 10, 16, 2026 ) )
);

wpcpm_card_check( 'nothing started names the connection date', false !== strpos( $wpcpm_card_nothing_started, 'Connected on October 16, 2026.' ), true );
wpcpm_card_check( 'nothing started has its own message', false !== strpos( $wpcpm_card_nothing_started, 'You have not started the course yet.' ), true );

$wpcpm_card_withdrawn = WPCPM_Learn_Progress_Card::render(
	'withdrawn',
	array( 'progress_percent' => 75 ),
	array(),
	array( 'withdrawn_at' => gmmktime( 0, 0, 0, 10, 17, 2026 ) )
);

wpcpm_card_check( 'withdrawn has its own message', false !== strpos( $wpcpm_card_withdrawn, 'A program Administrator withdrew this connection on October 17, 2026.' ), true );
wpcpm_card_check( 'withdrawn never renders cached percentage', 0, preg_match( '/\d+%/', $wpcpm_card_withdrawn ) );

$wpcpm_card_unavailable = WPCPM_Learn_Progress_Card::render(
	'unavailable',
	array(),
	array(),
	array()
);

wpcpm_card_check( 'unavailable without cache has its own message', false !== strpos( $wpcpm_card_unavailable, 'Learn did not answer just now. Try again in a few minutes.' ), true );
wpcpm_card_check( 'unavailable without cache has no percentage', 0, preg_match( '/\d+%/', $wpcpm_card_unavailable ) );

$wpcpm_card_progress_data = array(
	'generated_at' => gmmktime( 9, 12, 0, 10, 18, 2026 ),
	'courses'      => array(
		297853 => array(
			'status'       => 'in-progress',
			'completed_at' => null,
			'percent'      => 50,
			'lessons'      => array(
				array( 'id' => 11, 'status' => 'complete', 'quiz' => array( 'status' => 'passed', 'grade' => 85 ) ),
				array( 'id' => 12, 'status' => 'complete', 'quiz' => array( 'status' => 'graded', 'grade' => 50 ) ),
				array( 'id' => 13, 'status' => 'ungraded', 'quiz' => array( 'status' => 'ungraded', 'grade' => null ) ),
				array( 'id' => 14, 'status' => 'not-started', 'quiz' => null ),
				array( 'id' => 15, 'status' => 'in-progress', 'quiz' => array( 'status' => 'not-taken', 'grade' => null ) ),
			),
		),
	),
);
$wpcpm_card_structure     = array(
	297853 => array(
		'title'   => 'Theme development',
		'modules' => array(
			array(
				'title'   => 'Foundations',
				'lessons' => array(
					array( 'id' => 11, 'title' => 'Introduction' ),
					array( 'id' => 12, 'title' => 'Build a theme' ),
					array( 'id' => 13, 'title' => '<script>alert(1)</script>Review' ),
				),
			),
		),
	),
);
$wpcpm_card_in_progress   = WPCPM_Learn_Progress_Card::render(
	'in_progress',
	$wpcpm_card_progress_data,
	$wpcpm_card_structure,
	array()
);

wpcpm_card_check( 'in progress names the course', false !== strpos( $wpcpm_card_in_progress, '>Theme development</h5>' ), true );
wpcpm_card_check( 'in progress has an accessible progress value', false !== strpos( $wpcpm_card_in_progress, 'max="100" value="50">50% of the course</progress>' ), true );
wpcpm_card_check( 'in progress counts completed lessons', false !== strpos( $wpcpm_card_in_progress, '>2 of 5 lessons</p>' ), true );
wpcpm_card_check( 'in progress names the update time', false !== strpos( $wpcpm_card_in_progress, 'Last updated October 18, 2026, 09:12' ), true );
wpcpm_card_check( 'in progress explains the connection', false !== strpos( $wpcpm_card_in_progress, 'This connection lasts for the program.' ), true );
wpcpm_card_check( 'lesson table has scoped headings', 3, substr_count( $wpcpm_card_in_progress, '<th scope="col">' ) );
wpcpm_card_check( 'lesson title comes from structure', false !== strpos( $wpcpm_card_in_progress, '>Introduction</th>' ), true );
wpcpm_card_check( 'missing lesson title has a fallback', false !== strpos( $wpcpm_card_in_progress, '>Lesson 14</th>' ), true );
wpcpm_card_check( 'passed quiz shows its grade', false !== strpos( $wpcpm_card_in_progress, '>85%, passed</td>' ), true );
wpcpm_card_check( 'graded quiz shows its grade', false !== strpos( $wpcpm_card_in_progress, '>50%, graded</td>' ), true );
wpcpm_card_check( 'ungraded quiz says it is waiting', false !== strpos( $wpcpm_card_in_progress, '>Waiting to be graded</td>' ), true );
wpcpm_card_check( 'untaken quiz has its own result', false !== strpos( $wpcpm_card_in_progress, '>Not taken</td>' ), true );
wpcpm_card_check( 'lesson title is escaped', false === strpos( $wpcpm_card_in_progress, '<script' ), true );

$wpcpm_card_complete_data                                      = $wpcpm_card_progress_data;
$wpcpm_card_complete_data['courses'][297853]['status']         = 'complete';
$wpcpm_card_complete_data['courses'][297853]['completed_at']   = gmmktime( 16, 55, 0, 10, 20, 2026 );
$wpcpm_card_complete_data['courses'][297853]['percent']        = 100;
$wpcpm_card_complete_data['courses'][297853]['lessons'][2]['status'] = 'complete';
$wpcpm_card_complete_data['courses'][297853]['lessons'][3]['status'] = 'complete';
$wpcpm_card_complete = WPCPM_Learn_Progress_Card::render(
	'complete',
	$wpcpm_card_complete_data,
	$wpcpm_card_structure,
	array()
);

wpcpm_card_check( 'complete names the completion date', false !== strpos( $wpcpm_card_complete, 'Completed on October 20, 2026.' ), true );
wpcpm_card_check( 'complete shows one hundred percent', false !== strpos( $wpcpm_card_complete, 'value="100">100% of the course</progress>' ), true );

$wpcpm_card_cached_unavailable = WPCPM_Learn_Progress_Card::render(
	'unavailable',
	$wpcpm_card_progress_data,
	$wpcpm_card_structure,
	array()
);

wpcpm_card_check( 'unavailable with cache keeps the progress picture', false !== strpos( $wpcpm_card_cached_unavailable, 'value="50">50% of the course</progress>' ), true );
wpcpm_card_check( 'unavailable with cache explains the stale picture', false !== strpos( $wpcpm_card_cached_unavailable, 'These are your results as of October 18, 2026, 09:12.' ), true );

$wpcpm_card_escaped_structure = array(
	297853 => array( 'title' => '<img src=x onerror=alert(1)>' ),
);
$wpcpm_card_escaped_course    = WPCPM_Learn_Progress_Card::render(
	'in_progress',
	$wpcpm_card_progress_data,
	$wpcpm_card_escaped_structure,
	array()
);

wpcpm_card_check( 'course title is escaped', false === strpos( $wpcpm_card_escaped_course, '<img' ), true );

$wpcpm_card_student = new WP_User( 42 );
wpcpm_card_check( 'disabled panel renders nothing', WPCPM_Learn_Progress::panel( $wpcpm_card_student ), '' );

$GLOBALS['wpcpm_card_settings']['learn_enabled'] = true;
$GLOBALS['wpcpm_card_current_user_id']           = 42;
$wpcpm_card_fixture_panel                        = WPCPM_Learn_Progress::panel( $wpcpm_card_student );

wpcpm_card_check( 'fixture panel renders the in-progress state', false !== strpos( $wpcpm_card_fixture_panel, 'data-state="in_progress"' ), true );
wpcpm_card_check( 'fixture panel uses normalized progress', false !== strpos( $wpcpm_card_fixture_panel, 'value="50">50% of the course</progress>' ), true );
wpcpm_card_check( 'fixture panel uses the fixture username only as text', false === strpos( $wpcpm_card_fixture_panel, '<script' ), true );

if ( 0 !== $wpcpm_card_failures ) {
	echo "\n{$wpcpm_card_failures} FAILED ({$wpcpm_card_checks} checks)\n";
	exit( 1 );
}

echo "\nALL PASS ({$wpcpm_card_checks} checks)\n";
