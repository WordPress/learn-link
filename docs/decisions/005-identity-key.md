# 005. What the Dashboard keys the connection on

- **Status:** Proposed. Open decision 5 in handbook section 4; Maciej decides by the end of week 2.
- **Date:**
- **Decided by:** Maciej Pilarski
- **Issue:**

_Stub. Replace each "To be written" with a few sentences of your own._

## Context

Identity is the WordPress.org account: every student has one, and Learn knows its learners through WordPress.org single sign-on. Nothing in Learn Link keys on an Airtable record ID (`wpcpm_student_record_id`) or on the Airtable row (`wpcpm_student_program`). But the Dashboard's copy of each student's WordPress.org username comes from Airtable today, and the program's data may move off Airtable during the project.

To be written: how the identity check in section 3 of [the contract](../../contract/learn-progress-v1.md) uses the username, and why it is the one place where a participant could otherwise connect someone else's account to their own card.

## Decision

To be written once Maciej decides. The recommendation: store the username Learn confirms at connect time in a new user meta key, and key the connection on the WordPress user plus that verified username.

## Alternatives considered

To be written. At least: keying on the Airtable username alone; keying on Learn's numeric user ID (which the contract never sends).

## Consequences

To be written. For example: what happens when a student's username changes in Airtable after they connected.
