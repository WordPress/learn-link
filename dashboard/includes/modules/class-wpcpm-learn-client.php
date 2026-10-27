<?php
/**
 * Reads and normalizes a student's progress from Learn WordPress.
 *
 * @package WPCreditsProgramManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Learn Link client, with network access kept separate from parsing.
 */
final class WPCPM_Learn_Client {

	/** The contract version this client understands. */
	const CONTRACT = 'learn-progress/1';

	/** Course statuses allowed by the contract. */
	const COURSE = array( 'not-started', 'in-progress', 'complete' );

	/** Lesson statuses allowed by the contract. */
	const LESSON = array( 'not-started', 'in-progress', 'complete', 'ungraded' );

	/** Quiz statuses allowed by the updated contract. */
	const QUIZ = array( 'not-taken', 'ungraded', 'graded', 'passed', 'failed' );

	/** Error codes that Learn Link defines. */
	const ERRORS = array(
		'learn_progress_invalid_token',
		'learn_progress_revoked',
		'learn_progress_slow_down',
		'learn_progress_unavailable',
	);

	/**
	 * Validate and normalize a progress response from Learn.
	 *
	 * @param array $body The decoded JSON response.
	 * @return array|WP_Error Normalized progress or an error for an unreadable response.
	 */
	public static function parse( array $body ) {
		if ( ! isset( $body['contract'] ) || self::CONTRACT !== $body['contract'] ) {
			return self::bad_answer();
		}

		$learner  = isset( $body['learner'] ) && is_array( $body['learner'] ) ? $body['learner'] : array();
		$username = isset( $learner['wporg_username'] ) && is_string( $learner['wporg_username'] )
			? $learner['wporg_username']
			: '';

		if ( '' === trim( $username ) ) {
			return self::bad_answer();
		}

		$generated_at = self::date( isset( $body['generated_at'] ) ? $body['generated_at'] : null );

		if ( null === $generated_at ) {
			return self::bad_answer();
		}

		if ( ! isset( $body['courses'] ) || ! self::is_list( $body['courses'] ) ) {
			return self::bad_answer();
		}

		$courses = self::courses( $body['courses'] );

		if ( $courses instanceof WP_Error ) {
			return $courses;
		}

		return array(
			'username'     => $username,
			'generated_at' => $generated_at,
			'courses'      => $courses,
		);
	}

	/**
	 * Identify a failed response, preferring the contract's code over HTTP status.
	 *
	 * The HTTP fallback follows the P07 guide for missing or unknown JSON codes.
	 * Learn's message is never returned: the Dashboard supplies its own words.
	 *
	 * @param int   $status HTTP response status.
	 * @param mixed $body   Decoded response, or null when JSON decoding failed.
	 * @return string A known Learn error code or wpcpm_learn_bad_answer.
	 */
	public static function error_code( $status, $body ) {
		if ( is_array( $body ) && isset( $body['code'] ) && in_array( $body['code'], self::ERRORS, true ) ) {
			return $body['code'];
		}

		if ( ! is_int( $status ) ) {
			return 'wpcpm_learn_bad_answer';
		}

		$codes = array(
			401 => 'learn_progress_invalid_token',
			403 => 'learn_progress_revoked',
			429 => 'learn_progress_slow_down',
		);

		if ( isset( $codes[ $status ] ) ) {
			return $codes[ $status ];
		}

		if ( $status >= 500 && $status <= 599 ) {
			return 'learn_progress_unavailable';
		}

		return 'wpcpm_learn_bad_answer';
	}

	/**
	 * Validate and normalize the courses while preserving their response order.
	 *
	 * @param array $items Course objects from the decoded response.
	 * @return array|WP_Error Courses indexed by their Learn post ID.
	 */
	private static function courses( array $items ) {
		$courses = array();

		foreach ( $items as $course ) {
			if ( ! is_array( $course ) || ! isset( $course['course_id'] ) || ! is_int( $course['course_id'] ) || $course['course_id'] <= 0 ) {
				return self::bad_answer();
			}

			$course_id = $course['course_id'];

			if ( isset( $courses[ $course_id ] ) ) {
				return self::bad_answer();
			}

			if ( ! isset( $course['status'] ) || ! is_string( $course['status'] ) || ! in_array( $course['status'], self::COURSE, true ) ) {
				return self::bad_answer();
			}

			if ( ! array_key_exists( 'enrolled_at', $course ) || ! array_key_exists( 'completed_at', $course ) ) {
				return self::bad_answer();
			}

			$enrolled_at  = self::nullable_date( $course['enrolled_at'] );
			$completed_at = self::nullable_date( $course['completed_at'] );

			if ( false === $enrolled_at || false === $completed_at ) {
				return self::bad_answer();
			}

			if ( ! isset( $course['progress_percent'] ) || ! is_int( $course['progress_percent'] ) ) {
				return self::bad_answer();
			}

			if ( ! isset( $course['lessons'] ) || ! self::is_list( $course['lessons'] ) ) {
				return self::bad_answer();
			}

			$lessons = self::lessons( $course['lessons'] );

			if ( $lessons instanceof WP_Error ) {
				return $lessons;
			}

			$courses[ $course_id ] = array(
				'status'       => $course['status'],
				'enrolled_at'  => $enrolled_at,
				'completed_at' => $completed_at,
				'percent'      => max( 0, min( 100, $course['progress_percent'] ) ),
				'lessons'      => $lessons,
			);
		}

		return $courses;
	}

