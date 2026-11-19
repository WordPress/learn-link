<?php
/**
 * Privacy tools (exporter and eraser) on the local Learn site with the seeded learners.
 *
 * Needs the plugin active and the seeds loaded. It uses its own client ID and removes every grant
 * it creates. It prints no token or code.
 *
 * Run: studio wp eval-file wp-content/plugins/learn-progress-api/tests/integration-privacy.php
 *
 * @package WPOrg_Learn\Learn_Progress
 */

use WPOrg_Learn\Learn_Progress\Grants;
use WPOrg_Learn\Learn_Progress\Privacy;
use WPOrg_Learn\Learn_Progress\Tokens;

require_once __DIR__ . '/lib.php';

$client_id  = 'test-integration-privacy';
$challenge  = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
$learner_07 = get_user_by( 'login', 'learnlink-learner-07' );

ck( 'preconditions: seeded learner 07 exists', $learner_07 instanceof WP_User, true );

// 1. Both privacy filters register the learn-progress-api callbacks.
$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
ck( 'exporter is registered under learn-progress-api', isset( $exporters['learn-progress-api']['callback'] ), true );
ck( 'eraser is registered under learn-progress-api', isset( $erasers['learn-progress-api']['callback'] ), true );

// 2. Find two real courses from the local Learn site (or fallback IDs).
$course_ids = get_posts(
	array(
		'post_type'   => 'course',
		'post_status' => 'publish',
		'numberposts' => 2,
		'fields'      => 'ids',
		'orderby'     => 'ID',
		'order'       => 'ASC',
	)
);
$course_ids = ! empty( $course_ids ) ? array_map( 'intval', $course_ids ) : array( 57, 59 );

$client_filter = function ( $clients ) use ( $client_id, $course_ids ) {
	$clients              = is_array( $clients ) ? $clients : array();
	$clients[ $client_id ] = array(
		'name'          => 'WordPress Credits Privacy Test',
		'redirect_uris' => array( 'http://localhost:1/callback' ),
		'courses'       => $course_ids,
	);

	return $clients;
};
add_filter( 'learn_progress_clients', $client_filter );

