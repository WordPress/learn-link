<?php
/**
 * Grants: one private post per learner and client, holding only hashes.
 *
 * A private post type needs no schema migration on Learn, and a new grant replaces the old one
 * (Maciej's answer in #18). Only this file writes grant meta, except `_last_used` (#21),
 * `_token_hash` and `_code_used_hash` (#20).
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Grants;

use WPOrg_Learn\Learn_Progress\Tokens;

defined( 'WPINC' ) || die();

const POST_TYPE = 'learn_progress_grant';

add_action( 'init', __NAMESPACE__ . '\register_grant_type' );
add_action( 'delete_user', __NAMESPACE__ . '\delete_for_user', 10, 1 );
add_action( 'remove_user_from_blog', __NAMESPACE__ . '\delete_before_reassign', 10, 3 );

/**
 * Register the post type. Nothing exposes it: no screen, no front end, no REST route.
 *
 * @return void
 */
function register_grant_type() {
	register_post_type(
		POST_TYPE,
		array(
			'public'              => false,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'author' ),
			'capability_type'     => POST_TYPE,
			'map_meta_cap'        => true,
		)
	);
}

/**
 * Create a grant when a learner approves, and return its one-time code.
 *
 * The learner's previous grant for this client goes only after the new one is complete, so a
 * failure here never costs them the connection they already have.
 *
 * @param int    $user_id   The learner, who becomes the post author.
 * @param string $client_id The client.
 * @param array  $courses   The course IDs, copied from the client's whitelist at approval.
 * @param string $challenge The S256 code challenge.
 *
 * @return string|\WP_Error The code in plain text, this one time only.
 */
function create( int $user_id, string $client_id, array $courses, string $challenge ) {
	$previous = for_client( $user_id, $client_id );
	$grant_id = wp_insert_post(
		array(
			'post_type'   => POST_TYPE,
			'post_status' => 'private',
			'post_author' => $user_id,
			'post_title'  => 'grant',
		),
		true
	);

	if ( is_wp_error( $grant_id ) ) {
		return $grant_id;
	}

	$code = Tokens\mint();
	$now  = time();
	$meta = array(
		'_client_id'      => $client_id,
		'_courses'        => array_map( 'intval', $courses ),
		'_code_challenge' => $challenge,
		'_code_hash'      => Tokens\hash_token( $code ),
		'_code_expires'   => $now + 10 * MINUTE_IN_SECONDS,
		'_created'        => $now,
		'_last_used'      => 0,
	);

	foreach ( $meta as $key => $value ) {
		if ( false === update_post_meta( $grant_id, $key, $value ) ) {
			wp_delete_post( $grant_id, true );

			return new \WP_Error( 'learn_progress_grant_failed', 'The grant could not be saved.' );
		}
	}

	foreach ( $previous as $old_grant ) {
		delete( $old_grant ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- This file's Grants\delete(), not PHP's alias of unlink().
	}

	return $code;
}

/**
 * Every grant a learner holds.
 *
 * @param int $user_id The learner.
 *
 * @return int[]
 */
function for_user( int $user_id ): array {
	return find_ids( array( 'author' => $user_id ) );
}

/**
 * Delete a grant, and with it any token issued from it.
 *
 * @param int $grant_id The grant.
 *
 * @return bool False when the ID is not a grant, so a wrong ID can never delete another post.
 */
function delete( int $grant_id ): bool {
	return is_grant( $grant_id ) && (bool) wp_delete_post( $grant_id, true );
}

/**
 * Delete every grant a learner holds.
 *
 * Also runs before WordPress deletes a user. With a reassignment, the grant would otherwise move to
 * the new author, and the old token would read that person's progress.
 *
 * @param int $user_id The learner.
 *
 * @return int How many were deleted.
 */
function delete_for_user( int $user_id ): int {
	return count( array_filter( array_map( __NAMESPACE__ . '\delete', for_user( $user_id ) ) ) );
}

/**
 * Before a multisite removal reassigns a learner's posts, delete their grants on that site.
 *
 * WordPress has already switched to the site. Without a reassignment the grants do not change hands.
 *
 * @param int $user_id  The learner.
 * @param int $blog_id  The site.
 * @param int $reassign The user who receives the posts, or 0.
 *
 * @return void
 */
function delete_before_reassign( $user_id, $blog_id, $reassign = 0 ) {
	if ( $reassign ) {
		delete_for_user( (int) $user_id );
	}
}

/**
 * Mark a grant as withdrawn on Learn, so the next read answers 403 (contract section 2).
 *
 * @param int $grant_id The grant.
 *
 * @return void
 */
function revoke( int $grant_id ): void {
	if ( is_grant( $grant_id ) ) {
		update_post_meta( $grant_id, '_revoked_at', time() );
	}
}

/**
 * What a learner may be shown about a grant. Never a hash.
 *
 * @param int $grant_id The grant.
 *
 * @return array `client_id`, `courses`, `created` and `last_used`, or empty when the ID is not a grant.
 */
function summary( int $grant_id ): array {
	if ( ! is_grant( $grant_id ) ) {
		return array();
	}

	return array(
		'client_id' => (string) get_post_meta( $grant_id, '_client_id', true ),
		'courses'   => (array) get_post_meta( $grant_id, '_courses', true ),
		'created'   => (int) get_post_meta( $grant_id, '_created', true ),
		'last_used' => (int) get_post_meta( $grant_id, '_last_used', true ),
	);
}

/**
 * Whether an ID belongs to a grant.
 *
 * @param int $grant_id The post ID.
 *
 * @return bool
 */
function is_grant( int $grant_id ): bool {
	return POST_TYPE === get_post_type( $grant_id );
}

/**
 * A learner's grants for one client.
 *
 * @param int    $user_id   The learner.
 * @param string $client_id The client.
 *
 * @return int[]
 */
function for_client( int $user_id, string $client_id ): array {
	return find_ids(
		array(
			'author'     => $user_id,
			'meta_key'   => '_client_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A learner has few grants, and the author narrows them first.
			'meta_value' => $client_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- A learner has few grants, and the author narrows them first.
		)
	);
}

/**
 * The IDs of the grants that match.
 *
 * @param array $args More get_posts() arguments.
 *
 * @return int[]
 */
function find_ids( array $args ): array {
	$defaults = array(
		'post_type'     => POST_TYPE,
		'post_status'   => 'private',
		'fields'        => 'ids',
		'numberposts'   => -1,
		'no_found_rows' => true,
	);

	return array_map( 'intval', get_posts( $args + $defaults ) );
}
