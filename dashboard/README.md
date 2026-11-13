# The Dashboard half

The `WPCPM_Learn_Progress` module for the WPCredits Program Manager plugin, which runs the Dashboard on wordpresseducation.org. Nothing is built here yet: this folder starts empty in week 1.

New files go here **in the same paths they will have in the plugin**, so that copying this folder over the plugin puts each file where it belongs. Changes to files that already exist in the plugin are not copied: they are listed in [INTEGRATION.md](INTEGRATION.md), and Maciej brings them into the plugin with each reviewed piece of work. Never open pull requests against WordPress/WPCredits for this work; that folder is a mirror that each plugin release overwrites.

## Planned files

From section 4.1 of the design spec, with the corrections in section 8.4 of the technical guide:

| File | Responsibility |
| --- | --- |
| `includes/modules/class-wpcpm-learn-progress.php` | The module: settings keys, the three handlers (`wpcpm_learn_connect`, `wpcpm_learn_callback`, `wpcpm_learn_withdraw`), the state machine of the six card states, the identity check, meta keys. |
| `includes/modules/class-wpcpm-learn-client.php` | The HTTP client. `fetch( $token )` returns the decoded body or a `WP_Error` whose code is the contract's `code`; `parse( array $body )` is a pure function from the body to the plugin's own shape, and is what the suites test. Split exactly as the existing `WPCPM_Learn` splits fetch and parse. |
| `includes/modules/class-wpcpm-learn-progress-card.php` | Renders the six states into the "My course" section of the Student Report Card, and the one-line summary on the Mentor Report Card. Touches no network. |
| `includes/modules/class-wpcpm-learn-progress-sync.php` | The nightly warmer. Follow `WPCPM_Outcome_Figures`, not `WPCPM_Sync_Module` (an Airtable class). Also retries withdrawals that did not reach Learn. |
| `assets/css/learn.css` | Base rules for the panel: progress bar, modules, lessons table. |
| `bin/fixtures/learn-progress-*.json` | Based on the [contract's example files](../contract/), with adaptations documented in [the fixture notes](bin/fixtures/README.md). Use the `learn-progress-` prefix: the plugin already has `learn-course-*.json` and `learn-structure-*.json` fixtures for `WPCPM_Learn`. |
| `bin/test-learn-client.php`, `bin/test-learn-progress.php`, `bin/test-learn-card.php` | The three suites: parsing every fixture; the state machine, identity check and withdrawals; each state's words and escaping. |

There is no `class-wpcpm-learn-structure.php`, although the design spec plans one: the plugin's existing `WPCPM_Learn` already reads and caches the public course structure, so reuse it for lesson titles and modules.

## The six states of the panel

Not connected; connected, nothing started; connected, in progress; connected, complete; connection withdrawn; Learn did not answer. Each has its own words (section 3.1 of the design spec). A withdrawn or unreachable connection never shows as 0%. Everything from Learn is escaped as text; nothing it sends is trusted as markup.

## Testing it locally

Copy this folder over the plugin on your local Dashboard site (step 3 of the [README](../README.md)), leaving out the two Markdown files:

```bash
rsync -a --exclude README.md --exclude INTEGRATION.md ~/learn-link/dashboard/ ~/Studio/learn-link-dashboard/wp-content/plugins/wpcredits-program-manager/
```

On Windows, copy the folders with File Explorer instead. Then make the edits in [INTEGRATION.md](INTEGRATION.md) by hand in that local copy, and run the suites from the plugin's root folder (step 5 of the README).
