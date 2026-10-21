# Contributing to Learn Link

This is how the team works in this repository. It comes from section 9 of the Learn Link project handbook; when the two disagree, ask in an issue and we fix whichever is wrong.

## Issues and milestones

- Every task is an issue, and every phase is a milestone: Phase 1 to Phase 4 (section 10 of the handbook).
- Labels:

  | Label | Use it for |
  | --- | --- |
  | `learn` | The Sensei extension in `learn/` |
  | `dashboard` | The WPCPM_Learn_Progress files in `dashboard/` |
  | `contract` | Anything that touches `contract/` |
  | `docs` | README, guides, decision records |
  | `security` | Hardening and the security review (never the details of an open vulnerability, see below) |
  | `later` | Good ideas outside this term's scope, left for a future team |

- Questions go in issues too, so the answer stays with the code.
- Every Friday, a short written update in the pinned issue: done, next, blocked, hours.

## Branches

Branch from `trunk`. Name branches the way WordPress/Learn does: `{type}/{issue id}-{name}`, for example `add/12-consent-screen` or `fix/31-utc-dates`. Common types are `add`, `fix`, `update`, `remove` and `try`.

## Pull requests

- Every change is a pull request that links its issue (`Fixes #12`) and says what changed, why, and how to test it with the seeded learners. The template asks for each of these.
- Add screenshots for anything visible.
- Keep it small: under about 400 changed lines. Split larger work into a series.
- Before you ask for a review: the tests pass, the standards check is clean, the docs are updated, and there is no secret or real data anywhere in the change.

## Reviews

- One of the WordPress Credits product owners, Maciej Pilarski or Isotta Peira, reviews every pull request. Rita Robles Loaiza, the team's supervisor, may review any of them.
- Nobody merges their own pull request.
- The contract changes only through a pull request with the `contract` label that both product owners approve. Once it is frozen (by Friday 30 October), it changes only by a new version number (`learn-progress-v2.md`), never in place.

## Standards

- **PHP:** the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/), checked with PHP_CodeSniffer. Keep everything PHP 7.4 compatible, because Learn's environment runs PHP 7.4.
- **The Dashboard half** follows the plugin's own rules: text domain `wpcredits-program-manager`, prefix `wpcpm` or `WPCPM`, no Composer or npm, and its `bin/check-standards.sh` clean. Tests are plain PHP files in `bin/` in the plugin's style, answered from the contract's example files, never from the network.
- **The Learn half** follows WordPress/Learn's conventions, and reads Sensei only through its public functions, never its `@internal` classes or its tables directly (section 6 of the technical guide).
- **Everything from Learn is untrusted:** escape it as text before printing it.
- **Words a person reads:** US English; full product names ("Student Report Card", "Mentor Report Card", "Administrator Dashboard", "Institution Dashboard"), never a bare "Report Card"; the program's staff are "Administrators"; plain hyphens, no long dashes anywhere.
- **Comments explain why,** and name the decision behind a rule (for example "see docs/decisions/002").
- **Significant decisions** get a short record in [docs/decisions/](docs/decisions/), from the template there.

## No secrets, no real data

These are not negotiable (section 11 of the handbook):

- **Invented data only** until the Phase 4 pilot: never real names, usernames, emails or grades in code, fixtures, issues, screenshots or AI tools.
- **No secrets in the repository:** no tokens, passwords, API keys or Airtable tokens, not even expired ones. Never put an Airtable token on any test site. Write test values so that no literal has the shape of a real key.
- **No production access:** nobody on the team gets credentials for learn.wordpress.org or wordpresseducation.org.
- The endpoint takes no user parameter, and the token works only on the learn-progress routes. You are participants too, so the design must make it impossible, not just forbidden, to read a classmate's results.
- Hashes on Learn, sealed tokens on the Dashboard, and only fingerprints in logs.

If a secret or real data lands in a commit, tell Maciej at once. Deleting it in a later commit is not enough, because the history keeps it.

## Reporting a security problem

Tell Maciej privately by email (the address is in your project handbook), not in a public issue or pull request.

## AI coding tools

If you use them, you are still the author. Read and understand every line before you commit it, and never paste tokens, passwords or anyone's real data into them.

## License

By contributing, you agree that your work is licensed under GPL-2.0-or-later, the license of this repository.
