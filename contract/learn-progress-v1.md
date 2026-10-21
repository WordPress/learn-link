# Learn Progress contract, version 1

> **DRAFT. Not frozen.** The team reviews this page in weeks 1 to 3, writes a proposed answer to each of the five open review points in section 6, and freezes it by Friday 30 October 2026, the end of Phase 1, with the approval of the product owners, Maciej Pilarski and Isotta Peira. Until then anything here may change, and neither half should treat it as final. Once frozen, it changes only by a new version (`learn-progress-v2.md`), never in place.

| | |
| --- | --- |
| Version in the body | `learn-progress/1` |
| Status | Draft, to be frozen by the team by Friday 30 October 2026 |
| Sources | Section 2 of the [Learn Link design spec](https://github.com/WordPress/WPCredits/blob/trunk/Education/WordPress%20Education%20Dashboard/wpcredits-program-manager/docs/specs/2026-09-07-learn-link-design.md) (7 September 2026); the five review points in section 5 of the project handbook, found while testing Sensei LMS 4.26.4 on 16 October 2026 |
| Example files | Beside this page, listed in section 5 |

This page is the agreement about exactly what Learn sends. It lets both halves be built at the same time: the Learn half produces these bodies, and the Dashboard half is tested against the example files, never against the network.

## 1. Reading progress

```http
GET https://learn.wordpress.org/wp-json/learn-progress/v1/me
Authorization: Bearer <token>
Accept: application/json
```

No query parameters are needed. The course list is fixed on the Learn side for this client: the whitelist is the whole scope, and the Dashboard cannot widen it. A `courses` parameter, when sent, may only narrow the list to a subset of the whitelist; anything outside it is ignored, never an error, so a probe learns nothing about the whitelist.

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
| `courses` | array | One entry for every whitelisted course, including courses the learner never enrolled in. |
| `courses[].course_id` | integer | The course's post ID on Learn. |
| `courses[].status` | string | `not-started`, `in-progress` or `complete`. See review point 2. |
| `courses[].enrolled_at` | string or null | When the learner enrolled. Null when they never did. |
| `courses[].completed_at` | string or null | When the course was completed. Null until then. |
| `courses[].progress_percent` | integer | 0 to 100. See review point 5. |
| `courses[].lessons` | array | For an enrolled course, its published lessons in course order. Empty for a course the learner never enrolled in. |
| `lessons[].lesson_id` | integer | The lesson's post ID on Learn. |
| `lessons[].status` | string | `not-started`, `in-progress`, `complete` or `ungraded` (the learner submitted the quiz, and nobody has graded it yet). |
| `lessons[].completed_at` | string or null | When the lesson was completed. Null until then. |
| `lessons[].quiz` | object or null | Null for a lesson without a quiz. (Sensei gives every lesson a quiz post, even an empty one; a lesson "has a quiz" when that quiz has questions.) |
| `quiz.quiz_id` | integer | The quiz's post ID on Learn. |
| `quiz.status` | string | `not-taken`, `ungraded`, `passed` or `failed`. See review points 1 and 3. |
| `quiz.grade_percent` | integer or null | 0 to 100. Null until graded. |
| `quiz.passmark_percent` | integer | 0 to 100. |
| `quiz.graded_at` | string or null | When the quiz was graded. Null until then. |

### 1.3 Rules that keep the shape safe and stable

- **IDs only: no titles, no module tree.** Learn sends what only Learn knows: status, dates and grades. Names and the module grouping come from Learn's public course structure (`/wp-json/sensei-internal/v1/course-structure/<course id>`), which the Dashboard reads on its own. A curriculum rename changes nothing in the contract, and nothing personal travels that does not have to.
- **Every whitelisted course appears,** including one the learner never enrolled in (`status` `not-started`, `enrolled_at` null, `lessons` empty). "Not connected" and "connected but not started" must look different on the Student Report Card, and the second one has to come from Learn.
- **Closed vocabularies.** The status lists in section 1.2 are complete. A value outside them is an error, and the Dashboard refuses the body rather than guessing.
- **Percentages are whole numbers** from 0 to 100.
- **Times are UTC, RFC 3339** (`2026-10-14T09:12:33Z`). The Dashboard formats them in its own site's time zone. See review point 4.
- **The learner block carries the WordPress.org username.** The Dashboard compares it with the student's known username on every read.

## 2. Errors

Every error is JSON with a `code` and a `message`. WordPress adds `data.status` to every REST error on its own; the Dashboard decides by `code` alone, and shows its own words, never Learn's `message`.

| Status | `code` | Meaning for the Dashboard | Example |
| --- | --- | --- | --- |
| 401 | `learn_progress_invalid_token` | The token is unknown or malformed. Treat the student as not connected, and tell an Administrator. | [example-error-401-invalid-token.json](example-error-401-invalid-token.json) |
| 403 | `learn_progress_revoked` | The grant was withdrawn on Learn. Show "connection withdrawn", never 0%. | [example-error-403-revoked.json](example-error-403-revoked.json) |
| 429 | `learn_progress_slow_down` | Too many reads. Honor the `Retry-After` header, which Learn always sends with this status. | [example-error-429-slow-down.json](example-error-429-slow-down.json) |
| 503 | `learn_progress_unavailable` | Learn cannot read results right now. Keep the cache, and say so. | [example-error-503-unavailable.json](example-error-503-unavailable.json) |

Every response, success or error, carries `Cache-Control: no-store`.

**Rate expectation:** the Dashboard reads a student at most once per cache window, plus one nightly warmer pass. Learn may refuse more than sixty reads per token per hour.

## 3. Granting access

Modeled on WordPress core's `authorize-application.php`, with two changes: a one-time code instead of a token in the redirect, and a code verifier (PKCE) instead of a client secret. No secret ever has to live in this public repository, and no token ever appears in a browser's address bar, a referrer or a server log.

This section depends on open decision 1 of the handbook (a code exchange or a token in the redirect); the code exchange is the recommendation. See [docs/decisions/002-code-exchange-pkce.md](../docs/decisions/002-code-exchange-pkce.md).

1. **Start.** The student presses "Connect your Learn account" on their own Student Report Card. The Dashboard creates `state` (32 random bytes, base64url) and `code_verifier` (43 to 128 characters), keeps both for 15 minutes together with the student's user ID, computes `code_challenge = base64url( sha256( code_verifier ) )`, and sends the browser to:

   ```text
   https://learn.wordpress.org/learn-link/authorize/
     ?client_id=wpcredits
     &redirect_uri=https://wordpresseducation.org/wp-admin/admin-post.php?action=wpcpm_learn_callback
     &state=<state>
     &code_challenge=<code_challenge>
     &code_challenge_method=S256
   ```

2. **Approve.** Learn requires the person to be signed in (WordPress.org single sign-on). The consent screen is a front-end page, not wp-admin, so a learner with no special rights can use it. It says, in plain words, what will be shared (course progress, lesson status and quiz grades for the named courses, and nothing else), with whom (the WordPress Credits program on wordpresseducation.org), for how long (the length of the program), and that a program Administrator can withdraw it. `redirect_uri` must exactly match one registered for `client_id` on Learn (the live site and the staging copy); an unknown client, an unregistered redirect address, or a missing or malformed `code_challenge` is refused before the screen renders. Only `S256` is accepted. On approval, Learn stores a grant (learner, client, courses, `code_challenge`, created), mints a one-time `code` (valid 10 minutes, single use), lists the grant on the learner's Learn profile so they can always see it exists, and redirects to `redirect_uri` with `code` and `state`.

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

4. **Identity check, before anything is stored.** The Dashboard compares `learner.wporg_username` with the student's known username, ignoring case. A mismatch is refused, nothing is saved, the token is withdrawn at once (section 4), and the student sees: "The Learn account you approved belongs to @otherperson. Your Student Report Card is for @adaexample. Sign in to Learn as @adaexample and try again." This is the one place where a participant building the system could connect someone else's account to their own card, and it is closed by data, not by policy.

5. **Store.** The Dashboard seals the token before it stores it, and only the token's fingerprint is ever logged.

## 4. Withdrawing access

```http
DELETE https://learn.wordpress.org/wp-json/learn-progress/v1/token
Authorization: Bearer <token>
```

Learn deletes the grant and answers `204`; a later read with that token answers `401`. On the Dashboard, only an Administrator's withdrawal calls this; a student cannot disconnect. If Learn does not answer, the Dashboard deletes its copy of the token anyway and retries the DELETE nightly until Learn answers `204` or `401`.

A Learn administrator may also withdraw a grant from the learner's profile screen on Learn. The Dashboard then learns of it through `403 learn_progress_revoked` on the next read, and shows it truthfully.

## 5. The example files

The Dashboard's suites run against exactly these files, and the Learn half's output for the seeded learners should match their shape. All IDs except the track course 297853 are invented; course 123456 stands for a foundational course until open decision 4 names the real ones.

| File | What it shows |
| --- | --- |
| [example-in-progress.json](example-in-progress.json) | The track course, eight lessons in every state: complete without a quiz, complete with a passed quiz, complete with a failed quiz where no pass is required, waiting to be graded, in progress with a failed quiz where a pass is required, in progress with the quiz not taken, and not started. A second whitelisted course, never enrolled. |
| [example-not-started.json](example-not-started.json) | Enrolled in the track course, nothing done; the second course never enrolled. |
| [example-complete.json](example-complete.json) | The same learner at the end of the term: both courses complete, one quiz graded by hand, and the quiz that failed where no pass is required still failed, because a complete course does not mean every quiz passed. |
| [example-error-401-invalid-token.json](example-error-401-invalid-token.json) | Unknown or malformed token. |
| [example-error-403-revoked.json](example-error-403-revoked.json) | Grant withdrawn on Learn. |
| [example-error-429-slow-down.json](example-error-429-slow-down.json) | Too many reads (the real response also has a `Retry-After` header). |
| [example-error-503-unavailable.json](example-error-503-unavailable.json) | Learn cannot read results right now. |

The track course in the examples has this shape, the same in all three files:

| Lesson | Quiz | Grading | Pass required |
| --- | --- | --- | --- |
| 297901 | none | | |
| 297902 | 297952 | automatic, pass mark 70% | yes |
| 297903 | 297953 | automatic, pass mark 70% | no |
| 297904 | none | | |
| 297905 | 297955 | by hand, pass mark 70% | yes |
| 297906 | 297956 | automatic, pass mark 70% | yes |
| 297907 | 297957 | automatic, pass mark 70% | yes |
| 297908 | none | | |

## 6. Open review points: settle these before the freeze

These came out of testing Sensei on 16 October 2026 (section 6 of the technical guide). For each one, write the team's proposed answer here, in a pull request with the `contract` label, before the freeze.

1. **A graded quiz status.** Sensei has a quiz status the contract does not: `graded`, meaning marked when no pass mark is required. It counts as complete. Either add `graded` to the quiz vocabulary, or map it to `passed`.
   - Team's proposal: _to be written_
2. **What course "not-started" means.** Enrolling creates a course record at once, with the status in-progress. Define the contract's course `not-started` as "enrolled, no lesson started" (or never enrolled, which `enrolled_at` null tells apart), not as "no record". The examples follow this definition.
   - Team's proposal: _to be written_
3. **A failed quiz where a pass is required.** It leaves the lesson incomplete, so the lesson is `in-progress` and the quiz is `failed` (lesson 297906 in example-in-progress.json). A failed quiz where no pass is required leaves the lesson `complete` (lesson 297903). Say whether the quiz object should also carry whether a pass is required, so the Dashboard can explain why a lesson is not complete.
   - Team's proposal: _to be written_
4. **Dates in UTC.** Sensei stores some dates in the site's time zone and others in UTC. Learn converts every date to UTC before sending it. List which fields come from which store.
   - Team's proposal: _to be written_
5. **A computed percent.** Compute `progress_percent` on Learn (completed lessons divided by published lessons). Do not trust Sensei's stored "percent" value, which can be wrong when Sensei reads from its custom tables. Also say how it rounds: one lesson of eight is 12.5%, which the seed check prints as 13%.
   - Team's proposal: _to be written_

## 7. Not yet specified

Noticed while preparing this draft, and not among the five review points. Settle or consciously defer each one in the same review:

- The error bodies of the token endpoint (an expired or reused code, a verifier that does not match, an unknown client).
- The format of the optional `courses` parameter.
- The order of `courses` in the response (the Dashboard lists the track course first).
- `passmark_percent` for a quiz that sets no pass mark.

## Change log

- 16 October 2026: first draft, from section 2 of the design spec and section 5 of the handbook. Not frozen.
