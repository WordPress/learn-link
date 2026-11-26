<?php
/**
 * Storage Engine Comparison CLI Script and Library.
 *
 * Exports and compares Learn Progress Reader output between WordPress comment
 * storage and Sensei LMS High-Performance Progress Storage (HPPS).
 *
 * Modes:
 *   1. Dump (inside WordPress via WP-CLI):
 *      studio wp eval-file wp-content/plugins/learn-progress-api/tests/compare-storage.php [dump [output.json]]
 *
 *   2. Compare (pure PHP CLI, no WordPress required):
 *      php learn/learn-progress-api/tests/compare-storage.php compare <left.json> <right.json>
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Storage_Compare;

use WPOrg_Learn\Learn_Progress\Reader;

/**
 * Return the list of representative seeded learners compared for #3.
 *
 * @return string[]
 */
function target_learners() {
	return array(
		'learnlink-learner-04',
		'learnlink-learner-07',
		'learnlink-learner-11',
		'learnlink-learner-14',
		'learnlink-learner-17',
	);
}

/**
 * Determine whether an array is a sequential list (0..n-1) for PHP 7.4+ compatibility.
 *
 * @param array $value Array to inspect.
 * @return bool True when sequential list or empty array.
 */
function is_list_array( array $value ) {
	if ( array() === $value ) {
		return true;
	}

	return array_keys( $value ) === range( 0, count( $value ) - 1 );
}

/**
 * Format a field path segment for diff reporting.
 *
 * @param string     $base    Parent path.
 * @param int|string $key     Key or list index.
 * @param bool       $is_list True when indexing a sequential list.
 * @return string Formatted field path.
 */
function format_path( $base, $key, $is_list ) {
	if ( '' === $base ) {
		return $is_list ? "[{$key}]" : (string) $key;
	}

	return $is_list ? "{$base}[{$key}]" : "{$base}.{$key}";
}

/**
 * Encode a value for concise diff reporting without requiring WordPress.
 *
 * @param mixed $value Arbitrary value.
 * @return string JSON or var_export fallback string.
 */
