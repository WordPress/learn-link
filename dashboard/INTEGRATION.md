# Where the module connects to the plugin

The few places in existing WPCredits Program Manager files that the module needs, from section 8.2 of the technical guide. Paths are from the plugin's root folder. Line numbers are for **WPCredits Program Manager 1.122.27**; search by name if they move.

When your work needs an edit in one of these files, describe the exact lines in your pull request, and add them to the "Edits to bring into the plugin" list at the end of this page. Maciej brings them into the plugin with each reviewed piece of work.

| What | Where | Notes |
| --- | --- | --- |
| Student Report Card | `includes/modules/class-wpcpm-students-dashboard.php`: `render()` 214-336; the "My course" case 540-546; `render_links()` 837-878 | There are no hooks here, so the panel needs a small edit in `render_links()` that calls your class. |
| Mentor Report Card | `includes/modules/class-wpcpm-mentors-dashboard.php`: `render_mentee()` 1026-1268, its `$fields` table 1126-1221; `triage_data()` 356-470 | `triage_data()` is where a "not connected" filter goes. |
| The existing Learn reader | `includes/class-wpcpm-learn.php` | Reads public course data and the course structure, with a fetch and parse split and one-day transients. Reuse it for titles and modules; it is also your best template for an HTTP client. Tests: `bin/test-learn.php` with `bin/fixtures/learn-*.json`. |
| Track to course | `includes/class-wpcpm-program.php`: `course_ids()` and `course_id()` 128-156; filter `wpcpm_program_course_ids` | The track seeds in `includes/tracks/seeds/*.json` carry `learn_course_id`. |
| Settings and the switch | `includes/class-wpcpm-settings.php` | Every new key needs `defaults()`, `scopes()` (245) and `write()` (booleans at 693). There are no feature constants: switches are booleans in the option `wpcpm_settings`, for example `checker_cron_enabled`. `bin/test-settings.php` checks the round trip. |
| Storing the token | `includes/class-wpcpm-secret.php` (AES-256-GCM): `seal_for_option()` 131, `unseal_from_option()` 143, `fingerprint()` 160 | If sealing returns a `WP_Error`, refuse; never store a token in clear. Log only the fingerprint. |
| The nightly warmer | `includes/class-wpcpm-outcome-figures.php` 197-299 | Copy its cron pattern: schedule on `init` when missing, clear on deactivate, return early when the switch is off. |
| Who may withdraw | `WPCPM_Roles::CAP_MANAGE` (`wpcpm_manage_program`), `includes/class-wpcpm-roles.php:33` | Admin handlers check the capability, then the nonce. Use this capability, not `manage_options` as the design spec says. |
| Booting the module | `WPCPM_Students::boot()`, `includes/modules/class-wpcpm-students.php` 97-112; the main file `wpcredits-program-manager.php` | The module is a feature, not an audience: do not extend `WPCPM_Module`. Give it a static `init()` booted from `WPCPM_Students::boot()`, and add its `require_once` to the main file. |
| Uninstall | `uninstall.php` | Removes the plugin's data; add your meta keys there. Careful: line 251 sweeps every `_transient_wpcpm_learn_*` transient, a prefix `WPCPM_Learn` already uses. |
| The student guide | `docs/sections/NN-name.md`, listed in `bin/build-docs.php` | Run `php bin/build-docs.php` after adding a section. The "My course" section is described in `docs/sections/10-student-card.md`. |

A new module is also checked by existing suites: `bin/test-settings.php`, `bin/test-uninstall.php`, `bin/test-handlers.php` and `bin/test-student-modules.php`.

## Where the design spec and today's code disagree

From section 8.4 of the technical guide. Follow the code, not the spec, on each of these:

- The spec assumes mentors open their students' Student Report Cards. Today only Administrators can.
- The spec plans a new `WPCPM_Learn_Structure` class. `WPCPM_Learn` already reads and caches the course structure: reuse it.
- The spec uses the `manage_options` capability. Use `WPCPM_Roles::CAP_MANAGE`.
- The spec writes audit rows, but the plugin's audit log only accepts institution and sponsor records. Propose how to log connect and withdraw.
- The spec calls the warmer a `WPCPM_Sync_Module`, which is an Airtable class. Follow `WPCPM_Outcome_Figures` instead.
- The student's username on the Dashboard comes from Airtable today. See [ADR 005](../docs/decisions/005-identity-key.md).

## Edits to bring into the plugin

None yet. Add one entry per edit: the file, the function, the lines to add or change, and the pull request that needs it.
