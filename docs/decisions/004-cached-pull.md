# 004. A cached pull with a nightly warmer, not webhooks

- **Status:** Accepted
- **Date:** 2026-11-05 (the cache window, decision 7 in #2; the pull itself was decided before the term)
- **Decided by:** the product owners, Maciej Pilarski and Isotta Peira; Maciej chose the cache window and the warmer's hour
- **Issue:** #2, #47

## Context

The Dashboard needs Learn results on three pages: the student's own Student Report Card, the Mentor Report Card and the Administrator Dashboard. Learn may refuse more than sixty reads per token per hour, and a page must never wait on Learn, or break when Learn does not answer.

Learn pushing changes to the Dashboard was not chosen. Webhooks would make Learn hold the Dashboard's address and a credential for it, send personal data out without being asked, and retry whenever the Dashboard is down: more code on Learn, where every change is deployed by hand. Sensei's progress hooks also fire on many paths that differ between its two stores (006).

## Decision

The Dashboard pulls.

- A student's own Student Report Card reads Learn when its cache is older than six hours (`learn_cache_hours`, default 6), and otherwise renders the cache.
- A daily warmer, `WPCPM_Learn_Progress_Sync`, built like `WPCPM_Outcome_Figures`, refreshes every connected student at 08:00 UTC (agreed in #45; the plugin's next job starts at 08:17).
- The Mentor Report Card and the Administrator Dashboard read the cache only and never call Learn while a page loads. `bin/test-learn-mentor.php` fails if the mentor line makes any HTTP request.
- A read that fails keeps the cache and says so ("These are your results as of ...").

## Alternatives considered

- **Webhooks from Learn:** see Context.
- **Reading Learn on every page view:** pages would wait on Learn, break when it is down, and a mentor with many students would hit the sixty-reads limit.
- **A shorter window:** fresher results for the student, but more reads for no gain on the mentor's side, which the warmer already covers.

## Consequences

- A student's own results can be up to six hours old, and a mentor's view up to a day old.
- Grades are personal data, so the cache is deleted when the connection is withdrawn, when the account is deleted, through the WordPress privacy tools, and at uninstall (#51).
- Deactivating the plugin clears the warmer's event and its lock (`INTEGRATION.md`, block #7).