function encode_scalar_or_value( $value ) {
	if ( function_exists( 'wp_json_encode' ) ) {
		$encoded = \wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure CLI compare mode runs without WordPress.
		$encoded = json_encode( $value, JSON_UNESCAPED_SLASHES );
	}

	return false !== $encoded ? $encoded : var_export( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Fallback formatting for CLI diff output.
}

/**
 * Encode an array as pretty-printed JSON across both WP and pure PHP CLI modes.
 *
 * @param array $data Payload to encode.
 * @return string|false Encoded JSON string or false on failure.
 */
function encode_pretty_json( array $data ) {
	$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

	if ( function_exists( 'wp_json_encode' ) ) {
		return \wp_json_encode( $data, $flags );
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure CLI compare mode runs without WordPress.
	return json_encode( $data, $flags );
}

/**
 * Recursively remove volatile `generated_at` keys from a dump structure.
 *
 * @param array $data Decoded dump or sub-structure.
 * @return array Copy without `generated_at` fields.
 */
function strip_volatile_fields( array $data ) {
	$clean = array();

	foreach ( $data as $key => $value ) {
		if ( 'generated_at' === $key ) {
			continue;
		}

		$clean[ $key ] = is_array( $value ) ? strip_volatile_fields( $value ) : $value;
	}

	return $clean;
}

/**
 * Recursively compare two decoded values and collect exact field path differences.
 *
 * @param mixed  $left  Left value.
 * @param mixed  $right Right value.
 * @param string $path  Current dot/bracket path.
 * @param array  $diffs Collected difference strings (passed by reference).
 * @return void
 */
function compare_nodes( $left, $right, $path, array &$diffs ) {
	if ( is_array( $left ) && is_array( $right ) ) {
		$use_list = is_list_array( $left ) && is_list_array( $right );

		if ( $use_list ) {
			$max_count = max( count( $left ), count( $right ) );
			for ( $i = 0; $i < $max_count; $i++ ) {
				$child_path = format_path( $path, $i, true );
				if ( ! array_key_exists( $i, $left ) ) {
					$diffs[] = "{$child_path}: missing in left (right=" . encode_scalar_or_value( $right[ $i ] ) . ')';
					continue;
				}
				if ( ! array_key_exists( $i, $right ) ) {
					$diffs[] = "{$child_path}: missing in right (left=" . encode_scalar_or_value( $left[ $i ] ) . ')';
					continue;
				}
				compare_nodes( $left[ $i ], $right[ $i ], $child_path, $diffs );
			}
			return;
		}

		$all_keys = array_unique( array_merge( array_keys( $left ), array_keys( $right ) ) );
		foreach ( $all_keys as $key ) {
			$child_path = format_path( $path, $key, false );
			if ( ! array_key_exists( $key, $left ) ) {
				$diffs[] = "{$child_path}: missing in left (right=" . encode_scalar_or_value( $right[ $key ] ) . ')';
				continue;
			}
			if ( ! array_key_exists( $key, $right ) ) {
				$diffs[] = "{$child_path}: missing in right (left=" . encode_scalar_or_value( $left[ $key ] ) . ')';
				continue;
			}
			compare_nodes( $left[ $key ], $right[ $key ], $child_path, $diffs );
		}
		return;
	}

	if ( $left !== $right ) {
		$label   = '' !== $path ? $path : '(root)';
		$diffs[] = sprintf(
			'%s: %s !== %s',
			$label,
			encode_scalar_or_value( $left ),
			encode_scalar_or_value( $right )
		);
	}
}

/**
 * Compare two storage dump arrays, ignoring volatile timestamps and storage headers.
 *
 * @param array $left_dump  First decoded JSON dump.
 * @param array $right_dump Second decoded JSON dump.
 * @return string[] List of field path differences; empty when dumps match.
 */
function compare_dumps( array $left_dump, array $right_dump ) {
	$left_clean  = strip_volatile_fields( $left_dump );
	$right_clean = strip_volatile_fields( $right_dump );

	unset( $left_clean['storage'], $right_clean['storage'] );

	$diffs = array();
	compare_nodes( $left_clean, $right_clean, '', $diffs );

	return $diffs;
}

/**
 * Read and decode a JSON dump file from disk.
 *
 * @param string $path File path to read.
 * @return array{ok:bool,data?:array,error?:string} Result envelope.
 */
function read_json_file( $path ) {
	if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) ) {
		return array(
			'ok'    => false,
			'error' => "File not found: {$path}",
		);
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Pure CLI compare mode reads local JSON dump files outside WP_Filesystem.
	$raw = file_get_contents( $path );
	if ( false === $raw ) {
		return array(
			'ok'    => false,
			'error' => "Failed to read file: {$path}",
		);
	}

	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
		return array(
			'ok'    => false,
			'error' => 'Invalid JSON in ' . $path . ': ' . json_last_error_msg(),
		);
	}

	return array(
		'ok'   => true,
		'data' => $decoded,
	);
}

/**
 * Run pure PHP comparison checks against two JSON dump files using tests/lib.php.
 *
 * @param string $left_path  Path to the first JSON dump.
 * @param string $right_path Path to the second JSON dump.
 * @return void Exits via test_summary().
 */