// Clean up any leftover grant for this test client before starting.
foreach ( Grants\for_client( (int) $learner_07->ID, $client_id ) as $existing_grant ) {
	Grants\delete( $existing_grant ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.FoundWithAlternative -- Grants\delete(), not PHP unlink().
}

$code  = Grants\create( (int) $learner_07->ID, $client_id, $course_ids, $challenge );
$grant = is_string( $code ) ? Tokens\find_grant_by_code( $code ) : null;
ck( 'preconditions: grant created for learner 07', is_int( $grant ), true );

$token      = Tokens\mint();
$token_hash = Tokens\hash_token( $token );
$code_hash  = (string) get_post_meta( $grant, '_code_hash', true );
$used_hash  = Tokens\hash_token( $code );
update_post_meta( $grant, '_token_hash', $token_hash );
update_post_meta( $grant, '_code_used_hash', $used_hash );

/**
 * Index exported item data pairs by field label.
 *
 * @param array $item Export item.
 *
 * @return array<string, string>
 */
function privacy_test_fields( array $item ): array {
	$fields = array();
	foreach ( $item['data'] as $pair ) {
		$fields[ $pair['name'] ] = (string) $pair['value'];
	}

	return $fields;
}

// 3. I-L09: Export learner grants with program, courses, and dates.
$export = Privacy\export( $learner_07->user_email );
ck( 'I-L09 export returns done=true', $export['done'], true );
ck( 'I-L09 export includes the created grant', count( $export['data'] ) >= 1, true );

$matching = array_values(
	array_filter(
		$export['data'],
		function ( $item ) use ( $grant ) {
			return isset( $item['item_id'] ) && ( 'learn-link-' . $grant ) === $item['item_id'];
		}
	)
);
ck( 'I-L09 export includes item_id learn-link-<grant>', count( $matching ), 1 );
ck( 'I-L09 export uses group_id learn-link-connections', $matching[0]['group_id'], 'learn-link-connections' );

$fields = privacy_test_fields( $matching[0] );
ck( 'I-L09 export includes client program name', $fields['Program'], 'WordPress Credits Privacy Test' );

$expected_titles = implode( ', ', array_map( 'get_the_title', $course_ids ) );
ck( 'I-L09 export includes course titles', $fields['Courses'], $expected_titles );
ck( 'I-L09 export includes non-empty Connected on date', '' !== $fields['Connected on'] && 'Unknown' !== $fields['Connected on'], true );
ck( 'I-L09 export shows Not read yet when _last_used is 0', $fields['Last read'], 'Not read yet' );
ck( 'active grant export omits Withdrawn on Learn', isset( $fields['Withdrawn on Learn'] ), false );

// 4. SEC-17: Exported payload never exposes raw secrets or stored hashes.
$json = (string) wp_json_encode( $export );
ck(
	'SEC-17 export contains no plain code or token and no hashes',
	false === strpos( $json, $code )
	&& false === strpos( $json, $token )
	&& false === strpos( $json, $code_hash )
	&& false === strpos( $json, $token_hash )
	&& false === strpos( $json, $used_hash ),
	true
);

// 5. Last read and Withdrawn on Learn timestamps appear when present.
update_post_meta( $grant, '_last_used', time() - 120 );
Grants\revoke( $grant );

$revoked_export   = Privacy\export( $learner_07->user_email );
$revoked_matching = array_values(
	array_filter(
		$revoked_export['data'],
		function ( $item ) use ( $grant ) {
			return isset( $item['item_id'] ) && ( 'learn-link-' . $grant ) === $item['item_id'];
		}
	)
);
$revoked_fields   = privacy_test_fields( $revoked_matching[0] );
ck( 'export formats Last read when _last_used is set', '' !== $revoked_fields['Last read'] && 'Not read yet' !== $revoked_fields['Last read'], true );
ck( 'revoked grant export includes Withdrawn on Learn', isset( $revoked_fields['Withdrawn on Learn'] ) && '' !== $revoked_fields['Withdrawn on Learn'], true );

// 6. Unregistered client falls back to client_id.
remove_filter( 'learn_progress_clients', $client_filter );
$fallback_export   = Privacy\export( $learner_07->user_email );
$fallback_matching = array_values(
	array_filter(
		$fallback_export['data'],
		function ( $item ) use ( $grant ) {
			return isset( $item['item_id'] ) && ( 'learn-link-' . $grant ) === $item['item_id'];
		}
	)
);
$fallback_fields   = privacy_test_fields( $fallback_matching[0] );
ck( 'export falls back to client_id when client is unregistered', $fallback_fields['Program'], $client_id );

// 7. Unknown email address returns empty export and zero-removed erase.
ck(
	'unknown email export returns empty data and done=true',
	Privacy\export( 'unknown-learn-link-user@example.test' ),
	array(
		'data' => array(),
		'done' => true,
	)
);
ck(
	'unknown email erase returns 0 removed and done=true',
	Privacy\erase( 'unknown-learn-link-user@example.test' ),
	array(
		'items_removed'  => 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	)
);

// 8. I-L09: Erase removes all grants for the learner.
$before_count = count( Grants\for_user( (int) $learner_07->ID ) );
$erased       = Privacy\erase( $learner_07->user_email );
ck( 'I-L09 erase returns expected items_removed count', $erased['items_removed'], $before_count );
ck( 'I-L09 erase sets items_retained=false, empty messages and done=true', array( $erased['items_retained'], $erased['messages'], $erased['done'] ), array( false, array(), true ) );
ck( 'I-L09 Grants\\for_user() is empty after erase', Grants\for_user( (int) $learner_07->ID ), array() );

test_summary();
