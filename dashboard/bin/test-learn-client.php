<?php
/**
 * Learn Link response parsing.
 *
 * Run from the plugin root: php bin/test-learn-client.php
 *
 * Success fixtures follow the updated contract document.
 * See fixtures/README.md for their differences from contract/.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPCPM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

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
	 * @param string $message Error message (unused in unit tests).
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
	return rtrim( $string, '/\\' );
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
 * Mock settings class for testing.
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

/** @var array<string, mixed> Global tracking for mocked HTTP requests. */
$wpcpm_http_mock = array(
	'last_url'    => null,
	'last_args'   => null,
	'next_return' => null,
);

/**
 * Mock wp_remote_get.
 *
 * @param string $url  Request URL.
 * @param array  $args Request arguments.
 * @return array|WP_Error
 */
function wp_remote_get( $url, array $args = array() ) {
	global $wpcpm_http_mock;
	$wpcpm_http_mock['last_url']  = $url;
	$wpcpm_http_mock['last_args'] = $args;
	return $wpcpm_http_mock['next_return'];
}

/**
 * Mock wp_remote_post.
 *
 * @param string $url  Request URL.
 * @param array  $args Request arguments.
 * @return array|WP_Error
 */
function wp_remote_post( $url, array $args = array() ) {
	global $wpcpm_http_mock;
	$wpcpm_http_mock['last_url']  = $url;
	$wpcpm_http_mock['last_args'] = $args;
	return $wpcpm_http_mock['next_return'];
}

/**
 * Mock wp_remote_request.
 *
 * @param string $url  Request URL.
 * @param array  $args Request arguments.
 * @return array|WP_Error
 */
function wp_remote_request( $url, array $args = array() ) {
	global $wpcpm_http_mock;
	$wpcpm_http_mock['last_url']  = $url;
	$wpcpm_http_mock['last_args'] = $args;
	return $wpcpm_http_mock['next_return'];
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

require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-client.php';

$wpcpm_learn_client_failures = 0;
$wpcpm_learn_client_checks   = 0;

/**
 * Compare one result with its expected value.
 *
 * @param string $label Test label.
 * @param mixed  $got   Actual value.
 * @param mixed  $want  Expected value.
 */
function wpcpm_learn_client_check( $label, $got, $want ) {
	global $wpcpm_learn_client_failures, $wpcpm_learn_client_checks;

	++$wpcpm_learn_client_checks;
	if ( $got === $want ) {
		echo "OK: {$label}\n";
		return;
	}

	++$wpcpm_learn_client_failures;
	echo "FAIL: {$label}\n";
	echo '  got:  ' . var_export( $got, true ) . "\n";
	echo '  want: ' . var_export( $want, true ) . "\n";
}

/**
 * Load one progress fixture.
 *
 * @param string $name Fixture name without its prefix or extension.
 * @return array
 */
function wpcpm_learn_client_fixture( $name ) {
	$path = __DIR__ . '/fixtures/learn-progress-' . $name . '.json';
	return json_decode( file_get_contents( $path ), true );
}

$wpcpm_valid = wpcpm_learn_client_fixture( 'not-started' );
$wpcpm_parsed = WPCPM_Learn_Client::parse( $wpcpm_valid );

wpcpm_learn_client_check( 'a contract fixture passes the outer validation', is_array( $wpcpm_parsed ), true );
wpcpm_learn_client_check( 'the username is normalized to the top level', $wpcpm_parsed['username'], 'adaexample' );
wpcpm_learn_client_check( 'the generated date becomes a Unix timestamp', is_int( $wpcpm_parsed['generated_at'] ), true );
wpcpm_learn_client_check( 'the same username matches exactly', WPCPM_Learn_Client::same_username( 'adaexample', 'adaexample' ), true );
wpcpm_learn_client_check( 'username comparison ignores case and surrounding whitespace', WPCPM_Learn_Client::same_username( ' AdaExample ', ' adaexample' ), true );
wpcpm_learn_client_check( 'a different username does not match', WPCPM_Learn_Client::same_username( 'another-user', 'adaexample' ), false );
wpcpm_learn_client_check( 'blank usernames do not match', WPCPM_Learn_Client::same_username( '  ', '' ), false );
wpcpm_learn_client_check( 'a non-text username does not match', WPCPM_Learn_Client::same_username( array( 'adaexample' ), 'adaexample' ), false );

$wpcpm_bad             = $wpcpm_valid;
$wpcpm_bad['contract'] = 'learn-progress/2';
wpcpm_learn_client_check( 'another contract version is refused', WPCPM_Learn_Client::parse( $wpcpm_bad )->get_error_code(), 'wpcpm_learn_bad_answer' );

$wpcpm_bad                                  = $wpcpm_valid;
$wpcpm_bad['learner']['wporg_username']     = '   ';
wpcpm_learn_client_check( 'an empty username is refused', WPCPM_Learn_Client::parse( $wpcpm_bad )->get_error_code(), 'wpcpm_learn_bad_answer' );

$wpcpm_bad                 = $wpcpm_valid;
$wpcpm_bad['generated_at'] = '2026-10-14 09:12:33';
wpcpm_learn_client_check( 'a date without T and Z is refused', WPCPM_Learn_Client::parse( $wpcpm_bad )->get_error_code(), 'wpcpm_learn_bad_answer' );

$wpcpm_bad                 = $wpcpm_valid;
$wpcpm_bad['generated_at'] = '2026-02-31T09:12:33Z';
wpcpm_learn_client_check( 'an impossible calendar date is refused', WPCPM_Learn_Client::parse( $wpcpm_bad )->get_error_code(), 'wpcpm_learn_bad_answer' );

$wpcpm_bad            = $wpcpm_valid;
$wpcpm_bad['courses'] = array( 'course' => array() );
wpcpm_learn_client_check( 'courses must be a list', WPCPM_Learn_Client::parse( $wpcpm_bad )->get_error_code(), 'wpcpm_learn_bad_answer' );

$wpcpm_with_unknown            = $wpcpm_valid;
$wpcpm_with_unknown['private'] = 'must not survive';
$wpcpm_parsed                  = WPCPM_Learn_Client::parse( $wpcpm_with_unknown );
wpcpm_learn_client_check( 'unknown top-level fields are not normalized', array_key_exists( 'private', $wpcpm_parsed ), false );

foreach ( array( 'learner', 'generated_at', 'courses', 'contract' ) as $wpcpm_field ) {
	$wpcpm_bad = $wpcpm_valid;
	unset( $wpcpm_bad[ $wpcpm_field ] );
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_bad );
	wpcpm_learn_client_check(
		'a response missing ' . $wpcpm_field . ' is refused',
		is_wp_error( $wpcpm_result ) ? $wpcpm_result->get_error_code() : 'accepted',
		'wpcpm_learn_bad_answer'
	);
}

