<?php
/**
 * The clients allowed to read progress, and the courses each one may read.
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Clients;

defined( 'WPINC' ) || die();

/**
 * Every registered client, keyed by client ID.
 *
 * The whitelist is the whole scope (contract section 1): Learn's side holds it, and a client cannot
 * widen it. In Phase 1 the clients come only from the `learn_progress_clients` filter, so the local
 * `wpcredits` client lives in a mu-plugin on the test site, never in this repository.
 *
 * @return array[] Each client has `name`, `redirect_uris` (string[]) and `courses` (int[], in the order the response lists them).
 */
function all() {
	return (array) apply_filters( 'learn_progress_clients', array() );
}

/**
 * One client by its ID.
 *
 * @param string $client_id The client ID, for example `wpcredits`.
 *
 * @return array|null The client, or null when no client has that ID or its entry is malformed.
 */
function get( $client_id ) {
	$clients = all();

	return isset( $clients[ $client_id ] ) && is_valid( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
}

/**
 * Whether a filter entry has the shape the authorization code relies on.
 *
 * The redirect addresses and the courses decide where a code goes and what a token may read, so a
 * malformed entry is treated as no client at all rather than used in part.
 *
 * @param mixed $client The entry from the `learn_progress_clients` filter.
 *
 * @return bool
 */
function is_valid( $client ) {
	if ( ! is_array( $client ) || empty( $client['name'] ) || ! is_string( $client['name'] ) ) {
		return false;
	}

	if ( empty( $client['redirect_uris'] ) || ! is_list_of( $client['redirect_uris'], 'is_string' ) ) {
		return false;
	}

	return isset( $client['courses'] ) && is_list_of( $client['courses'], 'is_int' );
}

/**
 * Whether a value is a list whose every item passes a check.
 *
 * @param mixed    $value The value.
 * @param callable $check The check for each item.
 *
 * @return bool
 */
function is_list_of( $value, callable $check ) {
	return is_array( $value ) && array_values( $value ) === $value && count( array_filter( $value, $check ) ) === count( $value );
}
