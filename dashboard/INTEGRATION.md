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
| Uninstall | `uninstall.php` | Removes the plugin's data; add your meta keys there. The state prefix `wpcpm_learn_state_` is covered by the existing `_transient_wpcpm_learn_` and `_transient_timeout_wpcpm_learn_` sweeps, for both values and expiration times. |
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
| #1a | `wpcredits-program-manager.php`, `uninstall.php` | Load `includes/modules/class-wpcpm-learn-progress.php` with `require_once` after the existing Learn and secret classes have been loaded. The uninstall loader must require the same class. This block is also reserved for the Learn client and progress card when those files are added. | Pedro | P1.1 |
| #2 | `includes/modules/class-wpcpm-students.php`, `WPCPM_Students::boot()`, `bin/test-students-screen.php` | Call `WPCPM_Learn_Progress::init()` after `WPCPM_Students_Dashboard::init()`, and add its no-op stub to the isolated students screen suite. | Pedro | P1.1 |
| #3 | `includes/class-wpcpm-settings.php` | Add the five Learn Link keys to `defaults()`, the `advanced` scope and `write()`, including validation for each value. | Pedro | P1.1 |
| #4 | `includes/class-wpcpm-settings-screen.php`, `WPCPM_Settings_Screen::render_tab_advanced()` | Add the Learn Link section and its five settings controls. | Pedro | P1.1 |

### Block #1a: load the module in normal and uninstall requests

In `wpcredits-program-manager.php`, after the existing Learn and secret classes
are loaded, add:

```php
require_once WPCPM_PLUGIN_DIR . 'includes/modules/class-wpcpm-learn-progress.php';
```

In `uninstall.php`, beside its existing `class-wpcpm-learn.php` include, load
the same module with the uninstall file's path convention. `bin/test-roles.php`
checks that every class loaded by the plugin is also available to uninstall:

```php
require_once plugin_dir_path( __FILE__ ) . 'includes/modules/class-wpcpm-learn-progress.php';
```

### Block #2: boot the module and keep the isolated screen suite loadable

In `WPCPM_Students::boot()`, immediately after
`WPCPM_Students_Dashboard::init()`, add:

```php
WPCPM_Learn_Progress::init();
```

In `bin/test-students-screen.php`, beside its `WPCPM_Students_Dashboard`
stand-in, add the class that the updated `boot()` now calls:

```php
class WPCPM_Learn_Progress {
	public static function init() {}
}
```

### Block #3: Learn Link settings

In `WPCPM_Settings::defaults()`, add the five Learn Link defaults:

