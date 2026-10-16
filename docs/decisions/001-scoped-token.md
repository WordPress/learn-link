# 001. A scoped token of its own, not Application Passwords

- **Status:** Accepted before the term (handbook section 4, "Already made"). The team writes this record up in week 1 or 2.
- **Date:**
- **Decided by:** the product owners, Maciej Pilarski and Isotta Peira
- **Issue:**

_Stub. Replace each "To be written" with a few sentences of your own._

## Context

The Dashboard needs a credential to read one learner's progress from Learn. Learn already has WordPress Application Passwords switched on, and WordPress.org's own authorization flow at login.wordpress.org (March 2026) hands out a full Application Password. An Application Password can do everything its user can do.

To be written: why that matters here, with the builders being participants themselves.

## Decision

Learn Link uses its own token. It reads its holder's own progress for the whitelisted courses and nothing else, and it works only on the learn-progress routes.

To be written: how the token is made (32 random bytes), stored (only its hash on Learn, sealed on the Dashboard) and checked (inside each route's permission callback, never through `determine_current_user`).

## Alternatives considered

To be written. At least: core Application Passwords; WordPress.org's login-application-passwords flow.

## Consequences

To be written. For example: the Learn half must build and secure its own token storage, and a leaked token exposes one learner's progress and nothing more.
