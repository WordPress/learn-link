<?php
/**
 * Shared test helpers for Learn Progress API tests.
 */

$test_passes = 0;
$test_fails  = 0;

/**
 * Check if actual value equals expected value.
 *
 * @param string $label    Test assertion description.
 * @param mixed  $actual   Actual result.
 * @param mixed  $expected Expected result.
 * @return bool True if test passed, false otherwise.
 */
function ck( $label, $actual, $expected ) {
	if ( ! isset( $GLOBALS['test_passes'] ) ) {
		$GLOBALS['test_passes'] = 0;
		$GLOBALS['test_fails']  = 0;
	}

	if ( $actual === $expected ) {
		$GLOBALS['test_passes']++;
		echo "[PASS] {$label}\n";
		return true;
	}

	$GLOBALS['test_fails']++;
	$actual_str   = var_export( $actual, true );
	$expected_str = var_export( $expected, true );
	echo "[FAIL] {$label}\n  Expected: {$expected_str}\n  Actual:   {$actual_str}\n";
	return false;
}

/**
 * Print test summary and exit with status code.
 *
 * @return void
 */
function test_summary() {
	$passes = $GLOBALS['test_passes'] ?? 0;
	$fails  = $GLOBALS['test_fails'] ?? 0;
	$total  = $passes + $fails;

	echo "\n";
	if ( 0 === $fails ) {
		echo "ALL PASS ({$total} checks)\n";
		return;
	}

	echo "FAILED ({$fails} of {$total} checks failed)\n";
}
