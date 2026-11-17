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
	const META_PROFILE_SLUG   = '_wpcpm_learn_profile_slug';
	const META_CACHE          = '_wpcpm_learn_cache';
	const META_ERROR          = '_wpcpm_learn_last_error';
	const META_WITHDRAWN_AT   = '_wpcpm_learn_withdrawn_at';
	const META_WITHDRAWN_BY   = '_wpcpm_learn_withdrawn_by';
	const META_REVOKE_PENDING = '_wpcpm_learn_revoke_pending';

	const ACTION_CONNECT  = 'wpcpm_learn_connect';
	const ACTION_CALLBACK = 'wpcpm_learn_callback';
	const ACTION_WITHDRAW = 'wpcpm_learn_withdraw';
	const ACTION_ALLOW    = 'wpcpm_learn_allow';

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

		add_filter( 'wpcpm_learn_api_base', array( __CLASS__, 'learn_api_base' ) );

		if ( class_exists( 'WPCPM_Learn_Progress_Sync' ) ) {
			WPCPM_Learn_Progress_Sync::init();
		}

		add_action( 'admin_post_' . self::ACTION_CONNECT, array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_CALLBACK, array( __CLASS__, 'handle_callback_nopriv' ) );
		add_action( 'admin_post_' . self::ACTION_WITHDRAW, array( __CLASS__, 'handle_withdraw' ) );
		add_action( 'admin_post_' . self::ACTION_ALLOW, array( __CLASS__, 'handle_allow_reconnect' ) );
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
	 * Point the plugin's public Learn reader at the configured Learn site.
	 *
	 * The filter is registered only while Learn Link is enabled. Keeping the
	 * defensive switch check here also makes a direct callback invocation safe.
	 *
	 * @param string $api Default public Learn REST API root.
	 * @return string
	 */
	public static function learn_api_base( $api ) {
		if ( ! self::enabled() ) {
			return (string) $api;
		}

		$base_url = trim( self::settings()['learn_base_url'] );

		return '' === $base_url
			? (string) $api
			: untrailingslashit( $base_url ) . '/wp-json/';
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
		$can_manage = current_user_can( WPCPM_Roles::CAP_MANAGE );
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

		$structures       = array();
		$course_titles    = array();
		$expected_courses = self::configured_course_ids();
		$identity_notice  = array();

		if ( in_array( $state, array( 'nothing_started', 'in_progress', 'complete', 'unavailable' ), true ) && ! empty( $data['courses'] ) ) {
			foreach ( array_keys( $data['courses'] ) as $course_id ) {
				$course_id = absint( $course_id );

				if ( 1 > $course_id ) {
					continue;
				}

				$structures[ $course_id ] = $is_owner
					? self::structure_for( $course_id )
					: self::cached_structure_for( $course_id );
			}

			$title_course_ids = array_unique( array_merge( array_keys( $data['courses'] ), $expected_courses ) );

			foreach ( $title_course_ids as $course_id ) {
				$course_id = absint( $course_id );

				if ( 1 > $course_id ) {
					continue;
				}

				$course_titles[ $course_id ] = $is_owner
					? self::course_title_for( $course_id )
					: self::cached_course_title_for( $course_id );
			}
		}

		if ( $can_manage && self::identity_changed( $student_id ) ) {
			$program = get_user_meta( $student_id, 'wpcpm_student_program', true );

			$identity_notice = array(
				'known'    => isset( $program['username'] ) ? (string) $program['username'] : '',
				'verified' => (string) get_user_meta( $student_id, self::META_USERNAME, true ),
			);
		}

		return WPCPM_Learn_Progress_Card::render(
			$state,
			$data,
			$structures,
			array(
				'is_owner'         => $is_owner,
				'can_manage'       => $can_manage,
				'student_id'       => $student_id,
				'message'          => self::outcome_message( WPCPM_Flash::take( self::FLASH ) ),
				'connected_at'     => (int) get_user_meta( $student_id, self::META_CONNECTED, true ),
				'withdrawn_at'     => (int) get_user_meta( $student_id, self::META_WITHDRAWN_AT, true ),
				'course_titles'    => $course_titles,
				'expected_courses' => $expected_courses,
				'identity_notice'  => $identity_notice,
			)
		);
	}

	/**
	 * Read one course structure through the plugin's daily Learn cache.
	 *
	 * @param int $course_id Learn course post ID.
	 * @return array
	 */
	public static function structure_for( $course_id ) {
		$course_id = absint( $course_id );

		if ( 1 > $course_id || ! class_exists( 'WPCPM_Learn' ) ) {
			return array();
		}

		$structure = WPCPM_Learn::structure( $course_id );

		return is_array( $structure ) ? $structure : array();
	}

	/**
	 * Resolve one track course's title through the plugin's daily Learn cache.
	 *
	 * @param int $course_id Learn course post ID.
	 * @return string
	 */
	public static function course_title_for( $course_id ) {
		$course_id = absint( $course_id );

		if ( 1 > $course_id || ! class_exists( 'WPCPM_Learn' ) || ! class_exists( 'WPCPM_Program' ) ) {
			return '';
		}

		$url = self::course_url_for( $course_id );

		if ( '' === $url ) {
			return '';
		}

		$course = WPCPM_Learn::resolve( $url );

		return is_array( $course ) && isset( $course['title'] )
			? trim( (string) $course['title'] )
			: '';
	}

	/**
	 * Read a structure only when the plugin's daily cache already has it.
	 *
	 * @param int $course_id Learn course post ID.
	 * @return array
	 */
	private static function cached_structure_for( $course_id ) {
		$structure = get_transient( 'wpcpm_learn_structure_' . absint( $course_id ) );

		return is_array( $structure ) ? $structure : array();
	}

	/**
	 * Read a course title only when the plugin's daily cache already has it.
	 *
	 * @param int $course_id Learn course post ID.
	 * @return string
	 */
	private static function cached_course_title_for( $course_id ) {
		if ( ! class_exists( 'WPCPM_Learn' ) ) {
			return '';
		}

		$url  = self::course_url_for( $course_id );
		$slug = '' !== $url ? WPCPM_Learn::slug( $url ) : '';

		if ( '' === $slug ) {
			return '';
		}

		$course = get_transient( 'wpcpm_learn_course_' . $slug );

		return is_array( $course ) && isset( $course['title'] )
			? trim( (string) $course['title'] )
			: '';
	}

	/**
	 * Find the program course URL that belongs to one Learn course ID.
	 *
	 * @param int $course_id Learn course post ID.
	 * @return string
	 */
	private static function course_url_for( $course_id ) {
		if ( ! class_exists( 'WPCPM_Program' ) ) {
			return '';
		}

		foreach ( WPCPM_Program::course_ids() as $status => $known_course_id ) {
			if ( (int) $course_id === (int) $known_course_id ) {
				return trim( (string) WPCPM_Program::course_url( $status ) );
			}
		}

		return '';
	}

	/**
	 * Return the local or staging course override in its configured order.
	 *
	 * @return int[]
	 */
	private static function configured_course_ids() {
		$override = trim( self::settings()['learn_courses'] );

		if ( '' === $override ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', explode( ',', $override ) ) ) );
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
	 * @param mixed $outcome    Outcome code or its safe display context.
	 * @param int   $student_id Student an Administrator was viewing, if any.
	 */
	private static function back( $outcome, $student_id = 0 ) {
		WPCPM_Flash::set( self::FLASH, $outcome );

		$url = WPCPM_Students_Dashboard::page_url();
		$url = $url ? $url : home_url( '/' );

		if ( 0 < (int) $student_id ) {
			$url = add_query_arg( array( 'wpcpm_student_view' => (int) $student_id ), $url );
		}

		wp_safe_redirect( $url );
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
			case 'learn_withdraw_student':
				return __( 'Choose a valid student connection to withdraw.', 'wpcredits-program-manager' );
			case 'learn_withdraw_reason':
				return __( 'Enter a withdrawal reason of no more than 500 characters.', 'wpcredits-program-manager' );
			case 'learn_withdraw_connection':
				return __( 'That student does not have a Learn connection to withdraw.', 'wpcredits-program-manager' );
			case 'learn_withdraw_complete':
				return __( 'The Learn connection was withdrawn.', 'wpcredits-program-manager' );
			case 'learn_allow_student':
				return __( 'Choose a valid withdrawn student.', 'wpcredits-program-manager' );
			case 'learn_allow_not_withdrawn':
				return __( "That student's Learn connection is not withdrawn.", 'wpcredits-program-manager' );
			case 'learn_allow_complete':
				return __( 'The student can connect their Learn account again.', 'wpcredits-program-manager' );
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
	 * Whether the Student Report Card username changed after Learn was connected.
	 *
	 * @param int $student_id Student user ID.
	 * @return bool
	 */
	public static function identity_changed( $student_id ) {
		$student_id = (int) $student_id;

		if ( ! self::is_connected( $student_id ) ) {
			return false;
		}

		$program = get_user_meta( $student_id, 'wpcpm_student_program', true );
		$known   = is_array( $program ) && isset( $program['username'] ) && is_string( $program['username'] )
			? trim( $program['username'] )
			: '';

		if ( '' === $known ) {
			return false;
		}

		$verified = array(
			get_user_meta( $student_id, self::META_USERNAME, true ),
			get_user_meta( $student_id, self::META_PROFILE_SLUG, true ),
		);

		return ! self::identity_matches( $verified, $known );
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

		if ( get_user_meta( $user_id, self::META_WITHDRAWN_AT, true ) ) {
			self::back( 'learn_withdrawn' );
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
		update_user_meta( $user_id, self::META_PROFILE_SLUG, $learn_profile_slug );

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
		if ( ! current_user_can( WPCPM_Roles::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to withdraw Learn connections.', 'wpcredits-program-manager' ), 403 );
		}

		$request    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The student ID is needed to select the per-student nonce action.
		$student_id = isset( $request['student'] ) ? absint( $request['student'] ) : 0;

		check_admin_referer( self::ACTION_WITHDRAW . '_' . $student_id );

		$student = get_userdata( $student_id );

		if ( ! $student instanceof WP_User || ! WPCPM_Students_Dashboard::is_student( $student ) ) {
			self::back( 'learn_withdraw_student' );
		}

		$reason        = isset( $request['reason'] ) && is_string( $request['reason'] )
			? sanitize_textarea_field( $request['reason'] )
			: '';
		$reason_length = function_exists( 'mb_strlen' ) ? mb_strlen( $reason ) : strlen( $reason );

		if ( '' === $reason || 500 < $reason_length ) {
			self::back( 'learn_withdraw_reason', $student_id );
		}

		$sealed = get_user_meta( $student_id, self::META_TOKEN, true );

		if ( ! is_string( $sealed ) || '' === $sealed ) {
			self::back( 'learn_withdraw_connection', $student_id );
		}

		$token = WPCPM_Secret::unseal_from_option( $sealed );

		// The active local connection is gone even when Learn cannot be reached.
		delete_user_meta( $student_id, self::META_TOKEN );
		delete_user_meta( $student_id, self::META_CACHE );

		if ( is_wp_error( $token ) ) {
			update_user_meta( $student_id, self::META_REVOKE_PENDING, $sealed );
		} else {
			$revoked = WPCPM_Learn_Client::revoke( $token );

			if ( is_wp_error( $revoked ) ) {
				update_user_meta( $student_id, self::META_REVOKE_PENDING, $sealed );
			} else {
				delete_user_meta( $student_id, self::META_REVOKE_PENDING );
			}
		}

		update_user_meta( $student_id, self::META_WITHDRAWN_AT, time() );
		update_user_meta( $student_id, self::META_WITHDRAWN_BY, get_current_user_id() );

		$administrator      = wp_get_current_user();
		$administrator_name = $administrator instanceof WP_User && isset( $administrator->display_name )
			? trim( (string) $administrator->display_name )
			: '';

		if ( '' === $administrator_name ) {
			$administrator_name = __( 'A program Administrator', 'wpcredits-program-manager' );
		}

		WPCPM_Mail::send(
			$student,
			'learn-connection-withdrawn',
			static function ( $recipient ) use ( $administrator_name, $reason ) {
				$student_name = isset( $recipient->display_name ) ? trim( (string) $recipient->display_name ) : '';

				if ( '' === $student_name ) {
					$student_name = __( 'Student', 'wpcredits-program-manager' );
				}

				return WPCPM_Mail_Templates::render(
					'learn-connection-withdrawn',
					array(
						'student_name'       => $student_name,
						'administrator_name' => $administrator_name,
						'reason'             => $reason,
					)
				);
			}
		);

		self::back( 'learn_withdraw_complete', $student_id );
	}

	/**
	 * Let a withdrawn student connect their Learn account again.
	 */
	public static function handle_allow_reconnect() {
		if ( ! current_user_can( WPCPM_Roles::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to reopen Learn connections.', 'wpcredits-program-manager' ), 403 );
		}

		$request    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- The student ID is needed to select the per-student nonce action.
		$student_id = isset( $request['student'] ) ? absint( $request['student'] ) : 0;

		check_admin_referer( self::ACTION_ALLOW . '_' . $student_id );

		$student = get_userdata( $student_id );

		if ( ! $student instanceof WP_User || ! WPCPM_Students_Dashboard::is_student( $student ) ) {
			self::back( 'learn_allow_student' );
		}

		if ( ! get_user_meta( $student_id, self::META_WITHDRAWN_AT, true ) ) {
			self::back( 'learn_allow_not_withdrawn', $student_id );
		}

		delete_user_meta( $student_id, self::META_WITHDRAWN_AT );
		delete_user_meta( $student_id, self::META_WITHDRAWN_BY );

		self::back( 'learn_allow_complete', $student_id );
	}
}