```php
'learn_enabled'     => false,
'learn_base_url'    => 'https://learn.wordpress.org',
'learn_client_id'   => 'wpcredits',
'learn_courses'     => '',
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

Add `learn_enabled` to the existing guarded boolean flags loop: locate the loop
whose body checks `array_key_exists( $flag, $input )` (the same loop as
`mcp_enabled` and `sponsor_tiers_live`) and append `'learn_enabled'` to its
array of flags. Do not add it to the Mentor Status Checker flags loop, whose
missing values mean "off".

```php
if ( array_key_exists( 'learn_enabled', $input ) ) {
	$clean['learn_enabled'] = ! empty( $input['learn_enabled'] );
}
```

The separate snippet above shows the exact guarded behavior to preserve when
adding the key to that existing loop. A scoped save of the Advanced form still
passes an unticked checkbox as `false`; a partial write that does not contain
`learn_enabled` leaves its saved value unchanged.

Sanitize the Learn client ID as a key:

```php
if ( isset( $input['learn_client_id'] ) ) {
	$client_id                = is_string( $input['learn_client_id'] ) ? wp_unslash( $input['learn_client_id'] ) : '';
	$clean['learn_client_id'] = sanitize_key( $client_id );
}
```
Validate the complete comma-separated Learn course list before changing the
saved value. Each ID must contain only decimal digits and represent a positive
integer within PHP's integer range. Trim whitespace, normalize leading zeros,
and remove duplicates while preserving their first occurrence order. A blank
string means "no override" and saves an empty value, so the Dashboard uses the
student's own track course. For a non-blank value, reject empty entries,
non-string input, and any invalid ID as a whole; do not save just the valid part
of a malformed list. A missing key leaves the saved value alone. `$clean`
already contains the saved settings in `write()`.

```php
if ( array_key_exists( 'learn_courses', $input ) ) {
	$raw     = is_string( $input['learn_courses'] ) ? trim( wp_unslash( $input['learn_courses'] ) ) : null;
	$courses = array();
	$valid   = is_string( $raw );

	if ( '' === $raw ) {
		$clean['learn_courses'] = '';
	} elseif ( $valid ) {
		foreach ( explode( ',', $raw ) as $course ) {
			$course = trim( $course );
			$id     = ltrim( $course, '0' );

			if ( ! preg_match( '/^[0-9]+$/D', $course ) || '' === $id || (string) (int) $id !== $id ) {
				$valid = false;
				break;
			}

			$courses[] = $id;
		}
	}

	if ( '' !== $raw && $valid ) {
		$clean['learn_courses'] = implode( ',', array_unique( $courses ) );
	} elseif ( '' !== $raw ) {
		WPCPM_Flash::set( 'learn-courses-refused', true );
	}
}
```

At the **start** of `WPCPM_Settings::render_notices()`, before taking
`settings-defaults` and before its early return, insert this notice. The method
is already hooked to `admin_notices`; no extra hook is needed. Taking the
notice clears its stored value for the next request. Never echo rejected input.

```php
if ( WPCPM_Flash::take( 'learn-courses-refused' ) ) {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'The Learn course IDs were not saved. Enter positive whole numbers separated by commas. Your previous course IDs were kept.', 'wpcredits-program-manager' ) . '</p></div>';
}
```

Validate the raw address before sanitizing it, so a missing scheme or an invalid
host cannot be silently repaired into an accepted address. Accept HTTPS, or
HTTP only for `localhost` when WordPress reports the `local` environment.
Reject credentials (including an empty user or password component), query
strings and fragments: this is a base URL to which endpoint paths are appended.
Subdirectory installations and explicit ports remain supported. Remove trailing
slashes from valid addresses. An invalid value keeps `$clean['learn_base_url']`
unchanged and queues a notice; an absent key leaves it alone.

```php
if ( array_key_exists( 'learn_base_url', $input ) ) {
	$raw    = is_string( $input['learn_base_url'] ) ? trim( wp_unslash( $input['learn_base_url'] ) ) : '';
	$parts  = wp_parse_url( $raw );
	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	$host   = strtolower( (string) ( $parts['host'] ?? '' ) );

	$is_https         = 'https' === $scheme;
	$is_local_request = 'local' === wp_get_environment_type() && 'localhost' === $host && 'http' === $scheme;
	$is_valid         = is_array( $parts ) && '' !== $host && false !== filter_var( $raw, FILTER_VALIDATE_URL );
	$has_credentials  = isset( $parts['user'] ) || isset( $parts['pass'] );
	$has_suffix       = isset( $parts['query'] ) || isset( $parts['fragment'] );

	if ( $is_valid && ( $is_https || $is_local_request ) && ! $has_credentials && ! $has_suffix ) {
		$url = esc_url_raw( $raw, array( 'https', 'http' ) );

		if ( $url === $raw ) {
			$clean['learn_base_url'] = rtrim( $url, '/' );
		} else {
			WPCPM_Flash::set( 'learn-url-refused', true );
		}
	} else {
		WPCPM_Flash::set( 'learn-url-refused', true );
	}
}
```

At the start of `WPCPM_Settings::render_notices()`, beside the course list
notice and before the `settings-defaults` early return, insert:

```php
if ( WPCPM_Flash::take( 'learn-url-refused' ) ) {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'The Learn site URL was not saved. Enter an HTTPS base URL without credentials, a query string or a fragment. HTTP is allowed only for localhost in a local environment. Your previous URL was kept.', 'wpcredits-program-manager' ) . '</p></div>';
}
```

The notice must never include the rejected address, which may contain a password.
The URL regression cases below include the environment stub needed by
`bin/test-settings.php`.

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
	__( 'Leave blank to use each student\'s own track course. On a test copy, enter the local course IDs, separated by commas.', 'wpcredits-program-manager' ),
	'text',
	'',
	''
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

Update `docs/sections/31-admin-settings.md` and rebuild the generated guides. Extend `bin/test-settings.php` so all five controls make a round trip, a partial write without `learn_enabled` preserves its saved value, and the URL validation covers production and local environments.

`bin/test-settings-choices.php` also loads and saves the settings. If it does
not already define the environment helper, add the same configurable stub used
by the settings suite:

```php
function wp_get_environment_type() {
	return $GLOBALS['environment_type'] ?? 'production';
}
```

### Round-trip and switch cases for `bin/test-settings.php`

Apply blocks #3 and #4 before running the settings suite. Add the environment
stub shown under the URL cases below if it is not already present. Keep the
suite's existing `ck()` function, WordPress stubs, and in-memory option store.

Add the four non-boolean fields below to the suite's existing `$probe` array,
without duplicating keys already present. Its `$bool_probe` loop automatically
includes `learn_enabled` after the boolean default is added. Also append the
five Learn keys to the expected `advanced` scope list if the suite has a
separate expected scope table. Keep its other entries unchanged.

```php
'learn_base_url'    => 'https://learn.example.test',
'learn_client_id'   => 'dashboard-test',
'learn_courses'     => '301001,297853',
'learn_cache_hours' => '12',
```

Insert the following cases after `ck()` is defined and before the final failure
summary, followed by the course list and URL cases. These exercise the real
`save()`, `patch()` and `get_value()` methods. Each group restores the test
option store, user meta and signed-in user so it does not alter later cases.
The course and URL groups also check the actual notice renderer.

```php
$wpcpm_roundtrip_options = $GLOBALS['opts'];
$wpcpm_roundtrip_meta    = $GLOBALS['umeta'];
$wpcpm_roundtrip_user    = $GLOBALS['signed_in'] ?? null;
$GLOBALS['signed_in']   = 9100;
$wpcpm_roundtrip_defaults = array(
	'learn_enabled'     => false,
	'learn_base_url'    => 'https://learn.wordpress.org',
	'learn_client_id'   => 'wpcredits',
	'learn_courses'     => '',
	'learn_cache_hours' => 6,
);
$wpcpm_roundtrip_all_defaults = WPCPM_Settings::defaults();
$wpcpm_roundtrip_scopes       = WPCPM_Settings::scopes();