$wpcpm_in_progress = WPCPM_Learn_Client::parse( wpcpm_learn_client_fixture( 'in-progress' ) );
$wpcpm_course_ids  = array_keys( $wpcpm_in_progress['courses'] );
$wpcpm_track       = $wpcpm_in_progress['courses'][297853];
$wpcpm_foundation  = $wpcpm_in_progress['courses'][123456];

wpcpm_learn_client_check( 'courses are indexed without changing their response order', $wpcpm_course_ids, array( 297853, 123456 ) );
wpcpm_learn_client_check( 'the track course has its normalized fields only', array_keys( $wpcpm_track ), array( 'status', 'enrolled_at', 'completed_at', 'percent', 'lessons' ) );
wpcpm_learn_client_check( 'an enrollment date becomes a Unix timestamp', is_int( $wpcpm_track['enrolled_at'] ), true );
wpcpm_learn_client_check( 'a missing completion date stays null', $wpcpm_track['completed_at'], null );
wpcpm_learn_client_check( 'the fixture percentage is kept', $wpcpm_track['percent'], 50 );
wpcpm_learn_client_check( 'a never-enrolled course keeps a null enrollment date', $wpcpm_foundation['enrolled_at'], null );

$wpcpm_bad                              = $wpcpm_valid;
$wpcpm_bad['courses'][0]['course_id']   = '297853';
wpcpm_learn_client_check( 'a course ID must be an integer', WPCPM_Learn_Client::parse( $wpcpm_bad )->get_error_code(), 'wpcpm_learn_bad_answer' );

$wpcpm_bad                            = $wpcpm_valid;
$wpcpm_bad['courses'][0]['status']    = 'almost-finished';
wpcpm_learn_client_check( 'an unknown course status is refused', WPCPM_Learn_Client::parse( $wpcpm_bad )->get_error_code(), 'wpcpm_learn_bad_answer' );

$wpcpm_bad                                 = $wpcpm_valid;
$wpcpm_bad['courses'][0]['enrolled_at']    = '2026-10-12';
wpcpm_learn_client_check( 'a malformed course date is refused', WPCPM_Learn_Client::parse( $wpcpm_bad )->get_error_code(), 'wpcpm_learn_bad_answer' );

$wpcpm_high                                  = $wpcpm_valid;
$wpcpm_high['courses'][0]['progress_percent'] = 140;
$wpcpm_high                                  = WPCPM_Learn_Client::parse( $wpcpm_high );
wpcpm_learn_client_check( 'a percentage above 100 is capped', $wpcpm_high['courses'][297853]['percent'], 100 );

$wpcpm_low                                  = $wpcpm_valid;
$wpcpm_low['courses'][0]['progress_percent'] = -20;
$wpcpm_low                                  = WPCPM_Learn_Client::parse( $wpcpm_low );
wpcpm_learn_client_check( 'a percentage below zero is floored', $wpcpm_low['courses'][297853]['percent'], 0 );

$wpcpm_bad                                   = $wpcpm_valid;
$wpcpm_bad['courses'][0]['progress_percent'] = '50';
wpcpm_learn_client_check( 'a percentage written as text is refused', WPCPM_Learn_Client::parse( $wpcpm_bad )->get_error_code(), 'wpcpm_learn_bad_answer' );

$wpcpm_bad                            = $wpcpm_valid;
$wpcpm_bad['courses'][0]['lessons']   = array( 'lesson' => array() );
wpcpm_learn_client_check( 'lessons must be a list', WPCPM_Learn_Client::parse( $wpcpm_bad )->get_error_code(), 'wpcpm_learn_bad_answer' );

foreach ( array( 'course_id', 'status', 'enrolled_at', 'completed_at', 'progress_percent', 'lessons' ) as $wpcpm_field ) {
	$wpcpm_bad = $wpcpm_valid;
	unset( $wpcpm_bad['courses'][0][ $wpcpm_field ] );
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_bad );
	wpcpm_learn_client_check(
		'a course missing ' . $wpcpm_field . ' is refused',
		is_wp_error( $wpcpm_result ) ? $wpcpm_result->get_error_code() : 'accepted',
		'wpcpm_learn_bad_answer'
	);
}

$wpcpm_bad              = $wpcpm_valid;
$wpcpm_bad['courses'][] = $wpcpm_bad['courses'][0];
wpcpm_learn_client_check( 'a duplicate course ID is refused', WPCPM_Learn_Client::parse( $wpcpm_bad )->get_error_code(), 'wpcpm_learn_bad_answer' );

$wpcpm_complete                                   = wpcpm_learn_client_fixture( 'complete' );
$wpcpm_complete['courses'][0]['progress_percent'] = 88;
$wpcpm_complete                                   = WPCPM_Learn_Client::parse( $wpcpm_complete );
wpcpm_learn_client_check( 'a complete course with 88 percent is accepted', $wpcpm_complete['courses'][297853]['percent'], 88 );

