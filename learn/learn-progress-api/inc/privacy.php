<?php
/**
 * WordPress privacy tools for Learn Link grants: personal data exporter and eraser.
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Privacy;

use WPOrg_Learn\Learn_Progress\Clients;
use WPOrg_Learn\Learn_Progress\Grants;

defined( 'WPINC' ) || die();

add_filter( 'wp_privacy_personal_data_exporters', __NAMESPACE__ . '\register_exporter' );
add_filter( 'wp_privacy_personal_data_erasers', __NAMESPACE__ . '\register_eraser' );

/**
 * Register the Learn Link personal data exporter.
 *
 * @param array $exporters Registered personal data exporters.
 *
 * @return array
 */
function register_exporter( $exporters ): array {
	$exporters = is_array( $exporters ) ? $exporters : array();

	$exporters['learn-progress-api'] = array(
		'exporter_friendly_name' => __( 'Learn Link connections', 'wporg-learn' ),
		'callback'               => __NAMESPACE__ . '\export',
	);

	return $exporters;
}

/**
 * Register the Learn Link personal data eraser.
 *
 * @param array $erasers Registered personal data erasers.
 *
 * @return array
 */
function register_eraser( $erasers ): array {
	$erasers = is_array( $erasers ) ? $erasers : array();

	$erasers['learn-progress-api'] = array(
		'eraser_friendly_name' => __( 'Learn Link connections', 'wporg-learn' ),
		'callback'             => __NAMESPACE__ . '\erase',
	);

	return $erasers;
}

/**
 * Export every Learn Link grant belonging to the learner identified by email.
 *
 * Reads only public grant metadata via Grants\summary() and a read-only lookup
 * of `_revoked_at`, never exposing token or code hashes.
 *
 * @param string $email_address Learner email address.
 * @param int    $page          Pagination index requested by WordPress.
 *
 * @return array{data: array, done: bool}
 */
function export( $email_address, $page = 1 ): array {
	if ( (int) $page > 1 ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	$user = get_user_by( 'email', (string) $email_address );

	if ( ! $user instanceof \WP_User ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	$items = array();

	foreach ( Grants\for_user( (int) $user->ID ) as $grant_id ) {
		$summary = Grants\summary( (int) $grant_id );

		if ( empty( $summary ) ) {
			continue;
		}

		$client  = Clients\get( $summary['client_id'] );
		$program = ( is_array( $client ) && ! empty( $client['name'] ) )
			? (string) $client['name']
			: (string) $summary['client_id'];

		$course_titles = array();

		foreach ( $summary['courses'] as $course_id ) {
			$course_id = (int) $course_id;
			if ( $course_id <= 0 ) {
				continue;
			}

			$title           = (string) get_the_title( $course_id );
			$course_titles[] = '' !== $title ? $title : (string) $course_id;
		}

		$data = array(
			array(
				'name'  => __( 'Program', 'wporg-learn' ),
				'value' => $program,
			),
			array(
				'name'  => __( 'Courses', 'wporg-learn' ),
				'value' => ! empty( $course_titles )
					? implode( ', ', $course_titles )
					: __( 'None', 'wporg-learn' ),
			),
			array(
				'name'  => __( 'Connected on', 'wporg-learn' ),
				'value' => (int) $summary['created'] > 0
					? format_date( (int) $summary['created'] )
					: __( 'Unknown', 'wporg-learn' ),
			),
			array(
				'name'  => __( 'Last read', 'wporg-learn' ),
				'value' => (int) $summary['last_used'] > 0
					? format_date( (int) $summary['last_used'] )
					: __( 'Not read yet', 'wporg-learn' ),
			),
		);

		$revoked_at = (int) get_post_meta( (int) $grant_id, '_revoked_at', true );

		if ( $revoked_at > 0 ) {
			$data[] = array(
				'name'  => __( 'Withdrawn on Learn', 'wporg-learn' ),
				'value' => format_date( $revoked_at ),
			);
		}

		$items[] = array(
			'group_id'    => 'learn-link-connections',
			'group_label' => __( 'Learn Link connections', 'wporg-learn' ),
			'item_id'     => 'learn-link-' . (int) $grant_id,
			'data'        => $data,
		);
	}

	return array(
		'data' => $items,
		'done' => true,
	);
}

/**
 * Erase every Learn Link grant belonging to the learner identified by email.
 *
 * @param string $email_address Learner email address.
 * @param int    $page          Pagination index requested by WordPress.
 *
 * @return array{items_removed: int, items_retained: bool, messages: array, done: bool}
 */
function erase( $email_address, $page = 1 ): array {
	unset( $page );

	$user = get_user_by( 'email', (string) $email_address );

	if ( ! $user instanceof \WP_User ) {
		return array(
			'items_removed'  => 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	$removed = Grants\delete_for_user( (int) $user->ID );

	return array(
		'items_removed'  => $removed,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
}

/**
 * Format a Unix timestamp in the site's date and time format.
 *
 * @param int $timestamp Unix timestamp.
 *
 * @return string
 */
function format_date( int $timestamp ): string {
	$format = trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );
	$date   = wp_date( '' !== $format ? $format : 'Y-m-d H:i:s', $timestamp );

	return false !== $date ? $date : gmdate( 'Y-m-d H:i:s', $timestamp );
}
