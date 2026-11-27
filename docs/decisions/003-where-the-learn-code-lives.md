# 003. Where the Learn code lives

- **Status:** Open
- **Date:** 2026-11-18 (last update)
- **Decided by:** the WordPress Meta team (Learn), decision 6 in #2
- **Issue:** #2, and WordPress/Learn#3732

## Context

Learn's site plugin is `wp-content/plugins/wporg-learn`; its features are files in `inc/`, loaded by `wporg-learn.php` (for example `inc/profiles.php` and `inc/activity-kit-rest.php`). A separate plugin folder would also have to be added to `.github/workflows/build.yml` in WordPress/Learn, or the build removes it. A Meta committer deploys every change by hand; the team never deploys anything to Learn.

We asked the Learn team in [WordPress/Learn#3732](https://github.com/WordPress/Learn/issues/3732) on 26 October, followed up on 6 November, and Maciej nudged on 13 November. On 17 November Konstantin Obenland answered the storage question (see 006) and asked whether this can live in its entirety on learn.wordpress.org, adding that "in the current climate around security and limiting any possible attack surfaces, it doesn't feel prudent to add another one with the oauth-style setup that's being proposed." On 18 November Maciej agreed that the end goal is for this to live under wordpress.org/education, managed by Meta on its infrastructure, and that the team is preparing the tooling so the move is small.

## Decision

Not decided yet. Until the Learn team decides, the Learn half stays a small standalone plugin, `learn-progress-api`, written so that it can move into `wporg-learn/inc/` unchanged: its own namespace, the `wporg-learn` text domain, and the code that reads Sensei kept apart from the consent and token parts, so the reader can move on its own.

## Alternatives considered

- **A module in `wporg-learn/inc/`:** the shape the plugin is written for. It needs the Learn team's yes, and their concern about the authorization surface answered first.
- **A plugin of its own on Learn:** it also needs a line in `build.yml`, and the same review.
- **An endpoint upstream in Sensei LMS:** the Sensei team said on 19 October (Automattic/sensei#8336) that an official Sensei REST API is not a short-term goal.
- **Everything on wordpress.org/education, run by Meta:** the end goal Maciej described on 18 November. How the reader reaches Learn's data from there is part of the open question.

## Consequences

- Nothing from this repository is deployed to Learn. Every test so far ran against a local Learn, and the Phase 2 integration day reached it through a temporary tunnel (007).
- The Learn team's security concern has to be answered before any deploy. That work moves to Phase 4 (#45).
- Decision 4 in #2 also waits on Learn: each new WordPress Credits track needs its course added to the client's fixed list on Learn's side, and the Learn team has not said yet whether they agree.
- If the code moves into `wporg-learn/inc/`, only the loader changes. If it moves elsewhere, the reader in `inc/reader.php` is the part written to move as it is.
