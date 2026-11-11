<?php
/**
 * Tokens\mint() and hash_token(), and malformed input to the find functions. No WordPress needed.
 *
 * Run: php tests/test-tokens-pure.php
 *
 * @package WPOrg_Learn\Learn_Progress
 */

use WPOrg_Learn\Learn_Progress\Tokens;

define( 'WPINC', 'wp-includes' );

/**
 * A stand-in for WordPress's salt.
 *
 * @param string $scheme The salt's scheme.
 *
 * @return string
 */
function wp_salt( $scheme ) {
	return 'test-only-salt-' . $scheme;
}

require_once __DIR__ . '/../inc/tokens.php';
require_once __DIR__ . '/lib.php';

// U-L21: 1000 tokens, all well formed and all different.
$tokens = array();
for ( $i = 0; $i < 1000; $i++ ) {
	$tokens[] = Tokens\mint();
}
$well_formed = array_filter(
	$tokens,
	function ( $token ) {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $token );
	}
);
ck( 'U-L21 mint(): 1000 values of 43 base64url characters', count( $well_formed ), 1000 );
ck( 'U-L21 mint(): 1000 different values', count( array_unique( $tokens ) ), 1000 );

$secret = $tokens[0];
$hash   = Tokens\hash_token( $secret );
ck( 'hash_token(): 64 lowercase hex characters', 1 === preg_match( '/^[0-9a-f]{64}$/D', $hash ), true );
ck( 'hash_token(): the same input gives the same hash', Tokens\hash_token( $secret ), $hash );
ck( 'hash_token(): another input gives another hash', Tokens\hash_token( $tokens[1] ) === $hash, false );
ck( 'hash_token(): keyed with the auth salt, not a plain hash', $hash, hash_hmac( 'sha256', $secret, 'test-only-salt-auth' ) );
ck( 'hash_token(): never the token itself', $hash === $secret, false );

// Malformed input never reaches the database, so these need no WordPress.
$bad = array(
	'42 characters'      => str_repeat( 'a', 42 ),
	'44 characters'      => str_repeat( 'a', 44 ),
	'a plus sign'        => str_repeat( 'a', 42 ) . '+',
	'a trailing newline' => str_repeat( 'a', 43 ) . "\n",
	'empty'              => '',
	'null'               => null,
	'an array'           => array( str_repeat( 'a', 43 ) ),
);
foreach ( $bad as $label => $value ) {
	ck( "find_grant_by_token(): {$label} gives null", Tokens\find_grant_by_token( $value ), null );
	ck( "find_grant_by_code(): {$label} gives null", Tokens\find_grant_by_code( $value ), null );
}

test_summary();
