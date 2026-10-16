# Architecture

Learn Link connects two WordPress sites that do not trust each other by default: Learn WordPress (learn.wordpress.org), where students take their courses, and the Dashboard (wordpresseducation.org), where their Student Report Card lives. Learn has no API that reads a learner's results, so one half of Learn Link runs on Learn and the other half runs on the Dashboard. They meet only in [the contract](../contract/learn-progress-v1.md).

```text
 Student's browser                Dashboard                          Learn
 (wordpresseducation.org)         WPCPM_Learn_Progress               Sensei extension
 ------------------------         --------------------               ----------------
 "Connect your Learn account" --> state + code_verifier
                              <-- redirect to Learn ------------->  /learn-link/authorize/
                                                                    consent screen, grant
                              <-- redirect back with code -------
                                  POST /learn-progress/v1/token -->  check verifier, mint token
                                  <-- token + wporg_username -----   (stores only its hash)
                                  identity check, seal token
 Student Report Card <---------- cache <-- GET /learn-progress/v1/me  token holder's own progress
 Mentor Report Card  <---------- cache (filled by the nightly warmer)
 Administrator withdraws ------> DELETE /learn-progress/v1/token --> grant deleted
```

## The four flows

- **Connect.** The student presses "Connect your Learn account" on their own Student Report Card. The Dashboard sends them to Learn's consent screen. They approve, Learn redirects back with a one-time code, and the Dashboard exchanges it for a token, server to server ([ADR 002](decisions/002-code-exchange-pkce.md)). The Dashboard checks that the WordPress.org username Learn reports is the student's own before it stores anything ([ADR 005](decisions/005-identity-key.md)).
- **Read.** When the student opens their Student Report Card and the cache is older than the cache window, the Dashboard calls `GET /learn-progress/v1/me` with the token and stores the answer.
- **Warm.** Once a night, a scheduled job reads every connected student, so the Mentor Report Card renders from the cache alone and never calls Learn while a page loads ([ADR 004](decisions/004-cached-pull.md)).
- **Withdraw.** An Administrator withdraws a connection on the Dashboard. The Dashboard deletes its copy of the token at once and asks Learn to delete the grant, retrying nightly until Learn confirms. A student cannot disconnect: the connection lasts for the program.

## Where the safety comes from

- **The endpoint's shape.** `GET /learn-progress/v1/me` takes no user parameter and answers only for the token holder, for a course list fixed on Learn's side. There is no way to ask it about anyone else. This matters because the people building Learn Link are participants too, so they are inside the data set they build for.
- **A scoped token of its own,** not an Application Password, which can do everything its user can do ([ADR 001](decisions/001-scoped-token.md)). It reads its holder's progress and nothing else, and works only on the learn-progress routes.
- **No secret in the public code.** PKCE replaces a client secret, and tokens never appear in a URL.
- **Hashes on Learn, sealed tokens on the Dashboard,** and only fingerprints in logs.
- **IDs only in the contract.** Course and lesson titles come from Learn's public course structure, so nothing personal travels that does not have to.

## The two halves

- [`learn/`](../learn/): the Sensei extension. Where it finally lives on Learn is open ([ADR 003](decisions/003-where-the-learn-code-lives.md)).
- [`dashboard/`](../dashboard/): the `WPCPM_Learn_Progress` module, behind the `learn_enabled` switch, off by default. [`INTEGRATION.md`](../dashboard/INTEGRATION.md) lists where it connects to the plugin.

## Out of scope for this term

Analytics for Administrators, the Institution Dashboard, platforms other than Learn, and sending anything back to Airtable. Write good ideas down as issues labeled `later`.
