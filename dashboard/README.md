# The Dashboard half

The `WPCPM_Learn_Progress` module for the WPCredits Program Manager plugin, which runs the Dashboard on wordpresseducation.org. This folder began empty in week 1 and now contains the parser, connection handlers, cached-progress state machine, progress card, styles, contract fixtures and isolated suites.

New files go here **in the same paths they will have in the plugin**, so that copying this folder over the plugin puts each file where it belongs. Changes to files that already exist in the plugin are not copied: they are listed in [INTEGRATION.md](INTEGRATION.md), and Maciej brings them into the plugin with each reviewed piece of work. Never open pull requests against WordPress/WPCredits for this work; that folder is a mirror that each plugin release overwrites.

## Dashboard files and responsibilities

From section 4.1 of the design spec, with the corrections in section 8.4 of the technical guide:

| File | Status | Responsibility |
| --- | --- | --- |
| `includes/modules/class-wpcpm-learn-progress.php` | Implemented | Defines the meta and action names, reads the five settings, gates registration behind `learn_enabled`, handles connection, callback identity and Administrator withdrawal, and derives the six panel states from locally cached progress. A withdrawal deletes the active local token and cache immediately, keeps only a sealed retry value when Learn cannot confirm revocation, records the Administrator and tells the student through an editable Emails-tool template. Only the owning student may request a stale-cache refresh through the optional sync class. |
| `includes/modules/class-wpcpm-learn-client.php` | Implemented | Provides the pure `parse( array $body )` normalizer and `error_code()` mapping required by P07, and the network methods: `exchange()` trades the code for a token, `revoke()` withdraws it, and `fetch( $token, $courses )` reads the progress, sending `courses=<ids>` when it is given courses. Each network method returns a `WP_Error` whose code is the contract's `code` on failure. |
| `includes/modules/class-wpcpm-learn-progress-card.php` | Implemented | Renders the six states into the "My course" section of the Student Report Card without using the network, including the capability-gated Administrator withdrawal form for a connected student. The Mentor Report Card summary belongs to a later task. |
| `includes/modules/class-wpcpm-learn-progress-sync.php` | Implemented | The cached read: `refresh()` reads a student's progress when the cache is stale, with the student's track course (or the `learn_courses` override) as `courses=`, keeps the cache on a transient failure and records the last error. The nightly warmer is Phase 3: follow `WPCPM_Outcome_Figures`, not `WPCPM_Sync_Module` (an Airtable class). Withdrawals that did not reach Learn are retried here too (#25). |
| `assets/css/learn.css` | Implemented | Provides the panel, progress bar, lessons table and withdrawal form rules without fixed theme colors. |
| `bin/fixtures/learn-progress-*.json` | Implemented | Based on the [contract's example files](../contract/), with adaptations documented in [the fixture notes](bin/fixtures/README.md). The `learn-progress-` prefix avoids the plugin's existing `learn-course-*.json` and `learn-structure-*.json` fixtures. |
| `bin/test-learn-client.php` | Implemented | Exercises every contract fixture, strict normalization, vocabulary, types, percentage clamping and error-code mapping. |
| `bin/test-learn-progress.php` | Implemented | Exercises settings, feature gating, connection, callback and withdrawal handlers, identity checks, stored metadata, sealed revocation retry data, withdrawal mail and state-machine priority. |
| `bin/test-learn-card.php` | Implemented | Exercises the six states, visible wording, ownership rules, escaping, cached contract examples, refresh ownership and withdrawal-form authorization. |
| `bin/test-learn-progress-sync.php` | Implemented | Exercises the cached read, its errors and the `courses=` it sends. |

There is no `class-wpcpm-learn-structure.php`, although the design spec plans one: the plugin's existing `WPCPM_Learn` already reads and caches the public course structure, so reuse it for lesson titles and modules.

## The six states of the panel

Not connected; connected, nothing started; connected, in progress; connected, complete; connection withdrawn; Learn did not answer. Each has its own words (section 3.1 of the design spec). A withdrawn or unreachable connection never shows as 0%. Everything from Learn is escaped as text; nothing it sends is trusted as markup.

## Testing it locally

Copy this folder over the plugin on your local Dashboard site (step 3 of the [README](../README.md)), leaving out the two Markdown files:

```bash
rsync -a --exclude README.md --exclude INTEGRATION.md ~/learn-link/dashboard/ ~/Studio/learn-link-dashboard/wp-content/plugins/wpcredits-program-manager/
```

On Windows, copy the folders with File Explorer instead. Then make the edits in [INTEGRATION.md](INTEGRATION.md) by hand in that local copy, and run the suites from the plugin's root folder (step 5 of the README).
