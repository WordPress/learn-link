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

| Block | Existing plugin file | Edit | Owner | Task |
| --- | --- | --- | --- | --- |
| #1a | `wpcredits-program-manager.php` | Load `class-wpcpm-learn-client.php`, `class-wpcpm-learn-progress-card.php` and `class-wpcpm-learn-progress.php` after the existing Learn and secret classes. | Pedro | P1.1/P1.3 |
| #2 | `includes/modules/class-wpcpm-students.php`, `WPCPM_Students::boot()` | Call `WPCPM_Learn_Progress::init()` after `WPCPM_Students_Dashboard::init()`. | Pedro | P1.1 |
| #3 | `includes/class-wpcpm-settings.php` | Add the five Learn Link keys to `defaults()`, the `advanced` scope and `write()`, including validation for each value. | Pedro | P1.1 |
| #4 | `includes/class-wpcpm-settings-screen.php`, `WPCPM_Settings_Screen::render_tab_advanced()` | Add the Learn Link section and its five settings controls. | Pedro | P1.1 |
| #5 | `includes/modules/class-wpcpm-students-dashboard.php`, `render_links()` | Render `WPCPM_Learn_Progress::panel( $student )` below the course button and hours inside the My course section. | Pedro | P1.3 |
| #6 | `includes/modules/class-wpcpm-students-dashboard.php`, `register()` and `render()` | Register `assets/css/learn.css` after `student.css`, then enqueue it with the Student Report Card. | Pedro | P1.3 |

### Block #1a: Learn Link classes

After the existing Learn and secret classes are loaded in `wpcredits-program-manager.php`, load the three Phase 1 classes in dependency order:

```php
require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-client.php';
require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-progress-card.php';
require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-progress.php';
```

### Block #5: Student Report Card panel

In `WPCPM_Students_Dashboard::render_links()`, keep the existing two-column course content together, then render the Learn panel at full width before closing the My course section:

This is a temporary Phase 1 path for local review. `WPCPM_Learn_Progress::panel()` reads `bin/fixtures/learn-progress-in-progress.json` only when `WPCPM_LEARN_FIXTURE` is explicitly set to `true` in the local site's `wp-config.php`. The constant must not be added to this repository, the plugin or a production site. A developer may instead set it to the absolute path of another contract fixture for a local check. P16 replaces this fixture path with the student's cached Learn progress.

```php
self::render_course_button( $course );
WPCPM_Student_Report_Form::render_hours( $student, $program );

echo '</div>';

if ( class_exists( 'WPCPM_Learn_Progress' ) ) {
	echo WPCPM_Learn_Progress::panel( $student ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The card renderer escapes every value.
}

echo '</section>';
```

### Block #6: Learn progress stylesheet

In `WPCPM_Students_Dashboard::register()`, after registering `self::STYLE`, register the Learn stylesheet with the Student Report Card stylesheet as its dependency:

```php
if ( class_exists( 'WPCPM_Learn_Progress_Card' ) && ! wp_style_is( WPCPM_Learn_Progress_Card::STYLE, 'registered' ) ) {
	wp_register_style(
		WPCPM_Learn_Progress_Card::STYLE,
		WPCPM_PLUGIN_URL . 'assets/css/learn.css',
		array( self::STYLE ),
		WPCPM_VERSION
	);
}
```

In `WPCPM_Students_Dashboard::render()`, immediately after `wp_enqueue_style( self::STYLE );`, enqueue the registered Learn stylesheet:

```php
if ( class_exists( 'WPCPM_Learn_Progress_Card' ) ) {
	wp_enqueue_style( WPCPM_Learn_Progress_Card::STYLE );
}
```

### Block #3: Learn Link settings

In `WPCPM_Settings::defaults()`, add the five Learn Link defaults:

```php
'learn_enabled'     => false,
'learn_base_url'    => 'https://learn.wordpress.org',
'learn_client_id'   => 'wpcredits',
'learn_courses'     => '297853',
'learn_cache_hours' => 6,
```

In `WPCPM_Settings::scopes()`, add the five keys to the `advanced` scope:

```php
'learn_enabled',
'learn_base_url',
'learn_client_id',
'learn_courses',
'learn_cache_hours',
```
In `WPCPM_Settings::write()`, add `learn_cache_hours` to the existing `$limits` array:

```php

'learn_cache_hours' => array( 1, 48 ),
```

Add `learn_enabled` to the existing boolean flags loop:

