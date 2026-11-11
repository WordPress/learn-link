# Integration day: the staging Dashboard against a local Learn

The evidence for #26. Phase 2 is done when a test student connects on staging and sees their local Learn grades.

**Status:** planned. No case below has been run yet. Each result is filled in on the day it is run.

Invented data only. No tokens, codes or passwords in this file or in any screenshot.

## Setup

| Item | Value |
| --- | --- |
| Staging Dashboard | `https://learnlinktest.wpcomstaging.com` |
| Plugin on staging | WPCredits Program Manager `1.123.52-learnlink.1`, the staging-only build with the module |
| Commit on staging | To be recorded once agreed with Maciej |
| Local Learn | `http://localhost:8882`, reached through the tunnel in [decision record 007](decisions/007-integration-tunnel.md) |
| Staging settings | `learn_enabled` on, `learn_base_url` set to the tunnel address, the course override set to the local course |
| Client on the local Learn | `redirect_uri` is `https://learnlinktest.wpcomstaging.com/wp-admin/admin-post.php?action=wpcpm_learn_callback` |
| Invented student | `learnlink-student-01` on staging, with the same username and some progress on the local Learn |
| Other invented learner | `learnlink-learner-07`, for the different-identity case |

The staging addresses end in `example.test`, so no mail reaches anyone. Check the withdrawal mail in the plugin's email log.

## Who does what

| Person | Role |
| --- | --- |
| Wilberth | Coordinates, and writes this file and decision record 007 |
| Rafael | Runs the local Learn and the tunnel |
| Pedro | Runs the staging Dashboard and the browser cases |

Rafael and Pedro post their results as comments in #26, and Wilberth copies them here.

## Schedule

| Date | Step | Time | Result |
| --- | --- | --- | --- |
| 10 November | The tunnel starts, and the consent screen works through it | | Not run |
| 10 November | `WP_HOME` and `WP_SITEURL`: needed or not | | Not run |
| 11 November | Full rehearsal with both local sites | | Not run |
| 12 November | The cases below | | Not run |

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

### Local

| ID | Time | Result | Screenshot | Notes |
| --- | --- | --- | --- | --- |
| E2E-01 | | Not run | | |
| E2E-02 | | Not run | | |
| E2E-03 | | Not run | | |
| E2E-04 | | Not run | | |
| E2E-05 | | Not run | | |
| E2E-06 | | Not run | | |

### Staging

| ID | Time | Result | Screenshot | Notes |
| --- | --- | --- | --- | --- |
| E2E-01 | | Not run | | |
| E2E-02 | | Not run | | |
| E2E-04 | | Not run | | |
| E2E-06 | | Not run | | |

## Closing checks

- [ ] The tunnel is off.
- [ ] `WP_HOME` and `WP_SITEURL` are reverted.
- [ ] Both are confirmed in #26.
- [ ] The closing summary is posted in #17.
