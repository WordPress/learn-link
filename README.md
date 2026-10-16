# Learn Link

Learn Link shows a WordPress Credits student's own course progress from Learn WordPress (learn.wordpress.org) on their Student Report Card on wordpresseducation.org, once the student has approved the connection.

It has two halves, built together in this repository by a team of interns from Colegio Universitario de Cartago with the WordPress Credits program, 13 October to 11 December 2026:

- **The Learn half** (`learn/`): a Sensei LMS extension that runs on Learn WordPress. A consent screen where the student approves the connection, and one read-only REST endpoint, `GET /wp-json/learn-progress/v1/me`, that returns only the token holder's own progress for a fixed list of courses. It takes no user parameter, so there is no way to ask it about anyone else.
- **The Dashboard half** (`dashboard/`): the `WPCPM_Learn_Progress` module for the WPCredits Program Manager plugin. A "Connect your Learn account" button, a client that reads the endpoint and caches the answer, a progress panel in the "My course" section of the Student Report Card, and one progress line per student on the Mentor Report Card.

The two halves meet in one written agreement, the contract: [contract/learn-progress-v1.md](contract/learn-progress-v1.md). Read it first. It lets both halves be built at the same time, and it is the first thing a Meta team reviewer will read.

**Status:** week 1 starter. The contract is a draft until the team freezes it in week 2. Nothing in this repository runs on learn.wordpress.org or wordpresseducation.org, and everything in it is invented data.

## What is where

| Path | What it holds |
| --- | --- |
| [`contract/`](contract/) | The contract draft and its example responses. Both halves test against these files. |
| [`learn/`](learn/) | The Sensei extension for Learn WordPress. |
| [`dashboard/`](dashboard/) | The new plugin files, in the same paths they will have in the plugin. [`INTEGRATION.md`](dashboard/INTEGRATION.md) lists the few lines to add to existing plugin files. |
| [`seed/`](seed/) | Invented courses, twenty invented learners, one invented student and mentor, and a read-only check. |
| [`docs/`](docs/) | [`architecture.md`](docs/architecture.md) and the decision records in [`docs/decisions/`](docs/decisions/). |
| [`.github/`](.github/) | Issue and pull request templates. |

How we work (branches, pull requests, reviews, standards) is in [CONTRIBUTING.md](CONTRIBUTING.md).

## Run both halves locally in under 30 minutes

You build two sites on your own computer, both with invented data: a Learn test site with Sensei LMS and twenty invented learners, and a Dashboard test site with the WPCredits Program Manager plugin and one invented student and mentor. Every command below was run end to end on fresh local sites on 16 October 2026, with WordPress Studio, Sensei LMS 4.26.4 and WPCredits Program Manager 1.122.27.

### 1. Install these first

