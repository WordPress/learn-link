<?php
/**
 * The Learn line on the Mentor Report Card.
 *
 * The line comes from WPCPM_Learn_Progress::state_for() and the cached progress
 * only. Every network function and the stale-cache refresh are doubles that
 * count the call and throw, so a line that reached Learn fails the run.
 *
 * Run from the plugin root: php bin/test-learn-mentor.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPCPM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

$GLOBALS['wpcpm_mentor_settings'] = array( 'learn_enabled' => true );
$GLOBALS['wpcpm_mentor_meta']     = array();
$GLOBALS['wpcpm_mentor_network']  = array();

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
 * Minimal settings stand-in.
 */
class WPCPM_Settings {
	/**
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public static function get_value( $key, $fallback = null ) {
		return array_key_exists( $key, $GLOBALS['wpcpm_mentor_settings'] )
			? $GLOBALS['wpcpm_mentor_settings'][ $key ]
			: $fallback;
	}
}

/**
 * Record a forbidden call and stop the line that made it.
 *
 * @param string $what The call that was made.
 * @throws RuntimeException Always.
 */
function wpcpm_mentor_forbidden( $what ) {
	$GLOBALS['wpcpm_mentor_network'][] = $what;
	throw new RuntimeException( $what . ' was called' );
}

/**
 * The sync class must never be reached from the mentor line.
 */
class WPCPM_Learn_Progress_Sync {
	/**
	 * @param int  $student_id Student user ID.
	 * @param bool $force      Whether to ignore the cache age.
	 */
	public static function refresh( $student_id, $force = false ) {
		wpcpm_mentor_forbidden( 'WPCPM_Learn_Progress_Sync::refresh' );
	}
}

/**
 * @param string $url  URL.
 * @param array  $args Arguments.
 */
function wp_remote_get( $url, array $args = array() ) {
	wpcpm_mentor_forbidden( 'wp_remote_get' );
}

/**
 * @param string $url  URL.
 * @param array  $args Arguments.
 */
function wp_remote_post( $url, array $args = array() ) {
	wpcpm_mentor_forbidden( 'wp_remote_post' );
}

/**
 * @param string $url  URL.
 * @param array  $args Arguments.
 */
function wp_remote_request( $url, array $args = array() ) {
	wpcpm_mentor_forbidden( 'wp_remote_request' );
}

/**
 * Read one test user meta value.
 *
 * @param int    $user_id User ID.
 * @param string $key     Meta key.
 * @param bool   $single  Whether to return one value.
 * @return mixed
 */
function get_user_meta( $user_id, $key, $single = false ) {
	return isset( $GLOBALS['wpcpm_mentor_meta'][ (int) $user_id ][ $key ] )
		? $GLOBALS['wpcpm_mentor_meta'][ (int) $user_id ][ $key ]
		: '';
}

/**
 * Return untranslated text.
 *
 * @param string $text Source text.
 * @return string
 */
function __( $text ) {
	return $text;
}

/**
 * Pick the English singular or plural form.
 *
 * @param string $single Singular form.
 * @param string $plural Plural form.
 * @param int    $number Count that picks the form.
 * @return string
 */
function _n( $single, $plural, $number ) {
	return 1 === (int) $number ? $single : $plural;
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

require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-client.php';
require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-progress.php';
require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-progress-mentor.php';

$wpcpm_mentor_failures = 0;
$wpcpm_mentor_checks   = 0;

/**
 * Compare one result with its expected value.
 *
 * @param string $label Test label.
 * @param mixed  $got   Actual value.
 * @param mixed  $want  Expected value.
 */
function wpcpm_mentor_check( $label, $got, $want ) {
	global $wpcpm_mentor_failures, $wpcpm_mentor_checks;

	++$wpcpm_mentor_checks;
	if ( $got === $want ) {
		echo "OK: {$label}\n";
		return;
	}

	++$wpcpm_mentor_failures;
	echo "FAIL: {$label}\n";
	echo '  got:  ' . var_export( $got, true ) . "\n";
	echo '  want: ' . var_export( $want, true ) . "\n";
}

/**
 * Parse one contract fixture into the cache shape.
 *
 * @param string $filename   Fixture filename.
 * @param int    $fetched_at When the cache was written.
 * @return array
 */
function wpcpm_mentor_cached_fixture( $filename, $fetched_at = 0 ) {
	$body = json_decode( (string) file_get_contents( WPCPM_PLUGIN_DIR . 'bin/fixtures/' . $filename ), true );
	$data = WPCPM_Learn_Client::parse( $body );

	if ( is_wp_error( $data ) ) {
		echo "FAIL: fixture {$filename} does not parse\n";
		exit( 1 );
	}

	$data['fetched_at'] = $fetched_at ? $fetched_at : time();

	return $data;
}

/**
 * Store one student's Learn meta, replacing anything stored before.
 *
 * @param int   $student_id Student user ID.
 * @param array $meta       Meta key => value.
 */
function wpcpm_mentor_student( $student_id, array $meta ) {
	$GLOBALS['wpcpm_mentor_meta'][ (int) $student_id ] = $meta;
}

/**
 * The line for a student, or the forbidden call that stopped it.
 *
 * @param int $student_id Student user ID.
 * @return string
 */
function wpcpm_mentor_line( $student_id ) {
	try {
		return WPCPM_Learn_Progress_Mentor::line( $student_id );
	} catch ( RuntimeException $e ) {
		return 'STOPPED: ' . $e->getMessage();
	}
}

/**
 * A normalized course with a number of lessons, the first $done of them complete.
 *
 * @param int $percent Course percentage.
 * @param int $done    Completed lessons.
 * @param int $total   Total lessons.
 * @return array
 */
function wpcpm_mentor_course( $percent, $done, $total ) {
	$lessons = array();

	for ( $i = 1; $i <= $total; $i++ ) {
		$lessons[ 1000 + $i ] = array(
			'status'       => $i <= $done ? 'complete' : 'not-started',
			'completed_at' => null,
			'quiz'         => null,
		);
	}

	return array(
		'status'       => $done === $total && $total > 0 ? 'complete' : 'in-progress',
		'enrolled_at'  => 1756821780,
		'completed_at' => null,
		'percent'      => $percent,
		'lessons'      => $lessons,
	);
}

$wpcpm_token = 'sealed-token';

echo "=== The four lines (U-D11) ===\n";

wpcpm_mentor_student(
	11,
	array(
		WPCPM_Learn_Progress::META_TOKEN => $wpcpm_token,
		WPCPM_Learn_Progress::META_CACHE => wpcpm_mentor_cached_fixture( 'learn-progress-in-progress.json' ),
	)
);
wpcpm_mentor_check( 'state is in_progress for the in-progress fixture', WPCPM_Learn_Progress::state_for( 11 ), 'in_progress' );
wpcpm_mentor_check( 'in progress: the track course numbers', wpcpm_mentor_line( 11 ), 'Learn: 50% of the track course, 4 of 8 lessons' );

wpcpm_mentor_student(
	12,
	array(
		WPCPM_Learn_Progress::META_TOKEN => $wpcpm_token,
		WPCPM_Learn_Progress::META_CACHE => wpcpm_mentor_cached_fixture( 'learn-progress-complete.json' ),
	)
);
wpcpm_mentor_check( 'state is complete for the complete fixture', WPCPM_Learn_Progress::state_for( 12 ), 'complete' );
wpcpm_mentor_check( 'complete: the track course numbers', wpcpm_mentor_line( 12 ), 'Learn: 100% of the track course, 8 of 8 lessons' );

wpcpm_mentor_student(
	13,
	array(
		WPCPM_Learn_Progress::META_TOKEN => $wpcpm_token,
		WPCPM_Learn_Progress::META_CACHE => wpcpm_mentor_cached_fixture( 'learn-progress-not-started.json' ),
	)
);
wpcpm_mentor_check( 'state is nothing_started for the not-started fixture', WPCPM_Learn_Progress::state_for( 13 ), 'nothing_started' );
wpcpm_mentor_check( 'nothing started: zero, with the lesson count', wpcpm_mentor_line( 13 ), 'Learn: 0% of the track course, 0 of 8 lessons' );

wpcpm_mentor_student( 14, array() );
wpcpm_mentor_check( 'no token: not connected', wpcpm_mentor_line( 14 ), 'Learn: not connected' );

wpcpm_mentor_student(
	15,
	array(
		WPCPM_Learn_Progress::META_WITHDRAWN_AT => 1760000000,
		WPCPM_Learn_Progress::META_CACHE        => wpcpm_mentor_cached_fixture( 'learn-progress-in-progress.json' ),
	)
);
wpcpm_mentor_check( 'withdrawn, even with a cache left behind', wpcpm_mentor_line( 15 ), 'Learn: connection withdrawn' );

wpcpm_mentor_student(
	16,
	array(
		WPCPM_Learn_Progress::META_WITHDRAWN_AT => 1760000000,
		WPCPM_Learn_Progress::META_TOKEN        => $wpcpm_token,
	)
);
wpcpm_mentor_check( 'withdrawn wins over a token', wpcpm_mentor_line( 16 ), 'Learn: connection withdrawn' );

wpcpm_mentor_student( 17, array( WPCPM_Learn_Progress::META_TOKEN => $wpcpm_token ) );
wpcpm_mentor_check( 'state is unavailable for a token with no cache', WPCPM_Learn_Progress::state_for( 17 ), 'unavailable' );
wpcpm_mentor_check( 'unavailable with no cache: no results yet', wpcpm_mentor_line( 17 ), 'Learn: no results yet' );

wpcpm_mentor_student(
	18,
	array(
		WPCPM_Learn_Progress::META_TOKEN => $wpcpm_token,
		WPCPM_Learn_Progress::META_CACHE => array(
			'fetched_at' => time(),
			'courses'    => array(),
		),
	)
);
wpcpm_mentor_check( 'an empty course list: no results yet', wpcpm_mentor_line( 18 ), 'Learn: no results yet' );

wpcpm_mentor_student(
	19,
	array(
		WPCPM_Learn_Progress::META_TOKEN => $wpcpm_token,
		WPCPM_Learn_Progress::META_CACHE => wpcpm_mentor_cached_fixture( 'learn-progress-in-progress.json', time() - 7200 ),
		WPCPM_Learn_Progress::META_ERROR => array(
			'code' => 'wpcpm_learn_unreachable',
			'at'   => time() - 60,
		),
	)
);
wpcpm_mentor_check( 'state is unavailable after a later error', WPCPM_Learn_Progress::state_for( 19 ), 'unavailable' );
wpcpm_mentor_check( 'unavailable with an older cache: its numbers', wpcpm_mentor_line( 19 ), 'Learn: 50% of the track course, 4 of 8 lessons' );

echo "\n=== Rows with no account ===\n";

wpcpm_mentor_check( 'student 0: not connected', wpcpm_mentor_line( 0 ), 'Learn: not connected' );
wpcpm_mentor_check( 'a negative ID: not connected', wpcpm_mentor_line( -3 ), 'Learn: not connected' );

echo "\n=== Singular, plural and the numbers ===\n";

wpcpm_mentor_student(
	21,
	array(
		WPCPM_Learn_Progress::META_TOKEN => $wpcpm_token,
		WPCPM_Learn_Progress::META_CACHE => array(
			'fetched_at' => time(),
			'courses'    => array( 297853 => wpcpm_mentor_course( 0, 0, 1 ) ),
		),
	)
);
wpcpm_mentor_check( 'one lesson: singular', wpcpm_mentor_line( 21 ), 'Learn: 0% of the track course, 0 of 1 lesson' );

$GLOBALS['wpcpm_mentor_meta'][21][ WPCPM_Learn_Progress::META_CACHE ]['courses'] = array( 297853 => wpcpm_mentor_course( 100, 1, 1 ) );
wpcpm_mentor_check( 'one lesson done: singular', wpcpm_mentor_line( 21 ), 'Learn: 100% of the track course, 1 of 1 lesson' );

$GLOBALS['wpcpm_mentor_meta'][21][ WPCPM_Learn_Progress::META_CACHE ]['courses'] = array( 297853 => wpcpm_mentor_course( 40, 3, 8 ) );
wpcpm_mentor_check( 'decision 2 example: plural', wpcpm_mentor_line( 21 ), 'Learn: 40% of the track course, 3 of 8 lessons' );

$GLOBALS['wpcpm_mentor_meta'][21][ WPCPM_Learn_Progress::META_CACHE ]['courses'] = array( 297853 => wpcpm_mentor_course( 140, 2, 2 ) );
wpcpm_mentor_check( 'a percentage above 100 is clamped', wpcpm_mentor_line( 21 ), 'Learn: 100% of the track course, 2 of 2 lessons' );

$GLOBALS['wpcpm_mentor_meta'][21][ WPCPM_Learn_Progress::META_CACHE ]['courses'] = array( 297853 => wpcpm_mentor_course( -5, 1, 3 ) );
wpcpm_mentor_check( 'a negative percentage is clamped', wpcpm_mentor_line( 21 ), 'Learn: 0% of the track course, 1 of 3 lessons' );

$GLOBALS['wpcpm_mentor_meta'][21][ WPCPM_Learn_Progress::META_CACHE ]['courses'] = array(
	297853 => wpcpm_mentor_course( 25, 1, 4 ),
	123456 => wpcpm_mentor_course( 100, 3, 3 ),
);
wpcpm_mentor_check( 'two cached courses: the first one, the track course', wpcpm_mentor_line( 21 ), 'Learn: 25% of the track course, 1 of 4 lessons' );

echo "\n=== Escaping (SEC-15) ===\n";

$wpcpm_hostile                     = wpcpm_mentor_course( 60, 2, 3 );
$wpcpm_hostile['status']           = '<script>alert(1)</script>';
$wpcpm_hostile['title']            = '<script>alert(2)</script>';
$wpcpm_hostile['lessons'][1003]    = array( 'status' => '<script>alert(3)</script>' );
$wpcpm_hostile['lessons']['<img>'] = array( 'status' => 'complete' );
$wpcpm_hostile['percent']          = '60<script>';

wpcpm_mentor_student(
	22,
	array(
		WPCPM_Learn_Progress::META_TOKEN => $wpcpm_token,
		WPCPM_Learn_Progress::META_CACHE => array(
			'fetched_at' => time(),
			'courses'    => array( 297853 => $wpcpm_hostile ),
		),
	)
);
$wpcpm_hostile_line = wpcpm_mentor_line( 22 );
wpcpm_mentor_check( 'markup in the cache never reaches the line', false === strpos( $wpcpm_hostile_line, '<' ), true );
wpcpm_mentor_check( 'only the counted numbers are shown', $wpcpm_hostile_line, 'Learn: 0% of the track course, 3 of 4 lessons' );

echo "\n=== A stale cache is read, never refreshed ===\n";

wpcpm_mentor_student(
	23,
	array(
		WPCPM_Learn_Progress::META_TOKEN => $wpcpm_token,
		WPCPM_Learn_Progress::META_CACHE => wpcpm_mentor_cached_fixture( 'learn-progress-in-progress.json', time() - 30 * 86400 ),
	)
);
wpcpm_mentor_check( 'a month-old cache still gives its line', wpcpm_mentor_line( 23 ), 'Learn: 50% of the track course, 4 of 8 lessons' );

echo "\n=== The line never reaches Learn (I-D08 / UI-10) ===\n";

wpcpm_mentor_check( 'no network call and no refresh in any case above', $GLOBALS['wpcpm_mentor_network'], array() );

$wpcpm_mentor_source = (string) file_get_contents( WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-progress-mentor.php' );
$wpcpm_mentor_code   = implode(
	'',
	array_map(
		static function ( $token ) {
			return is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : ( is_array( $token ) ? $token[1] : $token );
		},
		token_get_all( $wpcpm_mentor_source )
	)
);

foreach ( array( 'wp_remote_', 'refresh(', 'WPCPM_Learn_Client', 'WPCPM_Learn::', 'WPCPM_Learn_Progress_Sync' ) as $wpcpm_forbidden ) {
	wpcpm_mentor_check( 'the class code does not use ' . $wpcpm_forbidden, false === strpos( $wpcpm_mentor_code, $wpcpm_forbidden ), true );
}

if ( $wpcpm_mentor_failures ) {
	echo "\n{$wpcpm_mentor_failures} FAILURE(S) out of {$wpcpm_mentor_checks} checks\n";
	exit( 1 );
}

echo "\nALL PASS ({$wpcpm_mentor_checks} checks)\n";
