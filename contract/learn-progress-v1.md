# Learn Progress contract, version 1

> **FROZEN on 9 November 2026.** Changes only by a new version (`learn-progress-v2.md`), never in place. Approved by Maciej Pilarski, to whom Isotta Peira left the product owners' decisions, in [#1](https://github.com/WordPress/learn-link/issues/1).

| | |
| --- | --- |
| Version in the body | `learn-progress/1` |
| Status | Frozen on 9 November 2026 |
| Sources | Section 2 of the [Learn Link design spec](https://github.com/WordPress/WPCredits/blob/trunk/Education/WordPress%20Education%20Dashboard/wpcredits-program-manager/docs/specs/2026-09-07-learn-link-design.md) (7 September 2026); the five review points in section 5 of the project handbook, found while testing Sensei LMS 4.26.4 on 16 October 2026 |
| Example files | Beside this page, listed in section 5 |

This page is the agreement about exactly what Learn sends. It lets both halves be built at the same time: the Learn half produces these bodies, and the Dashboard half is tested against the example files, never against the network.

## 1. Reading progress

```http
GET https://learn.wordpress.org/wp-json/learn-progress/v1/me
Authorization: Bearer <token>
Accept: application/json
```

No query parameters are required. The course list is fixed on the Learn side for this client: the whitelist is the whole scope, and the Dashboard cannot widen it. It holds every live track course (decision 4 in [#2](https://github.com/WordPress/learn-link/issues/2)), and the Dashboard sends `courses=<the student's track course>` on every read. A `courses` parameter may only narrow the list to a subset of the whitelist; anything outside it is ignored, never an error, so a probe learns nothing about the whitelist. Its format and the order of `courses` in the response are in section 1.4.

The endpoint identifies the learner from the token and nothing else. It has no user parameter, so there is no way to ask it about anyone else.

### 1.1 Response `200`

A shortened example (the full one is [example-in-progress.json](example-in-progress.json)):

```json
{
  "contract": "learn-progress/1",
  "learner": { "wporg_username": "adaexample" },
  "generated_at": "2026-10-14T09:12:33Z",
  "courses": [
    {
      "course_id": 297853,
      "status": "in-progress",
      "enrolled_at": "2026-09-02T14:03:00Z",
      "completed_at": null,
      "progress_percent": 50,
      "lessons": [
        {
          "lesson_id": 297902,
          "status": "complete",
          "completed_at": "2026-09-08T18:44:10Z",
          "quiz": {
            "quiz_id": 297952,
            "status": "passed",
            "grade_percent": 85,
            "passmark_percent": 70,
            "graded_at": "2026-09-08T18:44:10Z"
          }
        },
        {
          "lesson_id": 297908,
          "status": "not-started",
          "completed_at": null,
          "quiz": null
        }
      ]
    },
    {
      "course_id": 123456,
      "status": "not-started",
      "enrolled_at": null,
      "completed_at": null,
      "progress_percent": 0,
      "lessons": []
    }
  ]
}
```

### 1.2 Fields

| Field | Type | Meaning |
| --- | --- | --- |
| `contract` | string | Always `learn-progress/1` in this version. The version travels in the body, so a later version can be recognized without a header. |
| `learner.wporg_username` | string | The learner's WordPress.org username, as Learn knows it through WordPress.org single sign-on. Learn's own numeric user ID is never sent. |
| `generated_at` | string | When Learn built this answer. RFC 3339, UTC. |
| `courses` | array | One entry for every whitelisted course (or every course left after `courses` narrows the list, section 1.4), including courses the learner never enrolled in. |
| `courses[].course_id` | integer | The course's post ID on Learn. |
| `courses[].status` | string | `not-started`, `in-progress` or `complete`, from Sensei's own course completion result. It is independent of `progress_percent` (section 1.3). See review point 2. |
| `courses[].enrolled_at` | string or null | When the learner enrolled. Null when they never did. |
| `courses[].completed_at` | string or null | When the course was completed. Null until then. |
| `courses[].progress_percent` | integer | 0 to 100, calculated from published lessons whose status is `complete`. It may be below 100 for a `complete` course, or 100 for an `in-progress` one (section 1.3). See review points 2 and 5. |
| `courses[].lessons` | array | For an enrolled course, its published lessons in course order. Empty for a course the learner never enrolled in. |
| `lessons[].lesson_id` | integer | The lesson's post ID on Learn. |
| `lessons[].status` | string | `not-started`, `in-progress`, `complete` or `ungraded` (the learner submitted the quiz, and nobody has graded it yet). |
| `lessons[].completed_at` | string or null | When the lesson was completed. Null until then. |
| `lessons[].quiz` | object or null | Null for a lesson without a quiz. (Sensei gives every lesson a quiz post, even an empty one; a lesson "has a quiz" when that quiz has questions.) |
| `quiz.quiz_id` | integer | The quiz's post ID on Learn. |
| `quiz.status` | string | `not-taken`, `ungraded`, `graded`, `passed` or `failed`. `graded` applies when no pass is required; `passed` and `failed` require a pass mark. See review points 1 and 3. |
| `quiz.grade_percent` | integer or null | 0 to 100. Null until graded: `graded`, `passed` and `failed` always carry it, and `not-taken` and `ungraded` never do. |
| `quiz.passmark_percent` | integer or null | 0 to 100 exactly when a pass is required, on every quiz status; null otherwise, even if Sensei stores an old pass mark. When a pass is required and Sensei stores no pass mark, it is 0, as Sensei treats it. `passed` and `failed` must have a non-null pass mark; `graded` must have null. |
| `quiz.graded_at` | string or null | When the quiz was graded. Null until then. |

### 1.3 Rules that keep the shape safe and stable

- **IDs only: no titles, no module tree.** Learn sends what only Learn knows: status, dates and grades. Names and the module grouping come from Learn's public course structure (`/wp-json/sensei-internal/v1/course-structure/<course id>`), which the Dashboard reads on its own. A curriculum rename changes nothing in the contract, and nothing personal travels that does not have to.
- **Every whitelisted course appears,** including one the learner never enrolled in (`status` `not-started`, `enrolled_at` null, `lessons` empty). "Not connected" and "connected but not started" must look different on the Student Report Card, and the second one has to come from Learn.
- **Closed vocabularies.** The status lists in section 1.2 are complete. A value outside them is an error, and the Dashboard refuses the body rather than guessing. For quizzes, `passed` and `failed` require a non-null `passmark_percent`, while `graded` requires null; `graded`, `passed` and `failed` require a `grade_percent`, while `not-taken` and `ungraded` require null. Reject inconsistent combinations.
- **Percentages are whole numbers** from 0 to 100. Count only published lessons with status `complete`, and round with integer arithmetic (section 6).
- **Course status and percent are independent.** `courses[].status` comes from Sensei's own course completion result (with the rule that an enrolled course with no lesson started is `not-started`, review point 2), and `progress_percent` counts the course's currently published lessons. The two can disagree in both directions: `complete` below 100 (a lesson published after the learner completed the course), or `in-progress` at 100. Neither half recomputes one from the other, and the Dashboard accepts both combinations.
- **Times are UTC, RFC 3339** (`2026-10-14T09:12:33Z`). The Dashboard formats them in its own site's time zone. See review point 4.
- **The learner block carries the WordPress.org username.** At connect time the Dashboard compares it with the student's known username, ignoring case (section 3, step 4), and saves the verified name. On every read after that, it compares Learn's answer with that saved, verified name. If the student's known username changes later, the Dashboard flags it for an Administrator and keeps the connection (decision 5 in [#2](https://github.com/WordPress/learn-link/issues/2)).

### 1.4 The `courses` parameter and the order of courses

- **Format:** comma-separated course IDs, for example `?courses=297853,123456`.
- **It can only narrow** Learn's fixed list for the client. IDs outside the list, and anything that is not a positive whole number, are ignored. A duplicate counts once. If nothing usable is left, the parameter counts as absent and the whole list comes back. It is never an error.
- **Order:** `courses` in the response always follows the order of Learn's fixed list for the client, track course first, whatever the order in the parameter. The Dashboard matches courses by `course_id` and never relies on position.
- **A new track** needs its course added to Learn's fixed list too, or the Dashboard's `courses` for it is ignored.

## 2. Errors

Every error is JSON with a `code` and a `message`. WordPress adds `data.status` to every REST error on its own; the Dashboard decides by `code` alone, and shows its own words, never Learn's `message`.

A 401 or 403 without one of the codes below (for example from a firewall or a proxy, or WordPress's own `rest_cookie_invalid_nonce`) is a bad answer, never "invalid token" or "withdrawn". Only a 429 or a 5xx without a known code falls back on the HTTP status.

| Status | `code` | Meaning for the Dashboard | Example |
| --- | --- | --- | --- |
| 401 | `learn_progress_invalid_token` | The token is unknown or malformed. Treat the student as not connected, and tell an Administrator. | [example-error-401-invalid-token.json](example-error-401-invalid-token.json) |
| 403 | `learn_progress_revoked` | The grant was withdrawn on Learn. Show "connection withdrawn", never 0%. | [example-error-403-revoked.json](example-error-403-revoked.json) |
| 429 | `learn_progress_slow_down` | Too many reads. Honor the `Retry-After` header, which Learn always sends with this status. | [example-error-429-slow-down.json](example-error-429-slow-down.json) |
| 503 | `learn_progress_unavailable` | Learn cannot read results right now (including when Sensei is not active on Learn). Keep the cache, and say so. | [example-error-503-unavailable.json](example-error-503-unavailable.json) |

### 2.1 Errors from `POST /learn-progress/v1/token`

The code exchange (section 3, step 3) answers with these codes, following RFC 6749 section 5.2. `learn_progress_slow_down` (429) and `learn_progress_unavailable` (503) apply here too.

| Status | `code` | Meaning for the Dashboard | Example |
| --- | --- | --- | --- |
| 400 | `learn_progress_invalid_request` | A parameter is missing or malformed, or `grant_type` is not `authorization_code`. | [example-error-400-invalid-request.json](example-error-400-invalid-request.json) |
| 400 | `learn_progress_invalid_grant` | The code is expired, already used, or does not match the code verifier (section 3, step 3). The student starts again. | [example-error-400-invalid-grant.json](example-error-400-invalid-grant.json) |
| 401 | `learn_progress_invalid_client` | Learn does not know the client. Tell an Administrator. | [example-error-401-invalid-client.json](example-error-401-invalid-client.json) |

Every response, success or error, carries `Cache-Control: no-store`.

**Rate expectation:** the Dashboard reads a student at most once per cache window, plus one nightly warmer pass. Learn may refuse more than sixty reads per token per hour.

## 3. Granting access

Modeled on WordPress core's `authorize-application.php`, with two changes: a one-time code instead of a token in the redirect, and a code verifier (PKCE) instead of a client secret. No secret ever has to live in this public repository, and no token ever appears in a browser's address bar, a referrer or a server log.

The code exchange was decided by Maciej Pilarski on 5 November 2026 (decision 1 in [#2](https://github.com/WordPress/learn-link/issues/2)). See [docs/decisions/002-code-exchange-pkce.md](../docs/decisions/002-code-exchange-pkce.md).

1. **Start.** The student presses "Connect your Learn account" on their own Student Report Card. The Dashboard creates `state` (32 random bytes, base64url) and `code_verifier` (43 to 128 characters), keeps both for 15 minutes together with the student's user ID, computes `code_challenge = base64url( sha256( code_verifier ) )`, and sends the browser to:

   ```text
   https://learn.wordpress.org/learn-link/authorize/
     ?client_id=wpcredits
     &redirect_uri=https://wordpresseducation.org/wp-admin/admin-post.php?action=wpcpm_learn_callback
     &state=<state>
     &code_challenge=<code_challenge>
     &code_challenge_method=S256
   ```

2. **Approve.** Learn requires the person to be signed in (WordPress.org single sign-on). The consent screen is a front-end page, not wp-admin, so a learner with no special rights can use it. It says, in plain words, what will be shared (course progress, lesson status and quiz grades for the named courses, and nothing else), with whom (the WordPress Credits program on wordpresseducation.org: the student and program Administrators see the full results, and a mentor, if the student has one, sees only a one-line summary), for how long (the length of the program), and that a program Administrator can withdraw it. `redirect_uri` must exactly match one registered for `client_id` on Learn (the live site and the staging copy); an unknown client, an unregistered redirect address, or a missing or malformed `code_challenge` is refused before the screen renders. Only `S256` is accepted. On approval, Learn stores a grant (learner, client, courses, `code_challenge`, created; one grant per learner and client, so a new grant replaces the old one), mints a one-time `code` (valid 10 minutes, single use), lists the grant on the learner's Learn profile so they can always see it exists, and redirects to `redirect_uri` with `code` and `state`.

3. **Exchange.** The Dashboard checks `state` (single use: deleted on first read), then calls Learn, server to server:

   ```http
   POST https://learn.wordpress.org/wp-json/learn-progress/v1/token
   Content-Type: application/json

   { "grant_type": "authorization_code", "client_id": "wpcredits", "code": "<code>", "code_verifier": "<code_verifier>" }
   ```

   Learn verifies `sha256( code_verifier )` against the stored challenge, burns the code, mints the token (32 random bytes, base64url), stores only its hash, and answers:

   ```json
   { "token": "<token>", "learner": { "wporg_username": "adaexample" } }
   ```

   A code verifier that does not match burns the code too. If a code that was already used comes back, Learn deletes the grant, and with it the token issued from that code (RFC 6749 section 4.1.2, RFC 9700). Both answer `learn_progress_invalid_grant` (section 2.1).

4. **Identity check, before anything is stored.** The Dashboard compares `learner.wporg_username` with the student's known username, ignoring case. A mismatch is refused, nothing is saved, the token is withdrawn at once (section 4), and the student sees: "The Learn account you approved belongs to @otherperson. Your Student Report Card is for @adaexample. Sign in to Learn as @adaexample and try again." This is the one place where a participant building the system could connect someone else's account to their own card, and it is closed by data, not by policy.

5. **Store.** The Dashboard seals the token before it stores it, saves the verified username in its own user meta key (section 1.3), and only the token's fingerprint is ever logged.

## 4. Withdrawing access

```http
DELETE https://learn.wordpress.org/wp-json/learn-progress/v1/token
Authorization: Bearer <token>
```

Learn deletes the grant and answers `204`; a later read with that token answers `401`. On the Dashboard, only an Administrator's withdrawal calls this; a student cannot disconnect. If Learn does not answer, the Dashboard deletes its copy of the token anyway and retries the DELETE nightly until Learn answers `204` or `401`.

An Administrator's withdrawal requires a reason, and the program emails the student in the Administrator's name with that reason. The student cannot reconnect alone: a program Administrator has to allow it again (decision 8 in [#2](https://github.com/WordPress/learn-link/issues/2)).

A Learn administrator may also withdraw a grant from the learner's profile screen on Learn. The Dashboard then learns of it through `403 learn_progress_revoked` on the next read, and shows it truthfully as "withdrawn". That withdrawal sends no email.

## 5. The example files

The Dashboard's suites run against exactly these files, and the Learn half's output for the seeded learners should match their shape. All IDs except the track course 297853 are invented; course 123456 stands for a second whitelisted course. (Decision 4 in [#2](https://github.com/WordPress/learn-link/issues/2): Learn's fixed list holds every live track course, and the Dashboard narrows it to the student's own track course with `courses`.)

| File | What it shows |
| --- | --- |
| [example-in-progress.json](example-in-progress.json) | The track course, eight lessons in every state: complete without a quiz, complete with a passed quiz, complete with a graded quiz where no pass is required, waiting to be graded, in progress with a failed quiz where a pass is required, in progress with the quiz not taken, and not started. A second whitelisted course, never enrolled. |
| [example-not-started.json](example-not-started.json) | Enrolled in the track course, nothing done; the second course never enrolled. |
| [example-complete.json](example-complete.json) | The same learner at the end of the term: both courses complete, one quiz graded by hand, and a quiz graded without a required pass mark (`graded` with `passmark_percent` null). |
| [example-error-401-invalid-token.json](example-error-401-invalid-token.json) | Unknown or malformed token. |
| [example-error-403-revoked.json](example-error-403-revoked.json) | Grant withdrawn on Learn. |
| [example-error-429-slow-down.json](example-error-429-slow-down.json) | Too many reads (the real response also has a `Retry-After` header). |
| [example-error-503-unavailable.json](example-error-503-unavailable.json) | Learn cannot read results right now. |
| [example-error-400-invalid-request.json](example-error-400-invalid-request.json) | Token endpoint: a missing or malformed parameter, or a wrong `grant_type`. |
| [example-error-400-invalid-grant.json](example-error-400-invalid-grant.json) | Token endpoint: an expired, used or mismatched code or code verifier. |
| [example-error-401-invalid-client.json](example-error-401-invalid-client.json) | Token endpoint: an unknown client. |

The track course in the examples has this shape, the same in all three files:

| Lesson | Quiz | Grading | Pass required |
| --- | --- | --- | --- |
| 297901 | none | | |
| 297902 | 297952 | automatic, pass mark 70% | yes |
| 297903 | 297953 | automatic, no required pass mark | no |
| 297904 | none | | |
| 297905 | 297955 | by hand, pass mark 70% | yes |
| 297906 | 297956 | automatic, pass mark 70% | yes |
| 297907 | 297957 | automatic, pass mark 70% | yes |
| 297908 | none | | |

## 6. Review points: agreed answers

These came out of testing Sensei on 16 October 2026 (section 6 of the technical guide). Maciej agreed with the team's five answers in his review of 21 October 2026, with the clarifications recorded below, and confirmed the edge case in point 2 on 5 November 2026 ([#1](https://github.com/WordPress/learn-link/issues/1)).

1. **A graded quiz status.** Sensei uses `graded` for a marked quiz where no pass is required. Such a quiz is never `failed` and counts as complete.
   - Agreed rule: Add `graded` to the closed quiz vocabulary (`not-taken`, `ungraded`, `graded`, `passed`, `failed`). Keep it distinct from `passed`: a quiz with no required pass mark is `graded`, not `passed` or `failed`. `graded` must carry `passmark_percent` null.
2. **What course "not-started" means.** Enrolling creates a course record with Sensei status `in-progress`, even when no lesson has started.
   - Agreed rule: Map an enrolled course with no lesson started to `not-started`, and a started but not completed course to `in-progress`. Preserve never-enrolled whitelisted courses as `not-started`, with `enrolled_at` null and `lessons` empty. Distinguish enrolled `not-started` by non-null `enrolled_at`.
   - Edge case, confirmed on 5 November 2026: course `status` and `progress_percent` may disagree in both directions. Sensei preserves course completion when a new lesson is published after the learner completed the course, while the percentage reflects the currently published lessons, so a course can be `complete` at 88%; a course can also be `in-progress` at 100%. Use Sensei's course completion result for `status`, never a rule that ties it to the percentage, and neither half recomputes one from the other (section 1.3). The Learn half's reader suite ([#4](https://github.com/WordPress/learn-link/issues/4)) carries a regression test for each direction.
3. **A failed quiz where a pass is required.** Failure to reach a required pass mark holds the associated lesson at `in-progress`; it is not a fully independent lesson outcome.
   - Agreed rule: When a required pass mark is not met, return lesson `in-progress`, quiz `failed`, and lesson `completed_at` null. A quiz with no required pass mark is `graded` rather than `failed` and does not block lesson completion on pass-mark grounds. Do not add `pass_required`; instead set `passmark_percent` to an integer (0 to 100) **exactly when pass is required**, and null otherwise, even if Sensei still holds an old stored pass mark. Enforce that quiz `passed` and `failed` always have an integer pass mark and quiz `graded` always has null. Update lesson 297903 to `graded` with null pass mark and remove the impossible "still failed" wording from the complete example.
4. **Dates in UTC.** Sensei stores some dates in the site's time zone and others in UTC; the API must return UTC.
   - Agreed rule: Normalize `generated_at`, `enrolled_at`, course and lesson `completed_at`, and `graded_at` to RFC 3339 UTC (`YYYY-MM-DDTHH:MM:SSZ`), preserving nulls. Interpret each timestamp using its real source time zone before conversion, not by simply appending `Z`. The exact field-to-store and time-zone mapping is documented and tested in the Learn half, tracked in [#3](https://github.com/WordPress/learn-link/issues/3) (`learn/docs/sensei-storage.md`).
5. **A computed percent.** Do not rely on Sensei's stored percent, which can be wrong when reading from custom tables.
   - Agreed rule: Calculate `progress_percent` from **only published lessons with lesson status `complete`**, divided by all published lessons. An `ungraded` lesson does not count as complete. Return a whole number from 0 to 100, with halves rounded upward, using **integer arithmetic** to avoid PHP floating-point edge cases: `intdiv( 200 * $done + $total, 2 * $total )` for `$total > 0`. Test **1 of 8 = 13** and **23 of 40 = 58**. Exclude unpublished lessons from both counts; return 0 when there are no published lessons, avoiding division by zero.

### 6.1 From Sensei's statuses to the contract's

| Sensei | Condition | Contract |
| --- | --- | --- |
| Course, no record | Never enrolled | course `not-started`, `enrolled_at` null, `lessons` empty |
| Course `in-progress` | Enrolled, no lesson started | course `not-started`, `enrolled_at` set |
| Course `in-progress` | At least one lesson started | course `in-progress` |
| Course `complete` | Sensei's course completion result | course `complete`, whatever `progress_percent` says |
| Lesson, no record | Not started | lesson `not-started`, quiz `not-taken` (if the lesson has a quiz) |
| Lesson `in-progress` | Started, quiz not submitted | lesson `in-progress`, quiz `not-taken` |
| Lesson `complete` | No quiz | lesson `complete`, quiz null |
| Lesson `complete` | With a quiz and no submission (for example, completed by an Administrator) | lesson `complete`, quiz `not-taken`, `grade_percent` null, `passmark_percent` by the usual rule |
| Lesson `complete` | With a quiz and a submission | lesson `complete`; quiz `graded` when no pass is required, otherwise `passed` or `failed` by its grade against the pass mark |
| Lesson `ungraded` | Quiz submitted, waiting for grading | lesson `ungraded`, quiz `ungraded` |
| Lesson `graded` | Marked, no pass required | lesson `complete`, quiz `graded`, `passmark_percent` null |
| Lesson `passed` | Pass required and met | lesson `complete`, quiz `passed`, integer `passmark_percent` |
| Lesson `passed` | No pass required | lesson `complete`, quiz `graded`, `passmark_percent` null |
| Lesson `failed` | Pass required and not met | lesson `in-progress`, quiz `failed`, integer `passmark_percent`, lesson `completed_at` null |
| Lesson `failed` | No pass required | lesson `complete`, quiz `graded`, `passmark_percent` null |

Any other Sensei status is an error on Learn's side; the endpoint never guesses (section 1.3, closed vocabularies).

## 7. Settled before the freeze

The items left open in the 21 October draft were settled by Maciej Pilarski on 5 November 2026 ([#1](https://github.com/WordPress/learn-link/issues/1)):

- **Token endpoint error bodies:** section 2.1, with an example file for each code.
- **The optional `courses` parameter** and **the order of `courses` in the response:** section 1.4.
- **`passmark_percent` for a quiz that sets no pass mark:** null, as review points 1 and 3 say.

Also settled in the reviews of the pull requests that build on this contract:

- **A 401 or 403 without a Learn code** is a bad answer, and only 429 and 5xx fall back on the status (section 2; review of #10).
- **`grade_percent`** is present exactly for `graded`, `passed` and `failed` (sections 1.2 and 1.3; review of #10).
- **A required pass with no stored pass mark** gives `passmark_percent` 0 (section 1.2; review of #14).
- **A complete lesson whose quiz was never submitted** (section 6.1; asked in the review of #14).
- **The identity check on every read, one grant per learner and client, a used code coming back, and who sees what on the consent screen** (sections 1.3 and 3; decisions 2 and 5 in #2, and #18 and #20).

## Change log

- 16 October 2026: first draft, from section 2 of the design spec and section 5 of the handbook. Not frozen.
- 21 October 2026: incorporated Maciej's review into sections 1.2, 1.3, 5, 6 and 7; clarified graded/pass-mark consistency, later-published lessons, UTC issue, and integer percent rounding. Freeze still pending.
- 5 November 2026: the product owners' answers in #1 applied: course status and percent independent in both directions (section 1.3), token endpoint errors with three new example files (section 2.1), the `courses` parameter and order (section 1.4). Sections 1, 1.3, 3 and 4 record decisions 1, 2, 4, 5 and 8 of #2 and the answers in #18 and #20. Section 2 and the quiz rules carry the reviews of #10, #14 and #16, and section 6.1 maps Sensei's statuses.
- 9 November 2026: frozen. Changes only by a new version (`learn-progress-v2.md`).
