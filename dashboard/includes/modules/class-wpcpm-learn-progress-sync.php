<?php
/**
 * Cached progress reads and state error persistence for Learn Link.
 *
 * @package WPCreditsProgramManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes a connected student's Learn WordPress progress into user meta.
 */
final class WPCPM_Learn_Progress_Sync {

	/**
	 * Register synchronization hooks.
	 *
	 * Nightly cron scheduling is added in Phase 3.
	 */
	public static function init() {
	}

	/**
	 * Refresh a student's cached Learn progress when stale or forced.
	 *
	 * Preserves an existing cache when a transient network, rate-limit, server,
	 * or malformed-response failure occurs, and clears the token and cache on
	 * 401 (`learn_progress_invalid_token`) or 403 (`learn_progress_revoked`).
	 *
	 * @param int  $student_id Student user ID.
	 * @param bool $force      Whether to bypass the cache age check.
	 * @return true|WP_Error True when cache is fresh or updated, WP_Error on failure.
	 */
	public static function refresh( $student_id, $force = false ) {
		$student_id = (int) $student_id;

		if ( $student_id <= 0 || ! WPCPM_Learn_Progress::enabled() ) {
			return new WP_Error(
				'wpcpm_learn_disabled',
				__( 'Learn Link is not enabled.', 'wpcredits-program-manager' )
			);
		}

		$now        = time();
		$last_error = get_user_meta( $student_id, WPCPM_Learn_Progress::META_ERROR, true );

		// Respect an active 429 Retry-After window whether or not $force is true.
		if (
			is_array( $last_error )
			&& isset( $last_error['code'], $last_error['at'], $last_error['retry_after'] )
			&& 'learn_progress_slow_down' === $last_error['code']
			&& $now < ( (int) $last_error['at'] + (int) $last_error['retry_after'] )
		) {
			$remaining = ( (int) $last_error['at'] + (int) $last_error['retry_after'] ) - $now;

			return new WP_Error(
				'learn_progress_slow_down',
				__( 'Too many requests to Learn WordPress. Try again later.', 'wpcredits-program-manager' ),
				array( 'retry_after' => $remaining )
			);
		}

		if ( ! $force ) {
			$cache       = get_user_meta( $student_id, WPCPM_Learn_Progress::META_CACHE, true );
			$cache_hours = max( 1, (int) WPCPM_Settings::get_value( 'learn_cache_hours', 6 ) );

			if (
				is_array( $cache )
				&& isset( $cache['fetched_at'] )
				&& is_int( $cache['fetched_at'] )
				&& ( $now - $cache['fetched_at'] ) < ( $cache_hours * HOUR_IN_SECONDS )
			) {
				return true;
			}
		}

		$sealed = get_user_meta( $student_id, WPCPM_Learn_Progress::META_TOKEN, true );

		if ( ! is_string( $sealed ) || '' === $sealed ) {
			self::record_error( $student_id, 'wpcpm_learn_seal', $now, 0 );

			return new WP_Error(
				'wpcpm_learn_seal',
				__( 'The stored Learn token could not be read.', 'wpcredits-program-manager' )
			);
		}

		$token = WPCPM_Secret::unseal_from_option( $sealed );

		if ( is_wp_error( $token ) || ! is_string( $token ) || '' === $token ) {
			self::record_error( $student_id, 'wpcpm_learn_seal', $now, 0 );

			return new WP_Error(
				'wpcpm_learn_seal',
				__( 'The stored Learn token could not be unsealed.', 'wpcredits-program-manager' )
			);
		}

		$result = WPCPM_Learn_Client::fetch( $token, self::courses_for( $student_id ) );
		unset( $token );

		if ( is_wp_error( $result ) ) {
			$code        = $result->get_error_code();
			$data        = $result->get_error_data();
			$retry_after = is_array( $data ) && isset( $data['retry_after'] )
				? max( 0, (int) $data['retry_after'] )
				: 0;

			if ( 'learn_progress_invalid_token' === $code ) {
				delete_user_meta( $student_id, WPCPM_Learn_Progress::META_TOKEN );
				delete_user_meta( $student_id, WPCPM_Learn_Progress::META_CACHE );
				self::record_error( $student_id, 'learn_progress_invalid_token', $now, 0 );

				return $result;
			}

			if ( 'learn_progress_revoked' === $code ) {
				update_user_meta( $student_id, WPCPM_Learn_Progress::META_WITHDRAWN_AT, $now );
				update_user_meta( $student_id, WPCPM_Learn_Progress::META_WITHDRAWN_BY, 0 );
				delete_user_meta( $student_id, WPCPM_Learn_Progress::META_TOKEN );
				delete_user_meta( $student_id, WPCPM_Learn_Progress::META_CACHE );

				return $result;
			}

			self::record_error( $student_id, $code, $now, $retry_after );

			return $result;
		}

		$stored_username = get_user_meta( $student_id, WPCPM_Learn_Progress::META_USERNAME, true );
		$parsed_username = isset( $result['username'] ) ? $result['username'] : '';

		if ( ! WPCPM_Learn_Client::same_username( $parsed_username, $stored_username ) ) {
			self::record_error( $student_id, 'wpcpm_learn_identity', $now, 0 );

			return new WP_Error(
				'wpcpm_learn_identity',
				__( 'Learn WordPress returned progress for a different account.', 'wpcredits-program-manager' )
			);
		}

		$result['fetched_at'] = $now;
		update_user_meta( $student_id, WPCPM_Learn_Progress::META_CACHE, $result );
		delete_user_meta( $student_id, WPCPM_Learn_Progress::META_ERROR );

		return true;
	}

	/**
	 * The Learn courses to read for a student: `courses=` on every read (decision 4 in #2).
	 *
	 * The `learn_courses` setting wins when it is set, as the local or staging override.
	 * Otherwise it is the student's track course, the one "Open your course" links to:
	 * `WPCPM_Program::course_status()` keeps the course of a student waiting to graduate.
	 * An empty list sends no `courses` parameter, and Learn reads its whole fixed list.
	 *
	 * @param int $student_id Student user ID.
	 * @return int[] Course post IDs.
	 */
	private static function courses_for( $student_id ) {
		$override = trim( (string) WPCPM_Settings::get_value( 'learn_courses', '' ) );

		if ( '' !== $override ) {
			return array_values( array_filter( array_map( 'intval', explode( ',', $override ) ) ) );
		}

		if ( ! class_exists( 'WPCPM_Students_Sync' ) || ! class_exists( 'WPCPM_Program' ) ) {
			return array();
		}

		$program = WPCPM_Students_Sync::get_program( $student_id );
		$status  = '';

		if ( ! empty( $program['status'] ) ) {
			$status = (string) $program['status'];
		} elseif ( isset( $program['program'] ) ) {
			$status = (string) $program['program'];
		}

		$course = (int) WPCPM_Program::course_id( WPCPM_Program::course_status( $status, $student_id ) );

		return $course > 0 ? array( $course ) : array();
	}

	/**
	 * Record the last synchronization error in user meta.
	 *
	 * @param int    $student_id  Student user ID.
	 * @param string $code        Error code.
	 * @param int    $at          Unix timestamp when the error occurred.
	 * @param int    $retry_after Optional wait time in seconds.
	 */
	private static function record_error( $student_id, $code, $at, $retry_after = 0 ) {
		update_user_meta(
			(int) $student_id,
			WPCPM_Learn_Progress::META_ERROR,
			array(
				'code'        => (string) $code,
				'at'          => (int) $at,
				'retry_after' => max( 0, (int) $retry_after ),
			)
		);
	}
}
