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

$GLOBALS['wpcpm_card_settings']        = array();
$GLOBALS['wpcpm_card_current_user_id'] = 0;
$GLOBALS['wpcpm_card_title_reads']     = 0;
$GLOBALS['wpcpm_card_meta']            = array();
$GLOBALS['wpcpm_card_flash']           = '';
$GLOBALS['wpcpm_card_refresh_calls']   = array();

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

class WPCPM_Flash {
	public static function set( $channel, $value ) {
		if ( WPCPM_Learn_Progress::FLASH === (string) $channel ) {
			$GLOBALS['wpcpm_card_flash'] = $value;
		}
	}

	public static function take( $channel ) {
		if ( WPCPM_Learn_Progress::FLASH !== (string) $channel ) {
			return '';
		}

		$value                       = $GLOBALS['wpcpm_card_flash'];
		$GLOBALS['wpcpm_card_flash'] = '';

		return $value;
	}
}

/**
 * Existing Learn structure helper stand-in.
 */
class WPCPM_Learn {
	/**
	 * Find a lesson title across the plain module list returned by structure().
	 *
	 * @param array $modules   Course modules.
	 * @param int   $lesson_id Learn lesson ID.
	 * @return string
	 */
	public static function lesson_title( array $modules, $lesson_id ) {
		++$GLOBALS['wpcpm_card_title_reads'];

		foreach ( $modules as $module ) {
			foreach ( isset( $module['lessons'] ) && is_array( $module['lessons'] ) ? $module['lessons'] : array() as $lesson ) {
				if ( isset( $lesson['id'] ) && (int) $lesson['id'] === (int) $lesson_id ) {
					return isset( $lesson['title'] ) ? (string) $lesson['title'] : '';
				}
			}
		}

		return '';
	}
}

/**
 * Optional progress synchronization stand-in for panel tests.
 */
