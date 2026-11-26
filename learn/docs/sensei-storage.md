# Sensei LMS Storage and Date Mapping Analysis

## Overview

This document establishes the verified data mapping between the contract specification for **Learn Link** (`courses[]`), Sensei LMS internal data structures in WordPress, and the RFC 3339 UTC date normalization rules.

Empirical verification was conducted against the reference WordPress Studio environment running **Sensei LMS 4.26.4** with PHP 8.4 on the local Learn development site.

---

## 1. Progress Storage Mechanism (Verified)

Inspection of `sensei-settings`:
```json
{"course_page":5,"my_course_page":6,"course_completed_page":7}
```
Because `experimental_progress_storage` (High-Performance Progress Storage / HPPS) is absent, Sensei LMS operates strictly under the **traditional comment-based storage engine**:
- Progress records are stored as rows in `wp_comments`.
- Progress metadata is stored in `wp_commentmeta`.
- Course enrollment relationships utilize the `sensei_learner` taxonomy and caching structures.

---

## 2. Field Mapping Matrix

| Contract Field | Public Sensei / WP Function | Underlying Database Source | Stored Time Zone | Target Conversion | Notes |
|---|---|---|---|---|---|
| `courses[].course_id` | Parameter / Post ID | `wp_posts.ID` (`post_type = 'course'`) | N/A | Integer cast | Direct identifier. |
| `courses[].status` | Calculated via `Sensei_Utils::user_completed_course()` and `Sensei_Utils::has_started_course()` | `wp_comments.comment_approved` (`type = 'sensei_course_status'`) | N/A | String (`not-started`, `in-progress`, `complete`) | Contract rule: course status is strictly **calculated** based on completion and lesson facts, never copied verbatim. |
| `courses[].enrolled_at` | `Sensei()->course_progress_repository->get( $course_id, $user_id )->get_started_at()` | Comments: `wp_commentmeta.meta_key = 'start'` on `sensei_course_status`. Tables: `started_at` | Comments: Site Local Time. Tables: UTC | `DateTimeInterface` &rarr; RFC 3339 UTC (`Z`) | Read through the repository because the progress ID from `has_started_course()` is a table row ID on High-Performance Progress Storage, so it must not be passed to `get_comment_meta()`. `null` when there is no progress record. |
| `courses[].completed_at` | `Sensei()->course_progress_repository->get( $course_id, $user_id )->get_completed_at()` | Comments: `wp_comments.comment_date` on `sensei_course_status`. Tables: `completed_at` | Comments: Site Local Time. Tables: UTC | `DateTimeInterface` &rarr; RFC 3339 UTC (`Z`) | Read through the repository, as for `enrolled_at`. Only populated when status is `complete`. Null otherwise. |
| `courses[].progress_percent`| Calculated via `intdiv( 200 * $done + $total, 2 * $total )` | Count of completed lessons vs published lessons | N/A | Integer `0`-`100` | Calculated pure logic (Contract §6.5). |
| `lessons[].lesson_id` | Post ID | `wp_posts.ID` (`post_type = 'lesson'`) | N/A | Integer cast | Direct identifier. |
| `lessons[].status` | `Sensei_Utils::user_lesson_status()` | `wp_comments.comment_approved` (`type = 'sensei_lesson_status'`) | N/A | String (`not-started`, `in-progress`, `complete`, `ungraded`) | Follows state machine mapping defined in #1 and #4. |
| `lessons[].completed_at` | `$comment->comment_date_gmt` | `wp_comments.comment_date_gmt` | UTC | `Dates\from_gmt( $mysql )` &rarr; RFC 3339 UTC (`Z`) | Populated when the lesson maps to `complete` in the contract (Sensei `complete`, `graded`, `passed`, or `failed` with no pass required). Null otherwise. |
| `quiz.quiz_id` | `Sensei()->lesson->lesson_quizzes( $lesson_id )` / Post ID | `wp_posts.ID` (`post_type = 'quiz'`) | N/A | Integer cast | Only present if quiz has questions (`Sensei_Lesson::lesson_quiz_has_questions()`). Null object otherwise. |
| `quiz.status` | Derived from quiz metadata and lesson comment | `wp_comments.comment_approved` + `wp_postmeta._pass_required` | N/A | String (`not-taken`, `ungraded`, `graded`, `passed`, `failed`) | If marked without passmark requirement, maps to `graded` (never `passed` or `failed` without required pass; Contract review #1). |
| `quiz.grade_percent` | `Sensei_Quiz::get_user_quiz_grade()` | `wp_commentmeta.meta_key = 'grade'` on lesson comment | N/A | Integer `0`-`100` or `null` | Returns rounded integer grade percentage, or null until graded. |
| `quiz.passmark_percent` | `get_post_meta( $quiz_id, '_quiz_passmark', true )` | `wp_postmeta.meta_key = '_quiz_passmark'` | N/A | Integer `0`-`100` or `null` | Integer exactly when a pass is required (`_pass_required = 'on'`), and null otherwise, even if `_quiz_passmark` holds an old value (Contract review #1). Always null for `graded`. |
| `quiz.graded_at` | `$comment->comment_date_gmt` | `wp_comments.comment_date_gmt` | UTC | `Dates\from_gmt( $mysql )` &rarr; RFC 3339 UTC (`Z`) | Timestamp of the lesson status comment transition when graded or completed. |

---

## 3. Empirical Verification Cases

### 3.1 Enrollment Without Activity (Learners 04-06)
- **Learners checked:** `learnlink-learner-04` (ID 5), `05` (ID 6), `06` (ID 7).
- **Observed DB State:**
  - `wp_comments` contains a single record per learner with `comment_type = 'sensei_course_status'` for course 57.
  - `comment_approved` is `in-progress`.
  - `wp_commentmeta` contains `meta_key = 'start'` holding local timestamp `2026-10-17 00:09:57` and `percent = 0`.
  - Zero `sensei_lesson_status` comments exist.
- **Conclusion:** Confirms that `enrolled_at` is reliably derived from `start` on the course status comment.

### 3.2 Graded Quizzes and Date Transitions (Learners 17-20)
- **Learners checked:** `learnlink-learner-17` (ID 18) to `20` (ID 21).
- **Observed DB State:**
  - `wp_comments` contains `sensei_lesson_status` comments with `comment_approved = 'passed'` or `'complete'`.
  - `wp_commentmeta` stores:
    - `meta_key = 'start'`: lesson start time (local).
    - `meta_key = 'grade'`: quiz score (e.g., `100`).
    - `meta_key = 'quiz_grades'`: question-level grading serialization.
  - `comment_date_gmt` records the exact UTC moment when the quiz was submitted and approved/graded.
- **Conclusion:** `graded_at` maps directly to `comment_date_gmt` formatted via `Dates\from_gmt()`.

### 3.3 Enrollment Cache and Mutation Behavior (`Sensei_Course::is_user_enrolled`)
- In Sensei 4.26.4, `Sensei_Course::is_user_enrolled( $course_id, $user_id )` delegates to `Sensei_Course_Enrolment::is_enrolled()`.
- The method checks for cached enrolment results in user meta (`get_enrolment_check_results( $user_id )`).
- **Mutation warning for read operations:** When the stored enrollment result is missing or stale (version hash mismatch), `is_enrolled()` queries enrolment providers and invokes `save_enrolment( $user_id, $is_enrolled )`.
- If the user is enrolled, `save_enrolment()` calls `Sensei_Utils::user_start_course()`, sets the `sensei_learner` taxonomy term, and fires action hooks.
- Therefore, `is_user_enrolled()` can perform database writes if enrolment cache is absent. This behavior is critical for the read function in #14, which must remain strictly read-only.

---

## 4. Date Normalization Summary

- **MySQL GMT/UTC (`comment_date_gmt`):** Handled by `WPOrg_Learn\Learn_Progress\Dates\from_gmt()`.
- **MySQL Local (`start` meta):** Handled by `WPOrg_Learn\Learn_Progress\Dates\from_local()`, defaulting to site timezone via `wp_timezone()`.
- **Empty / Zero / Invalid values:** Guaranteed to return strict `null`. Both functions validate that the parsed date matches the exact input representation without month/day overflow (e.g., `2026-02-31` returns `null`).
- **Output Standard:** RFC 3339 UTC format (`Y-m-d\TH:i:s\Z`).

---

## 5. Storage Engine Comparison and Verified Sensei Configuration (HPPS vs Comments)

### 5.1 Objective
The purpose of `tests/compare-storage.php` is to verify the architectural requirement tracked in decision record 006 (#2): the progress reader functions must return identical contract JSON regardless of whether Sensei uses the legacy comment store (`wp_comments`) or High-Performance Progress Storage (HPPS custom tables).

### 5.2 Verification in Sensei LMS 4.26.4
Inspection of Sensei's `Progress_Storage_Configuration::resolve()` reveals that reads move to custom tables (`TABLES_BACKEND`) **only when all three settings are enabled concurrently**:
1. `experimental_progress_storage = true` (HPPS feature enabled)
2. `experimental_progress_storage_synchronization = true` (dual write synchronization enabled)
3. `experimental_progress_storage_repository = 'custom_tables'` (custom tables selected as active repository)

If any of these three flags is disabled or missing, Sensei automatically falls back to reading from `wp_comments` (`COMMENTS_BACKEND`).

### 5.3 Comparison Tool (`tests/compare-storage.php`) and Pure Tests
- **Migration Warning:** Enabling HPPS on an existing site with seeded comment records triggers or requires table population/migration and alters what Sensei reads. Never enable HPPS on the main local Learn development site.
- **Two Modes in `tests/compare-storage.php`:**
  - `dump` mode (inside WordPress via WP-CLI `wp eval-file`): Reads `sensei-settings` (`experimental_progress_storage`, `experimental_progress_storage_synchronization`, `experimental_progress_storage_repository`), resolves the test courses (`learn-link-test-course` and `learn-link-test-foundations`, falling back to published `course` posts by ID ascending), calls `Reader\read_course( $user_id, $course_id )` across the 5 representative seeded learners (`learnlink-learner-04`, `07`, `11`, `14`, and `17`), and emits JSON to a file or stdout.
  - `compare <a.json> <b.json>` mode (pure PHP CLI, no WordPress required): Uses `tests/lib.php` to verify all 5 representative learners are present and compares the decoded dumps field by field across courses, lessons, and quizzes while ignoring volatile `generated_at` timestamps and the `storage` settings header. Reports exact dot/bracket field paths for any mismatch (for example `learners.learnlink-learner-07.courses[0].status`).
- **Pure Unit Suite:** `php learn/learn-progress-api/tests/test-compare-storage-pure.php` verifies identical dumps, ignored `generated_at` / `storage` header differences, missing learners, empty course arrays, and exact diff path reporting without requiring WordPress.

### 5.4 Disposable Twin-Site Verification Procedure and Observed Results (#3)
Comparison verification was executed on a disposable WordPress Studio site running **WordPress 7.1.5**, **PHP 8.4**, and **Sensei LMS 4.27.0** (never enable HPPS on the main working Learn site):

1. Create a fresh disposable WordPress site and install **Sensei LMS 4.27.0** plus `learn-progress-api`.
2. Before running `seed/seed-learners.php`, enable HPPS + dual-write synchronization (`experimental_progress_storage = true`, `experimental_progress_storage_synchronization = true`), ensure Sensei's custom HPPS tables (`sensei_lms_progress`, `sensei_lms_quiz_submissions`, `sensei_lms_quiz_answers`, `sensei_lms_quiz_grades`) are created via `Sensei\Internal\Installer\Schema::create_tables()` with `sensei_feature_flag_tables_based_progress` active, and keep `experimental_progress_storage_repository = 'comments'` during CLI seeding so `Comment_Reading_Aggregate_Quiz_Progress_Repository::save()` dual-writes course, lesson, and quiz submission/grade records to both `wp_comments` and the HPPS custom tables.
   - Verified database row counts after `seed/seed-learners.php`: `sensei_lms_progress = 146` (`course`, `lesson`, and `quiz` rows), `sensei_lms_quiz_submissions = 36`, `sensei_lms_quiz_answers = 50`, `sensei_lms_quiz_grades = 43`, and `sensei_comments = 110`.
3. Switch `experimental_progress_storage_repository` to `'custom_tables'` (resolving `Progress_Storage_Configuration` to `tables` backend) and export the HPPS dump:
   ```bash
   wp eval-file wp-content/plugins/learn-progress-api/tests/compare-storage.php dump dump-custom-tables.json
   ```
4. Switch `experimental_progress_storage = false` and `experimental_progress_storage_repository = 'comments'` (resolving to `comments` backend) and export the comments baseline dump:
   ```bash
   wp eval-file wp-content/plugins/learn-progress-api/tests/compare-storage.php dump dump-comments.json
   ```
5. Compare both JSON dumps in pure PHP CLI:
   ```bash
   php learn/learn-progress-api/tests/compare-storage.php compare dump-custom-tables.json dump-comments.json
   ```
   - Observed result across `learnlink-learner-04` (`0%`), `07` (`50%` / `33%`), `11` (`13%`, failed quiz), `14` (`50%`, ungraded quiz), and `17` (`100%` / `100%`, complete with manual 90% quiz grade): **`ALL PASS (18 checks)`** (`0` field differences).
6. Delete the disposable site after capturing evidence.

### 5.5 Production Storage Question (`WordPress/Learn#3732`)
- **Production Learn repository status:** answered in `WordPress/Learn#3732` on 17 November 2026. `experimental_progress_storage` and its synchronization are off and the repository is `comments`, so learn.wordpress.org reads the comment storage, on the latest Sensei LMS. That is the store the team tested first, and 5.4 shows the tables give the same answer if that ever changes.
