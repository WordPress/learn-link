<?php
/**
 * Clients, grants and tokens, on the local Learn site with the seeded learners.
 *
 * Needs the plugin active and the seeds loaded. It uses its own client ID and deletes every grant
 * and user it creates. It prints no token or code.
 *
 * Run: studio wp eval-file wp-content/plugins/learn-progress-api/tests/integration-grants.php
 *
 * @package WPOrg_Learn\Learn_Progress
 */

use WPOrg_Learn\Learn_Progress\Clients;
use WPOrg_Learn\Learn_Progress\Grants;
use WPOrg_Learn\Learn_Progress\Tokens;

require_once __DIR__ . '/lib.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$client_id  = 'test-integration-grants';
$challenge  = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
$learner_07 = get_user_by( 'login', 'learnlink-learner-07' );
$learner_08 = get_user_by( 'login', 'learnlink-learner-08' );
$valid      = array(
	'name'          => 'Test client',
	'redirect_uris' => array( 'http://localhost:1/callback' ),
	'courses'       => array( 12, 21 ),
);

ck( 'preconditions: the seeded learners 07 and 08', array( (bool) $learner_07, (bool) $learner_08 ), array( true, true ) );

/**
 * Clients\get() for a client entry supplied through the filter.
 *
 * @param mixed $entry The entry.
 *
 * @return array|null
 */
function client_with( $entry ) {
	$filter = function () use ( $entry ) {
		return array( 'candidate' => $entry );
	};

	add_filter( 'learn_progress_clients', $filter, 99 );
	$client = Clients\get( 'candidate' );
	remove_filter( 'learn_progress_clients', $filter, 99 );

	return $client;
}

// Clients: a malformed entry is no client at all.
ck( 'a valid client is returned as it is', client_with( $valid ), $valid );
$invalid = array(
	'not an array'                 => 'client',
	'no name'                      => array_diff_key( $valid, array( 'name' => 1 ) ),
	'no redirect_uris'             => array_diff_key( $valid, array( 'redirect_uris' => 1 ) ),
	'empty redirect_uris'          => array_merge( $valid, array( 'redirect_uris' => array() ) ),
	'redirect_uris with a number'  => array_merge( $valid, array( 'redirect_uris' => array( 1 ) ) ),
	'redirect_uris as a string'    => array_merge( $valid, array( 'redirect_uris' => 'http://localhost:1/callback' ) ),
	'no courses'                   => array_diff_key( $valid, array( 'courses' => 1 ) ),
	'courses as strings'           => array_merge( $valid, array( 'courses' => array( '12' ) ) ),
	'courses that are not a list'  => array_merge( $valid, array( 'courses' => array( 'a' => 12 ) ) ),
);
foreach ( $invalid as $label => $entry ) {
	ck( "a client with {$label} gives null", client_with( $entry ), null );
}

// The post type is exposed nowhere.
wp_set_current_user( 0 );
$type_object = get_post_type_object( Grants\POST_TYPE );
ck( 'the post type is not public, has no screen and is not in REST', array( $type_object->public, $type_object->show_ui, $type_object->show_in_rest ), array( false, false, false ) );
ck( 'the post type has its own capabilities', $type_object->cap->edit_posts, 'edit_' . Grants\POST_TYPE . 's' );
$types = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/types' ) )->get_data();
ck( 'the type is not listed at /wp/v2/types', isset( $types[ Grants\POST_TYPE ] ), false );
$route = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . Grants\POST_TYPE ) );
ck( 'the type has no route under /wp/v2', array( $route->get_status(), $route->get_data()['code'] ), array( 404, 'rest_no_route' ) );

