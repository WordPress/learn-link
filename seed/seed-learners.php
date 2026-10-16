<?php
/**
 * Learn Link: seed twenty invented learners on a LOCAL Sensei LMS site.
 *
 * Run after importing courses.csv, lessons.csv and questions.csv with `wp sensei-import`:
 *   wp eval-file seed-learners.php
 *
 * Invented data only. The script refuses to run on learn.wordpress.org, sends no email,
 * and can be run again: existing learners are reused, and progress already recorded is
 * left as it is.
 *
 * States (main course "learn-link-test-course", foundations "learn-link-test-foundations"):
 *   01-03 not enrolled anywhere
 *   04-06 enrolled in the main course, nothing done
 *   07-10 halfway: lessons 1-4 done, both early quizzes passed; one foundations lesson done
 *   11-13 failed: lesson 1 done, the lesson 2 quiz failed
 *   14-16 waiting to be graded: lessons 1-4 done, the lesson 5 quiz submitted, not marked
 *   17-20 complete: every lesson done, every quiz passed; foundations complete too
 */

if ( false !== strpos( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'wordpress.org' ) ) {
	WP_CLI::error( 'Refusing to seed a wordpress.org site. This script is for local test sites only.' );
}

// Lesson ends and quiz submissions notify teachers. Nothing leaves this machine.
add_filter( 'pre_wp_mail', '__return_false' );

// A finished course sends the browser to the "course completed" page, which stops WP-CLI.
$ll_redirect = array( Sensei()->frontend, 'redirect_to_course_completed_page' );
$ll_priority = has_action( 'sensei_course_status_updated', $ll_redirect );
if ( false !== $ll_priority ) {
	remove_action( 'sensei_course_status_updated', $ll_redirect, $ll_priority );
}

/**
 * A course ID by slug, published so its structure is public like Learn's.
 */
function ll_course( $slug ) {
	$course = get_page_by_path( $slug, OBJECT, 'course' );
	if ( ! $course ) {
		WP_CLI::error( "Course '$slug' not found. Import the CSV files first." );
	}
	if ( 'publish' !== $course->post_status ) {
		wp_update_post( array( 'ID' => $course->ID, 'post_status' => 'publish' ) );
	}
	return (int) $course->ID;
}

/**
 * Answers for every question of a lesson's quiz, all right or all wrong.
 */
function ll_answers( $lesson_id, $right ) {
	$quiz_id = Sensei()->lesson->lesson_quizzes( $lesson_id );
	$answers = array();
	foreach ( (array) Sensei()->lesson->lesson_quiz_questions( $quiz_id ) as $question ) {
		$qid  = $question->ID;
		$type = Sensei()->question->get_question_type( $qid );
		$good = get_post_meta( $qid, '_question_right_answer', true );
		if ( 'multiple-choice' === $type ) {
			$bad             = (array) get_post_meta( $qid, '_question_wrong_answers', true );
			$answers[ $qid ] = $right ? (array) $good : array( reset( $bad ) );
		} elseif ( 'boolean' === $type ) {
			$answers[ $qid ] = $right ? $good : ( 'true' === $good ? 'false' : 'true' );
		} else {
			$answers[ $qid ] = $right ? 'https://core.trac.wordpress.org/ticket/00000' : 'I did not do it.';
		}
	}
	return $answers;
}

/**
 * Complete one lesson: a lesson without questions is marked complete, a quiz is submitted.
 */
function ll_do_lesson( $user_id, $lesson_id, $right = true ) {
	$quiz_id = Sensei()->lesson->lesson_quizzes( $lesson_id );
	if ( $quiz_id && Sensei_Lesson::lesson_quiz_has_questions( $lesson_id ) ) {
		Sensei_Quiz::submit_answers_for_grading( ll_answers( $lesson_id, $right ), array(), $lesson_id, $user_id );
	} else {
		Sensei_Utils::user_start_lesson( $user_id, $lesson_id, true );
	}
}

/**
 * Mark a submitted, manually graded quiz the way a teacher does on the Grading screen.
 */