function run_compare_cli( $left_path, $right_path ) {
	require_once __DIR__ . '/lib.php';

	$left_res  = read_json_file( $left_path );
	$right_res = read_json_file( $right_path );

	\ck( 'left dump loaded as valid JSON object', $left_res['ok'], true );
	\ck( 'right dump loaded as valid JSON object', $right_res['ok'], true );

	if ( ! $left_res['ok'] || ! $right_res['ok'] ) {
		if ( ! $left_res['ok'] ) {
			echo '[ERROR] ' . $left_res['error'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
		}
		if ( ! $right_res['ok'] ) {
			echo '[ERROR] ' . $right_res['error'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
		}
		\test_summary();
	}

	$left_dump  = $left_res['data'];
	$right_dump = $right_res['data'];

	foreach ( target_learners() as $login ) {
		$has_left  = isset( $left_dump['learners'][ $login ]['courses'] ) && is_array( $left_dump['learners'][ $login ]['courses'] );
		$has_right = isset( $right_dump['learners'][ $login ]['courses'] ) && is_array( $right_dump['learners'][ $login ]['courses'] );

		\ck( "left dump includes {$login}", $has_left, true );
		\ck( "right dump includes {$login}", $has_right, true );

		if ( $has_left && $has_right ) {
			$learner_diffs = array();
			compare_nodes(
				strip_volatile_fields( $left_dump['learners'][ $login ] ),
				strip_volatile_fields( $right_dump['learners'][ $login ] ),
				"learners.{$login}",
				$learner_diffs
			);
			\ck( "{$login} matches across dumps", $learner_diffs, array() );
		}
	}

	$diffs = compare_dumps( $left_dump, $right_dump );
	foreach ( $diffs as $diff ) {
		echo "[DIFF] {$diff}\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI test output.
	}

	\ck( 'full normalized dumps match across all fields', $diffs, array() );
	\test_summary();
}

/**
 * Resolve deterministic course IDs for dumping Sensei reader output.
 *
 * Uses the seeded test course slugs first, falling back to published courses
 * ordered by ID ascending when those slugs are absent.
 *
 * @return int[]
 */
function resolve_course_ids() {
	$course_ids = array();

	foreach ( array( 'learn-link-test-course', 'learn-link-test-foundations' ) as $slug ) {
		$post = \get_page_by_path( $slug, OBJECT, 'course' );
		if ( $post && 'publish' === $post->post_status ) {
			$course_ids[] = (int) $post->ID;
		}
	}

	if ( ! empty( $course_ids ) ) {
		return $course_ids;
	}

	$fallback_ids = \get_posts(
		array(
			'post_type'      => 'course',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		)
	);

	if ( ! is_array( $fallback_ids ) ) {
		return array();
	}

	return array_values( array_map( 'intval', $fallback_ids ) );
}

/**
 * Build the progress dump array from the current WordPress / Sensei database.
 *
 * @return array Structured dump containing `generated_at`, `storage`, and `learners`.
 */
function build_dump() {
	require_once dirname( __DIR__ ) . '/inc/dates.php';
	require_once dirname( __DIR__ ) . '/inc/reader.php';

	$settings   = \get_option( 'sensei-settings', array() );
	$has_hpps   = ! empty( $settings['experimental_progress_storage'] );
	$sync_on    = ! empty( $settings['experimental_progress_storage_synchronization'] );
	$repository = isset( $settings['experimental_progress_storage_repository'] ) && '' !== $settings['experimental_progress_storage_repository']
		? (string) $settings['experimental_progress_storage_repository']
		: 'comments';

	$active_engine = ( $has_hpps && $sync_on && 'custom_tables' === $repository )
		? 'custom_tables'
		: 'comments';

	$course_ids = resolve_course_ids();
	$learners   = array();

	foreach ( target_learners() as $login ) {
		$user = \get_user_by( 'login', $login );
		if ( ! $user ) {
			$learners[ $login ] = array(
				'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'missing_user' => true,
				'courses'      => array(),
			);
			continue;
		}

		$courses = array();
		foreach ( $course_ids as $course_id ) {
			$course_data = Reader\read_course( (int) $user->ID, (int) $course_id );
			if ( \is_wp_error( $course_data ) ) {
				$courses[] = array(
					'course_id' => (int) $course_id,
					'error'     => $course_data->get_error_code(),
					'message'   => $course_data->get_error_message(),
				);
			} else {
				$courses[] = $course_data;
			}
		}

		$learners[ $login ] = array(
			'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'courses'      => $courses,
		);
	}

	return array(
		'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'storage'      => array(
			'active_engine'                               => $active_engine,
			'experimental_progress_storage'               => $has_hpps,
			'experimental_progress_storage_synchronization' => $sync_on,
			'experimental_progress_storage_repository'    => $repository,
		),
		'learners'     => $learners,
	);
}

/**
 * Dump current Sensei reader output as JSON to a file or stdout.
 *
 * @param string $output_path Optional output file path. Prints raw JSON when empty.
 * @return void
 */
function run_dump_cli( $output_path = '' ) {
	if ( ! defined( 'ABSPATH' ) ) {
		echo "Error: dump mode must run within a WordPress environment (e.g., via wp eval-file).\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI error message.
		exit( 1 );
	}

	$dump = build_dump();
	$json = encode_pretty_json( $dump );

	if ( false === $json ) {
		echo "Error: failed to encode dump payload as JSON.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI error message.
		exit( 1 );
	}

	if ( '' !== $output_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI helper writing JSON dump to explicit local path.
		$written = file_put_contents( $output_path, $json . "\n" );
		if ( false === $written ) {
			echo "Error: failed to write dump to {$output_path}\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI error message.
			exit( 1 );
		}

		$engine = $dump['storage']['active_engine'];
		echo "Wrote storage dump (active_engine={$engine}) to {$output_path}\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI status message.
		return;
	}

	echo $json . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw JSON CLI output.
}

/**
 * Dispatch CLI arguments for WP-CLI (`wp eval-file`) and plain PHP invocations.
 *
 * @param array|null $wp_cli_args Positional arguments passed by `wp eval-file`, or null outside WP-CLI.
 * @return void
 */
function main( $wp_cli_args = null ) {
	if ( is_array( $wp_cli_args ) ) {
		$cli_args = array_values( $wp_cli_args );
	} else {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- CLI argv reading outside HTTP request context.
		$raw_argv = isset( $_SERVER['argv'] ) && is_array( $_SERVER['argv'] ) ? $_SERVER['argv'] : array();
		$cli_args = array_slice( $raw_argv, 1 );
	}

	$command = isset( $cli_args[0] ) ? (string) $cli_args[0] : '';

	if ( 'compare' === $command ) {
		if ( count( $cli_args ) < 3 ) {
			echo "Usage: php compare-storage.php compare <left.json> <right.json>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI usage message.
			exit( 1 );
		}
		run_compare_cli( (string) $cli_args[1], (string) $cli_args[2] );
		return;
	}

	if ( defined( 'ABSPATH' ) ) {
		$output_path = '';
		if ( 'dump' === $command ) {
			$output_path = isset( $cli_args[1] ) ? (string) $cli_args[1] : '';
		} elseif ( '' !== $command ) {
			$output_path = $command;
		}
		run_dump_cli( $output_path );
		return;
	}

	echo "Usage:\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI usage message.
	echo "  Dump (inside WordPress): wp eval-file .../compare-storage.php [dump [output.json]]\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI usage message.
	echo "  Compare (pure PHP CLI):  php .../compare-storage.php compare <left.json> <right.json>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI usage message.
	exit( 1 );
}

$is_wp_eval_file = defined( 'WP_CLI' ) && \WP_CLI;
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Comparing entry-point file path in CLI execution only.
$is_direct_php = isset( $_SERVER['SCRIPT_FILENAME'] ) && realpath( (string) $_SERVER['SCRIPT_FILENAME'] ) === realpath( __FILE__ );

if ( ! defined( 'LEARN_PROGRESS_COMPARE_STORAGE_LIBRARY' ) && ( $is_wp_eval_file || $is_direct_php ) ) {
	main( isset( $args ) && is_array( $args ) ? $args : null );
}
