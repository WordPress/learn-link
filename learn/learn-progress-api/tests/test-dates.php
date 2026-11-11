<?php
/**
 * Pure unit tests for date normalization functions (Dates\from_gmt and Dates\from_local).
 *
 * Runs without loading full WordPress environment.
 * Coverage: U-L15 to U-L18.
 */

// Satisfy the die() guard in dates.php without a WP bootstrap; without it the suite stops silently.
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/../inc/dates.php';

use function WPOrg_Learn\Learn_Progress\Dates\from_gmt;
use function WPOrg_Learn\Learn_Progress\Dates\from_local;

// Mock wp_timezone() if not running inside WordPress.
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new \DateTimeZone( 'UTC' );
	}
}

echo "Running tests for inc/dates.php...\n\n";

// U-L15: Standard GMT datetime string converts to RFC 3339 UTC.
ck(
	"U-L15: from_gmt('2026-09-08 18:44:10') -> '2026-09-08T18:44:10Z'",
	from_gmt( '2026-09-08 18:44:10' ),
	'2026-09-08T18:44:10Z'
);

// U-L16: Local time in Costa Rica (UTC-6) converts to UTC (+6 hours).
ck(
	"U-L16: from_local('2026-09-08 12:44:10', Costa Rica UTC-6) -> '2026-09-08T18:44:10Z'",
	from_local( '2026-09-08 12:44:10', new \DateTimeZone( 'America/Costa_Rica' ) ),
	'2026-09-08T18:44:10Z'
);

// U-L17: Time zone with Daylight Saving Time (DST) - America/New_York.
// EDT (Summer, UTC-4): 2026-07-01 12:00:00 -> 16:00:00Z.
ck(
	"U-L17: from_local('2026-07-01 12:00:00', EDT UTC-4) -> '2026-07-01T16:00:00Z'",
	from_local( '2026-07-01 12:00:00', new \DateTimeZone( 'America/New_York' ) ),
	'2026-07-01T16:00:00Z'
);

// EST (Winter, UTC-5): 2026-01-15 12:00:00 -> 17:00:00Z.
ck(
	"U-L17: from_local('2026-01-15 12:00:00', EST UTC-5) -> '2026-01-15T17:00:00Z'",
	from_local( '2026-01-15 12:00:00', new \DateTimeZone( 'America/New_York' ) ),
	'2026-01-15T17:00:00Z'
);

// Spring forward gap (2026-03-08: 02:00 jumps to 03:00 EDT).
// 01:59:59 is EST (UTC-5 -> 06:59:59Z), 03:00:00 is EDT (UTC-4 -> 07:00:00Z).
ck(
	"U-L17: from_local spring gap before ('2026-03-08 01:59:59', EST UTC-5) -> '2026-03-08T06:59:59Z'",
	from_local( '2026-03-08 01:59:59', new \DateTimeZone( 'America/New_York' ) ),
	'2026-03-08T06:59:59Z'
);
ck(
	"U-L17: from_local spring gap after ('2026-03-08 03:00:00', EDT UTC-4) -> '2026-03-08T07:00:00Z'",
	from_local( '2026-03-08 03:00:00', new \DateTimeZone( 'America/New_York' ) ),
	'2026-03-08T07:00:00Z'
);

// Autumn fallback overlap (2026-11-01: 02:00 EDT falls back to 01:00 EST).
// 01:30:00 occurs twice; PHP DateTimeImmutable resolves the ambiguous time to the first occurrence (EDT, UTC-4 -> 05:30:00Z).
ck(
	"U-L17: from_local autumn overlap ('2026-11-01 01:30:00', America/New_York) -> '2026-11-01T05:30:00Z'",
	from_local( '2026-11-01 01:30:00', new \DateTimeZone( 'America/New_York' ) ),
	'2026-11-01T05:30:00Z'
);

// Fallback to wp_timezone() when site_zone parameter is omitted.
ck(
	"Fallback: from_local('2026-09-08 18:44:10', site_zone=null -> wp_timezone UTC) -> '2026-09-08T18:44:10Z'",
	from_local( '2026-09-08 18:44:10' ),
	'2026-09-08T18:44:10Z'
);

// U-L18: Null, empty, zero, or invalid inputs must return null.
ck( "U-L18: from_gmt('') -> null", from_gmt( '' ), null );
ck( "U-L18: from_gmt(null) -> null", from_gmt( null ), null );
ck( "U-L18: from_gmt('0000-00-00 00:00:00') -> null", from_gmt( '0000-00-00 00:00:00' ), null );
ck( "U-L18: from_gmt('not-a-date') -> null", from_gmt( 'not-a-date' ), null );
ck( "U-L18: from_gmt('2026-02-31 10:00:00') -> null", from_gmt( '2026-02-31 10:00:00' ), null );

ck( "U-L18: from_local('') -> null", from_local( '' ), null );
ck( "U-L18: from_local(null) -> null", from_local( null ), null );
ck( "U-L18: from_local('0000-00-00 00:00:00') -> null", from_local( '0000-00-00 00:00:00' ), null );
ck( "U-L18: from_local('invalid-date') -> null", from_local( 'invalid-date' ), null );
ck( "U-L18: from_local('2026-02-31 10:00:00') -> null", from_local( '2026-02-31 10:00:00' ), null );

test_summary();
