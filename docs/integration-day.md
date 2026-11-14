# Integration day: the staging Dashboard against a local Learn

The record for #26. Phase 2 is done when a test student connects on staging and sees their local Learn grades.

**Status:** run on 12 and 13 November 2026. The six cases passed locally on 12 November, and the four staging cases passed on 13 November. A test student connected on staging and saw their local Learn grades.

The screenshots and command outputs are not kept in this repository. They are attached to the pull request for #26, and the results are summarized in the closing comment in #26.

Invented data only. No tokens, codes or passwords in this file or in any screenshot.

## Setup

| Item | Value |
| --- | --- |
| Staging Dashboard | `https://learnlinktest.wpcomstaging.com` |
| Plugin on staging | WPCredits Program Manager `1.123.52-learnlink.1`, the staging-only build with the module |
| Commit on staging | `698d211` plus the fix `9379366` from #36, as installed by Maciej. Its Dashboard code is the same as the heads of #40 (`6e30e4f`) and #41 (`2c20f3a`) |
| Local Learn | `http://localhost:8882`, reached through the tunnel in [decision record 007](decisions/007-integration-tunnel.md) |
| Staging settings | `learn_enabled` on, `learn_base_url` set to the tunnel address, the course override set to the local course |
| Client on the local Learn | `redirect_uri` is `https://learnlinktest.wpcomstaging.com/wp-admin/admin-post.php?action=wpcpm_learn_callback` |
| Invented student | `learnlink-student-01` on staging, with the same username and some progress on the local Learn |
| Other invented learner | `learnlink-learner-07`, for the different-identity case. It exists only on the local Learn |

The staging addresses end in `example.test`, so no mail reaches anyone. Check the withdrawal mail in the plugin's email log.

## Who does what

| Person | Role |
| --- | --- |
| Wilberth | Coordinates, writes this file and decision record 007, and posts the results in #26 and #17 |
| Rafael | Runs the local Learn and the tunnel |
| Pedro | Runs the staging Dashboard and the browser cases |

Wilberth records every result in this file, and posts the closing comment in #26 and the phase summary in #17.

## Schedule

| Date | Step | Time | Result |
| --- | --- | --- | --- |
| 10 November | The tunnel starts, and the consent screen works through it | 12 November, 13:19 | Pass, run on 12 November instead of 10 November. Through the tunnel, `/learn-link/authorize/` answers 400 ("This connection request is not valid.") and `/wp-json/learn-progress/v1/me` answers 401, with no redirect to `localhost` |
| 10 November | `WP_HOME` and `WP_SITEURL`: needed or not | 12 November, 13:44 | Needed, run on 12 November instead of 10 November. The sign-in form posted to `localhost`. On Studio, `wp-config.php` alone was not enough, and a temporary must-use plugin keeps the links on the tunnel ([decision record 007](decisions/007-integration-tunnel.md), Consequences) |
| 11 November | Full rehearsal with both local sites | 12 November, before 10:08 to 11:40 | Not run as a separate session. The local run of the six cases on 12 November served as the rehearsal (Results, Local) |
| 12 November | The six cases on the two local sites | Before 10:08 to 10:38, outputs recorded until 11:40 | 6 of 6 pass |
| 13 November | Staging configured with the tunnel address and the agreed build | 10:21 | Done |
| 13 November | The four cases on staging | 11:27 to 11:47 | 4 of 4 pass |
| 13 November | The tunnel is stopped | 11:56 | Done. No `cloudflared` or `ngrok` process is left running |

## Cases

Every case runs locally. Those marked "Yes" are repeated on staging, as #26 requires.

