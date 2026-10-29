<?php
/**
 * Pure unit tests for Reader — no WordPress or Sensei required.
 *
 * Covers U-L01 to U-L14 as specified in Fase_1.md section 2.3 and problema-05 section L.
 *
 * Run with the Studio PHP binary (no WP bootstrap needed):
 *   & "C:\Users\Rafae\AppData\Local\studio_app\app-1.24.0\resources\php-bin\8.4.26-studio-1\php.exe"
 *     "C:\Users\Rafae\Documents\GitHub\Practica_CUC\learn-link\learn\learn-progress-api\tests\test-reader-pure.php"
 *
 * Expected output: ALL PASS (14 checks)
 *
 * Architecture: all code uses bracketed-namespace form so multiple namespace
 * blocks can coexist in one file (required to stub Dates and Reader namespaces).
 */

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
			public function __construct( $code = '', $message = '' ) {
				$this->code    = $code;
				$this->message = $message;
			}
		}
	}

	// is_wp_error() stub.
	if ( ! function_exists( 'is_wp_error' ) ) {
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
	function from_gmt( $mysql ) {
		return $mysql ?: null;
	}
	function from_local( $mysql, $zone = null ) {
		return $mysql ?: null;
	}
}

// ---------------------------------------------------------------------------
// Reader namespace: load production code and run tests.
// ---------------------------------------------------------------------------
namespace WPOrg_Learn\Learn_Progress\Reader {

	require_once dirname( __DIR__ ) . '/inc/reader.php';

	// -------------------------------------------------------------------------
	// Helper: build a minimal facts array for map_lesson() tests.
	// -------------------------------------------------------------------------
	function make_facts( $raw, $pass_required = false, $passmark = null, $grade = 0.0, $quiz_id = 99, $changed_gmt = '2026-09-08 18:44:10' ) {
		return array(
			'lesson_id'     => 1,
			'raw'           => $raw,
			'changed_gmt'   => $changed_gmt,
			'quiz_id'       => $quiz_id,
			'pass_required' => $pass_required,
			'passmark'      => $passmark,
			'grade'         => $grade,
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
	ck( 'U-L05: raw="" -> quiz not-taken',     $r['quiz']['status'], 'not-taken' );

	// U-L06: raw=in-progress -> in-progress, quiz not-taken.
	$r = map_lesson( make_facts( 'in-progress' ) );
	ck( 'U-L06: in-progress -> lesson in-progress', $r['status'], 'in-progress' );
	ck( 'U-L06: in-progress -> quiz not-taken',     $r['quiz']['status'], 'not-taken' );

	// U-L07: raw=ungraded -> lesson ungraded, quiz ungraded, grade_percent null.
	$r = map_lesson( make_facts( 'ungraded', false, null, 0.0 ) );
	ck( 'U-L07: ungraded -> lesson ungraded',    $r['status'], 'ungraded' );
	ck( 'U-L07: ungraded -> quiz ungraded',      $r['quiz']['status'], 'ungraded' );
	ck( 'U-L07: ungraded -> grade_percent null', $r['quiz']['grade_percent'], null );

	// U-L08: raw=failed, pass_required=true -> lesson in-progress, quiz failed, passmark set.
	$r = map_lesson( make_facts( 'failed', true, 70, 50.0 ) );
	ck( 'U-L08: failed+req -> lesson in-progress',  $r['status'], 'in-progress' );
	ck( 'U-L08: failed+req -> quiz failed',         $r['quiz']['status'], 'failed' );
	ck( 'U-L08: failed+req -> passmark_percent 70', $r['quiz']['passmark_percent'], 70 );
	ck( 'U-L08: failed+req -> completed_at null',   $r['completed_at'], null );

	// U-L09: raw=failed, pass_required=false -> lesson complete, quiz graded, passmark null.
	$r = map_lesson( make_facts( 'failed', false, null, 60.0 ) );
	ck( 'U-L09: failed+!req -> lesson complete',       $r['status'], 'complete' );
	ck( 'U-L09: failed+!req -> quiz graded',           $r['quiz']['status'], 'graded' );
	ck( 'U-L09: failed+!req -> passmark_percent null', $r['quiz']['passmark_percent'], null );

	// U-L10: raw=passed, pass_required=true -> lesson complete, quiz passed.
	$r = map_lesson( make_facts( 'passed', true, 70, 85.0 ) );
	ck( 'U-L10: passed+req -> lesson complete', $r['status'], 'complete' );
	ck( 'U-L10: passed+req -> quiz passed',     $r['quiz']['status'], 'passed' );
	ck( 'U-L10: passed+req -> passmark 70',     $r['quiz']['passmark_percent'], 70 );

	// U-L11: raw=passed, pass_required=false -> lesson complete, quiz graded (contract open review pt 1).
	$r = map_lesson( make_facts( 'passed', false, null, 90.0 ) );
	ck( 'U-L11: passed+!req -> lesson complete', $r['status'], 'complete' );
	ck( 'U-L11: passed+!req -> quiz graded',     $r['quiz']['status'], 'graded' );

	// U-L12: raw=graded -> lesson complete, quiz graded.
	$r = map_lesson( make_facts( 'graded', false, null, 75.0 ) );
	ck( 'U-L12: graded -> lesson complete', $r['status'], 'complete' );
	ck( 'U-L12: graded -> quiz graded',     $r['quiz']['status'], 'graded' );

	// U-L13: raw=complete without quiz -> lesson complete, quiz null.
	$r = map_lesson( make_facts( 'complete', false, null, 0.0, 0 ) );
	ck( 'U-L13: complete+no-quiz -> lesson complete', $r['status'], 'complete' );
	ck( 'U-L13: complete+no-quiz -> quiz null',        $r['quiz'], null );

	// U-L14: unknown raw status -> WP_Error with code learn_progress_unknown_status.
	$r = map_lesson( make_facts( 'xyz_unknown' ) );
	ck( 'U-L14: unknown raw -> is WP_Error',        is_wp_error( $r ), true );
	ck( 'U-L14: unknown raw -> correct error code', $r->code, 'learn_progress_unknown_status' );

	// -------------------------------------------------------------------------
	// Final report.
	// -------------------------------------------------------------------------
	test_summary();
}