$wpcpm_lessons = $wpcpm_in_progress['courses'][297853]['lessons'];
wpcpm_learn_client_check(
	'lesson IDs and their original order are preserved',
	array_column( $wpcpm_lessons, 'id' ),
	array( 297901, 297902, 297903, 297904, 297905, 297906, 297907, 297908 )
);
wpcpm_learn_client_check(
	'a completed lesson without a quiz has the normalized shape',
	$wpcpm_lessons[0],
	array(
		'id'           => 297901,
		'status'       => 'complete',
		'completed_at' => gmmktime( 16, 20, 5, 9, 3, 2026 ),
		'quiz'         => null,
	)
);
wpcpm_learn_client_check( 'a lesson awaiting grading keeps its status', $wpcpm_lessons[4]['status'], 'ungraded' );
wpcpm_learn_client_check( 'an unfinished lesson keeps a null completion date', $wpcpm_lessons[4]['completed_at'], null );
wpcpm_learn_client_check( 'an empty lesson list stays empty', $wpcpm_foundation['lessons'], array() );

$wpcpm_extra = $wpcpm_valid;
$wpcpm_extra['courses'][0]['lessons'][0]['private'] = 'must not survive';
$wpcpm_extra = WPCPM_Learn_Client::parse( $wpcpm_extra );
wpcpm_learn_client_check(
	'unknown lesson fields are dropped',
	array_key_exists( 'private', $wpcpm_extra['courses'][297853]['lessons'][0] ),
	false
);

// Corrupt one lesson at a time; valid courses around it must not hide the error.
$wpcpm_lesson_cases = array(
	'text lesson ID'          => array( 'lesson_id', '297901' ),
	'zero lesson ID'          => array( 'lesson_id', 0 ),
	'negative lesson ID'      => array( 'lesson_id', -1 ),
	'fractional lesson ID'    => array( 'lesson_id', 1.5 ),
	'boolean lesson ID'       => array( 'lesson_id', true ),
	'unknown lesson status'   => array( 'status', 'almost-finished' ),
	'non-text lesson status'  => array( 'status', true ),
	'impossible lesson date'  => array( 'completed_at', '2026-02-31T09:12:33Z' ),
	'empty lesson date'       => array( 'completed_at', '' ),
	'non-text lesson date'    => array( 'completed_at', 123 ),
	'quiz as text'            => array( 'quiz', 'passed' ),
	'quiz as boolean'         => array( 'quiz', false ),
	'quiz as a list'          => array( 'quiz', array( 'passed' ) ),
	'quiz with no fields'     => array( 'quiz', array() ),
);
foreach ( $wpcpm_lesson_cases as $wpcpm_label => $wpcpm_case ) {
	$wpcpm_bad = $wpcpm_valid;
	$wpcpm_bad['courses'][0]['lessons'][0][ $wpcpm_case[0] ] = $wpcpm_case[1];
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_bad );
	wpcpm_learn_client_check(
		$wpcpm_label . ' is refused',
		is_wp_error( $wpcpm_result ) ? $wpcpm_result->get_error_code() : 'accepted',
		'wpcpm_learn_bad_answer'
	);
}

foreach ( array( 'lesson_id', 'status', 'completed_at', 'quiz' ) as $wpcpm_field ) {
	$wpcpm_bad = $wpcpm_valid;
	unset( $wpcpm_bad['courses'][0]['lessons'][0][ $wpcpm_field ] );
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_bad );
	wpcpm_learn_client_check(
		'a lesson missing ' . $wpcpm_field . ' is refused',
		is_wp_error( $wpcpm_result ) ? $wpcpm_result->get_error_code() : 'accepted',
		'wpcpm_learn_bad_answer'
	);
}

$wpcpm_bad = $wpcpm_valid;
$wpcpm_bad['courses'][0]['lessons'][] = $wpcpm_bad['courses'][0]['lessons'][0];
$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_bad );
wpcpm_learn_client_check(
	'a duplicate lesson within a course is refused',
	is_wp_error( $wpcpm_result ) ? $wpcpm_result->get_error_code() : 'accepted',
	'wpcpm_learn_bad_answer'
);

$wpcpm_bad = $wpcpm_valid;
$wpcpm_bad['courses'][0]['lessons'][0] = 'not a lesson object';
$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_bad );
wpcpm_learn_client_check(
	'a non-object lesson is refused',
	is_wp_error( $wpcpm_result ) ? $wpcpm_result->get_error_code() : 'accepted',
	'wpcpm_learn_bad_answer'
);

$wpcpm_shared = $wpcpm_valid;
$wpcpm_shared['courses'][1]['lessons'] = $wpcpm_shared['courses'][0]['lessons'];
$wpcpm_shared['courses'][1]['enrolled_at'] = $wpcpm_shared['courses'][0]['enrolled_at'];
wpcpm_learn_client_check(
	'the same lesson ID may appear in different courses',
	is_array( WPCPM_Learn_Client::parse( $wpcpm_shared ) ),
	true
);

// Pin the no-pass-required quiz across all three updated success examples.
$wpcpm_no_pass_cases = array(
	'not-started' => array( 'not-started', 'not-taken', null, null ),
	'in-progress' => array( 'complete', 'graded', 50, null ),
	'complete'    => array( 'complete', 'graded', 50, null ),
);
foreach ( $wpcpm_no_pass_cases as $wpcpm_name => $wpcpm_expected ) {
	$wpcpm_body   = wpcpm_learn_client_fixture( $wpcpm_name );
	$wpcpm_lesson = $wpcpm_body['courses'][0]['lessons'][2];
	wpcpm_learn_client_check(
		$wpcpm_name . ' fixture represents lesson 297903 with no pass requirement',
		array( $wpcpm_lesson['lesson_id'], $wpcpm_lesson['quiz']['quiz_id'], $wpcpm_lesson['status'], $wpcpm_lesson['quiz']['status'], $wpcpm_lesson['quiz']['grade_percent'], $wpcpm_lesson['quiz']['passmark_percent'] ),
		array_merge( array( 297903, 297953 ), $wpcpm_expected )
	);
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_body );
	$wpcpm_lesson = $wpcpm_result['courses'][297853]['lessons'][2];
	wpcpm_learn_client_check(
		$wpcpm_name . ' preserves the lesson status, quiz status, grade and null pass mark',
		array( $wpcpm_lesson['status'], $wpcpm_lesson['quiz']['status'], $wpcpm_lesson['quiz']['grade'], $wpcpm_lesson['quiz']['passmark'] ),
		$wpcpm_expected
	);
}

