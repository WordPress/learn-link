# Sensei LMS Storage and Date Mapping Analysis

## Overview

This document establishes the verified data mapping between the contract specification for **Learn Link** (`courses[]`), Sensei LMS internal data structures in WordPress, and the RFC 3339 UTC date normalization rules.

Empirical verification was conducted against the reference WordPress Studio environment running **Sensei LMS 4.26.4** with PHP 8.4 on the real site `C:\Studio\learn-link-learn`.

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
| `courses[].enrolled_at` | `get_comment_meta( $progress_id, 'start', true )` | `wp_commentmeta.meta_key = 'start'` on `sensei_course_status` | Site Local Time (`current_time('mysql')`) | `Dates\from_local( $mysql )` &rarr; RFC 3339 UTC (`Z`) | Sensei does not store an explicit enrollment timestamp; it creates the `sensei_course_status` comment upon enrollment with `meta_key = 'start'`. If not enrolled, `null`. |
| `courses[].completed_at` | `get_comment( $progress_id )->comment_date_gmt` | `wp_comments.comment_date_gmt` | UTC | `Dates\from_gmt( $mysql )` &rarr; RFC 3339 UTC (`Z`) | Only populated when status is `complete`. Null otherwise. |
| `courses[].progress_percent`| Calculated via `intdiv( 200 * $done + $total, 2 * $total ) or intdiv( $completed_lessons, $total_lessons ) * 100` | Count of completed lessons vs published lessons | N/A | Integer `0`–`100` | Calculated pure logic (Contract §6.5). |
| `lessons[].lesson_id` | Post ID | `wp_posts.ID` (`post_type = 'lesson'`) | N/A | Integer cast | Direct identifier. |
| `lessons[].status` | `Sensei_Utils::user_lesson_status()` | `wp_comments.comment_approved` (`type = 'sensei_lesson_status'`) | N/A | String (`not-started`, `in-progress`, `complete`, `ungraded`) | Follows state machine mapping defined in P01/P05. |
| `lessons[].completed_at` | `$comment->comment_date_gmt` | `wp_comments.comment_date_gmt` | UTC | `Dates\from_gmt( $mysql )` &rarr; RFC 3339 UTC (`Z`) | Populated when lesson status is `complete` or `passed`. |
| `quiz.quiz_id` | `Sensei()->lesson->lesson_quizzes( $lesson_id )` / Post ID | `wp_posts.ID` (`post_type = 'quiz'`) | N/A | Integer cast | Only present if quiz has questions (`Sensei_Lesson::lesson_quiz_has_questions()`). Null object otherwise. |
| `quiz.status` | Derived from quiz metadata and lesson comment | `wp_comments.comment_approved` + `wp_postmeta._pass_required` | N/A | String (`not-taken`, `ungraded`, `graded`, `passed`, `failed`) | If marked without passmark requirement, mapped to `graded` (or `passed` per P01/P05). |
| `quiz.grade_percent` | `Sensei_Quiz::get_user_quiz_grade()` | `wp_commentmeta.meta_key = 'grade'` on lesson comment | N/A | Integer `0`–`100` or `null` | Returns rounded integer grade percentage, or null until graded. |
| `quiz.passmark_percent` | `get_post_meta( $quiz_id, '_quiz_passmark', true )` | `wp_postmeta.meta_key = '_quiz_passmark'` | N/A | Integer `0`–`100` | Passing grade threshold configured for the quiz. |
| `quiz.graded_at` | `$comment->comment_date_gmt` | `wp_comments.comment_date_gmt` | UTC | `Dates\from_gmt( $mysql )` &rarr; RFC 3339 UTC (`Z`) | Approximated by the timestamp of the lesson status comment transition when graded/passed. |

---

## 3. Empirical Verification Cases

### 3.1 Enrollment Without Activity (Learners 04–06)
- **Learners checked:** `learnlink-learner-04` (ID 5), `05` (ID 6), `06` (ID 7).
- **Observed DB State:**
  - `wp_comments` contains a single record per learner with `comment_type = 'sensei_course_status'` for course 57.
  - `comment_approved` is `in-progress`.
  - `wp_commentmeta` contains `meta_key = 'start'` holding local timestamp `2026-10-17 00:09:57` and `percent = 0`.
  - Zero `sensei_lesson_status` comments exist.
- **Conclusion:** Confirms that `enrolled_at` is reliably derived from `start` on the course status comment.

### 3.2 Graded Quizzes and Date Transitions (Learners 17–20)
- **Learners checked:** `learnlink-learner-17` (ID 18) to `20` (ID 21).
- **Observed DB State:**
  - `wp_comments` contains `sensei_lesson_status` comments with `comment_approved = 'passed'` or `'complete'`.
  - `wp_commentmeta` stores:
    - `meta_key = 'start'`: lesson start time (local).
    - `meta_key = 'grade'`: quiz score (e.g., `100`).
    - `meta_key = 'quiz_grades'`: question-level grading serialization.
  - `comment_date_gmt` records the exact UTC moment when the quiz was submitted and approved/graded.
- **Conclusion:** `graded_at` maps directly to `comment_date_gmt` formatted via `Dates\from_gmt()`.

### 3.3 Enrollment Cache Behavior (`Sensei_Course::is_user_enrolled`)
- `Sensei_Course::is_user_enrolled( $course_id, $user_id )` utilizes WordPress object cache / transients.
- When evaluating enrollment, the function checks the `sensei_learner` taxonomy term for the course and verifies course status records.
- For read-only API requests, this provides consistent results without database write overhead.

---

## 4. Date Normalization Summary

- **MySQL GMT/UTC (`comment_date_gmt`):** Handled by `WPOrg_Learn\Learn_Progress\Dates\from_gmt()`.
- **MySQL Local (`start` meta):** Handled by `WPOrg_Learn\Learn_Progress\Dates\from_local()`, defaulting to site timezone via `wp_timezone()`.
- **Empty / Zero / Invalid values:** Guaranteed to return strict `null`.
- **Output Standard:** RFC 3339 UTC format (`Y-m-d\TH:i:s\Z`).

---

## 5. Storage Engine Comparison and Limitations (HPPS vs Comments)

### 5.1 Objective
The purpose of `tests/compare-storage.php` is to verify the core architectural decision **D-09**: the progress reader functions (`Sensei_Utils::user_lesson_status()`, etc.) must return identical contract JSON regardless of whether Sensei uses the legacy comment store (`wp_comments`) or High-Performance Progress Storage (HPPS custom tables).

### 5.2 Technical Limitation in Phase 1
- **Migration Requirement:** In Sensei LMS 4.26.4, enabling HPPS (`experimental_progress_storage = true`) on an existing site with seeded data does not automatically migrate historical comment rows to HPPS tables.
- Flipping this flag without running Sensei's migration routine leaves HPPS custom tables empty, corrupting progress readings for existing learners.
- **Phase 1 Strategy:**
  - `tests/compare-storage.php` is committed as a test scaffold and diagnostic script.
  - The formal comparison test will be executed in **Phase 4 (R4.1 / P24 / ISS-LL-24a)** using a clean twin site created specifically with HPPS and synchronization enabled *before* seeding data (`studio site create ... learn-link-hpps`).
