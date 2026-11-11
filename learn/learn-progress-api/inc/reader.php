<?php
/**
 * Reader functions for Learn Progress API.
 *
 * Reads course progress, lesson statuses, and quiz results from Sensei LMS,
 * transforming data into the normalized learn-progress/1 contract shape.
 *
 * All code adheres to WordPress Coding Standards and is strictly read-only.
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Reader;

use WPOrg_Learn\Learn_Progress\Dates;

defined( 'ABSPATH' ) || defined( 'WPINC' ) || die();

/**
 * Compute progress percentage according to Contract §6.5.
 *
 * Uses integer arithmetic with standard half-up rounding:
 * intdiv( 200 * $done + $total, 2 * $total ).
 *
 * @param int $done  Number of completed lessons.
 * @param int $total Total number of published lessons.
 * @return int Progress percentage (0 to 100).
 */
function progress_percent( $done, $total ) {
	$done  = (int) $done;
	$total = (int) $total;

	if ( $total <= 0 ) {
		return 0;
	}

	return intdiv( ( 200 * $done ) + $total, 2 * $total );
}

/**
 * Helper to construct a normalized lesson row array.
 *
 * @param array       $facts        Lesson facts extracted from Sensei.
 * @param string      $status       Normalized lesson status.
 * @param string|null $completed_at RFC 3339 UTC completion timestamp or null.
 * @param array|null  $quiz         Normalized quiz object or null.
 * @return array Normalized lesson entry matching contract.
 */
function row( array $facts, $status, $completed_at, $quiz ) {
	return array(
		'lesson_id'    => (int) $facts['lesson_id'],
		'status'       => (string) $status,
		'completed_at' => $completed_at,
		'quiz'         => $quiz,
	);
}

/**
 * Whether the quiz grade reaches the pass mark, by Sensei's own rule (raw grade >= pass mark).
 *
 * Used where Sensei's stored verdict no longer matches the current setting (Contract §6.1).
 *
 * @param array $facts Lesson facts with 'grade' (raw float) and 'passmark' (integer).
 * @return bool True when the grade meets the pass mark.
 */
function meets_passmark( array $facts ) {
	return (float) $facts['grade'] >= (int) $facts['passmark'];
}

/**
 * Map raw Sensei lesson and quiz facts into contract data structures.
 *
 * Pure function: operates strictly on the facts array and does not touch the database.
 * Handles state transitions for lessons and quizzes, ensuring closed vocabularies
 * and contract rules (Contract §1.2, §6.1, §6.3).
 *
 * @param array $facts Lesson facts containing raw status, timestamps, quiz info, and grades.
 * @return array|\WP_Error Array matching contract lesson schema or WP_Error on unrecognized status.
 */
