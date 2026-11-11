<?php
/**
 * Learn progress on the Student Report Card.
 *
 * @package WPCreditsProgramManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates the Dashboard half of Learn Link.
 */
class WPCPM_Learn_Progress {

	const META_TOKEN          = '_wpcpm_learn_token';
	const META_CONNECTED      = '_wpcpm_learn_connected_at';
	const META_USERNAME       = '_wpcpm_learn_username';
	const META_CACHE          = '_wpcpm_learn_cache';
	const META_ERROR          = '_wpcpm_learn_last_error';
	const META_WITHDRAWN_AT   = '_wpcpm_learn_withdrawn_at';
	const META_WITHDRAWN_BY   = '_wpcpm_learn_withdrawn_by';
	const META_REVOKE_PENDING = '_wpcpm_learn_revoke_pending';

	const ACTION_CONNECT  = 'wpcpm_learn_connect';
	const ACTION_CALLBACK = 'wpcpm_learn_callback';
	const ACTION_WITHDRAW = 'wpcpm_learn_withdraw';

	// Keep state values and timeouts within uninstall.php's Learn transient sweep.
	const STATE_PREFIX = 'wpcpm_learn_state_';
	const FLASH        = 'learn';

	/**
	 * Start the Learn Link hooks.
	 */
	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}

		if ( class_exists( 'WPCPM_Learn_Progress_Sync' ) ) {
			WPCPM_Learn_Progress_Sync::init();
		}

		add_action( 'admin_post_' . self::ACTION_CONNECT, array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_CALLBACK, array( __CLASS__, 'handle_callback_nopriv' ) );
		add_action( 'admin_post_' . self::ACTION_WITHDRAW, array( __CLASS__, 'handle_withdraw' ) );
	}

	/**
	 * Whether Learn Link is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) WPCPM_Settings::get_value( 'learn_enabled', false );
	}

	/**
	 * Return the Learn Link settings with stable types.
	 *
	 * @return array
	 */
	public static function settings() {
		$courses = WPCPM_Settings::get_value( 'learn_courses', '' );

		if ( is_array( $courses ) ) {
			$courses = implode( ',', array_map( 'strval', $courses ) );
		}

		return array(
			'learn_enabled'     => self::enabled(),
			'learn_base_url'    => (string) WPCPM_Settings::get_value( 'learn_base_url', 'https://learn.wordpress.org' ),
			'learn_client_id'   => (string) WPCPM_Settings::get_value( 'learn_client_id', 'wpcredits' ),
			'learn_courses'     => (string) $courses,
			'learn_cache_hours' => (int) WPCPM_Settings::get_value( 'learn_cache_hours', 6 ),
		);
	}

	/**
	 * Return cryptographically random bytes in the unpadded Base64URL alphabet.
	 *
	 * @param int $bytes Number of random bytes.
	 * @return string
	 */
	public static function random_b64url( $bytes ) {
		return rtrim( strtr( base64_encode( random_bytes( (int) $bytes ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Derive the RFC 7636 S256 challenge for one PKCE verifier.
	 *
	 * @param string $verifier PKCE code verifier.
	 * @return string
	 */
	public static function challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', (string) $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Build the Learn consent URL without exposing the PKCE verifier.
	 *
	 * @param string $state     Single-use request state.
	 * @param string $challenge S256 challenge derived from the verifier.
	 * @return string
	 */
	public static function authorize_url( $state, $challenge ) {
		$settings = self::settings();
		$query    = array_map(
			'rawurlencode',
			array(
				'client_id'             => $settings['learn_client_id'],
				'redirect_uri'          => admin_url( 'admin-post.php?action=' . self::ACTION_CALLBACK ),
				'state'                 => (string) $state,
				'code_challenge'        => (string) $challenge,
				'code_challenge_method' => 'S256',
			)
		);

		return add_query_arg( $query, untrailingslashit( $settings['learn_base_url'] ) . '/learn-link/authorize/' );
	}

	/**
	 * Whether the Dashboard currently holds a sealed token for a student.
	 *
	 * @param int $student_id Student user ID.
	 * @return bool
	 */
	public static function is_connected( $student_id ) {
		return '' !== (string) get_user_meta( (int) $student_id, self::META_TOKEN, true );
	}

	/**
	 * Determine the Learn card state from stored student data.
	 *
	 * This method never performs a remote read. It uses only the connection,
	 * withdrawal, cached progress and last operational error stored locally.
	 *
	 * @param int $student_id Student user ID.
	 * @return string Learn progress card state.
	 */
	public static function state_for( $student_id ) {
		$student_id = (int) $student_id;

		if ( get_user_meta( $student_id, self::META_WITHDRAWN_AT, true ) ) {
			return 'withdrawn';
		}

		if ( ! self::is_connected( $student_id ) ) {
			return 'not_connected';
		}

		$cache = get_user_meta( $student_id, self::META_CACHE, true );

		if (
			! is_array( $cache ) ||
			! isset( $cache['fetched_at'], $cache['courses'] ) ||
			! is_array( $cache['courses'] ) ||
			empty( $cache['courses'] )
		) {
			return 'unavailable';
		}

		$error             = get_user_meta( $student_id, self::META_ERROR, true );
		$unavailable_codes = array(
			'learn_progress_unavailable',
			'wpcpm_learn_unreachable',
			'wpcpm_learn_bad_answer',
			'wpcpm_learn_identity',
			'wpcpm_learn_seal',
		);

		if (
			is_array( $error ) &&
			isset( $error['code'], $error['at'] ) &&
			in_array( $error['code'], $unavailable_codes, true ) &&
			(int) $error['at'] > (int) $cache['fetched_at']
		) {
			return 'unavailable';
		}

		$nothing_started = true;

		foreach ( $cache['courses'] as $course ) {
			if ( ! is_array( $course ) || 'not-started' !== ( $course['status'] ?? '' ) ) {
				$nothing_started = false;
				break;
			}
		}

		if ( $nothing_started ) {
			return 'nothing_started';
		}

		$configured_courses = trim( self::settings()['learn_courses'] );
		$track_course       = null;

		if ( '' === $configured_courses ) {
			$courses      = $cache['courses'];
			$track_course = reset( $courses );
		} else {
			foreach ( explode( ',', $configured_courses ) as $course_id ) {
				$course_id = (int) trim( $course_id );

				if ( 0 >= $course_id ) {
					continue;
				}

				$track_course = $cache['courses'][ $course_id ] ?? null;
				break;
			}
		}

		if ( is_array( $track_course ) && 'complete' === ( $track_course['status'] ?? '' ) ) {
			return 'complete';
		}

		return 'in_progress';
	}

	/**
	 * Add the configured Learn host to this request's safe redirect allowlist.
	 *
	 * The callback is attached only around the Connect redirect.
	 *
	 * @param array $hosts Allowed redirect hosts.
	 * @return array
	 */
	public static function allow_learn_redirect_host( $hosts ) {
		$hosts = is_array( $hosts ) ? $hosts : array();
		$host  = (string) wp_parse_url( self::settings()['learn_base_url'], PHP_URL_HOST );

		if ( '' !== $host && ! in_array( $host, $hosts, true ) ) {
			$hosts[] = $host;
		}

		return $hosts;
	}

	/**
	 * Render the Learn panel on the Student Report Card.
	 *
	 * The panel reads only local user meta. A stale cache may ask the optional
	 * sync module to refresh when the student views their own report card.
	 *
	 * @param WP_User $student Student whose Student Report Card is being rendered.
	 * @return string
	 */
	public static function panel( WP_User $student ) {
		if ( ! self::enabled() || ! class_exists( 'WPCPM_Learn_Progress_Card' ) ) {
			return '';
		}

		$student_id = (int) $student->ID;
		$is_owner   = get_current_user_id() === $student_id;
		$cache      = get_user_meta( $student_id, self::META_CACHE, true );

		if (
			$is_owner &&
			self::is_connected( $student_id ) &&
			! get_user_meta( $student_id, self::META_WITHDRAWN_AT, true ) &&
			self::cache_is_stale( $cache ) &&
			class_exists( 'WPCPM_Learn_Progress_Sync' )
		) {
			WPCPM_Learn_Progress_Sync::refresh( $student_id );
			$cache = get_user_meta( $student_id, self::META_CACHE, true );
		}

		$state = self::state_for( $student_id );
		$data  = is_array( $cache ) && isset( $cache['courses'] ) && is_array( $cache['courses'] )
			? $cache
			: array();

		if ( in_array( $state, array( 'withdrawn', 'not_connected' ), true ) ) {
			$data = array();
		}

		return WPCPM_Learn_Progress_Card::render(
			$state,
			$data,
			array(),
			array(
				'is_owner'     => $is_owner,
				'message'      => self::outcome_message( WPCPM_Flash::take( self::FLASH ) ),
				'connected_at' => (int) get_user_meta( $student_id, self::META_CONNECTED, true ),
				'withdrawn_at' => (int) get_user_meta( $student_id, self::META_WITHDRAWN_AT, true ),
			)
		);
	}

	/**
	 * Whether cached progress is absent or older than its configured lifetime.
	 *
	 * @param mixed $cache Stored progress cache.
	 * @return bool
	 */
	private static function cache_is_stale( $cache ) {
		if ( ! is_array( $cache ) || empty( $cache['fetched_at'] ) ) {
			return true;
		}

		$maximum_age = max( 0, self::settings()['learn_cache_hours'] ) * 3600;

		return (int) $cache['fetched_at'] < time() - $maximum_age;
	}

	/**
	 * Start a connection.
	 */
	public static function handle_connect() {
		check_admin_referer( self::ACTION_CONNECT );

		$user = wp_get_current_user();

		if ( ! WPCPM_Students_Dashboard::is_student( $user ) ) {
			self::back( 'learn_only_student' );
		}

		$student_id = (int) $user->ID;

		if ( get_user_meta( $student_id, self::META_WITHDRAWN_AT, true ) ) {
			self::back( 'learn_withdrawn' );
		}

		if ( self::is_connected( $student_id ) ) {
			self::back( 'learn_already_connected' );
		}

		$state    = self::random_b64url( 32 );
		$verifier = self::random_b64url( 48 );

		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'user'     => $student_id,
				'verifier' => $verifier,
				'at'       => time(),
			),
			15 * MINUTE_IN_SECONDS
		);

		$filter = array( __CLASS__, 'allow_learn_redirect_host' );

		add_filter( 'allowed_redirect_hosts', $filter );
		wp_safe_redirect( self::authorize_url( $state, self::challenge( $verifier ) ) );
		remove_filter( 'allowed_redirect_hosts', $filter );
		exit;
	}

	/**
	 * Queue one Connect outcome and return to the Student Dashboard.
	 *
	 * @param mixed $outcome Outcome code or its safe display context.
	 */
	private static function back( $outcome ) {
		WPCPM_Flash::set( self::FLASH, $outcome );

		$url = WPCPM_Students_Dashboard::page_url();

		wp_safe_redirect( $url ? $url : home_url( '/' ) );
		exit;
	}

	/**
	 * Turn one internal callback outcome into the words shown on the card.
	 *
	 * The callback stores only fixed slugs and the two usernames required by the
	 * contract. The card escapes the returned sentence as text.
	 *
	 * @param mixed $outcome Flash value taken for the Learn channel.
	 * @return string
	 */
	private static function outcome_message( $outcome ) {
		$context = is_array( $outcome ) ? $outcome : array();
		$code    = isset( $context['code'] ) ? (string) $context['code'] : (string) $outcome;

		switch ( $code ) {
			case 'learn_only_student':
				return __( 'Only the student can connect their own account.', 'wpcredits-program-manager' );
			case 'learn_withdrawn':
				return __( 'This connection was withdrawn. Contact a program Administrator if you think this is a mistake.', 'wpcredits-program-manager' );
			case 'learn_already_connected':
				return __( 'Your Learn account is already connected.', 'wpcredits-program-manager' );
			case 'learn_state':
				return __( 'That connection request expired. Try again.', 'wpcredits-program-manager' );
			case 'learn_denied':
				return __( 'You did not approve the connection on Learn.', 'wpcredits-program-manager' );
			case 'learn_exchange':
				return __( 'Learn did not confirm the connection. Try again in a few minutes.', 'wpcredits-program-manager' );
			case 'learn_identity_missing':
				return __( 'Your Student Report Card has no WordPress.org username yet. Ask an Administrator.', 'wpcredits-program-manager' );
			case 'learn_identity':
				return sprintf(
					/* translators: 1: WordPress.org username returned by Learn, 2: username on the Student Report Card. */
					__( 'The Learn account you approved belongs to @%1$s. Your Student Report Card is for @%2$s. Sign in to Learn as @%2$s and try again.', 'wpcredits-program-manager' ),
					isset( $context['learn_username'] ) ? (string) $context['learn_username'] : '',
					isset( $context['known_username'] ) ? (string) $context['known_username'] : ''
				);
			case 'learn_seal':
				return __( 'This site cannot store the connection safely. Tell an Administrator.', 'wpcredits-program-manager' );
			case 'learn_connected':
				return __( 'Your Learn account is connected.', 'wpcredits-program-manager' );
			default:
				return '';
		}
	}

	/**
	 * Whether Learn confirmed the identity already known for this student.
	 *
	 * @param mixed $from_learn Username or usernames returned by the token exchange.
	 * @param mixed $known      Username stored in the student's program data.
	 * @return bool
	 */
	public static function identity_matches( $from_learn, $known ) {
		if ( ! is_string( $known ) || '' === $known ) {
			return false;
		}

		$candidates = is_array( $from_learn ) ? $from_learn : array( $from_learn );

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate && 0 === strcasecmp( $candidate, $known ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Receive the learner from Learn.
	 */
	public static function handle_callback() {
		// OAuth state is the CSRF token on this callback, so there is no WordPress nonce.
		$request = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state   = isset( $request['state'] ) && is_string( $request['state'] )
			? sanitize_text_field( $request['state'] )
			: '';
		$held    = false;

		if ( '' !== $state ) {
			$key  = self::STATE_PREFIX . $state;
			$held = get_transient( $key );

			// Single use: delete before checking the user, denial, code or exchange.
			delete_transient( $key );
		}

		$user_id = get_current_user_id();

		if (
			! is_array( $held )
			|| ! isset( $held['user'], $held['verifier'] )
			|| (int) $held['user'] !== $user_id
			|| ! is_string( $held['verifier'] )
			|| '' === $held['verifier']
		) {
			self::back( 'learn_state' );
		}

		$error = isset( $request['error'] ) && is_string( $request['error'] )
			? sanitize_text_field( $request['error'] )
			: '';

		if ( 'access_denied' === $error ) {
			self::back( 'learn_denied' );
		}

		$code = isset( $request['code'] ) && is_string( $request['code'] )
			? sanitize_text_field( $request['code'] )
			: '';

		if ( '' === $code ) {
			self::back( 'learn_exchange' );
		}

		$connection = WPCPM_Learn_Client::exchange( $code, $held['verifier'] );

		if (
			is_wp_error( $connection )
			|| ! is_array( $connection )
			|| ! isset( $connection['token'], $connection['username'] )
			|| ! is_string( $connection['token'] )
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $connection['token'] )
			|| ! is_string( $connection['username'] )
			|| '' === $connection['username']
			|| ( isset( $connection['profile_slug'] ) && ! is_string( $connection['profile_slug'] ) )
		) {
			self::back( 'learn_exchange' );
		}

		$token              = $connection['token'];
		$learn_username     = $connection['username'];
		$learn_profile_slug = isset( $connection['profile_slug'] ) ? $connection['profile_slug'] : '';
		$program            = get_user_meta( $user_id, 'wpcpm_student_program', true );
		$known_username     = is_array( $program ) && isset( $program['username'] ) && is_string( $program['username'] )
			? $program['username']
			: '';

		if ( ! self::identity_matches( array( $learn_username, $learn_profile_slug ), $known_username ) ) {
			WPCPM_Learn_Client::revoke( $token );

			if ( '' === $known_username ) {
				self::back( 'learn_identity_missing' );
			}

			self::back(
				array(
					'code'           => 'learn_identity',
					'learn_username' => $learn_username,
					'known_username' => $known_username,
				)
			);
		}

		$sealed = WPCPM_Secret::seal_for_option( $token );

		if ( is_wp_error( $sealed ) ) {
			WPCPM_Learn_Client::revoke( $token );
			self::back( 'learn_seal' );
		}

		update_user_meta( $user_id, self::META_TOKEN, $sealed );
		update_user_meta( $user_id, self::META_CONNECTED, time() );
		update_user_meta( $user_id, self::META_USERNAME, $learn_username );
		delete_user_meta( $user_id, self::META_WITHDRAWN_AT );
		delete_user_meta( $user_id, self::META_WITHDRAWN_BY );

		if ( class_exists( 'WPCPM_Learn_Progress_Sync' ) ) {
			// A first read is best effort. Its failure must not undo a valid connection.
			WPCPM_Learn_Progress_Sync::refresh( $user_id, true );
		}

		self::back( 'learn_connected' );
	}

	/**
	 * Send a signed-out callback through WordPress's normal login flow.
	 */
	public static function handle_callback_nopriv() {
		auth_redirect();
	}

	/**
	 * Withdraw a connection.
	 */
	public static function handle_withdraw() {
		// Implemented in Phase 2.
	}
}
