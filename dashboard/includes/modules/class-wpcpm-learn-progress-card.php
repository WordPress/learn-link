<?php
/**
 * Learn progress card on the Student Report Card.
 *
 * @package WPCreditsProgramManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Learn progress without reading the network or WordPress user data.
 */
class WPCPM_Learn_Progress_Card {
	/** Front-end stylesheet handle. */
	const STYLE = 'wpcpm-learn-progress';

	/**
	 * Render one Learn progress state.
	 *
	 * The caller decides the state and supplies already-normalized data. Keeping
	 * that decision outside this class makes the renderer deterministic and easy
	 * to test without WordPress user meta or network requests.
	 *
	 * @param string $state     Progress state to render.
	 * @param array  $data      Normalized progress data.
	 * @param array  $structure Public course structure.
	 * @param array  $ctx       Display context for the current viewer.
	 * @return string Escaped card markup.
	 */
	public static function render( $state, array $data, array $structure, array $ctx ) {
		ob_start();
		?>
		<div class="wpcpm-learn" data-state="<?php echo esc_attr( (string) $state ); ?>">
			<h4 class="wpcpm-learn__title"><?php echo esc_html__( 'Your progress on Learn', 'wpcredits-program-manager' ); ?></h4>
			<?php if ( isset( $ctx['message'] ) && is_string( $ctx['message'] ) && '' !== $ctx['message'] ) : ?>
				<p class="wpcpm-learn__notice" role="status"><?php echo esc_html( $ctx['message'] ); ?></p>
			<?php endif; ?>
			<?php
			switch ( $state ) {
				case 'not_connected':
					self::render_not_connected( $ctx );
					break;
				case 'nothing_started':
					self::render_nothing_started( $ctx );
					break;
				case 'in_progress':
					self::render_progress( $data, $structure, $ctx );
					self::render_last_updated( $data );
					self::render_connected_footer();
					break;
				case 'complete':
					self::render_complete( $data, $structure, $ctx );
					break;
				case 'withdrawn':
					self::render_withdrawn( $ctx );
					break;
				case 'unavailable':
					self::render_unavailable( $data, $structure, $ctx );
					break;
				default:
					self::render_unavailable( array(), array(), array() );
					break;
			}
			?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Render the card before a student has connected Learn.
	 *
	 * Only the student who owns the Student Report Card may start a connection.
	 * An Administrator viewing the same card receives a status, not a form that
	 * would let them act as the student.
	 *
	 * @param array $ctx Display context for the current viewer.
	 */
	private static function render_not_connected( array $ctx ) {
		if ( empty( $ctx['is_owner'] ) ) {
			?>
			<p><?php echo esc_html__( 'Not connected', 'wpcredits-program-manager' ); ?></p>
			<?php
			return;
		}
		?>
		<p><?php echo esc_html__( 'Connect your Learn account to show your course progress here.', 'wpcredits-program-manager' ); ?></p>
		<form class="wpcpm-learn__connect" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="wpcpm_learn_connect">
			<?php wp_nonce_field( 'wpcpm_learn_connect' ); ?>
			<button class="wpcpm-button" type="submit"><?php echo esc_html__( 'Connect your Learn account', 'wpcredits-program-manager' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Render a connection whose course has not been started.
	 *
	 * @param array $ctx Display context for the current viewer.
	 */
	private static function render_nothing_started( array $ctx ) {
		$connected_at = self::format_date( isset( $ctx['connected_at'] ) ? $ctx['connected_at'] : 0 );

		if ( '' === $connected_at ) {
			?>
			<p><?php echo esc_html__( 'You are connected, but you have not started the course yet.', 'wpcredits-program-manager' ); ?></p>
			<?php
			return;
		}
		?>
		<p><?php /* translators: %s: Learn connection date. */ echo esc_html( sprintf( __( 'Connected on %s. You have not started the course yet.', 'wpcredits-program-manager' ), $connected_at ) ); ?></p>
		<?php
	}

	/**
	 * Render a connection withdrawn by an Administrator.
	 *
	 * No cached progress is rendered here. A withdrawn connection must not look
	 * like zero progress and its former personal progress data is deleted.
	 *
	 * @param array $ctx Display context for the current viewer.
	 */
	private static function render_withdrawn( array $ctx ) {
		$withdrawn_at = self::format_date( isset( $ctx['withdrawn_at'] ) ? $ctx['withdrawn_at'] : 0 );

		if ( '' === $withdrawn_at ) {
			?>
			<p><?php echo esc_html__( 'This connection was withdrawn. Contact a program Administrator if you think this is a mistake.', 'wpcredits-program-manager' ); ?></p>
			<?php
			return;
		}
		?>
		<p><?php /* translators: %s: Learn connection withdrawal date. */ echo esc_html( sprintf( __( 'This connection was withdrawn on %s. Contact a program Administrator if you think this is a mistake.', 'wpcredits-program-manager' ), $withdrawn_at ) ); ?></p>
		<?php
	}

	/**
	 * Render a temporary failure to read Learn.
	 *
	 * The cached picture is added with the numeric progress renderer. Until a
	 * cache exists, this state deliberately contains no percentage.
	 *
	 * @param array $data      Normalized cached progress, or an empty array.
	 * @param array $structure Modules returned by WPCPM_Learn::structure().
	 * @param array $ctx       Display context for the current viewer.
	 */
	private static function render_unavailable( array $data, array $structure, array $ctx ) {
		if ( ! empty( $data['courses'] ) ) {
			self::render_progress( $data, $structure, $ctx );
			$updated_at = self::updated_at( $data );
			?>
			<p><?php /* translators: %s: Date and time of the cached Learn progress. */ echo esc_html( sprintf( __( 'Learn did not answer just now. These are your results as of %s.', 'wpcredits-program-manager' ), self::format_datetime( $updated_at ) ) ); ?></p>
			<?php
			self::render_last_updated( $data );
			self::render_connected_footer();
			return;
		}
		?>
		<p><?php echo esc_html__( 'Learn did not answer just now. Try again in a few minutes.', 'wpcredits-program-manager' ); ?></p>
		<?php
	}

	/**
	 * Render a completed course before its shared progress picture.
	 *
	 * @param array $data      Normalized progress data.
	 * @param array $structure Modules returned by WPCPM_Learn::structure().
	 * @param array $ctx       Display context for the current viewer.
	 */
	private static function render_complete( array $data, array $structure, array $ctx ) {
		$completed_at = 0;

		foreach ( isset( $data['courses'] ) && is_array( $data['courses'] ) ? $data['courses'] : array() as $course ) {
			if ( is_array( $course ) && ! empty( $course['completed_at'] ) ) {
				$completed_at = $course['completed_at'];
				break;
			}
		}
		$completed_date = self::format_date( $completed_at );
		if ( '' === $completed_date ) {
			?>
			<p><?php echo esc_html__( 'You completed this course.', 'wpcredits-program-manager' ); ?></p>
			<?php
		} else {
			?>
			<p><?php /* translators: %s: Course completion date. */ echo esc_html( sprintf( __( 'Completed on %s.', 'wpcredits-program-manager' ), $completed_date ) ); ?></p>
			<?php
		}
		self::render_progress( $data, $structure, $ctx );
		self::render_last_updated( $data );
		self::render_connected_footer();
	}

	/**
	 * Render the numeric picture shared by connected states.
	 *
	 * Phase 1 uses a flat lesson count. Module grouping and multiple configured
	 * course titles are added in Phase 3 without changing this public renderer.
	 *
	 * @param array $data      Normalized progress data.
	 * @param array $structure Modules returned by WPCPM_Learn::structure().
	 * @param array $ctx       Display context, including optional course titles.
	 */
	private static function render_progress( array $data, array $structure, array $ctx ) {
		$courses = isset( $data['courses'] ) && is_array( $data['courses'] ) ? $data['courses'] : array();

		foreach ( $courses as $course_id => $course ) {
			if ( ! is_array( $course ) ) {
				continue;
			}

			$percent = isset( $course['percent'] ) ? min( 100, absint( $course['percent'] ) ) : 0;
			$lessons = isset( $course['lessons'] ) && is_array( $course['lessons'] ) ? $course['lessons'] : array();
			$done    = 0;

			foreach ( $lessons as $lesson ) {
				if ( is_array( $lesson ) && isset( $lesson['status'] ) && 'complete' === $lesson['status'] ) {
					++$done;
				}
			}

			$title      = self::course_title( $ctx, $course_id );
			$heading_id = 'wpcpm-learn-course-' . absint( $course_id );
			?>
			<section class="wpcpm-learn__course">
				<h5 id="<?php echo esc_attr( $heading_id ); ?>"><?php echo esc_html( $title ); ?></h5>
				<?php if ( array_key_exists( 'enrolled_at', $course ) && null === $course['enrolled_at'] ) : ?>
					<p><?php echo esc_html__( 'You haven\'t enrolled in this course yet.', 'wpcredits-program-manager' ); ?></p>
				</section>
					<?php
					continue;
				endif;
				?>
				<progress class="wpcpm-learn__bar" aria-labelledby="<?php echo esc_attr( $heading_id ); ?>" max="100" value="<?php echo esc_attr( $percent ); ?>"><?php /* translators: %d: Course completion percentage. */ echo esc_html( sprintf( __( '%d%% of the course', 'wpcredits-program-manager' ), $percent ) ); ?></progress>
				<p class="wpcpm-learn__percent"><?php /* translators: %d: Course completion percentage. */ echo esc_html( sprintf( __( '%d%%', 'wpcredits-program-manager' ), $percent ) ); ?></p>
				<p><?php /* translators: 1: Number of completed lessons, 2: Total number of lessons. */ echo esc_html( sprintf( __( '%1$d of %2$d lessons', 'wpcredits-program-manager' ), $done, count( $lessons ) ) ); ?></p>
				<?php self::render_lessons( $lessons, $structure ); ?>
			</section>
			<?php
		}
	}

	/**
	 * Render a flat lesson table for Phase 1.
	 *
	 * @param array $lessons   Normalized lessons.
	 * @param array $structure Modules returned by WPCPM_Learn::structure().
	 */
	private static function render_lessons( array $lessons, array $structure ) {
		if ( empty( $lessons ) ) {
			return;
		}
		?>
		<table class="wpcpm-learn__table">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Lesson', 'wpcredits-program-manager' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Status', 'wpcredits-program-manager' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Quiz result', 'wpcredits-program-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lessons as $lesson ) : ?>
					<?php
					if ( ! is_array( $lesson ) ) {
						continue;
					}

					$lesson_id = isset( $lesson['id'] ) ? absint( $lesson['id'] ) : 0;
					$title     = self::lesson_title( $structure, $lesson_id );
					$status    = self::lesson_status( isset( $lesson['status'] ) ? $lesson['status'] : '' );
					$quiz      = self::quiz_result( isset( $lesson['quiz'] ) ? $lesson['quiz'] : null );
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $title ); ?></th>
						<td><?php echo esc_html( $status ); ?></td>
						<td><?php echo esc_html( $quiz ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Find a course title, falling back safely when Phase 3 structure is absent.
	 *
	 * @param array $ctx       Display context with optional titles by course ID.
	 * @param mixed $course_id Learn course ID.
	 * @return string
	 */
	private static function course_title( array $ctx, $course_id ) {
		if ( isset( $ctx['course_titles'][ $course_id ] ) && '' !== trim( (string) $ctx['course_titles'][ $course_id ] ) ) {
			return (string) $ctx['course_titles'][ $course_id ];
		}

		/* translators: %d: Learn course ID. */
		return sprintf( __( 'Course %d', 'wpcredits-program-manager' ), absint( $course_id ) );
	}

	/**
	 * Find a lesson title with the plugin's existing Learn structure helper.
	 *
	 * @param array $structure Modules returned by WPCPM_Learn::structure().
	 * @param int   $lesson_id Learn lesson ID.
	 * @return string
	 */
	private static function lesson_title( array $structure, $lesson_id ) {
		if ( class_exists( 'WPCPM_Learn' ) ) {
			$title = trim( (string) WPCPM_Learn::lesson_title( $structure, $lesson_id ) );

			if ( '' !== $title ) {
				return $title;
			}
		}

		/* translators: %d: Learn lesson ID. */
		return sprintf( __( 'Lesson %d', 'wpcredits-program-manager' ), $lesson_id );
	}

	/**
	 * Translate one normalized lesson status.
	 *
	 * @param mixed $status Normalized lesson status.
	 * @return string
	 */
	private static function lesson_status( $status ) {
		$labels = array(
			'not-started' => __( 'Not started', 'wpcredits-program-manager' ),
			'in-progress' => __( 'In progress', 'wpcredits-program-manager' ),
			'complete'    => __( 'Complete', 'wpcredits-program-manager' ),
			'ungraded'    => __( 'Waiting to be graded', 'wpcredits-program-manager' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : '';
	}

	/**
	 * Translate one normalized quiz result.
	 *
	 * @param mixed $quiz Normalized quiz, or null when the lesson has no quiz.
	 * @return string
	 */
	private static function quiz_result( $quiz ) {
		if ( ! is_array( $quiz ) ) {
			return __( 'No quiz', 'wpcredits-program-manager' );
		}

		$status = isset( $quiz['status'] ) ? $quiz['status'] : '';

		if ( 'not-taken' === $status ) {
			return __( 'Not taken', 'wpcredits-program-manager' );
		}

		if ( 'ungraded' === $status ) {
			return __( 'Waiting to be graded', 'wpcredits-program-manager' );
		}

		$labels = array(
			'graded' => __( 'graded', 'wpcredits-program-manager' ),
			'passed' => __( 'passed', 'wpcredits-program-manager' ),
			'failed' => __( 'failed', 'wpcredits-program-manager' ),
		);

		if ( ! isset( $labels[ $status ] ) || ! isset( $quiz['grade'] ) ) {
			return '';
		}

		if ( in_array( $status, array( 'passed', 'failed' ), true ) && isset( $quiz['passmark'] ) ) {
			return sprintf(
				/* translators: 1: Quiz grade percentage, 2: Quiz status, 3: Required pass mark percentage. */
				__( '%1$d%%, %2$s (pass mark %3$d%%)', 'wpcredits-program-manager' ),
				min( 100, absint( $quiz['grade'] ) ),
				$labels[ $status ],
				min( 100, absint( $quiz['passmark'] ) )
			);
		}

		return sprintf(
			/* translators: 1: Quiz grade percentage, 2: Quiz status. */
			__( '%1$d%%, %2$s', 'wpcredits-program-manager' ),
			min( 100, absint( $quiz['grade'] ) ),
			$labels[ $status ]
		);
	}

	/**
	 * Render when the numeric picture was refreshed.
	 *
	 * @param array $data Normalized progress data.
	 */
	private static function render_last_updated( array $data ) {
		?>
		<p class="wpcpm-learn__updated"><?php /* translators: %s: Date and time of the last Learn progress update. */ echo esc_html( sprintf( __( 'Last updated %s', 'wpcredits-program-manager' ), self::format_datetime( self::updated_at( $data ) ) ) ); ?></p>
		<?php
	}

	/**
	 * Render the explanation shared by active connections.
	 */
	private static function render_connected_footer() {
		?>
		<p class="wpcpm-learn__footer"><?php echo esc_html__( 'This connection lasts for the program. Only a program Administrator can withdraw it.', 'wpcredits-program-manager' ); ?></p>
		<?php
	}

	/**
	 * Read the cache time, accepting the parser time during Phase 1.
	 *
	 * @param array $data Normalized progress data.
	 * @return int
	 */
	private static function updated_at( array $data ) {
		if ( isset( $data['fetched_at'] ) ) {
			return absint( $data['fetched_at'] );
		}

		return isset( $data['generated_at'] ) ? absint( $data['generated_at'] ) : 0;
	}

	/**
	 * Format one Unix timestamp in the site's date format.
	 *
	 * @param mixed $timestamp Unix timestamp.
	 * @return string
	 */
	private static function format_date( $timestamp ) {
		$timestamp = absint( $timestamp );

		return 0 === $timestamp ? '' : wp_date( (string) get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Format one Unix timestamp with the site's date and time formats.
	 *
	 * @param mixed $timestamp Unix timestamp.
	 * @return string
	 */
	private static function format_datetime( $timestamp ) {
		$format = (string) get_option( 'date_format' ) . ', ' . (string) get_option( 'time_format' );

		return wp_date( $format, absint( $timestamp ) );
	}
}
