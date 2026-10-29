<?php
/**
 * Storage Engine Comparison CLI Script (Skeleton).
 *
 * Compares output between traditional WordPress comment storage and
 * Sensei LMS High-Performance Progress Storage (HPPS).
 *
 * In Phase 1 (R1.1 / ISS-LL-04), this file provides the test scaffolding and
 * documents the technical limitations.
 * Full execution across twin sites (comentarios vs HPPS) is scheduled for Phase 4 (R4.1 / P24).
 *
 * Usage with Studio WP-CLI:
 *   studio wp eval-file wp-content/plugins/learn-progress-api/tests/compare-storage.php --path="C:\Studio\learn-link-learn"
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "This script must be run within a WordPress environment (e.g., via WP-CLI).\n";
	exit( 1 );
}

echo "=== Learn Progress API: Storage Comparison Helper ===\n";

// 1. Detect current storage mode.
$settings = get_option( 'sensei-settings', array() );
$has_hpps = ! empty( $settings['experimental_progress_storage'] );
$sync_on  = ! empty( $settings['experimental_progress_storage_synchronization'] );

echo 'Current Sensei Engine: ' . ( $has_hpps ? 'HPPS (Experimental Tables)' : 'Traditional (wp_comments)' ) . "\n";
echo 'Synchronization:      ' . ( $sync_on ? 'Enabled' : 'Disabled' ) . "\n\n";

// 2. Technical Limitation Notice for Phase 1.
echo "LIMITATION NOTICE:\n";
echo "Enabling HPPS in an existing site requires full table migration.\n";
echo "Directly flipping 'experimental_progress_storage' on a seeded comments database\n";
echo "without migration leaves HPPS tables empty and leads to inconsistent progress reads.\n\n";

// 3. Execution condition.
if ( ! function_exists( 'WPOrg_Learn\Learn_Progress\Reader\read_course' ) ) {
	echo "Status: READER NOT LOADED.\n";
	echo "The reader function (WPOrg_Learn\\Learn_Progress\\Reader\\read_course) will be implemented in R1.2 (P05).\n";
	echo "Once R1.2 is completed and twin sites are configured in R4.1 (P24), this script will export\n";
	echo "and compare the 20 seeded learners across both storage engines.\n";
	exit( 0 );
}

echo "Reader function detected. Ready for Phase 4 comparison execution.\n";
