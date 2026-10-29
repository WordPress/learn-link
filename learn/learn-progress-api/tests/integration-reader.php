<?php
/**
 * Integration tests for reader.php against real WordPress + Sensei LMS data.
 *
 * Runs via WP-CLI:
 *   studio wp eval-file wp-content/plugins/learn-progress-api/tests/integration-reader.php --path="C:\Studio\learn-link-learn"
 *
 * Tests the 20 seeded learners (learnlink-learner-01 to 20), regression C-16,
 * and database read-only immutability (wp_comments and wp_usermeta row counts).
 * Outputs full formatted JSON for inclusion in Pull Request evidence.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/inc/dates.php';
require_once dirname( __DIR__ ) . '/inc/reader.php';
require_once __DIR__ . '/lib.php';

use WPOrg_Learn\Learn_Progress\Reader;

echo "====================================================================\n";
echo "LEARN LINK - INTEGRATION SUITE: 20 LEARNERS & IMMUTABILITY\n";
echo "====================================================================\n\n";

global $wpdb;

// Record row counts before running tests to verify strict read-only behavior.
$comments_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );
$usermeta_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" );

// Retrieve the two seed test courses.
$course_main = get_page_by_path( 'learn-link-test-course', OBJECT, 'course' );
$course_fnd  = get_page_by_path( 'learn-link-test-foundations', OBJECT, 'course' );

if ( ! $course_main || ! $course_fnd ) {
	echo "[ERROR] Seed courses not found. Ensure seed-learners.php has been executed.\n";
	exit( 1 );
}

$main_id = (int) $course_main->ID;
$fnd_id  = (int) $course_fnd->ID;

$all_results = array();

for ( $n = 1; $n <= 20; $n++ ) {
	$login = sprintf( 'learnlink-learner-%02d', $n );
	$user  = get_user_by( 'login', $login );

	if ( ! $user ) {
		echo "[FAIL] User {$login} not found in database.\n";
		continue;
	}

	$u_id = (int) $user->ID;

	$res_main = Reader\read_course( $u_id, $main_id );
	$res_fnd  = Reader\read_course( $u_id, $fnd_id );

	$all_results[ $login ] = array(
		'learner' => $login,
		'courses' => array(
			$res_main,
			$res_fnd,
		),
	);

	// Group assertions per specification (problema-05 section L):
	if ( $n >= 1 && $n <= 3 ) {
		// Group 01-03: Not enrolled in either course.
		ck( "{$login} [main] not-started", $res_main['status'], 'not-started' );
		ck( "{$login} [main] enrolled_at null", $res_main['enrolled_at'], null );
		ck( "{$login} [main] lessons empty", count( $res_main['lessons'] ), 0 );

		ck( "{$login} [fnd] not-started", $res_fnd['status'], 'not-started' );
		ck( "{$login} [fnd] enrolled_at null", $res_fnd['enrolled_at'], null );
	} elseif ( $n >= 4 && $n <= 6 ) {
		// Group 04-06: Enrolled, no activity.
		ck( "{$login} [main] not-started", $res_main['status'], 'not-started' );
		ck( "{$login} [main] enrolled_at set", ! empty( $res_main['enrolled_at'] ), true );
		ck( "{$login} [main] 0 percent", $res_main['progress_percent'], 0 );
		ck( "{$login} [main] 8 lessons", count( $res_main['lessons'] ), 8 );

		ck( "{$login} [fnd] not enrolled", $res_fnd['status'], 'not-started' );
		ck( "{$login} [fnd] enrolled_at null", $res_fnd['enrolled_at'], null );
	} elseif ( $n >= 7 && $n <= 10 ) {
		// Group 07-10: In progress.
		ck( "{$login} [main] in-progress", $res_main['status'], 'in-progress' );
		ck( "{$login} [main] 50 percent", $res_main['progress_percent'], 50 );

		ck( "{$login} [fnd] in-progress", $res_fnd['status'], 'in-progress' );
		ck( "{$login} [fnd] 33 percent", $res_fnd['progress_percent'], 33 );
	} elseif ( $n >= 11 && $n <= 13 ) {
		// Group 11-13: In progress with failed quiz on lesson 2.
		ck( "{$login} [main] in-progress", $res_main['status'], 'in-progress' );
		ck( "{$login} [main] 13 percent", $res_main['progress_percent'], 13 );
		ck( "{$login} [main] lesson 2 in-progress", $res_main['lessons'][1]['status'], 'in-progress' );
		ck( "{$login} [main] lesson 2 quiz failed", $res_main['lessons'][1]['quiz']['status'], 'failed' );
	} elseif ( $n >= 14 && $n <= 16 ) {
		// Group 14-16: Ungraded quiz on lesson 5.
		ck( "{$login} [main] in-progress", $res_main['status'], 'in-progress' );
		ck( "{$login} [main] 50 percent", $res_main['progress_percent'], 50 );
		ck( "{$login} [main] lesson 5 ungraded", $res_main['lessons'][4]['status'], 'ungraded' );
	} elseif ( $n >= 17 && $n <= 20 ) {
		// Group 17-20: Completed 100%.
		ck( "{$login} [main] complete", $res_main['status'], 'complete' );
		ck( "{$login} [main] 100 percent", $res_main['progress_percent'], 100 );
		ck( "{$login} [main] completed_at set", ! empty( $res_main['completed_at'] ), true );

		ck( "{$login} [fnd] complete", $res_fnd['status'], 'complete' );
		ck( "{$login} [fnd] 100 percent", $res_fnd['progress_percent'], 100 );
	}
}

// Immutability check (row counts must not change).
$comments_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );
$usermeta_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" );

ck( 'Immutability: wp_comments rows unchanged', $comments_after, $comments_before );
ck( 'Immutability: wp_usermeta rows unchanged', $usermeta_after, $usermeta_before );

test_summary();

echo "\n--- JSON OUTPUT OF 20 LEARNERS (FOR PULL REQUEST) ---\n";
echo wp_json_encode( $all_results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
echo "--- END JSON OUTPUT ---\n";
