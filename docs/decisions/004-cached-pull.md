# 004. A cached pull with a nightly warmer, not webhooks

- **Status:** Accepted before the term (handbook section 4, "Already made"). The cache window is still open (open decision 7). The team writes this record up in week 1 or 2.
- **Date:**
- **Decided by:** the product owners, Maciej Pilarski and Isotta Peira
- **Issue:**

_Stub. Replace each "To be written" with a few sentences of your own._

## Context

The Dashboard needs Learn results on three pages: the student's own Student Report Card, the Mentor Report Card and the Administrator Dashboard. Learn may refuse more than sixty reads per token per hour, and a page must never wait on Learn, or break when Learn does not answer.

To be written: why Learn pushing changes to the Dashboard (webhooks, Sensei's progress hooks) was not chosen.

## Decision

The Dashboard pulls. A student's own Student Report Card reads Learn when its cache is older than the cache window (six hours is the recommendation), and otherwise renders the cache. A nightly warmer refreshes every connected student, so the Mentor Report Card and the Administrator Dashboard read the cache only and never call Learn during a page view. A read that fails keeps the cache and says so.

To be written: the cache window the product owners choose.

## Alternatives considered

To be written. At least: webhooks from Learn; reading Learn on every page view.

## Consequences

To be written. For example: results can be up to one cache window old; grades are personal data, so the cache is deleted when the connection is withdrawn, when the account is deleted, and at uninstall.
