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
 * @return array|null The client, or null when no client has that ID.
 */
function get( $client_id ) {
	$clients = all();

	return isset( $clients[ $client_id ] ) && is_array( $clients[ $client_id ] ) ? $clients[ $client_id ] : null;
}
