<?php
/**
 * Pure unit tests for Reader — no WordPress or Sensei required.
 *
 * Covers U-L01 to U-L14 as specified in Issue #4.
 *
 * Run with:
 *   php tests/test-reader-pure.php
 *
 * Expected output: ALL PASS (43 checks)
 *
 * Architecture: all code uses bracketed-namespace form so multiple namespace
 * blocks can coexist in one file (required to stub Dates and Reader namespaces).
 */

// phpcs:disable Universal.Namespaces.DisallowCurlyBraceSyntax, Universal.Namespaces.DisallowDeclarationWithoutName, Universal.Namespaces.OneDeclarationPerFile, Universal.Files.SeparateFunctionsFromOO -- This pure test needs several bracketed namespaces, one of them global, to stub WordPress, Dates and Reader in one process.

// ---------------------------------------------------------------------------
// Global namespace block: stubs, bootstrap, and require.
// ---------------------------------------------------------------------------
namespace {

	// Shared test helpers (ck, test_summary).
	require_once __DIR__ . '/lib.php';

	// Minimal WP_Error stub — only code and message are needed by map_lesson().
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public $code;
			public $message;

			/**
			 * Keep the error code and message, as WordPress's WP_Error does.
			 *
			 * @param string $code    Error code.
			 * @param string $message Error message.
			 */
			public function __construct( $code = '', $message = '' ) {
				$this->code    = $code;
				$this->message = $message;
			}
		}
	}

	// is_wp_error() stub.
	if ( ! function_exists( 'is_wp_error' ) ) {
		/**
		 * Test double for WordPress's is_wp_error().
		 *
		 * @param mixed $thing Any value.
		 * @return bool Whether the value is a WP_Error.
		 */
		function is_wp_error( $thing ) {
			return $thing instanceof WP_Error;
		}
	}

	// Satisfy the die() guard in reader.php without a WP bootstrap.
	if ( ! defined( 'WPINC' ) ) {
		define( 'WPINC', true );
	}
}

// ---------------------------------------------------------------------------
// Dates namespace stub: identity functions so pure tests do not need real WP.
// ---------------------------------------------------------------------------
namespace WPOrg_Learn\Learn_Progress\Dates {
	/**
	 * Test double for Dates\from_gmt(): the value as given, or null when empty.
	 *
	 * @param string|null $mysql A MySQL datetime in GMT.
	 * @return string|null
	 */
	function from_gmt( $mysql ) {
		return $mysql ?: null;
	}

	/**
	 * Test double for Dates\from_local(): the value as given, or null when empty.
	 *
	 * @param string|null        $mysql A MySQL datetime in the site's time zone.
	 * @param \DateTimeZone|null $zone  Unused by the double.
	 * @return string|null
	 */
	function from_local( $mysql, $zone = null ) {
		return $mysql ?: null;
	}
}

// ---------------------------------------------------------------------------
// Reader namespace: load production code and run tests.
// ---------------------------------------------------------------------------
namespace WPOrg_Learn\Learn_Progress\Reader {

	require_once dirname( __DIR__ ) . '/inc/reader.php';

	/**
	 * Helper: build a minimal facts array for map_lesson() tests.
	 *
	 * @param string   $raw           Sensei's raw lesson status.
	 * @param bool     $pass_required Whether the quiz must be passed.
	 * @param int|null $passmark      The quiz passmark, or null.
	 * @param float    $grade         The quiz grade.
	 * @param int      $quiz_id       The quiz ID.
	 * @param string   $changed_gmt   When the status last changed, in GMT.
	 * @param bool     $submitted     Whether the quiz was submitted.
	 * @return array
	 */
	function make_facts( $raw, $pass_required = false, $passmark = null, $grade = 0.0, $quiz_id = 99, $changed_gmt = '2026-09-08 18:44:10', $submitted = true ) {
		return array(
			'lesson_id'     => 1,
			'raw'           => $raw,
			'changed_gmt'   => $changed_gmt,
			'quiz_id'       => $quiz_id,
			'pass_required' => $pass_required,
			'passmark'      => $passmark,
			'grade'         => $grade,
			'submitted'     => $submitted,
		);
	}

	// =========================================================================
	// U-L01 to U-L04: progress_percent() — integer arithmetic rounding
	// =========================================================================

	// U-L01: 1/8 = 13 (not 12; halves round up per contract section 6.5).
	ck( 'U-L01: progress_percent(1,8) = 13', progress_percent( 1, 8 ), 13 );

	// U-L02: 23/40 = 58 (= 57.5, halves up).
	ck( 'U-L02: progress_percent(23,40) = 58', progress_percent( 23, 40 ), 58 );

