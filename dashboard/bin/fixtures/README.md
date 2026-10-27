# Learn progress fixtures

These examples use invented learner data. Their baseline is the seven
`contract/example-*.json` files in the Learn Link repository.

The three success fixtures are adapted to section 6, points 1 and 3, of
the team's working document `Docs/learn-progress-v1-updated.md` (held outside
this repository).

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
course percentages are preserved. The four error fixtures remain exact copies
of their corresponding contract examples.

Run `php bin/test-learn-client.php` from the Dashboard folder or the local
plugin root. The suite checks these examples and constructs additional invalid
responses in memory.
