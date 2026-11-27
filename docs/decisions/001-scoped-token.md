# 001. A scoped token of its own, not Application Passwords

- **Status:** Accepted
- **Date:** 2026-09-07
- **Decided by:** the product owners, Maciej Pilarski and Isotta Peira, before the term (handbook section 4, "Already made")
- **Issue:** #2

## Context

The Dashboard needs a credential to read one learner's progress from Learn. Learn already has WordPress Application Passwords switched on, and WordPress.org's own authorization flow at login.wordpress.org (March 2026) hands out a full Application Password. An Application Password can do everything its user can do.

The people building Learn Link are participants in the program themselves. If the Dashboard held Application Passwords, a mistake or a leak would hand over fellow students' whole WordPress.org accounts, not just their course results. The credential has to be worth as little as possible to anyone who gets it.

## Decision

Learn Link uses its own token. It reads its holder's own progress for the whitelisted courses and nothing else, and it works only on the learn-progress routes.

- **Made:** 32 random bytes, encoded as 43 Base64URL characters (`Tokens\mint()`).
- **Stored on Learn:** only its keyed hash, `hash_hmac( 'sha256', $token, wp_salt( 'auth' ) )`, on the learner's grant. The one-time code is stored the same way.
- **Stored on the Dashboard:** sealed with the plugin's `WPCPM_Secret` (AES-256-GCM), and only its fingerprint is ever logged.
- **Checked:** on `GET /learn-progress/v1/me`, inside its permission callback (`Rest\authenticate()`). `DELETE /learn-progress/v1/token` has `__return_true` as its permission callback, and `Rest\delete_token()` looks the token up itself (`Tokens\find_grant_by_token()`), so a withdrawal does not count toward the read limit and still works on a grant already marked as revoked. The token never goes through `determine_current_user` and never calls `wp_set_current_user()`, so the token cannot sign anyone in or reach any other route.
- **Limited:** Learn may refuse more than sixty reads per token per hour.

## Alternatives considered

- **Core Application Passwords:** each one carries every capability of its user on every REST route. Storing one per student on the Dashboard would turn any leak into account takeover.
- **WordPress.org's login application-passwords flow:** it is convenient, but it hands out the same full Application Password, so it has the same problem.
- **A general OAuth server plugin on Learn:** it would give scopes, but it is far more code for the Meta team to review and maintain than one read-only route needs.

## Consequences

- The Learn half builds and tests its own grant storage, hashing and rate limit (the grants, bearer and token integration suites).
- A leaked token exposes one learner's progress in the whitelisted courses, and nothing more, until it is withdrawn.
- Changing Learn's `AUTH_KEY` or `AUTH_SALT` makes every stored hash useless, so every student would have to connect again.
- No route outside the learn-progress namespace may ever accept this token. `integration-bearer.php` checks that `/wp/v2/users/me` still answers 401 to it (SEC-07).