```php
foreach ( array( 'checker_cron_enabled', 'checker_cron_promotes', 'checker_skip_airtable', 'learn_enabled' ) as $flag ) {
	if ( isset( $reads[ $flag ] ) ) {
		$clean[ $flag ] = ! empty( $input[ $flag ] );
	}
}
```

Sanitize the Learn client ID as a key:

```php
if ( isset( $input['learn_client_id'] ) ) {
	$client_id                = is_string( $input['learn_client_id'] ) ? wp_unslash( $input['learn_client_id'] ) : '';
	$clean['learn_client_id'] = sanitize_key( $client_id );
}
```
Normalize the comma-separated Learn course IDs, preserving their first occurrence order:

```php
if ( isset( $input['learn_courses'] ) ) {
	$courses = is_string( $input['learn_courses'] ) ? explode( ',', wp_unslash( $input['learn_courses'] ) ) : array();
	$courses = array_map( 'absint', $courses );
	$courses = array_filter( $courses );
	$courses = array_unique( $courses );

	$clean['learn_courses'] = implode( ',', $courses );
}
```

Allow HTTPS Learn URLs, and allow HTTP only for `localhost` when WordPress runs in its local environment:

```php
if ( isset( $input['learn_base_url'] ) ) {
	$raw    = is_string( $input['learn_base_url'] ) ? trim( wp_unslash( $input['learn_base_url'] ) ) : '';
	$url    = esc_url_raw( $raw, array( 'https', 'http' ) );
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

	$is_https         = 'https' === $scheme;
	$is_localhost     = 'localhost' === $host;
	$is_local_site    = 'local' === wp_get_environment_type();
	$is_local_request = $is_local_site && $is_localhost && 'http' === $scheme;

	$clean['learn_base_url'] = $is_https || $is_local_request
		? $url
		: self::defaults()['learn_base_url'];
}
```

`bin/test-settings.php` must provide a `wp_get_environment_type()` stub and test both the production and local cases.

### Block #4: Learn Link settings screen

In `WPCPM_Settings_Screen::render_tab_advanced()`, add the Learn Link section after the existing Invitations section:

```php
$this->open_section(
	__( 'Learn Link', 'wpcredits-program-manager' ),
	__( 'The public connection details used to read a student\'s course progress from Learn WordPress.', 'wpcredits-program-manager' ),
	'learn-link'
);

$learn_enabled = __( 'Progress connection', 'wpcredits-program-manager' );

printf(
	'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="learn_enabled" value="1"%2$s> %3$s</label><p class="description">%4$s</p>',
	esc_html( $learn_enabled ),
	checked( ! empty( $settings['learn_enabled'] ), true, false ),
	esc_html__( 'Enable Learn Link', 'wpcredits-program-manager' ),
	esc_html__( 'When off, the Dashboard registers no Learn Link handlers and shows no Learn progress.', 'wpcredits-program-manager' )
);
WPCPM_Settings_Rows::close_row( $learn_enabled );

WPCPM_Settings_Rows::text_row(
	'learn_base_url',
	__( 'Learn site URL', 'wpcredits-program-manager' ),
	$settings['learn_base_url'],
	__( 'The Learn site that supplies progress; HTTPS is required except for localhost on a local WordPress environment.', 'wpcredits-program-manager' ),
	'url'
);
WPCPM_Settings_Rows::text_row(
	'learn_client_id',
	__( 'Client ID', 'wpcredits-program-manager' ),
	$settings['learn_client_id'],
	__( 'The public client identifier registered on the Learn site.', 'wpcredits-program-manager' )
);
WPCPM_Settings_Rows::text_row(
	'learn_courses',
	__( 'Course IDs', 'wpcredits-program-manager' ),
	$settings['learn_courses'],
	__( 'Comma-separated Learn course IDs, with the program track course first.', 'wpcredits-program-manager' ),
	'text',
	'',
	'297853'
);
WPCPM_Settings_Rows::number_row(
	'learn_cache_hours',
	__( 'Student cache (hours)', 'wpcredits-program-manager' ),
	$settings['learn_cache_hours'],
	1,
	48,
	__( 'How long a student\'s own page trusts cached progress before reading Learn again.', 'wpcredits-program-manager' )
);

$this->close_section();
```

Update `docs/sections/31-admin-settings.md` and rebuild the generated guides. Extend `bin/test-settings.php` so all five controls make a round trip and the URL validation covers production and local environments.