foreach ( $wpcpm_roundtrip_defaults as $wpcpm_roundtrip_key => $wpcpm_roundtrip_default ) {
	ck( 'Learn default and type: ' . $wpcpm_roundtrip_key, $wpcpm_roundtrip_all_defaults[ $wpcpm_roundtrip_key ] ?? null, $wpcpm_roundtrip_default );
	$wpcpm_roundtrip_owners = array();
	foreach ( $wpcpm_roundtrip_scopes as $wpcpm_roundtrip_scope => $wpcpm_roundtrip_keys ) {
		if ( in_array( $wpcpm_roundtrip_key, $wpcpm_roundtrip_keys, true ) ) {
			$wpcpm_roundtrip_owners[] = $wpcpm_roundtrip_scope;
		}
	}
	ck( 'Learn setting belongs only to Advanced: ' . $wpcpm_roundtrip_key, $wpcpm_roundtrip_owners, array( 'advanced' ) );
}

$wpcpm_roundtrip_input = array(
	'learn_enabled'     => '1',
	'learn_base_url'    => 'https://learn.example.test',
	'learn_client_id'   => 'dashboard-test',
	'learn_courses'     => '301001,297853',
	'learn_cache_hours' => '12',
);
$wpcpm_roundtrip_expected                      = $wpcpm_roundtrip_input;
$wpcpm_roundtrip_expected['learn_enabled']     = true;
$wpcpm_roundtrip_expected['learn_cache_hours'] = 12;
$wpcpm_roundtrip_saved = WPCPM_Settings::save( $wpcpm_roundtrip_input, 'advanced' );

foreach ( $wpcpm_roundtrip_expected as $wpcpm_roundtrip_key => $wpcpm_roundtrip_value ) {
	ck( 'Learn save returns the expected value: ' . $wpcpm_roundtrip_key, $wpcpm_roundtrip_saved[ $wpcpm_roundtrip_key ], $wpcpm_roundtrip_value );
	ck( 'Learn value can be read back: ' . $wpcpm_roundtrip_key, WPCPM_Settings::get_value( $wpcpm_roundtrip_key ), $wpcpm_roundtrip_value );
}

