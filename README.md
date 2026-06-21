Quizventure
===========

Students procrastinating too much? Are they playing games instead of studying? Well now you can motivate them by allowing them to do both at once!

Quizventure is an activity module that loads quiz questions from the course question bank.
The possible answers come down as spaceships and you have to shoot the correct one.

Supported question types: **multiple choice**, **true/false**, and **matching**.
Other question types are silently skipped.

Requirements
============

- Moodle 4.1 or later (CI-tested on Moodle 5.1 and 5.2)
- PHP 8.2 or later

Installation
============

**From the Moodle plugins directory:**
Go to the [Quizventure page](https://moodle.org/plugins/view.php?plugin=mod_quizgame) on the plugins DB.

**From GitHub:**
Download the ZIP, extract into `mod/quizgame/`, then log in as admin and go to
*Site Administration → Notifications* to run the database upgrade.

Setup
=====

1. Add questions to the course question bank (multiple choice, true/false, or matching).
2. Add a *Quizventure* activity to your course and select the question category to use.

The question category picker shows all question banks accessible within the course,
including any shared course question bank modules. Tick **Also include questions from
subcategories** to pull questions from the selected category's entire subcategory tree
instead of just the top category.

Grading
=======

Quizventure writes scores to the Moodle gradebook. Each correct answer on the first
attempt is worth 1000 game points.

In the activity settings, under **Grade**, you can configure:

- **Maximum grade** — the gradebook grade ceiling (default: 100).
- **Grade category** — which gradebook category to place the grade in.
- **Grade to pass** — the minimum grade required to pass (used for completion and course badges).
- **Game score for maximum grade** — the game score that earns the full maximum grade.
  For example, with a maximum grade of 100 and a target of 10 000, a student who scores
  5 000 game points receives 50/100. Scoring at or above the target awards the maximum grade.
  Set to 0 to store the raw game score (Moodle's maximum grade cap still applies).

The gradebook is updated immediately each time a student finishes a game, using only
their personal best score.

Completion
==========

Quizventure supports automatic completion based on a minimum game score. Enable
*Require score* in the activity completion settings and enter the target score.
Each correctly answered question on the first try is worth 1000 points, so a sensible
target is `(number of questions) × 1000`.

Privacy
=======

This plugin stores the following personal data:

| Data | Purpose |
|---|---|
| User ID | Identifies whose score is recorded |
| Game score | Tracks performance |
| Timestamp | Records when the game was played |

All stored data can be exported or deleted via Moodle's Privacy API
(*Site Administration → Privacy and policies → Data requests*).

Compatibility
=============

| Moodle | PHP | Status |
|---|---|---|
| 5.2 | 8.3 | ✓ CI |
| 5.1 | 8.2, 8.3 | ✓ CI |

CI runs on PostgreSQL and MariaDB for both combinations above.

License
=======

GNU GPL v3 or later — see [COPYING](COPYING.txt).
