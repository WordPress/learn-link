<?php
/**
 * Pure unit tests for compare-storage.php. No WordPress required.
 *
 * Run: php learn/learn-progress-api/tests/test-compare-storage-pure.php
 *
 * @package WPOrg_Learn\Learn_Progress
 */

namespace WPOrg_Learn\Learn_Progress\Storage_Compare\Tests;

use WPOrg_Learn\Learn_Progress\Storage_Compare;

define( 'LEARN_PROGRESS_COMPARE_STORAGE_LIBRARY', true );

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/compare-storage.php';

/**
 * Build a complete sample storage dump for the 5 representative learners (#3).
 *
 * @param string $repository   Storage repository header (`comments` or `custom_tables`).
 * @param string $generated_at Volatile timestamp placed at root and learner nodes.
 * @return array Sample dump array matching build_dump() structure.
 */
function make_sample_dump( $repository = 'comments', $generated_at = '2026-11-18T10:00:00Z' ) {
	$is_hpps = 'custom_tables' === $repository;

	return array(
		'generated_at' => $generated_at,
		'storage'      => array(
			'active_engine'                               => $repository,
			'experimental_progress_storage'               => $is_hpps,
			'experimental_progress_storage_synchronization' => $is_hpps,
			'experimental_progress_storage_repository'    => $repository,
		),
		'learners'     => array(
			'learnlink-learner-04' => array(
				'generated_at' => $generated_at,
				'courses'      => array(
					array(
						'course_id'        => 57,
						'status'           => 'not-started',
						'enrolled_at'      => '2026-10-17T06:09:57Z',
						'completed_at'     => null,
						'progress_percent' => 0,
						'lessons'          => array(
							array(
								'lesson_id'    => 58,
								'status'       => 'not-started',
								'completed_at' => null,
								'quiz'         => null,
							),
						),
					),
				),
			),
			'learnlink-learner-07' => array(
				'generated_at' => $generated_at,
				'courses'      => array(
					array(
						'course_id'        => 57,
						'status'           => 'in-progress',
						'enrolled_at'      => '2026-10-17T06:10:00Z',
						'completed_at'     => null,
						'progress_percent' => 50,
						'lessons'          => array(
							array(
								'lesson_id'    => 58,
								'status'       => 'complete',
								'completed_at' => '2026-10-17T07:00:00Z',
								'quiz'         => null,
							),
						),
					),
				),
			),
			'learnlink-learner-11' => array(
				'generated_at' => $generated_at,
				'courses'      => array(
					array(
						'course_id'        => 57,
						'status'           => 'in-progress',
						'enrolled_at'      => '2026-10-17T06:12:00Z',
						'completed_at'     => null,
						'progress_percent' => 13,
						'lessons'          => array(
							array(
								'lesson_id'    => 58,
								'status'       => 'complete',
								'completed_at' => '2026-10-17T07:10:00Z',
								'quiz'         => null,
							),
							array(
								'lesson_id'    => 59,
								'status'       => 'in-progress',
								'completed_at' => null,
								'quiz'         => array(
									'quiz_id'          => 60,
									'status'           => 'failed',
									'grade_percent'    => 40,
									'passmark_percent' => 70,
									'graded_at'        => '2026-10-17T07:15:00Z',
								),
							),
						),
					),
				),
			),
			'learnlink-learner-14' => array(
				'generated_at' => $generated_at,
				'courses'      => array(
					array(
						'course_id'        => 57,
						'status'           => 'in-progress',
						'enrolled_at'      => '2026-10-17T06:14:00Z',
						'completed_at'     => null,
						'progress_percent' => 50,
						'lessons'          => array(
							array(
								'lesson_id'    => 61,
								'status'       => 'ungraded',
								'completed_at' => null,
								'quiz'         => array(
									'quiz_id'          => 62,
									'status'           => 'ungraded',
									'grade_percent'    => null,
									'passmark_percent' => 70,
									'graded_at'        => null,
								),
							),
						),
					),
				),
			),
			'learnlink-learner-17' => array(
				'generated_at' => $generated_at,
				'courses'      => array(
					array(
						'course_id'        => 57,
						'status'           => 'complete',
						'enrolled_at'      => '2026-10-17T06:16:00Z',
						'completed_at'     => '2026-10-18T09:30:00Z',
						'progress_percent' => 100,
						'lessons'          => array(
							array(
								'lesson_id'    => 58,
								'status'       => 'complete',
								'completed_at' => '2026-10-18T09:00:00Z',
								'quiz'         => array(
									'quiz_id'          => 63,
									'status'           => 'passed',
									'grade_percent'    => 100,
									'passmark_percent' => 80,
									'graded_at'        => '2026-10-18T09:30:00Z',
								),
							),
						),
					),
				),
			),
		),
	);
}

// 1. Target learners list matches the 5 representative learners in #3.
\ck(
	'target_learners() lists the 5 representative seeded learners',
	Storage_Compare\target_learners(),
	array(
		'learnlink-learner-04',
		'learnlink-learner-07',
		'learnlink-learner-11',
		'learnlink-learner-14',
		'learnlink-learner-17',
	)
);