WPCPM_Settings::save( array( 'learn_cache_hours' => '24' ) );
ck( 'an unscoped save without the switch keeps it on', WPCPM_Settings::get_value( 'learn_enabled' ), true );
WPCPM_Settings::patch( array( 'learn_cache_hours' => 18 ) );
ck( 'a partial patch without the switch keeps it on', WPCPM_Settings::get_value( 'learn_enabled' ), true );
WPCPM_Settings::save( array( 'mail_contact' => 'programs@example.test', 'learn_enabled' => false ), 'tool:emails' );
ck( 'a different scope cannot change the Learn switch', WPCPM_Settings::get_value( 'learn_enabled' ), true );

WPCPM_Settings::save( array( 'learn_enabled' => false ), 'advanced' );
ck( 'explicit false turns the Learn switch off', WPCPM_Settings::get_value( 'learn_enabled' ), false );
WPCPM_Settings::patch( array( 'learn_enabled' => true ) );
ck( 'an explicit patch can turn the Learn switch on', WPCPM_Settings::get_value( 'learn_enabled' ), true );
WPCPM_Settings::save( array( 'learn_cache_hours' => '12' ), 'advanced' );
ck( 'an unchecked box on Advanced turns the Learn switch off', WPCPM_Settings::get_value( 'learn_enabled' ), false );
WPCPM_Settings::patch( array( 'learn_cache_hours' => 18 ) );
ck( 'a partial patch also preserves the off state', WPCPM_Settings::get_value( 'learn_enabled' ), false );

WPCPM_Settings::patch( array( 'learn_cache_hours' => 0 ) );
ck( 'Learn cache hours have a lower bound', WPCPM_Settings::get_value( 'learn_cache_hours' ), 1 );
WPCPM_Settings::patch( array( 'learn_cache_hours' => 999 ) );
ck( 'Learn cache hours have an upper bound', WPCPM_Settings::get_value( 'learn_cache_hours' ), 48 );
WPCPM_Settings::patch( array( 'learn_client_id' => 'WP Credits!' ) );
ck( 'Learn client ID is sanitized as a key', WPCPM_Settings::get_value( 'learn_client_id' ), 'wpcredits' );

$GLOBALS['opts']      = $wpcpm_roundtrip_options;
$GLOBALS['umeta']     = $wpcpm_roundtrip_meta;
$GLOBALS['signed_in'] = $wpcpm_roundtrip_user;
```

Run `php bin/test-settings.php` from the integrated plugin root. Replace old
Learn-specific cases that expect an invalid URL to restore defaults or a
malformed course list to be partially accepted with the regression cases below.
Do not remove unrelated tests. These snippets are additions to that suite, not
a replacement for it. The isolated `test-learn-progress.php` suite tests the
module and cannot verify the settings writer.

### Course list regression cases for `bin/test-settings.php`

Insert these cases after the existing `mail_contact` validation cases and before
the final failure summary. They use the suite's existing `ck()`, in-memory
`$GLOBALS['opts']`, and the real `WPCPM_Flash` with in-memory user meta. Apply both the validation and notice
snippets above first. These cases exercise the real settings class, including
the notice renderer, without changing a WordPress database.

```php
$wpcpm_learn_saved_options = $GLOBALS['opts'];
$wpcpm_learn_saved_meta    = $GLOBALS['umeta'];
$wpcpm_learn_saved_user    = $GLOBALS['signed_in'] ?? null;
$GLOBALS['signed_in']     = 9200;

$wpcpm_learn_saved = WPCPM_Settings::save(
	array( 'learn_courses' => ' 301001, 0297853,301001 ' ),
	'advanced'
);
ck( 'Learn course IDs keep their order, normalize zeros and remove duplicates', $wpcpm_learn_saved['learn_courses'], '301001,297853' );
ck( 'valid Learn course IDs do not queue a refusal', WPCPM_Flash::take( 'learn-courses-refused' ), '' );

$wpcpm_learn_invalid_courses = array(
	'abc',
	'297853abc',
	'301001,abc',
	'0',
	'-297853',
	'+297853',
	'297853.5',
	'1e3',
	'297853,',
	',297853',
	'297853,,301001',
	'297 853',
	(string) PHP_INT_MAX . '0',
	array( 297853 ),
	297853,
	false,
	null,
);