	// U-L03: 0/0 = 0 (zero-total guard).
	ck( 'U-L03: progress_percent(0,0) = 0', progress_percent( 0, 0 ), 0 );

	// U-L04: 8/8 = 100.
	ck( 'U-L04: progress_percent(8,8) = 100', progress_percent( 8, 8 ), 100 );

	// =========================================================================
	// U-L05 to U-L14: map_lesson() state machine
	// =========================================================================

	// U-L05: raw=' (no record) -> not-started, quiz not-taken.
	$r = map_lesson( make_facts( '' ) );
	ck( 'U-L05: raw="" -> lesson not-started', $r['status'], 'not-started' );
	ck( 'U-L05: raw="" -> quiz not-taken', $r['quiz']['status'], 'not-taken' );

	// U-L06: raw=in-progress -> in-progress, quiz not-taken.
	$r = map_lesson( make_facts( 'in-progress' ) );
	ck( 'U-L06: in-progress -> lesson in-progress', $r['status'], 'in-progress' );
	ck( 'U-L06: in-progress -> quiz not-taken', $r['quiz']['status'], 'not-taken' );

	// U-L07: raw=ungraded -> lesson ungraded, quiz ungraded, grade_percent null.
	$r = map_lesson( make_facts( 'ungraded', false, null, 0.0 ) );
	ck( 'U-L07: ungraded -> lesson ungraded', $r['status'], 'ungraded' );
	ck( 'U-L07: ungraded -> quiz ungraded', $r['quiz']['status'], 'ungraded' );
	ck( 'U-L07: ungraded -> grade_percent null', $r['quiz']['grade_percent'], null );

	// U-L08: raw=failed, pass_required=true -> lesson in-progress, quiz failed, passmark set.
	$r = map_lesson( make_facts( 'failed', true, 70, 50.0 ) );
	ck( 'U-L08: failed+req -> lesson in-progress', $r['status'], 'in-progress' );
	ck( 'U-L08: failed+req -> quiz failed', $r['quiz']['status'], 'failed' );
	ck( 'U-L08: failed+req -> passmark_percent 70', $r['quiz']['passmark_percent'], 70 );
	ck( 'U-L08: failed+req -> completed_at null', $r['completed_at'], null );

	// U-L09: raw=failed, pass_required=false -> lesson complete, quiz graded, passmark null.
	$r = map_lesson( make_facts( 'failed', false, null, 60.0 ) );
	ck( 'U-L09: failed+!req -> lesson complete', $r['status'], 'complete' );
	ck( 'U-L09: failed+!req -> quiz graded', $r['quiz']['status'], 'graded' );
	ck( 'U-L09: failed+!req -> passmark_percent null', $r['quiz']['passmark_percent'], null );

	// U-L10: raw=passed, pass_required=true -> lesson complete, quiz passed.
	$r = map_lesson( make_facts( 'passed', true, 70, 85.0 ) );
	ck( 'U-L10: passed+req -> lesson complete', $r['status'], 'complete' );
	ck( 'U-L10: passed+req -> quiz passed', $r['quiz']['status'], 'passed' );
	ck( 'U-L10: passed+req -> passmark 70', $r['quiz']['passmark_percent'], 70 );

	// U-L11: raw=passed, pass_required=false -> lesson complete, quiz graded (contract open review pt 1).
	$r = map_lesson( make_facts( 'passed', false, null, 90.0 ) );
	ck( 'U-L11: passed+!req -> lesson complete', $r['status'], 'complete' );
	ck( 'U-L11: passed+!req -> quiz graded', $r['quiz']['status'], 'graded' );

	// U-L12: raw=graded -> lesson complete, quiz graded.
	$r = map_lesson( make_facts( 'graded', false, null, 75.0 ) );
	ck( 'U-L12: graded -> lesson complete', $r['status'], 'complete' );
	ck( 'U-L12: graded -> quiz graded', $r['quiz']['status'], 'graded' );

