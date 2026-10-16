# 003. Where the Learn code lives

- **Status:** Open. Open decision 6 in handbook section 4; the WordPress Meta team decides.
- **Date:**
- **Decided by:** the WordPress Meta team (Learn)
- **Issue:**

_Stub. Replace each "To be written" with a few sentences of your own._

## Context

Learn's site plugin is `wp-content/plugins/wporg-learn`; its features are files in `inc/`, loaded by `wporg-learn.php` (for example `inc/profiles.php` and `inc/activity-kit-rest.php`). A separate plugin folder would also have to be added to `.github/workflows/build.yml` in WordPress/Learn, or the build removes it. A Meta committer deploys every change by hand; the team never deploys anything to Learn.

To be written: what the Meta team said, with a link to the #meta-learn thread and the WordPress/Learn issue.

## Decision

To be written once the Meta team answers. Until then: build a small standalone plugin during the term, written so that it can move into the `wporg-learn` plugin's `inc/` folder unchanged.

## Alternatives considered

To be written. At least: a module in `wporg-learn/inc/`; a plugin of its own on Learn; an endpoint upstream in Sensei LMS itself.

## Consequences

To be written. For example: what has to change, if anything, when the code moves.
