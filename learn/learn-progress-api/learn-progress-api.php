<?php
/**
 * Plugin Name:  Learn Progress API
 * Description:  A learner's own course progress, read-only, for the WordPress Credits program (Learn Link).
 * Version:      0.1.0
 * Requires PHP: 7.4
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  wporg-learn
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress;

defined( 'WPINC' ) || die();

define( 'LEARN_PROGRESS_API_FILE', __FILE__ );
define( 'LEARN_PROGRESS_API_VERSION', '0.1.0' );

load_files();

/**
 * Load the plugin's files, in dependency order.
 *
 * The list already names every file planned for this plugin, so nobody has to edit this one to add
 * theirs. A file that is not written yet is skipped. The is_readable() guard goes when the code is
 * prepared for WordPress/Learn, where every file exists (see docs/decisions/003).
 */
function load_files() {
	$files = array(
		'dates',
		'reader',
		'clients',
		'grants',
		'tokens',
		'response',
		'context',
		'rate',
		'rest',
		'exchange',
		'authorize',
		'privacy',
		'connections',
	);

	foreach ( $files as $file ) {
		$path = __DIR__ . '/inc/' . $file . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}