// Additional quiz cases are built in memory without modifying fixture files.
$wpcpm_quiz_body = wpcpm_learn_client_fixture( 'in-progress' );
$wpcpm_quiz      = $wpcpm_in_progress['courses'][297853]['lessons'][1]['quiz'];
wpcpm_learn_client_check(
	'a passed quiz has its normalized fields and exact UTC timestamp',
	$wpcpm_quiz,
	array(
		'id'        => 297952,
		'status'    => 'passed',
		'grade'     => 85,
		'passmark'  => 70,
		'graded_at' => gmmktime( 18, 44, 10, 9, 8, 2026 ),
	)
);

foreach ( array( 'not-taken', 'ungraded', 'graded', 'passed', 'failed' ) as $wpcpm_status ) {
	$wpcpm_pending = in_array( $wpcpm_status, array( 'not-taken', 'ungraded' ), true );
	$wpcpm_body = $wpcpm_quiz_body;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz'] = array(
		'quiz_id'          => 297952,
		'status'           => $wpcpm_status,
		'grade_percent'    => $wpcpm_pending ? null : ( 'failed' === $wpcpm_status ? 40 : 85 ),
		'passmark_percent' => 'graded' === $wpcpm_status ? null : 70,
		'graded_at'        => $wpcpm_pending ? null : '2026-09-08T18:44:10Z',
	);
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_body );
	wpcpm_learn_client_check(
		'a quiz keeps its ' . $wpcpm_status . ' state, grade and pass requirement',
		is_wp_error( $wpcpm_result ) ? $wpcpm_result->get_error_code() : $wpcpm_result['courses'][297853]['lessons'][1]['quiz'],
		array(
			'id'        => 297952,
			'status'    => $wpcpm_status,
			'grade'     => $wpcpm_pending ? null : ( 'failed' === $wpcpm_status ? 40 : 85 ),
			'passmark'  => 'graded' === $wpcpm_status ? null : 70,
			'graded_at' => $wpcpm_pending ? null : gmmktime( 18, 44, 10, 9, 8, 2026 ),
		)
	);
}

$wpcpm_quiz_cases = array(
	'text quiz ID'             => array( 'quiz_id' => '297952' ),
	'zero quiz ID'             => array( 'quiz_id' => 0 ),
	'negative quiz ID'         => array( 'quiz_id' => -1 ),
	'fractional quiz ID'       => array( 'quiz_id' => 1.5 ),
	'boolean quiz ID'          => array( 'quiz_id' => true ),
	'unknown quiz status'      => array( 'status' => 'excellent' ),
	'non-text quiz status'     => array( 'status' => true ),
	'text grade'               => array( 'grade_percent' => '85' ),
	'fractional grade'         => array( 'grade_percent' => 85.5 ),
	'boolean grade'            => array( 'grade_percent' => false ),
	'array grade'              => array( 'grade_percent' => array() ),
	'text pass mark'           => array( 'passmark_percent' => '70' ),
	'fractional pass mark'     => array( 'passmark_percent' => 70.5 ),
	'boolean pass mark'        => array( 'passmark_percent' => false ),
	'graded with a pass mark'  => array( 'status' => 'graded', 'passmark_percent' => 70 ),
	'passed without pass mark' => array( 'status' => 'passed', 'passmark_percent' => null ),
	'failed without pass mark' => array( 'status' => 'failed', 'passmark_percent' => null ),
	'impossible grading date'  => array( 'graded_at' => '2026-02-31T09:12:33Z' ),
	'non-UTC grading date'     => array( 'graded_at' => '2026-09-08T18:44:10+01:00' ),
	'empty grading date'       => array( 'graded_at' => '' ),
	'non-text grading date'    => array( 'graded_at' => 123 ),
);
foreach ( $wpcpm_quiz_cases as $wpcpm_label => $wpcpm_changes ) {
	$wpcpm_body = $wpcpm_quiz_body;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz'] = array_merge( $wpcpm_body['courses'][0]['lessons'][1]['quiz'], $wpcpm_changes );
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_body );
	wpcpm_learn_client_check(
		$wpcpm_label . ' is refused',
		is_wp_error( $wpcpm_result ) ? $wpcpm_result->get_error_code() : 'accepted',
		'wpcpm_learn_bad_answer'
	);
}

foreach ( array( 'quiz_id', 'status', 'grade_percent', 'passmark_percent', 'graded_at' ) as $wpcpm_field ) {
	$wpcpm_body = $wpcpm_quiz_body;
	unset( $wpcpm_body['courses'][0]['lessons'][1]['quiz'][ $wpcpm_field ] );
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_body );
	wpcpm_learn_client_check(
		'a quiz missing ' . $wpcpm_field . ' is refused',
		is_wp_error( $wpcpm_result ) ? $wpcpm_result->get_error_code() : 'accepted',
		'wpcpm_learn_bad_answer'
	);
}

foreach ( array( -20 => 0, 0 => 0, 100 => 100, 140 => 100 ) as $wpcpm_input => $wpcpm_expected ) {
	$wpcpm_body = $wpcpm_quiz_body;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['grade_percent'] = $wpcpm_input;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['passmark_percent'] = $wpcpm_input;
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_body );
	$wpcpm_quiz = $wpcpm_result['courses'][297853]['lessons'][1]['quiz'];
	wpcpm_learn_client_check( 'quiz percentages normalize ' . $wpcpm_input, array( $wpcpm_quiz['grade'], $wpcpm_quiz['passmark'] ), array( $wpcpm_expected, $wpcpm_expected ) );
}