	// Graded with no pass required, then a pass became required: the lesson stays complete,
	// and only the quiz follows the current setting (contract 6.1, review point 3).
	$r = map_lesson( make_facts( 'graded', true, 70, 85.0 ) );
	ck(
		'graded+req+above -> complete, passed, passmark 70, completed_at set',
		array( $r['status'], $r['quiz']['status'], $r['quiz']['passmark_percent'], null !== $r['completed_at'] ),
		array( 'complete', 'passed', 70, true )
	);
	$r = map_lesson( make_facts( 'graded', true, 70, 50.0 ) );
	ck(
		'graded+req+below -> complete, failed, passmark 70, completed_at kept',
		array( $r['status'], $r['quiz']['status'], $r['quiz']['passmark_percent'], null !== $r['completed_at'] ),
		array( 'complete', 'failed', 70, true )
	);
	$r = map_lesson( make_facts( 'graded', true, 70, 70.0 ) );
	ck( 'graded+req+equal -> passed (grade >= passmark, as Sensei)', $r['quiz']['status'], 'passed' );
	// The raw grade decides, as in Sensei: 69.6 shows as 70 but does not reach 70.
	$r = map_lesson( make_facts( 'graded', true, 70, 69.6 ) );
	ck( 'graded+req+69.6 vs 70 -> failed, grade_percent 70', array( $r['quiz']['status'], $r['quiz']['grade_percent'] ), array( 'failed', 70 ) );
	$r = map_lesson( make_facts( 'graded', true, 0, 0.0 ) );
	ck( 'graded+req+passmark 0 -> passed', array( $r['quiz']['status'], $r['quiz']['passmark_percent'] ), array( 'passed', 0 ) );

	// U-L13: raw=complete without quiz -> lesson complete, quiz null.
	$r = map_lesson( make_facts( 'complete', false, null, 0.0, 0 ) );
	ck( 'U-L13: complete+no-quiz -> lesson complete', $r['status'], 'complete' );
	ck( 'U-L13: complete+no-quiz -> quiz null', $r['quiz'], null );

	// A required pass mark travels before the quiz is taken or graded too (contract review point 1).
	$r = map_lesson( make_facts( '', true, 70 ) );
	ck( 'not-taken+req -> passmark_percent 70', $r['quiz']['passmark_percent'], 70 );
	$r = map_lesson( make_facts( 'ungraded', true, 70 ) );
	ck( 'ungraded+req -> passmark_percent 70', $r['quiz']['passmark_percent'], 70 );

	// Complete with a submission and a required pass: quiz passed, passmark preserved.
	$r = map_lesson( make_facts( 'complete', true, 70, 80.0 ) );
	ck( 'complete+quiz+req -> passed, passmark 70', array( $r['quiz']['status'], $r['quiz']['passmark_percent'] ), array( 'passed', 70 ) );

	// Complete with a submission below a required pass mark: the lesson stays complete, the quiz is failed (contract 6.1).
	$r = map_lesson( make_facts( 'complete', true, 70, 50.0 ) );
	ck(
		'complete+quiz+req+below -> complete, failed, passmark 70',
		array( $r['status'], $r['quiz']['status'], $r['quiz']['passmark_percent'] ),
		array( 'complete', 'failed', 70 )
	);

	// When complete without a required pass, quiz maps to graded with passmark null.
	$r = map_lesson( make_facts( 'complete', false, null, 80.0 ) );
	ck( 'complete+quiz+!req -> graded, passmark null', array( $r['quiz']['status'], $r['quiz']['passmark_percent'] ), array( 'graded', null ) );

	// Complete with the quiz never submitted (learner's Complete Lesson button, or an Administrator): not-taken.
	$r = map_lesson( make_facts( 'complete', false, null, 0.0, 99, '2026-09-08 18:44:10', false ) );
	ck( 'complete+quiz+no-submission -> lesson complete', $r['status'], 'complete' );
	ck(
		'complete+quiz+no-submission -> not-taken, nulls',
		array( $r['quiz']['status'], $r['quiz']['grade_percent'], $r['quiz']['passmark_percent'], $r['quiz']['graded_at'] ),
		array( 'not-taken', null, null, null )
	);

	// Same, with a required pass (only an Administrator can do this): pass mark still travels.
	$r = map_lesson( make_facts( 'complete', true, 70, 0.0, 99, '2026-09-08 18:44:10', false ) );
	ck( 'complete+quiz+req+no-submission -> not-taken, passmark 70', array( $r['quiz']['status'], $r['quiz']['passmark_percent'] ), array( 'not-taken', 70 ) );

	// If pass is required but passmark is 0, passmark_percent is 0 (never null).
	$r = map_lesson( make_facts( 'passed', true, 0, 100.0 ) );
	ck( 'passed+req+passmark 0 -> passmark_percent 0', $r['quiz']['passmark_percent'], 0 );

	// U-L14: unknown raw status -> WP_Error with code learn_progress_unknown_status.
	$r = map_lesson( make_facts( 'xyz_unknown' ) );
	ck( 'U-L14: unknown raw -> is WP_Error', is_wp_error( $r ), true );
	ck( 'U-L14: unknown raw -> correct error code', $r->code, 'learn_progress_unknown_status' );

	// -------------------------------------------------------------------------
	// Final report.
	// -------------------------------------------------------------------------
	test_summary();
}