// 2. Identical dumps produce zero differences.
$comments_dump = make_sample_dump( 'comments', '2026-11-18T10:00:00Z' );
$identical     = make_sample_dump( 'comments', '2026-11-18T10:00:00Z' );
\ck(
	'identical dumps produce zero differences',
	Storage_Compare\compare_dumps( $comments_dump, $identical ),
	array()
);

// 3. Different generated_at timestamps and storage header are ignored.
$hpps_dump = make_sample_dump( 'custom_tables', '2026-11-18T11:45:12Z' );
$hpps_dump['learners']['learnlink-learner-04']['generated_at'] = '2026-11-18T11:45:13Z';
\ck(
	'different generated_at timestamps and storage header produce zero differences',
	Storage_Compare\compare_dumps( $comments_dump, $hpps_dump ),
	array()
);

// 4. strip_volatile_fields preserves progress date fields while removing generated_at.
$stripped = Storage_Compare\strip_volatile_fields( $comments_dump );
\ck( 'strip_volatile_fields() removes root generated_at', array_key_exists( 'generated_at', $stripped ), false );
\ck(
	'strip_volatile_fields() removes learner generated_at',
	array_key_exists( 'generated_at', $stripped['learners']['learnlink-learner-17'] ),
	false
);
\ck(
	'strip_volatile_fields() keeps course enrolled_at',
	$stripped['learners']['learnlink-learner-17']['courses'][0]['enrolled_at'],
	'2026-10-17T06:16:00Z'
);

// 5. Difference in course status is detected with exact field path.
$status_diff_dump                                                   = make_sample_dump( 'custom_tables' );
$status_diff_dump['learners']['learnlink-learner-07']['courses'][0]['status'] = 'complete';
$status_diffs = Storage_Compare\compare_dumps( $comments_dump, $status_diff_dump );
\ck( 'course status mismatch yields 1 diff', count( $status_diffs ), 1 );
\ck(
	'course status mismatch identifies learners.learnlink-learner-07.courses[0].status',
	0 === strpos( $status_diffs[0], 'learners.learnlink-learner-07.courses[0].status:' ),
	true
);

// 6. Difference in lesson completed_at and course progress_percent identifies both exact paths.
$lesson_diff_dump = make_sample_dump( 'custom_tables' );
$lesson_diff_dump['learners']['learnlink-learner-11']['courses'][0]['progress_percent']         = 25;
$lesson_diff_dump['learners']['learnlink-learner-11']['courses'][0]['lessons'][1]['completed_at'] = '2026-10-17T07:20:00Z';
$lesson_diffs = Storage_Compare\compare_dumps( $comments_dump, $lesson_diff_dump );
\ck( 'progress_percent + lesson completed_at mismatch yields 2 diffs', count( $lesson_diffs ), 2 );
\ck(
	'first diff identifies learners.learnlink-learner-11.courses[0].progress_percent',
	0 === strpos( $lesson_diffs[0], 'learners.learnlink-learner-11.courses[0].progress_percent:' ),
	true
);
\ck(
	'second diff identifies learners.learnlink-learner-11.courses[0].lessons[1].completed_at',
	0 === strpos( $lesson_diffs[1], 'learners.learnlink-learner-11.courses[0].lessons[1].completed_at:' ),
	true
);

// 7. Difference in quiz grade_percent is detected with exact field path.
$quiz_diff_dump = make_sample_dump( 'custom_tables' );
$quiz_diff_dump['learners']['learnlink-learner-14']['courses'][0]['lessons'][0]['quiz']['grade_percent'] = 85;
$quiz_diffs = Storage_Compare\compare_dumps( $comments_dump, $quiz_diff_dump );
\ck( 'quiz grade_percent mismatch yields 1 diff', count( $quiz_diffs ), 1 );
\ck(
	'quiz grade_percent mismatch identifies exact path',
	0 === strpos( $quiz_diffs[0], 'learners.learnlink-learner-14.courses[0].lessons[0].quiz.grade_percent:' ),
	true
);

// 8. Missing learner is reported with exact learner path.
$missing_learner_dump = make_sample_dump( 'custom_tables' );
unset( $missing_learner_dump['learners']['learnlink-learner-17'] );
$missing_diffs = Storage_Compare\compare_dumps( $comments_dump, $missing_learner_dump );
\ck( 'missing learner yields 1 diff', count( $missing_diffs ), 1 );
\ck(
	'missing learner identifies learners.learnlink-learner-17',
	0 === strpos( $missing_diffs[0], 'learners.learnlink-learner-17:' ),
	true
);

// 9. Empty courses list vs populated courses list detects missing courses[0].
$empty_courses_dump                                         = make_sample_dump( 'custom_tables' );
$empty_courses_dump['learners']['learnlink-learner-04']['courses'] = array();
$course_list_diffs = Storage_Compare\compare_dumps( $comments_dump, $empty_courses_dump );
\ck( 'empty courses array vs populated yields 1 diff', count( $course_list_diffs ), 1 );
\ck(
	'empty courses array identifies learners.learnlink-learner-04.courses[0]',
	0 === strpos( $course_list_diffs[0], 'learners.learnlink-learner-04.courses[0]:' ),
	true
);

\test_summary();
