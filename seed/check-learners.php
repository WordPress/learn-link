<?php
/**
 * Learn Link: print what Sensei says about each invented learner, read-only.
 *
 * Run after seed-learners.php:
 *   wp eval-file check-learners.php
 *
 * It uses only Sensei's public functions, never its @internal repositories, and writes
 * nothing. Read it as a first sketch of the read function: one learner, one course.
 */

$courses = array( 'learn-link-test-course', 'learn-link-test-foundations' );

for ( $n = 1; $n <= 20; $n++ ) {
	$user = get_user_by( 'login', sprintf( 'learnlink-learner-%02d', $n ) );
	if ( ! $user ) {
		continue;
	}
	$line = $user->user_login;
	foreach ( $courses as $slug ) {
		$course = get_page_by_path( $slug, OBJECT, 'course' );
		if ( ! $course ) {
			continue;
		}
		if ( ! Sensei_Course::is_user_enrolled( $course->ID, $user->ID ) ) {
			$line .= " | $slug: not enrolled";
			continue;
		}
		$status  = Sensei_Utils::user_completed_course( $course->ID, $user->ID ) ? 'complete' : 'in-progress';
		$percent = (int) round( Sensei()->course->get_completion_percentage( $course->ID, $user->ID ) );
		$lessons = array();
		foreach ( Sensei()->course->course_lessons( $course->ID, 'publish', 'ids' ) as $lesson_id ) {
			$comment = Sensei_Utils::user_lesson_status( $lesson_id, $user->ID );
			$state   = $comment ? $comment->comment_approved : 'not-started';
			if ( Sensei_Lesson::lesson_quiz_has_questions( $lesson_id ) && $comment && in_array( $state, array( 'passed', 'failed', 'graded' ), true ) ) {
				$state .= ' ' . (int) Sensei_Quiz::get_user_quiz_grade( $lesson_id, $user->ID ) . '%';
			}
			$lessons[] = $state;
		}
		$line .= " | $slug: $status $percent% [" . implode( ', ', $lessons ) . ']';
	}
	WP_CLI::log( $line );
}
