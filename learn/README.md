# The Learn half

A Sensei LMS extension that runs on Learn WordPress (learn.wordpress.org). Its working name in the design spec is `learn-progress-api`. Nothing is built here yet: this folder starts empty in week 1.

## What goes here

- **The read function** (Phase 1): one learner's results for one course, and the only code that touches Sensei data. Write it with Sensei's public functions only, so the same code works with both of Sensei's progress stores. [`seed/check-learners.php`](../seed/check-learners.php) is a first sketch of it.
- **The endpoint** `GET /wp-json/learn-progress/v1/me` (Phase 1, then with tokens in Phase 2): the token holder's own progress for the whitelisted courses, in exactly the shape of [the contract](../contract/learn-progress-v1.md).
- **The consent screen** at `/learn-link/authorize/`, a front-end page (Phase 2).
- **The token endpoint** `POST` and `DELETE /wp-json/learn-progress/v1/token`: the code exchange with PKCE, and withdrawal (Phase 2).
- **Grants:** learner, client, courses, the token's hash, created and last-used times; listed on the learner's Learn profile.

Where this code finally lives on Learn is open: see [ADR 003](../docs/decisions/003-where-the-learn-code-lives.md). Until the Meta team answers, build it as a small standalone plugin, written so it can move into the `wporg-learn` plugin's `inc/` folder unchanged.

## Rules for this half

The detail is in section 6 of the technical guide. In short:

- Read Sensei only through its public functions. Never read its comment store or its custom tables directly, and never use the classes marked `@internal`.
- Never call a function that writes from a read-only endpoint, for example `Sensei()->modules->get_user_module_progress()`, which saves user meta.
- Authenticate the token inside each learn-progress route's permission callback. Never use the `determine_current_user` filter for it, or the token would work on every other route of Learn too.
- Store only a hash of each token, and compare with `hash_equals()`.
- The redirect address must match a registered one exactly; `state` is single use; PKCE accepts `S256` only; codes last 10 minutes and work once.
- Rate-limit reads per token, and answer `429` with `Retry-After`. Every response carries `Cache-Control: no-store`. Logs never contain tokens or codes.
- Offer the WordPress privacy tools: export and erase a learner's grants.
- Keep the code PHP 7.4 compatible.

## Testing it locally

Use the Learn test site from step 2 of the [README](../README.md): twenty invented learners in every state the contract knows.