function map_lesson( array $facts ) {
	$at = Dates\from_gmt( $facts['changed_gmt'] );

	$quiz = function ( $status, $graded ) use ( $facts, $at ) {
		if ( empty( $facts['quiz_id'] ) ) {
			return null;
		}

		$is_graded = in_array( $status, array( 'graded', 'passed', 'failed' ), true );

		return array(
			'quiz_id'          => (int) $facts['quiz_id'],
			'status'           => (string) $status,
			'grade_percent'    => $is_graded ? max( 0, min( 100, (int) round( (float) $facts['grade'] ) ) ) : null,
			// Exactly when a pass is required (passmark is null otherwise).
			'passmark_percent' => ! empty( $facts['pass_required'] ) ? (int) $facts['passmark'] : null,
			'graded_at'        => $is_graded ? $at : null,
		);
	};

	switch ( $facts['raw'] ) {
		case '':
			return row( $facts, 'not-started', null, $quiz( 'not-taken', false ) );

		case 'in-progress':
			return row( $facts, 'in-progress', null, $quiz( 'not-taken', false ) );

		case 'ungraded':
			return row( $facts, 'ungraded', null, $quiz( 'ungraded', false ) );

		case 'graded':
			// Graded with no pass required, then the setting changed. Sensei has completed the lesson,
			// so it stays complete; only the quiz follows the current setting (Contract §6.1, review point 3).
			if ( ! empty( $facts['pass_required'] ) ) {
				return row( $facts, 'complete', $at, $quiz( meets_passmark( $facts ) ? 'passed' : 'failed', true ) );
			}
			return row( $facts, 'complete', $at, $quiz( 'graded', true ) );

		case 'passed':
			return row( $facts, 'complete', $at, $quiz( ! empty( $facts['pass_required'] ) ? 'passed' : 'graded', true ) );

		case 'failed':
			return ! empty( $facts['pass_required'] )
				? row( $facts, 'in-progress', null, $quiz( 'failed', true ) )
				: row( $facts, 'complete', $at, $quiz( 'graded', true ) );

		case 'complete':
			if ( empty( $facts['quiz_id'] ) ) {
				return row( $facts, 'complete', $at, null );
			}
			// Completed without submitting the quiz: the learner's own Complete Lesson
			// button when no pass is required, or an Administrator (Contract §6.1).
			if ( empty( $facts['submitted'] ) ) {
				return row( $facts, 'complete', $at, $quiz( 'not-taken', false ) );
			}
			// The lesson stays complete; the quiz is passed or failed by its grade (Contract §6.1).
			$quiz_status = empty( $facts['pass_required'] ) ? 'graded' : ( meets_passmark( $facts ) ? 'passed' : 'failed' );
			return row( $facts, 'complete', $at, $quiz( $quiz_status, true ) );

		default:
			return new \WP_Error( 'learn_progress_unknown_status', 'Unknown Sensei lesson status.' );
	}
}

/**
 * Gather facts for a specific lesson and user from Sensei LMS.
 *
 * Uses strictly Sensei public APIs and WordPress comment/post metadata.
 * Does not mutate any progress data or invoke internal writing methods.
 *
 * @param int $lesson_id Post ID of the lesson.
 * @param int $user_id   User ID of the learner.
 * @return array Associative array of facts for map_lesson().
 */
function lesson_facts( $lesson_id, $user_id ) {
	$lesson_id = (int) $lesson_id;
	$user_id   = (int) $user_id;

	// Read user status comment via public Sensei utility (Decision record 006, #2).
	$comment  = \Sensei_Utils::user_lesson_status( $lesson_id, $user_id );
	$has_quiz = \Sensei_Lesson::lesson_quiz_has_questions( $lesson_id );
	$quiz_id  = $has_quiz ? (int) \Sensei()->lesson->lesson_quizzes( $lesson_id ) : 0;
	$required = $has_quiz && 'on' === get_post_meta( $quiz_id, '_pass_required', true );

	$passmark_meta = get_post_meta( $quiz_id, '_quiz_passmark', true );
	$passmark      = $required ? ( '' !== $passmark_meta ? (int) round( (float) $passmark_meta ) : 0 ) : null;

	$grade = 0.0;
	if ( $has_quiz && class_exists( 'Sensei_Quiz' ) ) {
		$grade = (float) \Sensei_Quiz::get_user_quiz_grade( $lesson_id, $user_id );
	}

	// Submitted means Sensei graded it: saving answers alone creates a submission with no final grade.
	$submission = $has_quiz ? \Sensei()->quiz_submission_repository->get( $quiz_id, $user_id ) : null;
	$submitted  = $submission && null !== $submission->get_final_grade();

	return array(
		'lesson_id'     => $lesson_id,
		'raw'           => $comment ? (string) $comment->comment_approved : '',
		'changed_gmt'   => $comment ? (string) $comment->comment_date_gmt : '',
		'quiz_id'       => $quiz_id,
		'pass_required' => $required,
		'passmark'      => $passmark,
		'grade'         => $grade,
		'submitted'     => $submitted,
	);
}

