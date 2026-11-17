<?php
/**
 * The Learn line on the Mentor Report Card.
 *
 * @package WPCreditsProgramManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One Learn summary line per student, read from local meta only.
 *
 * Decision 2 in #2: mentors see the one-line roll-up and nothing else, because
 * they cannot open a Student Report Card. The line never calls
 * WPCPM_Learn_Progress_Sync::refresh(), WPCPM_Learn_Client or WPCPM_Learn: the
 * Mentor Report Card never reaches Learn (design spec section 3.2).
 */
class WPCPM_Learn_Progress_Mentor {

	/**
	 * The Learn line for a student, as plain text. The caller escapes it.
	 *
	 * Only numbers from the cache reach the line, never text that Learn sent.
	 *
	 * @param int $student_id Student user ID, or 0 when the row has no account.
	 * @return string
	 */
	public static function line( $student_id ) {
		$student_id = (int) $student_id;

		if ( 0 >= $student_id ) {
			return __( 'Learn: not connected', 'wpcredits-program-manager' );
		}

		$state = WPCPM_Learn_Progress::state_for( $student_id );

		if ( 'withdrawn' === $state ) {
			return __( 'Learn: connection withdrawn', 'wpcredits-program-manager' );
		}

		if ( 'not_connected' === $state ) {
			return __( 'Learn: not connected', 'wpcredits-program-manager' );
		}

		$course = self::track_course( $student_id );

		// Only "unavailable" reaches here without a usable cache. With an older
		// cache it keeps showing the numbers the mentor last had.
		if ( null === $course ) {
			return __( 'Learn: no results yet', 'wpcredits-program-manager' );
		}

		$percent = isset( $course['percent'] ) && is_numeric( $course['percent'] )
			? max( 0, min( 100, (int) $course['percent'] ) )
			: 0;
		$lessons = isset( $course['lessons'] ) && is_array( $course['lessons'] ) ? $course['lessons'] : array();
		$total   = count( $lessons );
		$done    = 0;

		foreach ( $lessons as $lesson ) {
			if ( is_array( $lesson ) && isset( $lesson['status'] ) && 'complete' === $lesson['status'] ) {
				++$done;
			}
		}

		return sprintf(
			/* translators: 1: Track course completion percentage, 2: Number of completed lessons, 3: Total number of lessons. */
			_n(
				'Learn: %1$d%% of the track course, %2$d of %3$d lesson',
				'Learn: %1$d%% of the track course, %2$d of %3$d lessons',
				$total,
				'wpcredits-program-manager'
			),
			$percent,
			$done,
			$total
		);
	}

	/**
	 * The Learn facet of a student for the Mentor Report Card's triage.
	 *
	 * Read from state_for() alone, so it agrees with line(): a student is
	 * `connected` while the Dashboard holds their token, whatever their results,
	 * including when Learn did not answer.
	 *
	 * @param int $student_id Student user ID, or 0 when the row has no account.
	 * @return string `connected`, `not_connected` or `withdrawn`.
	 */
	public static function facet( $student_id ) {
		$student_id = (int) $student_id;

		if ( 0 >= $student_id ) {
			return 'not_connected';
		}

		$state = WPCPM_Learn_Progress::state_for( $student_id );

		if ( 'withdrawn' === $state || 'not_connected' === $state ) {
			return $state;
		}

		return 'connected';
	}

	/**
	 * The words a mentor can search for to find a student by their Learn state.
	 *
	 * Empty for a connected student, so a search for "not connected" never
	 * matches one. Lowercase, as the triage search compares.
	 *
	 * @param int $student_id Student user ID, or 0 when the row has no account.
	 * @return string
	 */
	public static function search_words( $student_id ) {
		if ( 'connected' === self::facet( $student_id ) ) {
			return '';
		}

		$line = self::line( $student_id );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $line ) : strtolower( $line );
	}

	/**
	 * The first cached course, which is the track course.
	 *
	 * Contract section 1.4 keeps the list in a fixed order with the track course
	 * first, and decision 4 sends `courses=` with the track course only.
	 *
	 * @param int $student_id Student user ID.
	 * @return array|null Null when there is no usable cache.
	 */
	private static function track_course( $student_id ) {
		$cache = get_user_meta( (int) $student_id, WPCPM_Learn_Progress::META_CACHE, true );

		if ( ! is_array( $cache ) || ! isset( $cache['courses'] ) || ! is_array( $cache['courses'] ) || empty( $cache['courses'] ) ) {
			return null;
		}

		$course = reset( $cache['courses'] );

		return is_array( $course ) ? $course : null;
	}
}