// I-L04: create, find, alter, delete.
$code  = Grants\create( $learner_07->ID, $client_id, array( '12', 21 ), $challenge );
$grant = is_string( $code ) ? Tokens\find_grant_by_code( $code ) : null;
ck( 'I-L04 create() returns a well-formed code', is_string( $code ) && 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/D', $code ), true );
ck( 'I-L04 the code finds its grant', is_int( $grant ), true );

$grant_post = get_post( $grant );
ck( 'the grant is private, and its author is the learner', array( $grant_post->post_type, $grant_post->post_status, (int) $grant_post->post_author ), array( Grants\POST_TYPE, 'private', (int) $learner_07->ID ) );
ck( 'the courses are stored as integers', get_post_meta( $grant, '_courses', true ), array( 12, 21 ) );
ck( 'the challenge is stored', get_post_meta( $grant, '_code_challenge', true ), $challenge );
ck( 'the stored code is its keyed hash', get_post_meta( $grant, '_code_hash', true ), Tokens\hash_token( $code ) );
$expires_in = (int) get_post_meta( $grant, '_code_expires', true ) - time();
ck( 'the code expires in 10 minutes', $expires_in > 590 && $expires_in <= 600, true );
ck( 'for_user() lists the grant', in_array( $grant, Grants\for_user( $learner_07->ID ), true ), true );

$token = Tokens\mint();
update_post_meta( $grant, '_token_hash', Tokens\hash_token( $token ) );
ck( 'I-L04 the token finds its grant', Tokens\find_grant_by_token( $token ), $grant );
$altered = substr( $token, 0, -1 ) . ( 'A' === substr( $token, -1 ) ? 'B' : 'A' );
ck( 'I-L04 one altered character finds nothing', Tokens\find_grant_by_token( $altered ), null );
ck( 'an unknown token finds nothing', Tokens\find_grant_by_token( Tokens\mint() ), null );
ck( 'a code is not accepted as a token', Tokens\find_grant_by_token( $code ), null );
ck( 'a token is not accepted as a code', Tokens\find_grant_by_code( $token ), null );

// SEC-17: only hashes reach the database.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A test that must read the raw table.
$in_meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s OR meta_value LIKE %s", '%' . $wpdb->esc_like( $token ) . '%', '%' . $wpdb->esc_like( $code ) . '%' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A test that must read the raw table.
$in_posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE %s OR post_content LIKE %s", '%' . $wpdb->esc_like( $token ) . '%', '%' . $wpdb->esc_like( $code ) . '%' ) );
ck( 'SEC-17 no stored value contains an issued token or code', array( $in_meta, $in_posts ), array( 0, 0 ) );

$summary = Grants\summary( $grant );
ck( 'summary() has only the four public fields', array_keys( $summary ), array( 'client_id', 'courses', 'created', 'last_used' ) );
ck( 'summary() contains no hash', false === strpos( wp_json_encode( $summary ), Tokens\hash_token( $token ) ) && false === strpos( wp_json_encode( $summary ), Tokens\hash_token( $code ) ), true );

Grants\revoke( $grant );
ck( 'revoke() records when', (int) get_post_meta( $grant, '_revoked_at', true ) > 0, true );

// A new approval replaces the old grant.
$second_code  = Grants\create( $learner_07->ID, $client_id, array( 12 ), $challenge );
$second_grant = Tokens\find_grant_by_code( $second_code );
ck( 'a second approval leaves one grant for that learner and client', Grants\for_client( $learner_07->ID, $client_id ), array( $second_grant ) );
ck( 'the old code and token no longer find anything', array( Tokens\find_grant_by_code( $code ), Tokens\find_grant_by_token( $token ) ), array( null, null ) );

$other_client_code  = Grants\create( $learner_07->ID, $client_id . '-2', array( 12 ), $challenge );
$other_client_grant = Tokens\find_grant_by_code( $other_client_code );
ck( 'a grant for another client is left alone', Grants\for_client( $learner_07->ID, $client_id ), array( $second_grant ) );
ck( 'and that other grant exists', Grants\is_grant( $other_client_grant ), true );
Grants\delete( $other_client_grant );

// A failure never costs the learner the connection they have.
add_filter( 'wp_insert_post_empty_content', '__return_true' );
$failed = Grants\create( $learner_07->ID, $client_id, array( 12 ), $challenge );
remove_filter( 'wp_insert_post_empty_content', '__return_true' );
ck( 'a failed insert returns a WP_Error', is_wp_error( $failed ), true );
ck( 'a failed insert keeps the previous grant', Grants\for_client( $learner_07->ID, $client_id ), array( $second_grant ) );

$refuse_meta = function ( $check, $object_id, $meta_key ) {
	return '_code_hash' === $meta_key ? false : $check;
};
add_filter( 'add_post_metadata', $refuse_meta, 10, 3 );
$failed = Grants\create( $learner_07->ID, $client_id, array( 12 ), $challenge );
remove_filter( 'add_post_metadata', $refuse_meta, 10 );
ck( 'a failed meta write returns a WP_Error', is_wp_error( $failed ), true );
ck( 'a failed meta write leaves no half grant, and keeps the previous one', Grants\for_client( $learner_07->ID, $client_id ), array( $second_grant ) );

// A wrong ID can never touch another post.
$lesson = get_posts(
	array(
		'post_type'   => 'lesson',
		'numberposts' => 1,
		'fields'      => 'ids',
	)
);
Grants\revoke( $lesson[0] );
ck( 'delete() refuses a post that is not a grant', Grants\delete( $lesson[0] ), false );
ck( 'and that post is still there, with no _revoked_at', array( get_post_type( $lesson[0] ), get_post_meta( $lesson[0], '_revoked_at', true ) ), array( 'lesson', '' ) );
ck( 'summary() of a post that is not a grant is empty', Grants\summary( $lesson[0] ), array() );

ck( 'delete() removes a grant', Grants\delete( $second_grant ), true );
ck( 'a deleted grant is no longer found', Tokens\find_grant_by_code( $second_code ), null );

// A grant must never change hands with a reassignment: the old token would read the new author.
$user_args = array(
	'user_login' => 'learnlink-grants-test',
	'user_pass'  => wp_generate_password(),
	'user_email' => 'learnlink-grants-test@example.test',
);
$temp_user = wp_insert_user( $user_args );
Grants\create( $temp_user, $client_id, array( 12 ), $challenge );
$before = Grants\for_client( $learner_08->ID, $client_id );
wp_delete_user( $temp_user, $learner_08->ID );
ck( 'deleting a user with a reassignment hands no grant to the new author', Grants\for_client( $learner_08->ID, $client_id ), $before );

$temp_user = wp_insert_user( $user_args );
Grants\create( $temp_user, $client_id, array( 12 ), $challenge );
do_action( 'remove_user_from_blog', $temp_user, get_current_blog_id(), 0 );
ck( 'removal from a site without a reassignment keeps the grant', count( Grants\for_client( $temp_user, $client_id ) ), 1 );
do_action( 'remove_user_from_blog', $temp_user, get_current_blog_id(), $learner_08->ID );
ck( 'removal from a site with a reassignment deletes the grant first', Grants\for_client( $temp_user, $client_id ), array() );

Grants\create( $temp_user, $client_id, array( 12 ), $challenge );
Grants\create( $temp_user, $client_id . '-2', array( 12 ), $challenge );
ck( 'delete_for_user() deletes them all and counts them', Grants\delete_for_user( $temp_user ), 2 );
wp_delete_user( $temp_user );
ck( 'the test user is gone', get_user_by( 'login', 'learnlink-grants-test' ), false );

test_summary();
