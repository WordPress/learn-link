# Learn progress fixtures

These examples use invented learner data. Their baseline is the ten
`contract/example-*.json` files in the Learn Link repository.

The three success fixtures follow review points 1 and 3 tracked in
[issue #1](https://github.com/WordPress/learn-link/issues/1).

Lesson `297903`, quiz `297953`, has no required pass mark:

| Fixture | Quiz status | Grade | Pass mark |
| --- | --- | --- | --- |
| `learn-progress-not-started.json` | `not-taken` | `null` | `null` |
| `learn-progress-in-progress.json` | `graded` | `50` | `null` |
| `learn-progress-complete.json` | `graded` | `50` | `null` |

In the baseline success examples, that quiz's pass mark was `70`, and its
graded result was labeled `failed`. The updated document represents a graded
quiz without a pass requirement as `graded`, preserving its actual grade.
The lesson remains complete in the in-progress and complete examples.

Only those quiz fields have changed. IDs, dates, grades, lesson statuses and
course percentages are preserved. The six error fixtures are exact copies of
their contract examples:

| Fixture | Contract example |
| --- | --- |
| `learn-progress-error-400-invalid-grant.json` | `example-error-400-invalid-grant.json` |
| `learn-progress-error-401-invalid-client.json` | `example-error-401-invalid-client.json` |
| `learn-progress-error-401.json` | `example-error-401-invalid-token.json` |
| `learn-progress-error-403.json` | `example-error-403-revoked.json` |
| `learn-progress-error-429.json` | `example-error-429-slow-down.json` |
| `learn-progress-error-503.json` | `example-error-503-unavailable.json` |

`example-error-400-invalid-request.json` has no fixture: the suite builds that
error in memory.

Run `php bin/test-learn-client.php` from the Dashboard folder or the local
plugin root. The suite checks these examples and constructs additional invalid
responses in memory.
