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
			// Exactly when a pass is required (passmark is null otherwise); `graded` always has null.
			'passmark_percent' => 'graded' !== $status && null !== $facts['passmark'] ? (int) $facts['passmark'] : null,
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
			return row( $facts, 'complete', $at, $quiz( 'graded', true ) );

		case 'passed':
			return row( $facts, 'complete', $at, $quiz( ! empty( $facts['pass_required'] ) ? 'passed' : 'graded', true ) );

		case 'failed':
			return ! empty( $facts['pass_required'] )
				? row( $facts, 'in-progress', null, $quiz( 'failed', true ) )
				: row( $facts, 'complete', $at, $quiz( 'graded', true ) );

		case 'complete':
			return row( $facts, 'complete', $at, ! empty( $facts['quiz_id'] ) ? $quiz( 'graded', true ) : null );

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

	// D-09: Read user status comment via public Sensei utility.
	$comment  = \Sensei_Utils::user_lesson_status( $lesson_id, $user_id );
	$has_quiz = \Sensei_Lesson::lesson_quiz_has_questions( $lesson_id );
	$quiz_id  = $has_quiz ? (int) \Sensei()->lesson->lesson_quizzes( $lesson_id ) : 0;
	$required = $has_quiz && 'on' === get_post_meta( $quiz_id, '_pass_required', true );

	$passmark_meta = get_post_meta( $quiz_id, '_quiz_passmark', true );
	$passmark      = ( $required && '' !== $passmark_meta ) ? (int) round( (float) $passmark_meta ) : null;

	$grade = 0.0;
	if ( $has_quiz && class_exists( 'Sensei_Quiz' ) ) {
		$grade = (float) \Sensei_Quiz::get_user_quiz_grade( $lesson_id, $user_id );
	}

	return array(
		'lesson_id'     => $lesson_id,
		'raw'           => $comment ? (string) $comment->comment_approved : '',
		'changed_gmt'   => $comment ? (string) $comment->comment_date_gmt : '',
		'quiz_id'       => $quiz_id,
		'pass_required' => $required,
		'passmark'      => $passmark,
		'grade'         => $grade,
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
	if ( ! \Sensei_Course::is_user_enrolled( $course_id, $user_id ) ) {
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
	$progress = \Sensei_Utils::has_started_course( $course_id, $user_id );
	$record   = $progress ? get_comment( $progress ) : null;

	// Contract §6.2: Status is calculated: complete, in-progress, or not-started.
	$course_status = $complete ? 'complete' : ( $started ? 'in-progress' : 'not-started' );

	$enrolled_at  = null;
	$completed_at = null;

	if ( $progress ) {
		$start_meta  = get_comment_meta( $progress, 'start', true );
		$enrolled_at = Dates\from_local( $start_meta );
	}

	if ( $complete && $record ) {
		$completed_at = Dates\from_gmt( $record->comment_date_gmt );
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
