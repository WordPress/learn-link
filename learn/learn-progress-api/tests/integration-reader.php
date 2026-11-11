<?php
/**
 * Integration tests for reader.php against real WordPress + Sensei LMS data.
 *
 * Runs via WP-CLI:
 *   studio wp eval-file wp-content/plugins/learn-progress-api/tests/integration-reader.php --path="C:\Studio\learn-link-learn"
 *
 * Tests the 20 seeded learners (learnlink-learner-01 to 20), regression C-16,
 * stale enrolment cache behavior, and database read-only immutability.
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

// Hook guards to detect unwanted write actions during reads.
add_action(
	'sensei_user_course_start',
	function () {
		ck( 'sensei_user_course_start did not fire', false, true );
	}
);

add_filter(
	'wp_mail',
	function ( $args ) {
		ck( 'wp_mail did not fire', false, true );
		return $args;
	}
);

// Ensure learners 01-03 (never enrolled anywhere) have no residual learner term before recording baselines.
if ( class_exists( 'Sensei_Learner' ) && class_exists( 'Sensei_PostTypes' ) ) {
	for ( $n = 1; $n <= 3; $n++ ) {
		$un_user = get_user_by( 'login', sprintf( 'learnlink-learner-%02d', $n ) );
		if ( $un_user ) {
			$un_term = get_term_by(
				'slug',
				Sensei_Learner::get_learner_term_slug( (int) $un_user->ID ),
				Sensei_PostTypes::LEARNER_TAXONOMY_NAME
			);
			if ( $un_term instanceof WP_Term ) {
				wp_delete_term( (int) $un_term->term_id, Sensei_PostTypes::LEARNER_TAXONOMY_NAME );
			}
		}
	}
}

// Record baseline row counts before running tests to verify strict read-only behavior.
$comments_before           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );
$commentmeta_before        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta}" );
$usermeta_before           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" );
$terms_before              = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms}" );
$term_taxonomy_before      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy}" );
$term_relationships_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships}" );

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

	// Group assertions per specification:
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

// Immutability check across 20 learners (database must be completely untouched).
$comments_after           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );
$commentmeta_after        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->commentmeta}" );
$usermeta_after           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" );
$terms_after              = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms}" );
$term_taxonomy_after      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy}" );
$term_relationships_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships}" );

ck( 'Immutability: wp_comments rows unchanged', $comments_after, $comments_before );
ck( 'Immutability: wp_commentmeta rows unchanged', $commentmeta_after, $commentmeta_before );
ck( 'Immutability: wp_usermeta rows unchanged', $usermeta_after, $usermeta_before );
ck( 'Immutability: wp_terms rows unchanged', $terms_after, $terms_before );
ck( 'Immutability: wp_term_taxonomy rows unchanged', $term_taxonomy_after, $term_taxonomy_before );
ck( 'Immutability: wp_term_relationships rows unchanged', $term_relationships_after, $term_relationships_before );

// Stale enrolment cache & never-enrolled learner test: ensure reading does not trigger save_enrolment or create a learner term.
$learner_01 = get_user_by( 'login', 'learnlink-learner-01' );
if ( $learner_01 && class_exists( 'Sensei_Course_Enrolment' ) ) {
	$course_enrolment = Sensei_Course_Enrolment::get_course_instance( $main_id );
	if ( method_exists( $course_enrolment, 'invalidate_learner_result' ) ) {
		$course_enrolment->invalidate_learner_result( (int) $learner_01->ID );
	}
	$comments_stale_before      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );
	$terms_stale_before         = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms}" );
	$term_taxonomy_stale_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy}" );
	$stale_res                  = Reader\read_course( (int) $learner_01->ID, $main_id );
	$comments_stale_after       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" );
	$terms_stale_after          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms}" );
	$term_taxonomy_stale_after  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy}" );
	$learner_01_term            = get_term_by(
		'slug',
		Sensei_Learner::get_learner_term_slug( (int) $learner_01->ID ),
		Sensei_PostTypes::LEARNER_TAXONOMY_NAME
	);

	ck( 'Stale enrolment read: status remains not-started', $stale_res['status'], 'not-started' );
	ck( 'Stale enrolment read: no course progress comment created', $comments_stale_after, $comments_stale_before );
	ck( 'Never-enrolled learner read: wp_terms rows unchanged', $terms_stale_after, $terms_stale_before );
	ck( 'Never-enrolled learner read: wp_term_taxonomy rows unchanged', $term_taxonomy_stale_after, $term_taxonomy_stale_before );
	ck( 'Never-enrolled learner read: no learner term created', $learner_01_term, false );
}

// Regression C-16: A completed course with 8 lessons that receives a 9th lesson computes 89% and remains complete.
$learner_17 = get_user_by( 'login', 'learnlink-learner-17' );
if ( $learner_17 ) {
	$temp_lesson_id = wp_insert_post(
		array(
			'post_title'  => 'C-16 Regression Test Lesson',
			'post_type'   => 'lesson',
			'post_status' => 'publish',
		)
	);
	if ( $temp_lesson_id && ! is_wp_error( $temp_lesson_id ) ) {
		update_post_meta( $temp_lesson_id, '_lesson_course', $main_id );

		$res_c16 = Reader\read_course( (int) $learner_17->ID, $main_id );

		ck( 'C-16: course with added 9th lesson has 89 percent', $res_c16['progress_percent'], 89 );
		ck( 'C-16: completed status preserved despite new lesson', $res_c16['status'], 'complete' );

		// Clean up the temporary test lesson.
		wp_delete_post( $temp_lesson_id, true );
	}
}

// Output JSON for PR evidence BEFORE test_summary() because test_summary() exits.
echo "\n--- JSON OUTPUT OF 20 LEARNERS (FOR PULL REQUEST) ---\n";
echo wp_json_encode( $all_results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
echo "--- END JSON OUTPUT ---\n\n";

test_summary();
