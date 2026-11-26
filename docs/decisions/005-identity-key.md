# 005. What the Dashboard keys the connection on

- **Status:** Accepted
- **Date:** 2026-11-05
- **Decided by:** Maciej Pilarski, decision 5 in #2
- **Issue:** #2, #52

## Context

Identity is the WordPress.org account: every student has one, and Learn knows its learners through WordPress.org single sign-on. Nothing in Learn Link keys on an Airtable record ID (`wpcpm_student_record_id`) or on the Airtable row (`wpcpm_student_program`). But the Dashboard's copy of each student's WordPress.org username comes from Airtable today, and the program's data may move off Airtable during the project.

At connect time, section 3 of [the contract](../../contract/learn-progress-v1.md) has Learn return the username of the learner who approved. The Dashboard compares it with the student's known username before it stores anything. This is the one place where a participant could otherwise connect someone else's Learn account, and its grades, to their own Student Report Card, for example by approving while signed in to a classmate's WordPress.org account.

## Decision

The WordPress user on the Dashboard, plus the username Learn confirms at connect time, saved in its own user meta key (`_wpcpm_learn_username`, with Learn's profile slug in `_wpcpm_learn_profile_slug`).

- At connect, Learn's username must equal the student's known username, ignoring case; the profile slug is accepted too. Otherwise the Dashboard revokes the new token at once and stores nothing.
- On every read after that, Learn's answer is compared with the saved, verified name.
- If the known username changes later, the Student Report Card shows an Administrator a notice and keeps the connection (#52). The student sees nothing different.

## Alternatives considered

- **The Airtable username alone:** it can change, and the data may leave Airtable, so the connection would silently follow whatever the field says.
- **Learn's numeric user ID:** the contract never sends it, on purpose: user IDs stay inside Learn.
- **The Airtable record ID:** it ties Learn Link to Airtable, which the program may leave.

## Consequences

- A student whose known username is missing cannot connect until an Administrator fixes their record.
- If a student's username changes on WordPress.org, reads fail with the identity error until an Administrator withdraws the connection and allows reconnecting (#42).
- The two meta keys are personal data: they are removed with the rest of the Learn data on withdrawal, account deletion and uninstall (#51).
