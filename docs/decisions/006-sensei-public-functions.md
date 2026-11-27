# 006. Read Sensei through its public functions, plus two read-only repository calls

- **Status:** Accepted
- **Date:** 2026-10-19
- **Decided by:** the team (Wilberth, Rafael and Pedro), technical guide section 6
- **Issue:** #2, #3

## Context

The Learn half has to read a learner's course and lesson status and quiz grades from Sensei LMS. Sensei can keep progress in comments (the traditional storage) or in its own tables (High-Performance Progress Storage), and its newer progress and quiz submission repositories, which work with both, are marked `@internal`.

We asked the Sensei team in [Automattic/sensei#8336](https://github.com/Automattic/sensei/issues/8336). On 19 October they answered that Sensei has no official REST API and that one is not a short-term goal; that the public functions we listed are generally suitable, but `Sensei_Utils::user_lesson_status()` still reads comments directly; and that the repositories cover both stores but are `@internal` and may change, so any calls should stay in one place.

## Decision

The reader, `learn/learn-progress-api/inc/reader.php`, uses Sensei's public functions, including `Sensei_Utils::user_lesson_status()`, wherever one gives what the contract needs. For two facts no public function does, it reads two of Sensei's `@internal` repositories: `Sensei()->quiz_submission_repository->get()`, to tell whether a quiz was graded, and `Sensei()->course_progress_repository->get()`, for the course's start and completion dates. Both calls only read. Every Sensei call, these two included, lives in that one file, as the Sensei team advised, so a change in a repository's interface touches one file. The reader only reads: it never enrolls, starts or changes anything.

The public functions it uses: `Sensei_Utils::user_lesson_status()`, `Sensei_Utils::user_completed_course()`, `Sensei_Utils::has_started_course()`, `Sensei_Quiz::get_user_quiz_grade()`, `Sensei()->lesson->lesson_quizzes()`, `Sensei()->course->course_lessons()`, `Sensei_Lesson::lesson_quiz_has_questions()` and `Sensei_Learner::get_learner_term_slug()`. The `@internal` repositories it reads: `Sensei()->quiz_submission_repository->get()` and `Sensei()->course_progress_repository->get()`.

## Alternatives considered

- **Reading everything through the `@internal` repositories:** they read both stores, but their interfaces may change in any release, and a break on Learn would wait for a Meta committer's manual deploy. So the reader uses them only for the two facts above, and only to read.
- **SQL on `wp_comments` or Sensei's tables:** it ties the reader to a schema and skips Sensei's own rules for status and grades.
- **The `sensei-internal` REST namespace:** it is for Sensei's own use, at our own risk. Its course structure route is public, and the contract uses it for course names and modules (technical guide), but no route there gives a learner's own progress.

## Consequences

- learn.wordpress.org reads the comment storage (WordPress/Learn#3732, 17 November 2026), which is the store `user_lesson_status()` reads. It is also the store the team tested first.
- On a throwaway site with the tables turned on, `tests/compare-storage.php` found no difference between the two stores for learners 04, 07, 11, 14 and 17 on Sensei 4.27.0 (`ALL PASS (18 checks)`, #3). The details are in [`learn/docs/sensei-storage.md`](../../learn/docs/sensei-storage.md), section 5.
- If Learn ever turns on the tables repository, or upgrades Sensei, rerun the comparison and the integration suites first.
