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
	 * A student without a token gets the real not-connected state. Connected
	 * progress still uses the Phase 1 fixture behind its explicit local safety
	 * gate; the cached read replaces that remaining branch later in Phase 2.
	 *
	 * @param WP_User $student Student whose Student Report Card is being rendered.
	 * @return string
	 */
	public static function panel( WP_User $student ) {
		if ( ! self::enabled() || ! class_exists( 'WPCPM_Learn_Progress_Card' ) ) {
			return '';
		}

		$student_id   = (int) $student->ID;
		$is_owner     = get_current_user_id() === $student_id;
		$withdrawn_at = (int) get_user_meta( $student_id, self::META_WITHDRAWN_AT, true );

		if ( $withdrawn_at ) {
			return WPCPM_Learn_Progress_Card::render(
				'withdrawn',
				array(),
				array(),
				array(
					'is_owner'     => $is_owner,
					'withdrawn_at' => $withdrawn_at,
				)
			);
		}

		if ( ! self::is_connected( $student_id ) ) {
			return WPCPM_Learn_Progress_Card::render(
				'not_connected',
				array(),
				array(),
				array(
					'is_owner' => $is_owner,
				)
			);
		}

		if ( ! defined( 'WPCPM_LEARN_FIXTURE' ) || ! WPCPM_LEARN_FIXTURE || ! class_exists( 'WPCPM_Learn_Client' ) ) {
			return '';
		}

		$fixture = is_string( WPCPM_LEARN_FIXTURE ) && '' !== WPCPM_LEARN_FIXTURE
			? WPCPM_LEARN_FIXTURE
			: WPCPM_PLUGIN_DIR . 'bin/fixtures/learn-progress-in-progress.json';
		$body    = is_readable( $fixture ) ? json_decode( (string) file_get_contents( $fixture ), true ) : null;

		if ( ! is_array( $body ) ) {
			return '';
		}

		$data = WPCPM_Learn_Client::parse( $body );

		if ( is_wp_error( $data ) ) {
			return '';
		}

		return WPCPM_Learn_Progress_Card::render(
			'in_progress',
			$data,
			array(),
			array(
				'is_owner' => $is_owner,
			)
		);
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
	 * @param string $outcome Outcome code rendered after the redirect.
	 */
	private static function back( $outcome ) {
		WPCPM_Flash::set( self::FLASH, (string) $outcome );

		$url = WPCPM_Students_Dashboard::page_url();

		wp_safe_redirect( $url ? $url : home_url( '/' ) );
		exit;
	}

	/**
	 * Receive the learner from Learn.
	 */
	public static function handle_callback() {
		// Implemented in Phase 2.
	}

	/**
	 * Withdraw a connection.
	 */
	public static function handle_withdraw() {
		// Implemented in Phase 2.
	}
}
