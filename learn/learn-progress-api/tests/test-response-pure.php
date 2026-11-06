<?php
/**
 * Response\narrow(): the `courses` parameter can only narrow the whitelist, in the whitelist's
 * order, and anything else is ignored (contract section 1). No WordPress needed.
 *
 * Run: php tests/test-response-pure.php
 *
 * @package WPOrg_Learn\Learn_Progress
 */

use function WPOrg_Learn\Learn_Progress\Response\narrow;

define( 'WPINC', 'wp-includes' );

require_once __DIR__ . '/../inc/response.php';

$total = 0;
$fail  = 0;

/**
 * One check: print ok or FAIL, and count it.
 */
function ck( $label, $actual, $expected ) {
	global $total, $fail;
	++$total;

	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text to a terminal, not HTML.
	if ( $actual === $expected ) {
		echo "ok   $label\n";
		return;
	}

	++$fail;
	echo "FAIL $label\n     expected: " . var_export( $expected, true ) . "\n     actual:   " . var_export( $actual, true ) . "\n";
	// phpcs:enable
}

// U-L22.
ck( 'duplicates, words and a course outside the whitelist are ignored', narrow( array( 12, 21 ), '21,abc,21,99999' ), array( 21 ) );

// U-L23, pending decision D-12.
ck( 'an empty parameter answers the whole whitelist', narrow( array( 12, 21 ), '' ), array( 12, 21 ) );

ck( "the whitelist's order wins over the order asked", narrow( array( 12, 21 ), '21,12' ), array( 12, 21 ) );
ck( 'nothing inside the whitelist answers the whole whitelist, so a probe learns nothing', narrow( array( 12, 21 ), '99999' ), array( 12, 21 ) );
ck( 'spaces around an ID are allowed', narrow( array( 12, 21 ), ' 12 , x' ), array( 12 ) );
ck( 'a negative, a decimal or a signed number is not a course ID', narrow( array( 12, 21 ), '-12,12.0,+21' ), array( 12, 21 ) );
ck( 'whitelist IDs stored as strings come back as integers', narrow( array( '12', '21' ), '21' ), array( 21 ) );
ck( 'a whitelist with a repeated ID lists it once', narrow( array( 12, 21, 12 ), '' ), array( 12, 21 ) );

printf( "\n%s (%d checks)\n", $fail ? sprintf( '%d FAILURE(S)', $fail ) : 'ALL PASS', $total ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text to a terminal.
exit( $fail ? 1 : 0 );
