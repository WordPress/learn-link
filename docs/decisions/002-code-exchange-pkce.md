# 002. A one-time code exchanged with PKCE, not a token in the redirect

- **Status:** Proposed. Open decision 1 in handbook section 4; the product owners decide by the end of week 2.
- **Date:**
- **Decided by:** the product owners, Maciej Pilarski and Isotta Peira
- **Issue:**

_Stub. Replace each "To be written" with a few sentences of your own._

## Context

After the learner approves on Learn's consent screen, the Dashboard needs a token. The roadmap first said Learn would redirect back with the token. The design spec recommends a one-time code that the Dashboard exchanges for the token, server to server, proving with a code verifier (PKCE, RFC 7636) that it started the request. This repository is public, so the Dashboard cannot hold a client secret.

To be written: what goes wrong when a token travels in a URL (browser history, referrers, server logs).

## Decision

To be written once the product owners decide. The recommendation is the code exchange: codes valid 10 minutes and single use, `state` single use, `S256` only, an exactly matching redirect address. The steps are in section 3 of [the contract](../../contract/learn-progress-v1.md).

## Alternatives considered

To be written. At least: the token in the redirect; a client secret on the Dashboard.

## Consequences

To be written. For example: the Learn half needs one more endpoint (`POST /learn-progress/v1/token`), and no secret ever lives in this repository.