foreach ( array( 'not-taken', 'ungraded' ) as $wpcpm_status ) {
	$wpcpm_body = $wpcpm_quiz_body;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['status'] = $wpcpm_status;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['grade_percent'] = null;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['passmark_percent'] = null;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['graded_at'] = null;
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_body );
	wpcpm_learn_client_check( $wpcpm_status . ' allows no pass requirement', is_array( $wpcpm_result ), true );
}

foreach ( array( 'graded', 'passed', 'failed' ) as $wpcpm_status ) {
	$wpcpm_body = $wpcpm_quiz_body;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['status'] = $wpcpm_status;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['grade_percent'] = null;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['passmark_percent'] = 'graded' === $wpcpm_status ? null : 70;
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_body );
	wpcpm_learn_client_check(
		$wpcpm_status . ' without a grade is refused',
		is_wp_error( $wpcpm_result ) ? $wpcpm_result->get_error_code() : 'accepted',
		'wpcpm_learn_bad_answer'
	);
}

foreach ( array( 'not-taken', 'ungraded' ) as $wpcpm_status ) {
	$wpcpm_body = $wpcpm_quiz_body;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['status'] = $wpcpm_status;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['grade_percent'] = 85;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['passmark_percent'] = null;
	$wpcpm_body['courses'][0]['lessons'][1]['quiz']['graded_at'] = null;
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_body );
	wpcpm_learn_client_check(
		$wpcpm_status . ' with a grade is refused',
		is_wp_error( $wpcpm_result ) ? $wpcpm_result->get_error_code() : 'accepted',
		'wpcpm_learn_bad_answer'
	);
}

$wpcpm_body = $wpcpm_quiz_body;
$wpcpm_body['courses'][0]['lessons'][1]['quiz']['private'] = 'must not survive';
$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_body );
wpcpm_learn_client_check( 'unknown quiz fields are discarded', array_key_exists( 'private', $wpcpm_result['courses'][297853]['lessons'][1]['quiz'] ), false );
wpcpm_learn_client_check( 'a failed required quiz does not become a completed lesson', $wpcpm_result['courses'][297853]['lessons'][5]['status'], 'in-progress' );

// Error classification uses codes, never Learn's message or its nested data.status.
$wpcpm_error_fixtures = array(
	401 => 'learn_progress_invalid_token',
	403 => 'learn_progress_revoked',
	429 => 'learn_progress_slow_down',
	503 => 'learn_progress_unavailable',
);
foreach ( $wpcpm_error_fixtures as $wpcpm_http_status => $wpcpm_expected ) {
	$wpcpm_error_body = wpcpm_learn_client_fixture( 'error-' . $wpcpm_http_status );
	wpcpm_learn_client_check(
		'the ' . $wpcpm_http_status . ' fixture returns its contract code',
		WPCPM_Learn_Client::error_code( $wpcpm_http_status, $wpcpm_error_body ),
		$wpcpm_expected
	);
	wpcpm_learn_client_check(
		'the ' . $wpcpm_http_status . ' fixture code takes precedence over HTTP status',
		WPCPM_Learn_Client::error_code( 502, $wpcpm_error_body ),
		$wpcpm_expected
	);
}

$wpcpm_http_cases = array(
	401 => 'wpcpm_learn_bad_answer',
	403 => 'wpcpm_learn_bad_answer',
	429 => 'learn_progress_slow_down',
	500 => 'learn_progress_unavailable',
	502 => 'learn_progress_unavailable',
	503 => 'learn_progress_unavailable',
	599 => 'learn_progress_unavailable',
	499 => 'wpcpm_learn_bad_answer',
	600 => 'wpcpm_learn_bad_answer',
	418 => 'wpcpm_learn_bad_answer',
	404 => 'wpcpm_learn_bad_answer',
	200 => 'wpcpm_learn_bad_answer',
	0   => 'wpcpm_learn_bad_answer',
);
foreach ( $wpcpm_http_cases as $wpcpm_http_status => $wpcpm_expected ) {
	wpcpm_learn_client_check(
		'HTTP ' . $wpcpm_http_status . ' with no JSON uses the fallback',
		WPCPM_Learn_Client::error_code( $wpcpm_http_status, null ),
		$wpcpm_expected
	);
}

$wpcpm_error_bodies = array(
	'empty body'        => array(),
	'text body'         => 'not JSON',
	'boolean body'      => false,
	'numeric body'      => 123,
	'unknown code'      => array( 'code' => 'unexpected_error' ),
	'null code'         => array( 'code' => null ),
	'boolean code'      => array( 'code' => true ),
	'list-shaped code'  => array( 'code' => array( 'learn_progress_revoked' ) ),
	'misleading fields' => array( 'message' => 'learn_progress_revoked', 'data' => array( 'status' => 403 ) ),
);
foreach ( $wpcpm_error_bodies as $wpcpm_label => $wpcpm_error_body ) {
	wpcpm_learn_client_check(
		$wpcpm_label . ' falls back to the actual HTTP status',
		WPCPM_Learn_Client::error_code( 503, $wpcpm_error_body ),
		'learn_progress_unavailable'
	);
}

wpcpm_learn_client_check(
	'an unknown code on an unmapped status is not passed through',
	WPCPM_Learn_Client::error_code( 400, array( 'code' => 'unexpected_error' ) ),
	'wpcpm_learn_bad_answer'
);
wpcpm_learn_client_check(
	'a non-integer HTTP status is not guessed',
	WPCPM_Learn_Client::error_code( '401', null ),
	'wpcpm_learn_bad_answer'
);
wpcpm_learn_client_check(
	'a known code still works without a usable HTTP status',
	WPCPM_Learn_Client::error_code( null, array( 'code' => 'learn_progress_revoked' ) ),
	'learn_progress_revoked'
);