	/**
	 * Normalize lessons in their original course order.
	 *
	 * @param array $items Lesson objects from the decoded response.
	 * @return array|WP_Error
	 */
	private static function lessons( array $items ) {
		$lessons = array();
		$seen    = array();

		foreach ( $items as $lesson ) {
			if ( ! is_array( $lesson ) || ! isset( $lesson['lesson_id'] ) || ! is_int( $lesson['lesson_id'] ) || $lesson['lesson_id'] <= 0 ) {
				return self::bad_answer();
			}

			$lesson_id = $lesson['lesson_id'];

			// A repeated lesson would count the same work twice in the card.
			if ( isset( $seen[ $lesson_id ] ) ) {
				return self::bad_answer();
			}
			$seen[ $lesson_id ] = true;

			if ( ! isset( $lesson['status'] ) || ! in_array( $lesson['status'], self::LESSON, true ) ) {
				return self::bad_answer();
			}

			// Null is allowed, but omitting a required field is a malformed response.
			if ( ! array_key_exists( 'completed_at', $lesson ) || ! array_key_exists( 'quiz', $lesson ) ) {
				return self::bad_answer();
			}

			$completed_at = self::nullable_date( $lesson['completed_at'] );

			if ( false === $completed_at ) {
				return self::bad_answer();
			}

			$quiz = $lesson['quiz'];

			if ( null !== $quiz && ( ! is_array( $quiz ) || self::is_list( $quiz ) ) ) {
				return self::bad_answer();
			}

			if ( null !== $quiz ) {
				$quiz = self::quiz( $quiz );

				if ( $quiz instanceof WP_Error ) {
					return $quiz;
				}
			}

			$lessons[] = array(
				'id'           => $lesson_id,
				'status'       => $lesson['status'],
				'completed_at' => $completed_at,
				'quiz'         => $quiz,
			);
		}

		return $lessons;
	}

	/**
	 * Validate a quiz without deriving the lesson's status from its result.
	 *
	 * @param array $item Quiz fields from the decoded response.
	 * @return array|WP_Error
	 */
	private static function quiz( array $item ) {
		if ( ! isset( $item['quiz_id'] ) || ! is_int( $item['quiz_id'] ) || $item['quiz_id'] <= 0 ) {
			return self::bad_answer();
		}

		if ( ! isset( $item['status'] ) || ! in_array( $item['status'], self::QUIZ, true ) ) {
			return self::bad_answer();
		}

		foreach ( array( 'grade_percent', 'passmark_percent', 'graded_at' ) as $field ) {
			if ( ! array_key_exists( $field, $item ) ) {
				return self::bad_answer();
			}
		}

		$grade    = $item['grade_percent'];
		$passmark = $item['passmark_percent'];

		if ( ( null !== $grade && ! is_int( $grade ) ) || ( null !== $passmark && ! is_int( $passmark ) ) ) {
			return self::bad_answer();
		}

		// Updated contract review points 1 and 3: graded has no pass requirement.
		if ( 'graded' === $item['status'] && null !== $passmark ) {
			return self::bad_answer();
		}

		if ( in_array( $item['status'], array( 'passed', 'failed' ), true ) && null === $passmark ) {
			return self::bad_answer();
		}

		$graded_at = self::nullable_date( $item['graded_at'] );

		if ( false === $graded_at ) {
			return self::bad_answer();
		}

		return array(
			'id'        => $item['quiz_id'],
			'status'    => $item['status'],
			'grade'     => null === $grade ? null : max( 0, min( 100, $grade ) ),
			'passmark'  => null === $passmark ? null : max( 0, min( 100, $passmark ) ),
			'graded_at' => $graded_at,
		);
	}

	/**
	 * Whether a value is a JSON-style list with consecutive numeric keys.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	private static function is_list( $value ) {
		return is_array( $value ) && array_values( $value ) === $value;
	}

	/**
	 * Convert a contract date in RFC 3339 UTC format to a Unix timestamp.
	 *
	 * @param mixed $value Date to convert.
	 * @return int|null
	 */
	private static function date( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			return null;
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp || gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) !== $value ) {
			return null;
		}

		return $timestamp;
	}

	/**
	 * Convert an optional contract date, distinguishing null from an invalid date.
	 *
	 * @param mixed $value Date or null.
	 * @return int|null|false
	 */
	private static function nullable_date( $value ) {
		if ( null === $value ) {
			return null;
		}

		$timestamp = self::date( $value );

		return null === $timestamp ? false : $timestamp;
	}

	/**
	 * Return the one public error used for an unreadable Learn response.
	 *
	 * @return WP_Error
	 */
	private static function bad_answer() {
		return new WP_Error(
			'wpcpm_learn_bad_answer',
			__( 'Learn WordPress sent progress this site cannot read.', 'wpcredits-program-manager' )
		);
	}
}