foreach ( $wpcpm_learn_invalid_courses as $wpcpm_learn_case => $wpcpm_learn_input ) {
	// A different test user avoids Flash's per-request memoized take().
	++$GLOBALS['signed_in'];
	$wpcpm_learn_saved = WPCPM_Settings::save( array( 'learn_courses' => $wpcpm_learn_input ), 'advanced' );
	ck( 'invalid Learn course list keeps the saved value: ' . $wpcpm_learn_case, $wpcpm_learn_saved['learn_courses'], '301001,297853' );
	ck( 'invalid Learn course list queues a refusal: ' . $wpcpm_learn_case, WPCPM_Flash::take( 'learn-courses-refused' ), true );
}

++$GLOBALS['signed_in'];
$wpcpm_learn_saved = WPCPM_Settings::patch( array( 'learn_cache_hours' => 12 ) );
ck( 'omitting Learn course IDs preserves them', $wpcpm_learn_saved['learn_courses'], '301001,297853' );
ck( 'omitting Learn course IDs does not queue a refusal', WPCPM_Flash::take( 'learn-courses-refused' ), '' );

foreach ( array( '', '   ' ) as $wpcpm_learn_case => $wpcpm_learn_input ) {
	++$GLOBALS['signed_in'];
	$wpcpm_learn_saved = WPCPM_Settings::save( array( 'learn_courses' => $wpcpm_learn_input ), 'advanced' );
	ck( 'blank Learn course list saves no override: ' . $wpcpm_learn_case, $wpcpm_learn_saved['learn_courses'], '' );
	ck( 'blank Learn course list queues no refusal: ' . $wpcpm_learn_case, WPCPM_Flash::take( 'learn-courses-refused' ), '' );
}

++$GLOBALS['signed_in'];
WPCPM_Settings::save( array( 'learn_courses' => 'abc' ), 'advanced' );
WPCPM_Flash::take( 'settings-defaults' );
ob_start();
WPCPM_Settings::render_notices();
$wpcpm_learn_notice = ob_get_clean();
ck( 'the course refusal appears even without a defaults notice', false !== strpos( $wpcpm_learn_notice, 'Your previous course IDs were kept.' ), true );
$wpcpm_learn_pending = get_user_meta( get_current_user_id(), WPCPM_Flash::META, true );
ck( 'the course refusal is no longer pending for the next request', isset( $wpcpm_learn_pending['learn-courses-refused'] ), false );

$GLOBALS['opts']      = $wpcpm_learn_saved_options;
$GLOBALS['umeta']     = $wpcpm_learn_saved_meta;
$GLOBALS['signed_in'] = $wpcpm_learn_saved_user;
```

### URL regression cases for `bin/test-settings.php`

If absent, add this stub alongside the suite's existing WordPress function
stubs. If it already exists, use this configurable body rather than defining
the function twice:

```php
function wp_get_environment_type() {
	return $GLOBALS['environment_type'] ?? 'production';
}
```

Insert the cases below after the course list cases and before the final failure
summary. Replace any old Learn URL assertion that expects a bad URL to restore
the default: the expected result is now the previously saved URL. These tests
use the suite's in-memory options and user meta, and the real settings and Flash
classes. Distinct invented user IDs avoid Flash's per-request memoization.

```php
$wpcpm_url_saved_options = $GLOBALS['opts'];
$wpcpm_url_saved_meta    = $GLOBALS['umeta'];
$wpcpm_url_saved_user    = $GLOBALS['signed_in'] ?? null;
$wpcpm_url_saved_env     = $GLOBALS['environment_type'] ?? null;
$GLOBALS['signed_in']   = 9400;

$wpcpm_url_valid_cases = array(
	array( 'production', ' https://learn.example.test/ ', 'https://learn.example.test' ),
	array( 'production', 'https://learn.example.test/wordpress///', 'https://learn.example.test/wordpress' ),
	array( 'production', 'https://learn.example.test:8443/', 'https://learn.example.test:8443' ),
	array( 'staging', 'https://learn.example.test/', 'https://learn.example.test' ),
	array( 'local', 'https://learn.example.test/', 'https://learn.example.test' ),
	array( 'local', 'http://localhost:8881/', 'http://localhost:8881' ),
	array( 'local', 'http://localhost:8881/wordpress/', 'http://localhost:8881/wordpress' ),
);