// Acceptance checks: all success fixtures, no input changes, and no printed output.
foreach ( array( 'not-started', 'in-progress', 'complete' ) as $wpcpm_name ) {
	$wpcpm_original = wpcpm_learn_client_fixture( $wpcpm_name );
	$wpcpm_body     = $wpcpm_original;
	ob_start();
	$wpcpm_result = WPCPM_Learn_Client::parse( $wpcpm_body );
	$wpcpm_output = ob_get_clean();
	wpcpm_learn_client_check( $wpcpm_name . ' parses without modifying the example', array( is_array( $wpcpm_result ), $wpcpm_body === $wpcpm_original, $wpcpm_output ), array( true, true, '' ) );
	wpcpm_learn_client_check( $wpcpm_name . ' produces the same result on a second call', WPCPM_Learn_Client::parse( $wpcpm_body ), $wpcpm_result );
}

// Compare the whole result, including course, lesson and quiz dates.
$wpcpm_timezone = date_default_timezone_get();
try {
	date_default_timezone_set( 'UTC' );
	$wpcpm_utc = WPCPM_Learn_Client::parse( $wpcpm_quiz_body );
	date_default_timezone_set( 'America/Mexico_City' );
	wpcpm_learn_client_check( 'UTC dates do not depend on the local time zone', WPCPM_Learn_Client::parse( $wpcpm_quiz_body ), $wpcpm_utc );
} finally {
	date_default_timezone_set( $wpcpm_timezone );
}

$wpcpm_body = $wpcpm_quiz_body;
$wpcpm_body['learner']['id'] = 999;
$wpcpm_body['learner']['email'] = 'invented@example.test';
$wpcpm_body['courses'][0]['title'] = 'An extra course title';
$wpcpm_body['courses'][0]['lessons'][0]['title'] = 'An extra lesson title';
$wpcpm_body['courses'][0]['lessons'][1]['quiz']['private'] = 'An extra quiz field';
wpcpm_learn_client_check(
	'additional learner and curriculum fields do not enter the normalized result',
	WPCPM_Learn_Client::parse( $wpcpm_body ),
	WPCPM_Learn_Client::parse( $wpcpm_quiz_body )
);

// --- Tests for WPCPM_Learn_Client::exchange() and revoke() ---

