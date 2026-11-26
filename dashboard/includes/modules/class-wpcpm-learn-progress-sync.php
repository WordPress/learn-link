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
	 * Daily cron hook that refreshes cached progress and retries pending revocations.
	 *
	 * @var string
	 */
	const HOOK = 'wpcpm_learn_progress_nightly';

	/**
	 * Option storing the summary counts of the last warmer run.
	 *
	 * @var string
	 */
	const RUN_OPTION = 'wpcpm_learn_progress_run';

	/**
	 * Option holding the Unix timestamp of an active warmer run lock.
	 *
	 * @var string
	 */
	const LOCK_OPTION = 'wpcpm_learn_progress_lock';

	/**
	 * How long a lock holds before an unfinished run is considered stale: ten minutes.
	 *
	 * @var int
	 */
	const LOCK_TTL = 600;

	/**
	 * The hour, UTC, the daily warmer run starts (08:00 UTC).
	 *
	 * @var int
	 */
	const RUN_HOUR = 8;

	/**
	 * Maximum number of connected students queried per batch.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 500;

	/**
	 * Register synchronization hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Register data lifecycle hooks that must run even when Learn Link is disabled.
	 */
	public static function lifecycle() {
		add_action( 'delete_user', array( __CLASS__, 'delete_user_data' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Attempt remote token revocation and delete stored Learn meta before a user is deleted.
	 *
	 * @param int $user_id User ID being deleted.
	 */
	public static function delete_user_data( $user_id ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		self::revoke_user_tokens( $user_id );

		foreach ( self::meta_keys() as $meta_key ) {
			delete_user_meta( $user_id, $meta_key );
		}
	}

	/**
	 * Register the Dashboard Learn progress personal data exporter.
	 *
	 * @param array $exporters Registered personal data exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters = is_array( $exporters ) ? $exporters : array();

		$exporters['wpcpm-learn-progress'] = array(
			'exporter_friendly_name' => __( 'Learn progress on the Dashboard', 'wpcredits-program-manager' ),
			'callback'               => array( __CLASS__, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the Dashboard Learn progress personal data eraser.
	 *
	 * @param array $erasers Registered personal data erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers = is_array( $erasers ) ? $erasers : array();

		$erasers['wpcpm-learn-progress'] = array(
			'eraser_friendly_name' => __( 'Learn progress on the Dashboard', 'wpcredits-program-manager' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export stored Learn progress summary and connection metadata for a user.
	 *
	 * Never exports sealed or plain tokens.
	 *
	 * @param string $email User email address.
	 * @param int    $page  Pagination index.
	 * @return array{data: array, done: bool}
	 */
	public static function export( $email, $page = 1 ) {
		if ( (int) $page > 1 ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user = get_user_by( 'email', trim( (string) $email ) );

		if ( ! $user instanceof WP_User ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$user_id      = (int) $user->ID;
		$username     = get_user_meta( $user_id, WPCPM_Learn_Progress::META_USERNAME, true );
		$slug_key     = defined( 'WPCPM_Learn_Progress::META_PROFILE_SLUG' )
			? constant( 'WPCPM_Learn_Progress::META_PROFILE_SLUG' )
			: '_wpcpm_learn_profile_slug';
		$profile_slug = get_user_meta( $user_id, $slug_key, true );
		$connected    = (int) get_user_meta( $user_id, WPCPM_Learn_Progress::META_CONNECTED, true );
		$withdrawn    = (int) get_user_meta( $user_id, WPCPM_Learn_Progress::META_WITHDRAWN_AT, true );
		$cache        = get_user_meta( $user_id, WPCPM_Learn_Progress::META_CACHE, true );
		$rows         = array();

		if ( is_string( $username ) && '' !== $username ) {
			$rows[] = array(
				'name'  => __( 'Learn username', 'wpcredits-program-manager' ),
				'value' => self::escape_text( $username ),
			);
		}

		if ( is_string( $profile_slug ) && '' !== $profile_slug && $profile_slug !== $username ) {
			$rows[] = array(
				'name'  => __( 'Learn profile slug', 'wpcredits-program-manager' ),
				'value' => self::escape_text( $profile_slug ),
			);
		}

		if ( $connected > 0 ) {
			$rows[] = array(
				'name'  => __( 'Connected on', 'wpcredits-program-manager' ),
				'value' => gmdate( 'Y-m-d H:i:s', $connected ) . ' UTC',
			);
		}

		if ( $withdrawn > 0 ) {
			$rows[] = array(
				'name'  => __( 'Withdrawn on', 'wpcredits-program-manager' ),
				'value' => gmdate( 'Y-m-d H:i:s', $withdrawn ) . ' UTC',
			);
		}

		if ( is_array( $cache ) ) {
			if ( ! empty( $cache['fetched_at'] ) && (int) $cache['fetched_at'] > 0 ) {
				$rows[] = array(
					'name'  => __( 'Last read', 'wpcredits-program-manager' ),
					'value' => gmdate( 'Y-m-d H:i:s', (int) $cache['fetched_at'] ) . ' UTC',
				);
			}

			$summary = self::summarize_cached_courses( $cache );

			if ( '' !== $summary ) {
				$rows[] = array(
					'name'  => __( 'Cached progress', 'wpcredits-program-manager' ),
					'value' => self::escape_text( $summary ),
				);
			}
		}

		if ( empty( $rows ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		return array(
			'data' => array(
				array(
					'group_id'    => 'wpcpm-learn-progress',
					'group_label' => __( 'Learn progress on the Dashboard', 'wpcredits-program-manager' ),
					'item_id'     => 'wpcpm-learn-progress-' . $user_id,
					'data'        => $rows,
				),
			),
			'done' => true,
		);
	}

	/**
	 * Erase all stored Learn progress user meta for a user and attempt remote token revocation.
	 *
	 * @param string $email User email address.
	 * @param int    $page  Pagination index.
	 * @return array{items_removed: bool, items_retained: bool, messages: array, done: bool}
	 */
	public static function erase( $email, $page = 1 ) {
		unset( $page );

		$user = get_user_by( 'email', trim( (string) $email ) );

		if ( ! $user instanceof WP_User ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id = (int) $user->ID;
		self::revoke_user_tokens( $user_id );

		$removed = false;

		foreach ( self::meta_keys() as $meta_key ) {
			if ( '' !== get_user_meta( $user_id, $meta_key, true ) ) {
				delete_user_meta( $user_id, $meta_key );
				$removed = true;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Discover all Learn progress user meta keys declared as `META_*` constants.
	 *
	 * @param string $class_name Class declaring `META_*` constants.
	 * @return string[] Meta key names.
	 */
	public static function meta_keys( $class_name = 'WPCPM_Learn_Progress' ) {
		if ( ! class_exists( $class_name ) ) {
			return array();
		}

		$reflection = new ReflectionClass( $class_name );
		$keys       = array();

		foreach ( $reflection->getConstants() as $name => $value ) {
			if ( 0 === strpos( (string) $name, 'META_' ) && is_string( $value ) && '' !== $value ) {
				$keys[] = $value;
			}
		}

		return $keys;
	}

	/**
	 * Schedule the daily warmer event on `init` when missing.
	 */
	public static function schedule() {
		if ( ! WPCPM_Learn_Progress::enabled() ) {
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( self::next_run( time() ), 'daily', self::HOOK );
		}
	}

	/**
	 * Return the next 08:00 UTC timestamp at or after a given Unix time.
	 *
	 * @param int $now Unix timestamp.
	 * @return int Next 08:00 UTC Unix timestamp.
	 */
	public static function next_run( $now ) {
		$now      = (int) $now;
		$midnight = $now - ( $now % DAY_IN_SECONDS );
		$at       = $midnight + ( self::RUN_HOUR * HOUR_IN_SECONDS );

		return $at >= $now ? $at : $at + DAY_IN_SECONDS;
	}

	/**
	 * Clear the scheduled daily event and any active lock on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Retry pending revocations and refresh cached progress for connected students.
	 *
	 * @return array|false Run summary counts, or false when disabled or already locked.
	 */
	public static function run() {
		if ( ! WPCPM_Learn_Progress::enabled() ) {
			return false;
		}

		$now = time();

		if ( ! self::lock( $now ) ) {
			return false;
		}

		try {
			$revocations = self::retry_revocations();
			$user_ids    = self::connected_user_ids();
			$refreshed   = 0;
			$failed      = 0;
			$waiting     = 0;

			foreach ( $user_ids as $user_id ) {
				if ( get_user_meta( $user_id, WPCPM_Learn_Progress::META_WITHDRAWN_AT, true ) ) {
					continue;
				}

				$res = self::refresh( $user_id, true );

				if ( true === $res ) {
					++$refreshed;
					continue;
				}

				if ( is_wp_error( $res ) && 'learn_progress_slow_down' === $res->get_error_code() ) {
					++$waiting;
					continue;
				}

				++$failed;
			}

			$summary = array(
				'at'          => $now,
				'refreshed'   => $refreshed,
				'failed'      => $failed,
				'waiting'     => $waiting,
				'revocations' => $revocations,
			);

			update_option( self::RUN_OPTION, $summary, false );

			return $summary;
		} finally {
			self::unlock();
		}
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
	 * Retry pending remote token revocations queued during offline disconnects.
	 *
	 * Clears `META_REVOKE_PENDING` when Learn confirms the token is gone (`204`,
	 * `401 learn_progress_invalid_token`, or `403 learn_progress_revoked`), and
	 * retains the sealed token on transient failures (`429`, `5xx`, network error,
	 * or unseal failure) for the next scheduled run.
	 *
	 * @return array{retried: int, cleared: int, failed: int} Summary counts.
	 */
	public static function retry_revocations() {
		$summary = array(
			'retried' => 0,
			'cleared' => 0,
			'failed'  => 0,
		);

		if ( ! WPCPM_Learn_Progress::enabled() ) {
			return $summary;
		}

		$user_ids = get_users(
			array(
				'meta_key' => WPCPM_Learn_Progress::META_REVOKE_PENDING,
				'fields'   => 'ID',
			)
		);

		if ( ! is_array( $user_ids ) ) {
			return $summary;
		}

		foreach ( $user_ids as $raw_id ) {
			$user_id = (int) $raw_id;
			if ( $user_id <= 0 ) {
				continue;
			}

			++$summary['retried'];

			$sealed = get_user_meta( $user_id, WPCPM_Learn_Progress::META_REVOKE_PENDING, true );
			if ( ! is_string( $sealed ) || '' === $sealed ) {
				++$summary['failed'];
				continue;
			}

			$token = WPCPM_Secret::unseal_from_option( $sealed );
			if ( is_wp_error( $token ) || ! is_string( $token ) || '' === $token ) {
				++$summary['failed'];
				continue;
			}

			$res = WPCPM_Learn_Client::revoke( $token );
			unset( $token );

			if (
				true === $res
				|| (
					is_wp_error( $res )
					&& in_array(
						$res->get_error_code(),
						array( 'learn_progress_invalid_token', 'learn_progress_revoked' ),
						true
					)
				)
			) {
				delete_user_meta( $user_id, WPCPM_Learn_Progress::META_REVOKE_PENDING );
				++$summary['cleared'];
				continue;
			}

			++$summary['failed'];
		}

		return $summary;
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
	 * Query all user IDs holding a stored Learn token in batches.
	 *
	 * @return int[] Deduplicated user IDs.
	 */
	private static function connected_user_ids() {
		$seen  = array();
		$paged = 1;

		do {
			$batch = get_users(
				array(
					'meta_key' => WPCPM_Learn_Progress::META_TOKEN,
					'fields'   => 'ID',
					'number'   => self::BATCH_SIZE,
					'paged'    => $paged,
				)
			);

			if ( ! is_array( $batch ) || empty( $batch ) ) {
				break;
			}

			$batch_count = count( $batch );

			foreach ( $batch as $raw_id ) {
				$user_id = (int) $raw_id;
				if ( $user_id > 0 ) {
					$seen[ $user_id ] = true;
				}
			}

			++$paged;
		} while ( $batch_count >= self::BATCH_SIZE );

		return array_keys( $seen );
	}

	/**
	 * Acquire the warmer lock, taking over any lock older than `LOCK_TTL`.
	 *
	 * @param int $now Current Unix timestamp.
	 * @return bool True if the lock was acquired, false if another active run holds it.
	 */
	private static function lock( $now ) {
		if ( add_option( self::LOCK_OPTION, (int) $now, '', false ) ) {
			return true;
		}

		$held = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $held > 0 && ( (int) $now - $held ) < self::LOCK_TTL ) {
			return false;
		}

		update_option( self::LOCK_OPTION, (int) $now, false );

		return true;
	}

	/**
	 * Release the warmer lock.
	 */
	private static function unlock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Unseal any active or pending-revocation tokens for a user and ask Learn to revoke them.
	 *
	 * @param int $user_id Student user ID.
	 */
	private static function revoke_user_tokens( $user_id ) {
		if ( ! class_exists( 'WPCPM_Secret' ) || ! class_exists( 'WPCPM_Learn_Client' ) ) {
			return;
		}

		$meta_keys = array(
			WPCPM_Learn_Progress::META_TOKEN,
			WPCPM_Learn_Progress::META_REVOKE_PENDING,
		);
		$seen      = array();

		foreach ( $meta_keys as $meta_key ) {
			$sealed = get_user_meta( $user_id, $meta_key, true );

			if ( ! is_string( $sealed ) || '' === $sealed ) {
				continue;
			}

			$token = WPCPM_Secret::unseal_from_option( $sealed );

			if ( is_wp_error( $token ) || ! is_string( $token ) || '' === $token ) {
				continue;
			}

			if ( isset( $seen[ $token ] ) ) {
				unset( $token );
				continue;
			}

			$seen[ $token ] = true;
			WPCPM_Learn_Client::revoke( $token );
			unset( $token );
		}

		unset( $seen );
	}

	/**
	 * Build a human-readable summary of cached course progress for privacy export.
	 *
	 * @param array $cache Normalized cached progress array.
	 * @return string Course summary text, or empty string when none exists.
	 */
	private static function summarize_cached_courses( array $cache ) {
		if ( empty( $cache['courses'] ) || ! is_array( $cache['courses'] ) ) {
			return '';
		}

		$parts = array();

		foreach ( $cache['courses'] as $course_id => $course ) {
			if ( ! is_array( $course ) ) {
				continue;
			}

			$status  = isset( $course['status'] ) ? (string) $course['status'] : '';
			$percent = isset( $course['percent'] )
				? (int) $course['percent']
				: ( isset( $course['progress_percent'] ) ? (int) $course['progress_percent'] : 0 );

			$parts[] = sprintf(
				'Course %d: %s (%d%%)',
				(int) $course_id,
				'' !== $status ? $status : 'unknown',
				$percent
			);
		}

		return implode( '; ', $parts );
	}

	/**
	 * Escape plain text for privacy export rendering.
	 *
	 * @param string $text Text to escape.
	 * @return string Escaped text.
	 */
	private static function escape_text( $text ) {
		$text = (string) $text;

		return function_exists( 'esc_html' )
			? esc_html( $text )
			: htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
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
