# 002. A one-time code exchanged with PKCE, not a token in the redirect

- **Status:** Accepted
- **Date:** 2026-11-05
- **Decided by:** Maciej Pilarski (Isotta Peira left this decision to him), decision 1 in #2
- **Issue:** #2

## Context

After the learner approves on Learn's consent screen, the Dashboard needs a token. The roadmap first said Learn would redirect back with the token. The design spec recommends a one-time code that the Dashboard exchanges for the token, server to server, proving with a code verifier (PKCE, RFC 7636) that it started the request. This repository is public, so the Dashboard cannot hold a client secret.

A token in a URL leaks in many quiet ways: it stays in the browser history, it can reach other sites in the `Referer` header, and it lands in the access logs of every server and proxy on the way. Anyone who finds it later can read that learner's progress.

## Decision

The code exchange with PKCE, as recommended. Learn redirects back with a one-time code, and the Dashboard exchanges it server to server at `POST /learn-progress/v1/token` with its code verifier. The steps are in section 3 of [the contract](../../contract/learn-progress-v1.md).

- The code is valid for 10 minutes and works once. A second use deletes the grant and its token (SEC-11).
- `S256` is the only challenge method.
- `state` is single use. The Dashboard keeps it, with the verifier, in a 15-minute transient tied to the student who pressed Connect.
- The redirect address must match the registered one exactly.
- No token ever travels in a URL, and no secret lives in this repository.

## Alternatives considered

- **The token in the redirect:** simpler, but the token would leak through history, referrers and logs, as above.
- **A client secret on the Dashboard:** the code is public and every copy of the plugin would share the secret, so it would protect nothing. PKCE gives the same proof without a secret.

## Consequences

- The Learn half has one more endpoint, `POST /learn-progress/v1/token`, with its own error codes (RFC 6749 section 5.2) and a limit on failed exchanges per address.
- A learner who waits more than 10 minutes on the way back has to press Connect again.
- Section 3 of the contract no longer depends on an open decision.