foreach ( $wpcpm_url_valid_cases as $wpcpm_url_index => $wpcpm_url_case ) {
	++$GLOBALS['signed_in'];
	$GLOBALS['environment_type'] = $wpcpm_url_case[0];
	$wpcpm_url_saved = WPCPM_Settings::save( array( 'learn_base_url' => $wpcpm_url_case[1] ), 'advanced' );
	ck( 'valid Learn URL is normalized: ' . $wpcpm_url_index, $wpcpm_url_saved['learn_base_url'], $wpcpm_url_case[2] );
	ck( 'valid Learn URL queues no refusal: ' . $wpcpm_url_index, WPCPM_Flash::take( 'learn-url-refused' ), '' );
}

$wpcpm_url_invalid_cases = array(
	array( 'production', 'http://localhost:8881' ),
	array( 'staging', 'http://localhost:8881' ),
	array( 'local', 'http://learn.example.test' ),
	array( 'local', 'http://localhost.example.test' ),
	array( 'local', 'http://127.0.0.1:8881' ),
	array( 'production', 'ftp://learn.example.test' ),
	array( 'production', 'javascript:alert(1)' ),
	array( 'production', 'learn.example.test' ),
	array( 'production', '//learn.example.test' ),
	array( 'production', 'https:///wordpress' ),
	array( 'production', 'https://not a host' ),
	array( 'production', 'https://learn.example.test:99999' ),
	array( 'production', 'https://demo:example-only@learn.example.test' ),
	array( 'production', 'https://demo@learn.example.test' ),
	array( 'production', 'https://@learn.example.test' ),
	array( 'local', 'http://demo:example-only@localhost:8881' ),
	array( 'production', 'https://learn.example.test?course=1' ),
	array( 'production', 'https://learn.example.test#section' ),
	array( 'production', '' ),
	array( 'production', '   ' ),
	array( 'production', array( 'https://learn.example.test' ) ),
	array( 'production', false ),
	array( 'production', null ),
);

foreach ( $wpcpm_url_invalid_cases as $wpcpm_url_index => $wpcpm_url_case ) {
	++$GLOBALS['signed_in'];
	$GLOBALS['environment_type'] = $wpcpm_url_case[0];
	$wpcpm_url_before = WPCPM_Settings::save( array( 'learn_base_url' => 'https://saved.example.test/wordpress' ), 'advanced' );
	$wpcpm_url_saved  = WPCPM_Settings::save( array( 'learn_base_url' => $wpcpm_url_case[1] ), 'advanced' );
	ck( 'invalid Learn URL keeps the saved address: ' . $wpcpm_url_index, $wpcpm_url_saved['learn_base_url'], $wpcpm_url_before['learn_base_url'] );
	ck( 'invalid Learn URL queues a refusal: ' . $wpcpm_url_index, WPCPM_Flash::take( 'learn-url-refused' ), true );
}

++$GLOBALS['signed_in'];
$wpcpm_url_saved = WPCPM_Settings::patch( array( 'learn_cache_hours' => 12 ) );
ck( 'omitting the Learn URL preserves it', $wpcpm_url_saved['learn_base_url'], 'https://saved.example.test/wordpress' );
ck( 'omitting the Learn URL queues no refusal', WPCPM_Flash::take( 'learn-url-refused' ), '' );

++$GLOBALS['signed_in'];
WPCPM_Settings::save( array( 'learn_base_url' => 'https://demo:example-only@learn.example.test' ), 'advanced' );
WPCPM_Flash::take( 'settings-defaults' );
ob_start();
WPCPM_Settings::render_notices();
$wpcpm_url_notice = ob_get_clean();
ck( 'the URL refusal is visible without a defaults notice', false !== strpos( $wpcpm_url_notice, 'Your previous URL was kept.' ), true );
ck( 'the URL refusal does not expose credentials', false === strpos( $wpcpm_url_notice, 'example-only' ), true );
$wpcpm_url_pending = get_user_meta( get_current_user_id(), WPCPM_Flash::META, true );
ck( 'the URL refusal is cleared for the next request', isset( $wpcpm_url_pending['learn-url-refused'] ), false );

$GLOBALS['opts']             = $wpcpm_url_saved_options;
$GLOBALS['umeta']            = $wpcpm_url_saved_meta;
$GLOBALS['signed_in']        = $wpcpm_url_saved_user;
$GLOBALS['environment_type'] = $wpcpm_url_saved_env;
```