// 1. Unreachable when learn_base_url is empty (no HTTP request made).
WPCPM_Settings::$options = array(
	'learn_base_url'  => '',
	'learn_client_id' => 'client-123',
);
$wpcpm_http_mock['last_url'] = null;
$wpcpm_res                   = WPCPM_Learn_Client::exchange( 'code_123', 'verifier_456' );
wpcpm_learn_client_check( 'exchange returns wpcpm_learn_unreachable when base URL is empty', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_unreachable' );
wpcpm_learn_client_check( 'exchange makes no request when base URL is empty', $wpcpm_http_mock['last_url'], null );

$wpcpm_res = WPCPM_Learn_Client::revoke( 'token_123' );
wpcpm_learn_client_check( 'revoke returns wpcpm_learn_unreachable when base URL is empty', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_unreachable' );
wpcpm_learn_client_check( 'revoke makes no request when base URL is empty', $wpcpm_http_mock['last_url'], null );

// Setup base URL for subsequent tests.
WPCPM_Settings::$options['learn_base_url'] = 'https://learn.test/';

// 2. Network error / unreachable during exchange.
$wpcpm_http_mock['next_return'] = new WP_Error( 'http_request_failed' );
$wpcpm_res                      = WPCPM_Learn_Client::exchange( 'code_123', 'verifier_456' );
wpcpm_learn_client_check( 'exchange returns wpcpm_learn_unreachable on network failure', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_unreachable' );
wpcpm_learn_client_check( 'exchange sets redirection to 0', $wpcpm_http_mock['last_args']['redirection'], 0 );
wpcpm_learn_client_check( 'exchange sets timeout to 5', $wpcpm_http_mock['last_args']['timeout'], 5 );
wpcpm_learn_client_check( 'exchange calls expected token endpoint', $wpcpm_http_mock['last_url'], 'https://learn.test/wp-json/learn-progress/v1/token' );

// 3. Successful exchange (200 with valid token, username and profile_slug).
$wpcpm_valid_token_43           = 'ABCdefGHIjklMNOpqrSTUvwxYZ_0123456789-abcde';
$wpcpm_http_mock['next_return'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode(
		array(
			'token'   => $wpcpm_valid_token_43,
			'learner' => array(
				'wporg_username'     => 'learn_student_01',
				'wporg_profile_slug' => 'learn-student-01',
			),
		)
	),
);
$wpcpm_res = WPCPM_Learn_Client::exchange( 'code_123', 'verifier_456' );
wpcpm_learn_client_check( 'exchange returns array on valid 200 response', is_array( $wpcpm_res ), true );
wpcpm_learn_client_check( 'exchange returns matching token', isset( $wpcpm_res['token'] ) ? $wpcpm_res['token'] : null, $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'exchange returns matching username', isset( $wpcpm_res['username'] ) ? $wpcpm_res['username'] : null, 'learn_student_01' );
wpcpm_learn_client_check( 'exchange returns matching profile_slug', isset( $wpcpm_res['profile_slug'] ) ? $wpcpm_res['profile_slug'] : null, 'learn-student-01' );

// 3b. Successful exchange when wporg_profile_slug is omitted.
$wpcpm_http_mock['next_return'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode(
		array(
			'token'   => $wpcpm_valid_token_43,
			'learner' => array( 'wporg_username' => 'learn_student_01' ),
		)
	),
);
$wpcpm_res = WPCPM_Learn_Client::exchange( 'code_123', 'verifier_456' );
wpcpm_learn_client_check(
	'exchange returns token and username when profile_slug is omitted',
	$wpcpm_res,
	array(
		'token'    => $wpcpm_valid_token_43,
		'username' => 'learn_student_01',
	)
);

// 4. Malformed 200 responses -> bad_answer.
$wpcpm_http_mock['next_return']['body'] = json_encode(
	array(
		'token'   => substr( $wpcpm_valid_token_43, 0, 42 ),
		'learner' => array( 'wporg_username' => 'learn_student_01' ),
	)
);
$wpcpm_res = WPCPM_Learn_Client::exchange( 'code_123', 'verifier_456' );
wpcpm_learn_client_check( 'exchange rejects 42-char token as bad_answer', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_bad_answer' );

$wpcpm_http_mock['next_return']['body'] = json_encode(
	array(
		'token'   => $wpcpm_valid_token_43,
		'learner' => array( 'wporg_username' => '   ' ),
	)
);
$wpcpm_res = WPCPM_Learn_Client::exchange( 'code_123', 'verifier_456' );
wpcpm_learn_client_check( 'exchange rejects empty username as bad_answer', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_bad_answer' );

$wpcpm_http_mock['next_return']['body'] = json_encode(
	array(
		'token'   => $wpcpm_valid_token_43,
		'learner' => array(
			'wporg_username'     => 'learn_student_01',
			'wporg_profile_slug' => array( 'invalid' ),
		),
	)
);
$wpcpm_res = WPCPM_Learn_Client::exchange( 'code_123', 'verifier_456' );
wpcpm_learn_client_check( 'exchange rejects non-string profile_slug as bad_answer', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_bad_answer' );

// 5. Contract error fixtures and responses during exchange (TOKEN_ERRORS).
$wpcpm_token_fixture_cases = array(
	400 => array( 'error-400-invalid-grant', 'learn_progress_invalid_grant' ),
	401 => array( 'error-401-invalid-client', 'learn_progress_invalid_client' ),
);
foreach ( $wpcpm_token_fixture_cases as $wpcpm_http_status => $wpcpm_case ) {
	$wpcpm_fixture_body             = wpcpm_learn_client_fixture( $wpcpm_case[0] );
	$wpcpm_http_mock['next_return'] = array(
		'response' => array( 'code' => $wpcpm_http_status ),
		'body'     => json_encode( $wpcpm_fixture_body ),
	);
	$wpcpm_res = WPCPM_Learn_Client::exchange( 'code_123', 'verifier_456' );
	wpcpm_learn_client_check(
		'exchange maps fixture ' . $wpcpm_case[0] . ' to ' . $wpcpm_case[1],
		is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null,
		$wpcpm_case[1]
	);
}

foreach ( array( 'learn_progress_invalid_request', 'learn_progress_slow_down' ) as $wpcpm_err_code ) {
	$wpcpm_http_mock['next_return'] = array(
		'response' => array( 'code' => 400 ),
		'body'     => json_encode( array( 'code' => $wpcpm_err_code, 'message' => 'Detail for ' . $wpcpm_err_code ) ),
	);
	$wpcpm_res = WPCPM_Learn_Client::exchange( 'code_123', 'verifier_456' );
	wpcpm_learn_client_check( "exchange maps {$wpcpm_err_code} contract code", is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, $wpcpm_err_code );
}

// 6. 503 error during exchange -> learn_progress_unavailable.
$wpcpm_http_mock['next_return'] = array(
	'response' => array( 'code' => 503 ),
	'body'     => 'Service Unavailable',
);
$wpcpm_res = WPCPM_Learn_Client::exchange( 'code_123', 'verifier_456' );
wpcpm_learn_client_check( 'exchange maps 503 to learn_progress_unavailable', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'learn_progress_unavailable' );

// 7. Revoke tests.
// 7a. 204 No Content -> true.
$wpcpm_http_mock['next_return'] = array(
	'response' => array( 'code' => 204 ),
	'body'     => '',
);
$wpcpm_res = WPCPM_Learn_Client::revoke( $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'revoke returns true on 204', $wpcpm_res, true );
wpcpm_learn_client_check( 'revoke sets DELETE method', $wpcpm_http_mock['last_args']['method'], 'DELETE' );
wpcpm_learn_client_check( 'revoke sets Authorization Bearer header', $wpcpm_http_mock['last_args']['headers']['Authorization'], 'Bearer ' . $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'revoke sets redirection to 0', $wpcpm_http_mock['last_args']['redirection'], 0 );

// 7b. 401 learn_progress_invalid_token and 403 learn_progress_revoked -> true (contract section 4).
$wpcpm_http_mock['next_return'] = array(
	'response' => array( 'code' => 401 ),
	'body'     => json_encode( wpcpm_learn_client_fixture( 'error-401' ) ),
);
$wpcpm_res = WPCPM_Learn_Client::revoke( $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'revoke returns true on 401 learn_progress_invalid_token', $wpcpm_res, true );

$wpcpm_http_mock['next_return'] = array(
	'response' => array( 'code' => 403 ),
	'body'     => json_encode( wpcpm_learn_client_fixture( 'error-403' ) ),
);
$wpcpm_res = WPCPM_Learn_Client::revoke( $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'revoke returns true on 403 learn_progress_revoked', $wpcpm_res, true );

// 7c. 401 or 403 without a contract JSON code -> wpcpm_learn_bad_answer.
$wpcpm_http_mock['next_return'] = array(
	'response' => array( 'code' => 401 ),
	'body'     => 'Unauthorized',
);
$wpcpm_res = WPCPM_Learn_Client::revoke( $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'revoke rejects 401 without contract code as wpcpm_learn_bad_answer', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_bad_answer' );

// 7d. Network failure during revoke.
$wpcpm_http_mock['next_return'] = new WP_Error( 'http_request_failed' );
$wpcpm_res                      = WPCPM_Learn_Client::revoke( $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'revoke returns wpcpm_learn_unreachable on network failure', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_unreachable' );

// 7e. 503 and 429 during revoke -> WP_Error so retry continues.
$wpcpm_http_mock['next_return'] = array(
	'response' => array( 'code' => 503 ),
	'body'     => json_encode( wpcpm_learn_client_fixture( 'error-503' ) ),
);
$wpcpm_res = WPCPM_Learn_Client::revoke( $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'revoke returns learn_progress_unavailable on 503', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'learn_progress_unavailable' );

$wpcpm_http_mock['next_return'] = array(
	'response' => array( 'code' => 429 ),
	'body'     => json_encode( wpcpm_learn_client_fixture( 'error-429' ) ),
);
$wpcpm_res = WPCPM_Learn_Client::revoke( $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'revoke returns learn_progress_slow_down on 429', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'learn_progress_slow_down' );

// --- Tests for WPCPM_Learn_Client::fetch() ---

// 8a. Empty base URL -> wpcpm_learn_unreachable without request.
WPCPM_Settings::$options['learn_base_url'] = '';
$wpcpm_http_mock['last_url']               = null;
$wpcpm_res                                 = WPCPM_Learn_Client::fetch( $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'fetch returns wpcpm_learn_unreachable when base URL is empty', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_unreachable' );
wpcpm_learn_client_check( 'fetch makes no request when base URL is empty', $wpcpm_http_mock['last_url'], null );

WPCPM_Settings::$options['learn_base_url'] = 'https://learn.test/';

// 8b. Network failure -> wpcpm_learn_unreachable, redirection = 0, timeout = 5.
$wpcpm_http_mock['next_return'] = new WP_Error( 'http_request_failed' );
$wpcpm_res                      = WPCPM_Learn_Client::fetch( $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'fetch returns wpcpm_learn_unreachable on network failure', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_unreachable' );
wpcpm_learn_client_check( 'fetch calls expected /me endpoint', $wpcpm_http_mock['last_url'], 'https://learn.test/wp-json/learn-progress/v1/me' );
wpcpm_learn_client_check( 'fetch sets redirection to 0', $wpcpm_http_mock['last_args']['redirection'], 0 );
wpcpm_learn_client_check( 'fetch sets timeout to 5', $wpcpm_http_mock['last_args']['timeout'], 5 );
wpcpm_learn_client_check( 'fetch sends Bearer token header', $wpcpm_http_mock['last_args']['headers']['Authorization'], 'Bearer ' . $wpcpm_valid_token_43 );

// 8c. 200 with valid fixture -> normalized parse() output.
$wpcpm_in_progress_fixture      = wpcpm_learn_client_fixture( 'in-progress' );
$wpcpm_http_mock['next_return'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => json_encode( $wpcpm_in_progress_fixture ),
);
$wpcpm_res = WPCPM_Learn_Client::fetch( $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'fetch returns parsed progress on valid 200 response', $wpcpm_res, WPCPM_Learn_Client::parse( $wpcpm_in_progress_fixture ) );

// 8d. 200 with non-JSON or scalar JSON body -> wpcpm_learn_bad_answer.
$wpcpm_http_mock['next_return'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => 'not valid json',
);
$wpcpm_res = WPCPM_Learn_Client::fetch( $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'fetch returns wpcpm_learn_bad_answer on non-JSON 200 body', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'wpcpm_learn_bad_answer' );

// 8e. Non-200 error fixtures (401, 403, 429 with Retry-After, 503).
$wpcpm_http_mock['next_return'] = array(
	'response' => array( 'code' => 429 ),
	'headers'  => array( 'Retry-After' => '1800' ),
	'body'     => json_encode( wpcpm_learn_client_fixture( 'error-429' ) ),
);
$wpcpm_res = WPCPM_Learn_Client::fetch( $wpcpm_valid_token_43 );
wpcpm_learn_client_check( 'fetch returns learn_progress_slow_down on 429', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_code() : null, 'learn_progress_slow_down' );
wpcpm_learn_client_check( 'fetch attaches retry_after from Retry-After header on 429', is_wp_error( $wpcpm_res ) ? $wpcpm_res->get_error_data() : null, array( 'retry_after' => 1800 ) );

// 8f. courses= on the read (contract section 1.4, decision 4 in #2).
$wpcpm_http_mock['next_return'] = new WP_Error( 'http_request_failed' );
WPCPM_Learn_Client::fetch( $wpcpm_valid_token_43, array( 297853 ) );
wpcpm_learn_client_check( 'fetch sends courses= with the one track course', $wpcpm_http_mock['last_url'], 'https://learn.test/wp-json/learn-progress/v1/me?courses=297853' );
$wpcpm_http_mock['next_return'] = new WP_Error( 'http_request_failed' );
WPCPM_Learn_Client::fetch( $wpcpm_valid_token_43, array( 301001, '297853', 301001 ) );
wpcpm_learn_client_check( 'fetch sends several courses comma-separated, once each', $wpcpm_http_mock['last_url'], 'https://learn.test/wp-json/learn-progress/v1/me?courses=301001,297853' );
$wpcpm_http_mock['next_return'] = new WP_Error( 'http_request_failed' );
WPCPM_Learn_Client::fetch( $wpcpm_valid_token_43, array( 0, -5, 'abc', '12x', array( 7 ), null ) );
wpcpm_learn_client_check( 'fetch drops invalid course IDs and then sends no courses', $wpcpm_http_mock['last_url'], 'https://learn.test/wp-json/learn-progress/v1/me' );
$wpcpm_http_mock['next_return'] = new WP_Error( 'http_request_failed' );
WPCPM_Learn_Client::fetch( $wpcpm_valid_token_43, array() );
wpcpm_learn_client_check( 'fetch with no courses reads the whole list', $wpcpm_http_mock['last_url'], 'https://learn.test/wp-json/learn-progress/v1/me' );

if ( 0 !== $wpcpm_learn_client_failures ) {
	echo "\n{$wpcpm_learn_client_failures} FAILED ({$wpcpm_learn_client_checks} checks)\n";
	exit( 1 );
}

echo "\nALL PASS ({$wpcpm_learn_client_checks} checks)\n";