/**
 * Read the full progress state of a learner for a given course.
 *
 * Produces an array strictly adhering to the `courses[]` shape in Contract §1.2:
 * course_id, status, enrolled_at, completed_at, progress_percent, and lessons.
 *
 * Strictly read-only: does not call mutating progress methods.
 *
 * @param int $user_id   WordPress user ID of the learner.
 * @param int $course_id Post ID of the course.
 * @return array|\WP_Error Course progress array or WP_Error on failure.
 */
function read_course( $user_id, $course_id ) {
	$user_id   = (int) $user_id;
	$course_id = (int) $course_id;

	$empty_course = array(
		'course_id'        => $course_id,
		'status'           => 'not-started',
		'enrolled_at'      => null,
		'completed_at'     => null,
		'progress_percent' => 0,
		'lessons'          => array(),
	);

	// Contract §1.3: If not enrolled, return course with status not-started and empty lessons.
	// Check enrolment strictly read-only without invoking mutating Sensei methods.
	$has_progress = (bool) \Sensei_Utils::has_started_course( $course_id, $user_id );
	$has_term     = false;

	if ( ! $has_progress && class_exists( 'Sensei_Learner' ) && class_exists( 'Sensei_PostTypes' ) ) {
		$term = get_term_by(
			'slug',
			\Sensei_Learner::get_learner_term_slug( $user_id ),
			\Sensei_PostTypes::LEARNER_TAXONOMY_NAME
		);
		if ( $term instanceof \WP_Term ) {
			$has_term = ( true === is_object_in_term(
				$course_id,
				\Sensei_PostTypes::LEARNER_TAXONOMY_NAME,
				$term->term_id
			) );
		}
	}

	if ( ! $has_progress && ! $has_term ) {
		return $empty_course;
	}

	$lessons = array();
	$done    = 0;
	$started = false;

	$published_lesson_ids = \Sensei()->course->course_lessons( $course_id, 'publish', 'ids' );
	if ( is_array( $published_lesson_ids ) ) {
		foreach ( $published_lesson_ids as $lesson_id ) {
			$facts  = lesson_facts( $lesson_id, $user_id );
			$lesson = map_lesson( $facts );

			if ( is_wp_error( $lesson ) ) {
				return $lesson;
			}

			if ( 'complete' === $lesson['status'] ) {
				$done++;
			}

			if ( 'not-started' !== $lesson['status'] ) {
				$started = true;
			}

			$lessons[] = $lesson;
		}
	}

	$complete = \Sensei_Utils::user_completed_course( $course_id, $user_id );

	// Contract §6.2: Status is calculated: complete, in-progress, or not-started.
	$course_status = $complete ? 'complete' : ( $started ? 'in-progress' : 'not-started' );

	// Storage-agnostic progress dates lookup (works for both HPPS tables and legacy comments).
	$enrolled_at  = null;
	$completed_at = null;

	$progress_repo = ( function_exists( 'Sensei' ) && isset( \Sensei()->course_progress_repository ) )
		? \Sensei()->course_progress_repository
		: null;

	$progress_model = $progress_repo ? $progress_repo->get( $course_id, $user_id ) : null;

	if ( $progress_model ) {
		$started_at = $progress_model->get_started_at();
		if ( $started_at instanceof \DateTimeInterface ) {
			$utc_started = ( clone $started_at )->setTimezone( new \DateTimeZone( 'UTC' ) );
			$enrolled_at = $utc_started->format( 'Y-m-d\TH:i:s\Z' );
		}

		if ( $complete ) {
			$ended_at = $progress_model->get_completed_at();
			if ( $ended_at instanceof \DateTimeInterface ) {
				$utc_ended    = ( clone $ended_at )->setTimezone( new \DateTimeZone( 'UTC' ) );
				$completed_at = $utc_ended->format( 'Y-m-d\TH:i:s\Z' );
			}
		}
	}

	return array(
		'course_id'        => $course_id,
		'status'           => $course_status,
		'enrolled_at'      => $enrolled_at,
		'completed_at'     => $completed_at,
		'progress_percent' => progress_percent( $done, count( $lessons ) ),
		'lessons'          => $lessons,
	);
}