- [WordPress Studio](https://developer.wordpress.com/studio/) (free). It runs local WordPress sites without Docker and includes WP-CLI as `studio wp`. Turn on Studio CLI for terminal in the app's Settings tab ([Studio CLI guide](https://developer.wordpress.com/docs/developer-tools/studio/cli/)).
- Git, a GitHub account with an SSH key, and a code editor (for example VS Code).
- PHP 7.4 or newer on the command line, to run the plugin's test suites; Composer and PHP_CodeSniffer with the WordPress Coding Standards, for the standards check.
- Later, only for the real Learn codebase (step 6): Docker Desktop, Node 20 with nvm, Yarn and SVN.

The commands assume you clone this repository to `~/learn-link`:

```bash
git clone git@github.com:WordPress/learn-link.git ~/learn-link
```

On Windows, run the commands in PowerShell; where a command differs, copy the files with File Explorer instead.

### 2. The Learn test site: Sensei LMS with twenty invented learners

```bash
# 1. Create the Learn test site. Note the Site URL and admin password Studio prints.
studio create --path ~/Studio/learn-link-learn --name "Learn Link: Learn" --skip-browser
cd ~/Studio/learn-link-learn

# 2. Install Sensei LMS (the free edition is enough).
studio wp plugin install sensei-lms --activate

# 3. Copy the seed files INTO the site folder (Studio can only read files there).
mkdir learn-link-seed
cp ~/learn-link/seed/* learn-link-seed/

# 4. Import the invented courses, lessons and quiz questions.
studio wp sensei-import --user=admin --courses=learn-link-seed/courses.csv --lessons=learn-link-seed/lessons.csv --questions=learn-link-seed/questions.csv

# 5. Create the twenty invented learners, then print what Sensei says about each.
studio wp eval-file learn-link-seed/seed-learners.php
studio wp eval-file learn-link-seed/check-learners.php
```

The import creates two courses: "Learn Link Test Course" (three modules, eight lessons, four quizzes) and "Learn Link Test Foundations" (three lessons). The quizzes cover every case: two auto-graded quizzes with a 70% pass mark, one manually graded quiz, and one more auto-graded quiz in the last module.

| Learners | State after seeding | What it lets you test |
| --- | --- | --- |
| `learnlink-learner-01` to `03` | Not enrolled anywhere | Every course on the list still appears, as not-started |
| `04` to `06` | Enrolled, nothing done (0%) | "Connected, nothing started"; Sensei's in-progress record at 0% |
| `07` to `10` | Halfway (50%): four lessons, two quizzes passed at 100%; one foundations lesson (33%) | The progress bar, modules, two courses at once |
| `11` to `13` | Lesson 1 done, lesson 2 quiz failed (0%) | A failed quiz with a required pass mark |
| `14` to `16` | Four lessons done, lesson 5 quiz submitted and waiting to be graded | "Waiting to be graded" |
| `17` to `20` | Both courses complete (100%); lesson 5 marked 90% by the teacher | Completion and a manual grade |

The last command prints one line per learner. Shortened here ("main" stands for `learn-link-test-course` and "found" for `learn-link-test-foundations`), it looks like this:

```text
learnlink-learner-07 | main: in-progress 50% [complete, passed 100%, passed 100%, complete, not-started, ...]
learnlink-learner-11 | main: in-progress 13% [complete, failed 0%, not-started, ...]
learnlink-learner-14 | main: in-progress 50% [complete, passed 100%, passed 100%, complete, ungraded, ...]
learnlink-learner-17 | main: complete 100% [..., passed 90%, complete, passed 100%, complete]
```

Check the public course structure in a browser: `http://localhost:PORT/wp-json/sensei-internal/v1/course-structure/COURSE_ID`, with your site's port and the ID from `studio wp post list --post_type=course --fields=ID,post_name`. It answers with the three modules.

To start again from nothing at any time:

```bash
# Moves the site files to the Trash; then repeat the steps above.
studio delete ~/Studio/learn-link-learn
```

The seed scripts can also be run twice safely.

### 3. The Dashboard test site: the WPCredits plugin with an invented student and mentor

```bash
# 1. Get the plugin and theme from the public mirror (only their folder).
git clone --depth 1 --filter=blob:none --sparse https://github.com/WordPress/WPCredits.git
cd WPCredits
git sparse-checkout set "Education/WordPress Education Dashboard"

# 2. Create the Dashboard test site.
studio create --path ~/Studio/learn-link-dashboard --name "Learn Link: Dashboard" --skip-browser

# 3. Copy the plugin and the theme into the site, then switch them on.
cp -R "Education/WordPress Education Dashboard/wpcredits-program-manager" ~/Studio/learn-link-dashboard/wp-content/plugins/
cp -R "Education/WordPress Education Dashboard/wpcredits-theme" ~/Studio/learn-link-dashboard/wp-content/themes/
cd ~/Studio/learn-link-dashboard
studio wp plugin activate wpcredits-program-manager
studio wp theme activate wpcredits-theme

# 4. Seed one invented student and one invented mentor.
mkdir learn-link-seed
cp ~/learn-link/seed/seed-dashboard.php learn-link-seed/
studio wp eval-file learn-link-seed/seed-dashboard.php
```

Then look at the two cards. Sign in as `admin` with the password Studio printed (or set a new one with `studio wp user update admin --user_pass=...`):

```text
# Open the two cards as the invented users see them:
http://localhost:PORT/student-dashboard/?wpcpm_student_view=STUDENT_ID
http://localhost:PORT/mentor-dashboard/?wpcpm_mentor=MENTOR_ID
```

```bash
# Or render a card from the command line, as that user:
studio wp eval 'wp_set_current_user( STUDENT_ID ); echo do_shortcode( "[wpcpm_student_dashboard]" );'

# Find the IDs:
studio wp user list --fields=ID,user_login,roles
```

What you will see, and why it is fine: the Student Report Card shows Ada Example with her mentor Grace Example, and the "My course" link. The report form says "No Airtable Personal Access Token is configured". That is expected. **Never add an Airtable token to any test site:** the plugin's default base is the live one, and the data in it is real.

### 4. Making the two sites talk to each other

- Both Studio sites run on your computer, so the Dashboard reaches Learn at `http://localhost:LEARN_PORT`. A server-to-server call with `wp_remote_get()` works (tested).
- `wp_safe_remote_get()` refuses localhost ("A valid URL was not provided"). Use `wp_remote_get()` with the configured base URL, or allow the host with the `http_request_host_is_external` filter in development only.
- Put Learn's address in a setting (`learn_base_url` in the contract and the design spec), never in the code.
- If you use wp-env instead of Studio, PHP runs inside Docker, so the Dashboard must call `http://host.docker.internal:PORT`, while the browser still uses localhost. (Not tested by the program.)
- Known gap for the connect flow: the Dashboard's invented student is `learnlink-student-01`, and the Learn learners are `learnlink-learner-01` to `20`. The identity check compares those usernames, so pick or create one matching pair on both sites before you test connecting end to end (Phase 2).

### 5. Run the plugin's tests

The plugin's tests are plain PHP files in `bin/`, not PHPUnit. Each one stubs the WordPress functions it needs, loads the class under test, and checks results with `ck()`. Network calls are answered from fixture files, so no test touches the internet. From the plugin's root folder (`~/Studio/learn-link-dashboard/wp-content/plugins/wpcredits-program-manager`):

```bash
# One suite; it prints ok or FAIL and exits 1 on failure.
php bin/test-learn.php
```

```bash
# Every suite; it names the ones that fail.
php bin/check-temp-litter.php
```

```bash
# The standards check: PHP_CodeSniffer plus the house checks.
bin/check-standards.sh
```

### 6. Later: the real Learn codebase (optional, from Phase 2)

To prepare the pull request to [WordPress/Learn](https://github.com/WordPress/Learn), run Learn itself, following its readme. Its Composer packages are fetched over SSH, so your GitHub SSH key must work first.

```bash
# Needs Docker, Node 20 (nvm), Yarn, Composer, SVN, and a GitHub SSH key.
git clone git@github.com:WordPress/Learn.git
cd Learn
nvm use
yarn
yarn run create          # builds everything and starts wp-env
# Open http://localhost:8888 and sign in as admin / password.
yarn run wp-env stop     # later: yarn run wp-env start
```

That site imports no courses. Use the same CSV files and seed scripts through wp-env's command line (`yarn run wp-env run cli wp ...`). Learn's environment runs PHP 7.4, so keep your code PHP 7.4 compatible.

### Later still: the staging site

Before the Phase 2 integration day, the program sets up a staging copy of wordpresseducation.org with invented records, and you get your own access to it. Nobody on the team gets access to the live site or to learn.wordpress.org.

## Background reading

- [The Learn Link design spec](https://github.com/WordPress/WPCredits/blob/trunk/Education/WordPress%20Education%20Dashboard/wpcredits-program-manager/docs/specs/2026-09-07-learn-link-design.md): the Dashboard half and the first draft of the contract. It is groundwork, not an approved design.
- [WPCredits Program Manager and its theme, public source](https://github.com/WordPress/WPCredits/tree/trunk/Education/WordPress%20Education%20Dashboard). This is a mirror that each plugin release overwrites, so never open pull requests against it for this work. Reviewed work from `dashboard/` is brought into the plugin by Maciej.
- [WordPress/Learn](https://github.com/WordPress/Learn), [Sensei LMS](https://github.com/Automattic/sensei) and its [hooks reference](https://automattic.github.io/sensei/).

## Security

Found a security problem? Tell Maciej privately by email (the address is in your project handbook), not in a public issue.

## License

Learn Link is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any later version (`GPL-2.0-or-later`). The full text is in [LICENSE](LICENSE). The license matches WordPress's own, so the Learn half can be submitted upstream unchanged.