class WPCPM_Learn_Progress_Sync {
	/**
	 * Record one refresh request.
	 *
	 * @param int  $student_id Student user ID.
	 * @param bool $force      Whether to ignore cache freshness.
	 * @return bool
	 */
	public static function refresh( $student_id, $force = false ) {
		$GLOBALS['wpcpm_card_refresh_calls'][] = array( (int) $student_id, (bool) $force );

		return true;
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

function get_user_meta( $user_id, $key, $single = false ) {
	unset( $single );

	return isset( $GLOBALS['wpcpm_card_meta'][ (int) $user_id ][ (string) $key ] )
		? $GLOBALS['wpcpm_card_meta'][ (int) $user_id ][ (string) $key ]
		: '';
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
 * Return text unchanged for the escaping mutation check.
 *
 * @param mixed $text Source value.
 * @return string
 */
function wpcpm_card_passthrough( $text ) {
	return (string) $text;
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

/**
 * Parse one contract fixture and mark it as freshly cached.
 *
 * @param string $filename Fixture filename.
 * @return array
 */
function wpcpm_card_cached_fixture( $filename ) {
	$path = WPCPM_PLUGIN_DIR . 'bin/fixtures/' . (string) $filename;
	$body = json_decode( (string) file_get_contents( $path ), true );
	$data = WPCPM_Learn_Client::parse( $body );

	if ( is_wp_error( $data ) ) {
		return array();
	}

	$data['fetched_at'] = time();

	return $data;
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

$wpcpm_card_notice = WPCPM_Learn_Progress_Card::render(
	'not_connected',
	array(),
	array(),
	array(
		'is_owner' => false,
		'message'  => '<script>alert(1)</script>Connection refused.',
	)
);
wpcpm_card_check( 'callback notice uses a status region', false !== strpos( $wpcpm_card_notice, 'class="wpcpm-learn__notice" role="status"' ), true );
wpcpm_card_check( 'callback notice is escaped as text', false === strpos( $wpcpm_card_notice, '<script' ), true );

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

$wpcpm_card_nothing_started_dateless = WPCPM_Learn_Progress_Card::render( 'nothing_started', array(), array(), array() );
wpcpm_card_check( 'nothing started without a date uses a complete dateless sentence', false !== strpos( $wpcpm_card_nothing_started_dateless, 'You are connected, but you have not started the course yet.' ), true );
wpcpm_card_check( 'a missing connection date never prints the Unix epoch', false === strpos( $wpcpm_card_nothing_started_dateless, '1970' ), true );

$wpcpm_card_unknown = WPCPM_Learn_Progress_Card::render( 'future_state', array(), array(), array() );
wpcpm_card_check( 'an unknown state uses the safe unavailable fallback', false !== strpos( $wpcpm_card_unknown, 'Learn did not answer just now. Try again in a few minutes.' ), true );

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
			'enrolled_at'  => gmmktime( 0, 0, 0, 9, 1, 2026 ),
			'completed_at' => null,
			'percent'      => 50,
			'lessons'      => array(
				array( 'id' => 11, 'status' => 'complete', 'quiz' => array( 'status' => 'passed', 'grade' => 85, 'passmark' => 70 ) ),
				array( 'id' => 12, 'status' => 'complete', 'quiz' => array( 'status' => 'graded', 'grade' => 50 ) ),
				array( 'id' => 13, 'status' => 'ungraded', 'quiz' => array( 'status' => 'ungraded', 'grade' => null ) ),
				array( 'id' => 14, 'status' => 'not-started', 'quiz' => null ),
				array( 'id' => 15, 'status' => 'in-progress', 'quiz' => array( 'status' => 'not-taken', 'grade' => null ) ),
				array( 'id' => 16, 'status' => 'in-progress', 'quiz' => array( 'status' => 'failed', 'grade' => 40, 'passmark' => 70 ) ),
			),
		),
	),
);
$wpcpm_card_withdrawn     = WPCPM_Learn_Progress_Card::render(
	'withdrawn',
	$wpcpm_card_progress_data,
	array(),
	array( 'withdrawn_at' => gmmktime( 0, 0, 0, 10, 17, 2026 ) )
);

wpcpm_card_check( 'withdrawn has its own message', false !== strpos( $wpcpm_card_withdrawn, 'This connection was withdrawn on October 17, 2026. Contact a program Administrator if you think this is a mistake.' ), true );
wpcpm_card_check( 'withdrawn never renders cached percentage', 0, preg_match( '/\d+%/', $wpcpm_card_withdrawn ) );

$wpcpm_card_withdrawn_dateless = WPCPM_Learn_Progress_Card::render( 'withdrawn', $wpcpm_card_progress_data, array(), array() );
wpcpm_card_check( 'withdrawn without a date uses a complete dateless sentence', false !== strpos( $wpcpm_card_withdrawn_dateless, 'This connection was withdrawn. Contact a program Administrator if you think this is a mistake.' ), true );
wpcpm_card_check( 'a missing withdrawal date never prints the Unix epoch', false === strpos( $wpcpm_card_withdrawn_dateless, '1970' ), true );

$wpcpm_card_structure     = array(
	array(
		'id'      => 101,
		'title'   => 'Theme development',
		'lessons' => array(
			array( 'id' => 11, 'title' => 'Introduction' ),
			array( 'id' => 12, 'title' => 'Build a theme' ),
			array( 'id' => 13, 'title' => '<script>alert(1)</script>Review' ),
			array( 'id' => 16, 'title' => 'Final assessment' ),
		),
	),
);
$wpcpm_card_context       = array(
	'course_titles' => array(
		297853 => 'Theme development',
	),
	'is_owner'      => true,
	);
$wpcpm_card_in_progress   = WPCPM_Learn_Progress_Card::render(
	'in_progress',
	$wpcpm_card_progress_data,
	$wpcpm_card_structure,
	$wpcpm_card_context
);

wpcpm_card_check( 'in progress names the course with a stable heading ID', false !== strpos( $wpcpm_card_in_progress, 'id="wpcpm-learn-course-297853">Theme development</h5>' ), true );
wpcpm_card_check( 'the progress bar is labelled by the course heading', false !== strpos( $wpcpm_card_in_progress, 'aria-labelledby="wpcpm-learn-course-297853" max="100" value="50"' ), true );
wpcpm_card_check( 'the percentage is visible outside the progress fallback', false !== strpos( $wpcpm_card_in_progress, 'class="wpcpm-learn__percent">50%</p>' ), true );
wpcpm_card_check( 'in progress counts completed lessons', false !== strpos( $wpcpm_card_in_progress, '>2 of 6 lessons</p>' ), true );
wpcpm_card_check( 'in progress names the update time', false !== strpos( $wpcpm_card_in_progress, 'Last updated October 18, 2026, 09:12' ), true );
wpcpm_card_check( 'in progress explains who can withdraw the connection', false !== strpos( $wpcpm_card_in_progress, 'This connection lasts for the program. Only a program Administrator can withdraw it.' ), true );
wpcpm_card_check( 'the connection footer does not send a student to a mentor', false === strpos( $wpcpm_card_in_progress, 'mentor' ), true );
wpcpm_card_check( 'lesson table has scoped headings', 3, substr_count( $wpcpm_card_in_progress, '<th scope="col">' ) );
wpcpm_card_check( 'lesson title comes from structure', false !== strpos( $wpcpm_card_in_progress, '>Introduction</th>' ), true );
wpcpm_card_check( 'lesson titles use the existing WPCPM_Learn helper', $GLOBALS['wpcpm_card_title_reads'] > 0, true );
wpcpm_card_check( 'missing lesson title has a fallback', false !== strpos( $wpcpm_card_in_progress, '>Lesson 14</th>' ), true );
wpcpm_card_check( 'passed quiz shows its grade and pass mark', false !== strpos( $wpcpm_card_in_progress, '>85%, passed (pass mark 70%)</td>' ), true );
wpcpm_card_check( 'failed quiz shows its grade and pass mark', false !== strpos( $wpcpm_card_in_progress, '>40%, failed (pass mark 70%)</td>' ), true );
wpcpm_card_check( 'graded quiz shows its grade', false !== strpos( $wpcpm_card_in_progress, '>50%, graded</td>' ), true );
wpcpm_card_check( 'ungraded quiz says it is waiting', false !== strpos( $wpcpm_card_in_progress, '>Waiting to be graded</td>' ), true );
wpcpm_card_check( 'untaken quiz has its own result', false !== strpos( $wpcpm_card_in_progress, '>Not taken</td>' ), true );
wpcpm_card_check( 'lesson title is escaped', false === strpos( $wpcpm_card_in_progress, '<script' ), true );

$wpcpm_card_never_enrolled_data = array(
	'generated_at' => gmmktime( 9, 12, 0, 10, 18, 2026 ),
	'courses'      => array(
		123456 => array(
			'status'       => 'not-started',
			'enrolled_at'  => null,
			'completed_at' => null,
			'percent'      => 0,
			'lessons'      => array(),
		),
	),
);
$wpcpm_card_never_enrolled_context = array(
	'course_titles' => array( 123456 => 'Foundation course' ),
);
$wpcpm_card_never_enrolled = WPCPM_Learn_Progress_Card::render(
	'in_progress',
	$wpcpm_card_never_enrolled_data,
	array(),
	$wpcpm_card_never_enrolled_context
);

wpcpm_card_check( 'a never-enrolled course explains its state', false !== strpos( $wpcpm_card_never_enrolled, esc_html( 'You haven\'t enrolled in this course yet.' ) ), true );
wpcpm_card_check( 'a never-enrolled course has no progress bar', false === strpos( $wpcpm_card_never_enrolled, '<progress' ), true );
wpcpm_card_check( 'a never-enrolled course has no lesson count', false === strpos( $wpcpm_card_never_enrolled, '0 of 0 lessons' ), true );

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
	$wpcpm_card_context
);

wpcpm_card_check( 'complete names the completion date', false !== strpos( $wpcpm_card_complete, 'Completed on October 20, 2026.' ), true );
wpcpm_card_check( 'complete shows one hundred percent', false !== strpos( $wpcpm_card_complete, 'class="wpcpm-learn__percent">100%</p>' ), true );

$wpcpm_card_complete_dateless_data                                      = $wpcpm_card_complete_data;
$wpcpm_card_complete_dateless_data['courses'][297853]['completed_at']   = null;
$wpcpm_card_complete_dateless = WPCPM_Learn_Progress_Card::render( 'complete', $wpcpm_card_complete_dateless_data, $wpcpm_card_structure, $wpcpm_card_context );
wpcpm_card_check( 'complete without a date uses a complete dateless sentence', false !== strpos( $wpcpm_card_complete_dateless, 'You completed this course.' ), true );
wpcpm_card_check( 'a missing completion date never prints the Unix epoch', false === strpos( $wpcpm_card_complete_dateless, '1970' ), true );

$wpcpm_card_cached_unavailable = WPCPM_Learn_Progress_Card::render(
	'unavailable',
	$wpcpm_card_progress_data,
	$wpcpm_card_structure,
	$wpcpm_card_context
);

wpcpm_card_check( 'unavailable with cache keeps the progress picture', false !== strpos( $wpcpm_card_cached_unavailable, 'class="wpcpm-learn__percent">50%</p>' ), true );
wpcpm_card_check( 'unavailable with cache explains the stale picture', false !== strpos( $wpcpm_card_cached_unavailable, 'These are your results as of October 18, 2026, 09:12.' ), true );

$wpcpm_card_escaped_context                       = $wpcpm_card_context;
$wpcpm_card_escaped_context['course_titles'][297853] = '<img src=x onerror=alert(1)>';
$wpcpm_card_escaped_course                        = WPCPM_Learn_Progress_Card::render(
	'in_progress',
	$wpcpm_card_progress_data,
	$wpcpm_card_structure,
	$wpcpm_card_escaped_context
);

wpcpm_card_check( 'course title is escaped', false === strpos( $wpcpm_card_escaped_course, '<img' ), true );

// Prove the escaping assertions detect a regression by loading a temporary
// copy whose esc_html() calls deliberately pass text through unchanged.
$wpcpm_card_mutation_dir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wpcpm-learn-card-' . getmypid();
$wpcpm_card_mutation_file = $wpcpm_card_mutation_dir . DIRECTORY_SEPARATOR . 'class-wpcpm-learn-progress-card.php';
$wpcpm_card_source        = (string) file_get_contents( WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-progress-card.php' );
$wpcpm_card_source        = str_replace( 'class WPCPM_Learn_Progress_Card', 'class WPCPM_Learn_Progress_Card_Mutation', $wpcpm_card_source );
$wpcpm_card_source        = str_replace( 'esc_html(', 'wpcpm_card_passthrough(', $wpcpm_card_source );

if ( ! is_dir( $wpcpm_card_mutation_dir ) ) {
	mkdir( $wpcpm_card_mutation_dir );
}

file_put_contents( $wpcpm_card_mutation_file, $wpcpm_card_source );
require_once $wpcpm_card_mutation_file;

$wpcpm_card_mutated_context                         = $wpcpm_card_context;
$wpcpm_card_mutated_context['course_titles'][297853] = '<img src=x onerror=alert(1)>';
$wpcpm_card_mutated_context['message']               = '<script>alert(1)</script>';
$wpcpm_card_mutated_html                            = WPCPM_Learn_Progress_Card_Mutation::render(
	'in_progress',
	$wpcpm_card_progress_data,
	$wpcpm_card_structure,
	$wpcpm_card_mutated_context
);
$wpcpm_card_mutated_escape_checks = array(
	false === strpos( $wpcpm_card_mutated_html, '<script' ),
	false === strpos( $wpcpm_card_mutated_html, '<img' ),
	false === strpos( $wpcpm_card_mutated_html, '<script>alert(1)</script>' ),
);

wpcpm_card_check( 'removing esc_html makes all escaping checks fail', $wpcpm_card_mutated_escape_checks, array( false, false, false ) );

unlink( $wpcpm_card_mutation_file );
rmdir( $wpcpm_card_mutation_dir );

$wpcpm_card_student = new WP_User( 42 );
wpcpm_card_check( 'disabled panel renders nothing', WPCPM_Learn_Progress::panel( $wpcpm_card_student ), '' );

$GLOBALS['wpcpm_card_settings']['learn_enabled']     = true;
$GLOBALS['wpcpm_card_settings']['learn_courses']     = '297853';
$GLOBALS['wpcpm_card_settings']['learn_cache_hours'] = 6;
$GLOBALS['wpcpm_card_current_user_id']           = 42;
$GLOBALS['wpcpm_card_flash']                     = 'learn_state';
$wpcpm_card_owner_panel                          = WPCPM_Learn_Progress::panel( $wpcpm_card_student );

wpcpm_card_check( 'owner panel without a token is not connected', false !== strpos( $wpcpm_card_owner_panel, 'data-state="not_connected"' ), true );
wpcpm_card_check( 'owner panel without a token offers Connect', false !== strpos( $wpcpm_card_owner_panel, 'Connect your Learn account' ), true );
wpcpm_card_check( 'owner panel shows the callback outcome', false !== strpos( $wpcpm_card_owner_panel, 'That connection request expired. Try again.' ), true );
wpcpm_card_check( 'owner panel consumes the callback outcome', $GLOBALS['wpcpm_card_flash'], '' );

$GLOBALS['wpcpm_card_flash'] = array(
	'code'           => 'learn_identity',
	'learn_username' => '<script>alert(1)</script>',
	'known_username' => 'adaexample',
);
$wpcpm_card_identity_panel = WPCPM_Learn_Progress::panel( $wpcpm_card_student );
wpcpm_card_check( 'identity outcome names the expected account', false !== strpos( $wpcpm_card_identity_panel, 'Student Report Card is for @adaexample.' ), true );
wpcpm_card_check( 'identity outcome never trusts the Learn username as markup', false === strpos( $wpcpm_card_identity_panel, '<script' ), true );

$GLOBALS['wpcpm_card_current_user_id'] = 7;
$wpcpm_card_manager_panel              = WPCPM_Learn_Progress::panel( $wpcpm_card_student );

wpcpm_card_check( 'Administrator panel without a token says Not connected', false !== strpos( $wpcpm_card_manager_panel, '>Not connected</p>' ), true );
wpcpm_card_check( 'Administrator panel cannot connect for the student', false === strpos( $wpcpm_card_manager_panel, '<form' ), true );

$GLOBALS['wpcpm_card_current_user_id'] = 42;
$GLOBALS['wpcpm_card_meta'][42][ WPCPM_Learn_Progress::META_WITHDRAWN_AT ] = gmmktime( 9, 0, 0, 11, 4, 2026 );
$wpcpm_card_withdrawn_panel = WPCPM_Learn_Progress::panel( $wpcpm_card_student );

wpcpm_card_check( 'withdrawn student panel uses the withdrawn state', false !== strpos( $wpcpm_card_withdrawn_panel, 'data-state="withdrawn"' ), true );
wpcpm_card_check( 'withdrawn student panel never offers Connect', false === strpos( $wpcpm_card_withdrawn_panel, '<form' ), true );

unset( $GLOBALS['wpcpm_card_meta'][42][ WPCPM_Learn_Progress::META_WITHDRAWN_AT ] );
$GLOBALS['wpcpm_card_meta'][42][ WPCPM_Learn_Progress::META_TOKEN ] = 'sealed-token';
$wpcpm_card_fresh_cache                                              = $wpcpm_card_progress_data;
$wpcpm_card_fresh_cache['fetched_at']                                = time();
$GLOBALS['wpcpm_card_meta'][42][ WPCPM_Learn_Progress::META_CACHE ]  = $wpcpm_card_fresh_cache;
$GLOBALS['wpcpm_card_meta'][42][ WPCPM_Learn_Progress::META_CONNECTED ] = gmmktime( 9, 0, 0, 10, 16, 2026 );
$GLOBALS['wpcpm_card_refresh_calls']                                 = array();
$wpcpm_card_cached_panel                                             = WPCPM_Learn_Progress::panel( $wpcpm_card_student );

wpcpm_card_check( 'cached panel renders the in-progress state', false !== strpos( $wpcpm_card_cached_panel, 'data-state="in_progress"' ), true );
wpcpm_card_check( 'cached panel uses stored normalized progress', false !== strpos( $wpcpm_card_cached_panel, 'class="wpcpm-learn__percent">50%</p>' ), true );
wpcpm_card_check( 'a fresh cache triggers no refresh', $GLOBALS['wpcpm_card_refresh_calls'], array() );

$wpcpm_card_cached_examples = array(
	'learn-progress-not-started.json' => 'nothing_started',
	'learn-progress-in-progress.json' => 'in_progress',
	'learn-progress-complete.json'    => 'complete',
);

foreach ( $wpcpm_card_cached_examples as $wpcpm_card_fixture => $wpcpm_card_expected_state ) {
	$GLOBALS['wpcpm_card_meta'][42][ WPCPM_Learn_Progress::META_CACHE ] = wpcpm_card_cached_fixture( $wpcpm_card_fixture );
	$GLOBALS['wpcpm_card_refresh_calls']                                = array();
	$wpcpm_card_example_panel                                           = WPCPM_Learn_Progress::panel( $wpcpm_card_student );

	wpcpm_card_check(
		$wpcpm_card_fixture . ' maps cached progress to ' . $wpcpm_card_expected_state,
		false !== strpos( $wpcpm_card_example_panel, 'data-state="' . $wpcpm_card_expected_state . '"' ),
		true
	);
	wpcpm_card_check(
		$wpcpm_card_fixture . ' stays local while its cache is fresh',
		$GLOBALS['wpcpm_card_refresh_calls'],
		array()
	);
}

$wpcpm_card_stale_cache                                               = $wpcpm_card_fresh_cache;
$wpcpm_card_stale_cache['fetched_at']                                 = time() - ( 7 * 3600 );
$GLOBALS['wpcpm_card_meta'][42][ WPCPM_Learn_Progress::META_CACHE ]   = $wpcpm_card_stale_cache;
$GLOBALS['wpcpm_card_refresh_calls']                                  = array();
$wpcpm_card_stale_owner_panel                                         = WPCPM_Learn_Progress::panel( $wpcpm_card_student );

wpcpm_card_check( 'a stale owner cache refreshes exactly once', $GLOBALS['wpcpm_card_refresh_calls'], array( array( 42, false ) ) );
wpcpm_card_check( 'a stale owner cache still renders stored progress', false !== strpos( $wpcpm_card_stale_owner_panel, 'class="wpcpm-learn__percent">50%</p>' ), true );

$GLOBALS['wpcpm_card_current_user_id'] = 7;
$GLOBALS['wpcpm_card_refresh_calls']   = array();
$wpcpm_card_stale_manager_panel        = WPCPM_Learn_Progress::panel( $wpcpm_card_student );

wpcpm_card_check( 'an Administrator viewing stale progress triggers no refresh', $GLOBALS['wpcpm_card_refresh_calls'], array() );
wpcpm_card_check( 'an Administrator may see the stored progress picture', false !== strpos( $wpcpm_card_stale_manager_panel, 'class="wpcpm-learn__percent">50%</p>' ), true );

$GLOBALS['wpcpm_card_current_user_id'] = 42;
unset( $GLOBALS['wpcpm_card_meta'][42][ WPCPM_Learn_Progress::META_CACHE ] );
$GLOBALS['wpcpm_card_refresh_calls'] = array();
$wpcpm_card_missing_cache_panel      = WPCPM_Learn_Progress::panel( $wpcpm_card_student );

wpcpm_card_check( 'an owner without cache requests one refresh', $GLOBALS['wpcpm_card_refresh_calls'], array( array( 42, false ) ) );
wpcpm_card_check( 'an unavailable panel without cache has no percentage', 0, preg_match( '/\d+%/', $wpcpm_card_missing_cache_panel ) );

if ( 0 !== $wpcpm_card_failures ) {
	echo "\n{$wpcpm_card_failures} FAILED ({$wpcpm_card_checks} checks)\n";
	exit( 1 );
}

echo "\nALL PASS ({$wpcpm_card_checks} checks)\n";
