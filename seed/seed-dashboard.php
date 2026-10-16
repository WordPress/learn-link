<?php
/**
 * Learn Link: seed one invented student and one invented mentor on a LOCAL Dashboard site.
 * Run with: wp eval-file seed-dashboard.php
 * Invented data only. Never run this on wordpresseducation.org.
 */
if ( 'wordpresseducation.org' === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	WP_CLI::error( 'Refusing to seed the live site.' );
}

function ll_seed_user( $login, $email, $name, $role ) {
	$user = get_user_by( 'login', $login );
	$id   = $user ? $user->ID : wp_insert_user( array(
		'user_login'   => $login,
		'user_email'   => $email,
		'display_name' => $name,
		'user_pass'    => wp_generate_password( 24 ),
		'role'         => $role,
	) );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	return $id;
}

$record = 'recLEARNLINK00001'; // An Airtable-style ID: "rec" and 14 letters or digits.
$start  = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
$end    = gmdate( 'Y-m-d', strtotime( '+60 days' ) );

$student = ll_seed_user( 'learnlink-student-01', 'student01@example.test', 'Ada Example', 'wpcpm_student' );
update_user_meta( $student, 'wpcpm_student_record_id', $record );
update_user_meta( $student, 'wpcpm_student_program', array(
	'record_id' => $record,
	'name'      => 'Ada Example',
	'email'     => 'student01@example.test',
	'program'   => 'In Sensei',
	'status'    => 'In Sensei',
	'is_past'   => false,
	'start'     => $start,
	'end'       => $end,
	'username'  => 'learnlink-student-01',
	'profile'   => 'https://profiles.wordpress.org/learnlink-student-01/',
	'institution' => 'Example University',
) );

$mentor = ll_seed_user( 'learnlink-mentor-01', 'mentor01@example.test', 'Grace Example', 'wpcpm_mentor' );
update_user_meta( $mentor, 'wpcpm_mentees', array(
	array(
		'record_id'   => $record,
		'name'        => 'Ada Example',
		'email'       => 'student01@example.test',
		'status'      => 'In Sensei',
		'is_past'     => false,
		'start'       => $start,
		'end'         => $end,
		'username'    => 'learnlink-student-01',
		'profile'     => 'https://profiles.wordpress.org/learnlink-student-01/',
		'institution' => 'Example University',
		'team'        => 'Training',
		'slack'       => '',
		'website'     => '',
		'hours'       => 12,
	),
) );
// The student's copy of their mentor's card (the shape WPCPM_Students_Sync writes).
update_user_meta( $student, 'wpcpm_student_mentor', array(
	'record_id' => 'recLEARNLINKM0001',
	'name'      => 'Grace Example',
	'email'     => 'mentor01@example.test',
	'username'  => 'learnlink-mentor-01',
	'profile'   => 'https://profiles.wordpress.org/learnlink-mentor-01/',
	'slack'     => '',
	'website'   => '',
	'teams'     => array(),
	'avatar'    => '',
) );
update_user_meta( $student, 'wpcpm_student_active', 1 );

WP_CLI::success( "Student user $student and mentor user $mentor are ready." );
