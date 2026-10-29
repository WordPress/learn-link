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

	const STATE_PREFIX = 'wpcpm_learnlink_state_';

	/**
	 * Start the Learn Link hooks.
	 */
	public static function init() {
		if ( class_exists( 'WPCPM_Learn_Progress_Sync' ) ) {
			WPCPM_Learn_Progress_Sync::init();
		}

		if ( ! self::enabled() ) {
			return;
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
		return array(
			'learn_enabled'     => self::enabled(),
			'learn_base_url'    => (string) WPCPM_Settings::get_value( 'learn_base_url', 'https://learn.wordpress.org' ),
			'learn_client_id'   => (string) WPCPM_Settings::get_value( 'learn_client_id', 'wpcredits' ),
			'learn_courses'     => (string) WPCPM_Settings::get_value( 'learn_courses', '297853' ),
			'learn_cache_hours' => (int) WPCPM_Settings::get_value( 'learn_cache_hours', 6 ),
		);
	}

	/**
	 * Render the Learn panel from the Phase 1 example file.
	 *
	 * The local constant is an explicit safety gate: reviewed plugin code never
	 * shows invented progress unless a developer opted into that fixture in the
	 * local site's wp-config.php. Phase 2 replaces this branch with cached data.
	 *
	 * @param WP_User $student Student whose Report Card is being rendered.
	 * @return string
	 */
	public static function panel( WP_User $student ) {
		if ( ! self::enabled() || ! defined( 'WPCPM_LEARN_FIXTURE' ) || ! WPCPM_LEARN_FIXTURE ) {
			return '';
		}

		if ( ! class_exists( 'WPCPM_Learn_Client' ) || ! class_exists( 'WPCPM_Learn_Progress_Card' ) ) {
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
				'is_owner' => get_current_user_id() === (int) $student->ID,
			)
		);
	}

	/**
	 * Start a connection.
	 */
	public static function handle_connect() {
		// Implemented in Phase 2.
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