| ID | Case | Steps | Expected result | On staging |
| --- | --- | --- | --- | --- |
| E2E-01 | Connect | Sign in to the Dashboard as the student, press Connect, sign in to Learn, approve | Back on the card with "Your Learn account is connected." | Yes |
| E2E-02 | See progress | After E2E-01, reload the card | The panel shows the real local data | Yes |
| E2E-03 | Deny | Press Connect, then deny | A refusal message, and no connection meta | No |
| E2E-04 | Different identity | Sign in to Learn as `learnlink-learner-07`, press Connect, approve | The identity message naming both usernames, and no grant left for that account | Yes |
| E2E-05 | Learn down | After E2E-02, stop Learn, expire the cache, reload the card | "Learn did not answer..." with the cached data, never 0% | No |
| E2E-06 | Withdrawal | After E2E-02, as an Administrator, withdraw with a reason | The `withdrawn` card, the mail in the log, no grant left, and 401 for the old token | Yes |

`DELETE` removes the grant (section 4 of the contract), so the expected result after a mismatch or a withdrawal is no grant at all, rather than a revoked one.

## Results

Each case has a screenshot in the pull request for #26.

### Local (12 November)

Times are in the team's time zone (UTC-6), taken from what the screenshots show. Both local sites run on UTC, so the card and the email log show times six hours ahead. The command outputs for E2E-03, E2E-04 and E2E-06 were recorded from 11:31 to 11:40.

| ID | Time | Result | Notes |
| --- | --- | --- | --- |
| E2E-03 | Before 10:08 | Pass | "You did not approve the connection on Learn." No `_wpcpm_learn_*` meta is left for the student. The card still offers Connect, so this ran before E2E-01 |
| E2E-04 | Before 10:08 | Pass | The identity message names `@learnlink-learner-07` and `@learnlink-student-01`. Learn holds no grant for `learnlink-learner-07`. The card still offers Connect, so this ran before E2E-01 |
| E2E-01 | 10:08 | Pass | "Your Learn account is connected." The card says "Last updated November 12, 2026, 4:08 pm" (UTC) |
| E2E-02 | 10:08 | Pass | The panel shows the local Learn data from that read: course 57 at 50% (4 of 8 lessons) and course 59 at 33% (1 of 3 lessons) |
| E2E-05 | After 10:34 | Pass | "Learn did not answer just now..." with the cached numbers, never 0%. The cache was read at 10:34 and moved back seven hours to expire it, so the card says "as of November 12, 2026, 9:34 am" (UTC) |
| E2E-06 | 10:38 | Pass | The `withdrawn` card and the mail in the email log, at 16:38 (UTC). Learn holds no grant, and the old token gets `learn_progress_invalid_token`, the 401 |

### Staging (13 November)

Times are when each result was recorded, in the team's time zone (UTC-6). Staging runs on Europe/Warsaw time (UTC+1), so its email log shows the withdrawal mail at 18:42.

| ID | Time | Result | Notes |
| --- | --- | --- | --- |
| E2E-04 | 11:27 | Pass | Run first, as planned. "The Learn account you approved belongs to @learnlink-learner-07. Your Student Report Card is for @learnlink-student-01." The local Learn holds no grant for `learnlink-learner-07` |
| E2E-01 | 11:36 | Pass | "Your Learn account is connected." |
| E2E-02 | 11:39 | Pass | The staging card shows the local Learn grades through the tunnel: course 57 at 50% (4 of 8 lessons, two quizzes passed at 100%) and course 59 at 33% (1 of 3 lessons) |
| E2E-06 | 11:42 to 11:47 | Pass | The `withdrawn` card and the mail in the staging email log. The local Learn holds no grant |

**The 401 for the old token on staging.** Staging has no command line, so the sealed token cannot be read back and sent to Learn there. The proof is that the grant no longer exists on Learn: without a grant, any token for it gets 401 (section 4 of the contract). The 401 itself was shown locally, in E2E-06, with the same code.

## Closing checks

- [x] The tunnel is off (13 November, 11:56).
- [x] `WP_HOME` and `WP_SITEURL` are reverted on the local Learn, and the temporary must-use plugin is deleted.
- [x] Both are confirmed in #43.
- [x] The closing summary is posted in #17.
