<?php
/**
 * Tokens and one-time codes: 32 random bytes, of which Learn keeps only a keyed hash.
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Tokens;

use const WPOrg_Learn\Learn_Progress\Grants\POST_TYPE;

defined( 'WPINC' ) || die();

/**
 * A new secret: 32 random bytes as 43 base64url characters, with no padding (contract section 3).
 *
 * Tokens and one-time codes are made the same way. The meta key they are stored under tells them apart.
 *
 * @return string
 */
function mint() {
	return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
}

/**
 * The only form of a token or code that Learn stores.
 *
 * Keyed, so a leaked postmeta table alone cannot be matched against guesses (see docs/decisions/001).
 * Changing the `auth` salt therefore invalidates every token and code.
 *
 * @param string $secret A token or code.
 *
 * @return string 64 hex characters.
 */
function hash_token( $secret ) {
	return hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
}

/**
 * Whether a code verifier matches the stored S256 challenge (RFC 7636, section 4.6).
 *
 * @param string $verifier  The code verifier as received.
 * @param string $challenge The challenge stored in the grant.
 *
 * @return bool
 */
function verify_pkce( $verifier, $challenge ) {
	$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

	return hash_equals( $challenge, $computed );
}

/**
 * The grant that holds this token.
 *
 * @param mixed $token The token as received.
 *
 * @return int|null The grant ID. Null when malformed or unknown, so both get the same 401 (contract section 2).
 */
function find_grant_by_token( $token ): ?int {
	return find_grant( '_token_hash', $token );
}

/**
 * The grant that holds this one-time code. The caller checks that the code has not expired.
 *
 * @param mixed $code The code as received.
 *
 * @return int|null The grant ID, or null when malformed or unknown.
 */
function find_grant_by_code( $code ): ?int {
	return find_grant( '_code_hash', $code );
}

/**
 * Find a grant by the hash of a secret.
 *
 * The database compares in variable time, which reveals nothing here: what it looks up is a keyed
 * hash, which an attacker cannot compute or walk toward without the salt. The final hash_equals()
 * is the comparison the technical guide asks for (section 6).
 *
 * @param string $meta_key Where the hash is stored.
 * @param mixed  $secret   The token or code as received.
 *
 * @return int|null
 */
function find_grant( $meta_key, $secret ) {
	if ( ! is_string( $secret ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/D', $secret ) ) {
		return null;
	}

	$hash = hash_token( $secret );
	$ids  = get_posts(
		array(
			'post_type'     => POST_TYPE,
			'post_status'   => 'private',
			'meta_key'      => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The only way to find a grant from its secret.
			'meta_value'    => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The only way to find a grant from its secret.
			'fields'        => 'ids',
			'numberposts'   => 1,
			'no_found_rows' => true,
		)
	);

	if ( ! $ids ) {
		return null;
	}

	return hash_equals( (string) get_post_meta( $ids[0], $meta_key, true ), $hash ) ? (int) $ids[0] : null;
}