function ll_teacher_grades( $user_id, $lesson_id, $grade ) {
	$quiz_id = Sensei()->lesson->lesson_quizzes( $lesson_id );
	Sensei_Utils::sensei_grade_quiz( $quiz_id, $grade, $user_id );
	$lesson = Sensei()->lesson_progress_repository->get( $lesson_id, $user_id );
	$quiz   = Sensei()->quiz_progress_repository->get( $quiz_id, $user_id );
	if ( ! $lesson || ! $quiz ) {
		return;
	}
	if ( (float) get_post_meta( $quiz_id, '_quiz_passmark', true ) <= $grade ) {
		$lesson->complete();
		$quiz->pass();
	} else {
		$quiz->fail();
	}
	Sensei()->lesson_progress_repository->save( $lesson );
	Sensei()->quiz_progress_repository->save( $quiz );
	if ( $lesson->is_complete() ) {
		do_action( 'sensei_user_lesson_end', $user_id, $lesson_id );
	}
}

$main        = ll_course( 'learn-link-test-course' );
$foundations = ll_course( 'learn-link-test-foundations' );
$lessons     = array_values( array_map( 'intval', Sensei()->course->course_lessons( $main, 'publish', 'ids' ) ) );
$f_lessons   = array_values( array_map( 'intval', Sensei()->course->course_lessons( $foundations, 'publish', 'ids' ) ) );
$enrol       = Sensei_Course_Enrolment_Manager::instance()->get_manual_enrolment_provider();

if ( count( $lessons ) < 8 || count( $f_lessons ) < 3 ) {
	WP_CLI::error( 'The test courses do not have all their lessons. Import the CSV files again.' );
}

for ( $n = 1; $n <= 20; $n++ ) {
	$login = sprintf( 'learnlink-learner-%02d', $n );
	$user  = get_user_by( 'login', $login );
	$id    = $user ? $user->ID : wp_insert_user( array(
		'user_login'   => $login,
		'user_email'   => $login . '@example.test',
		'display_name' => sprintf( 'Test Learner %02d', $n ),
		'user_pass'    => wp_generate_password( 24 ),
		'role'         => 'subscriber',
	) );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	if ( $user && Sensei_Course::is_user_enrolled( $main, $id ) ) {
		WP_CLI::log( "$login exists and is enrolled; left as it is." );
		continue;
	}
	if ( $n <= 3 ) {
		WP_CLI::log( "$login: not enrolled" );
		continue;
	}

	// Sensei's auto-grading saves the grade for the CURRENT user, so act as the learner.
	wp_set_current_user( $id );
	$enrol->enrol_learner( $id, $main );

	if ( $n <= 6 ) {
		$state = 'enrolled, nothing done';
	} elseif ( $n <= 10 ) {
		foreach ( array_slice( $lessons, 0, 4 ) as $lesson ) {
			ll_do_lesson( $id, $lesson );
		}
		$enrol->enrol_learner( $id, $foundations );
		ll_do_lesson( $id, $f_lessons[0] );
		$state = 'halfway';
	} elseif ( $n <= 13 ) {
		ll_do_lesson( $id, $lessons[0] );
		ll_do_lesson( $id, $lessons[1], false );
		$state = 'failed the lesson 2 quiz';
	} elseif ( $n <= 16 ) {
		foreach ( array_slice( $lessons, 0, 5 ) as $lesson ) {
			ll_do_lesson( $id, $lesson );
		}
		$state = 'lesson 5 quiz waiting to be graded';
	} else {
		foreach ( $lessons as $i => $lesson ) {
			ll_do_lesson( $id, $lesson );
			if ( 4 === $i ) {
				ll_teacher_grades( $id, $lesson, 90 );
			}
		}
		$enrol->enrol_learner( $id, $foundations );
		foreach ( $f_lessons as $lesson ) {
			ll_do_lesson( $id, $lesson );
		}
		$state = 'complete';
	}
	wp_set_current_user( 0 );
	WP_CLI::log( "$login: $state" );
}

WP_CLI::success( 'Twenty invented learners are ready.' );
