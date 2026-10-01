Quizventure
===========

Students procrastinating too much? Are they playing games instead of studying? Well now
you can motivate them by allowing them to do both at once!

Quizventure is an activity module that turns questions from a question bank into a space
shooter. Each level is a question; the possible answers come down as spaceships and you
have to shoot the correct one.

Supported question types: **multiple choice** (single and multiple answer),
**true/false**, and **matching**. Other question types are skipped.

This repository is a fork of the original Quizventure plugin by John Okely and Stephen
Bourget, updated for Moodle 5.1–5.3.

Requirements
============

- Moodle 5.1, 5.2 or 5.3 (questions come from 5.x question bank activities)
- PHP 8.2 or later (8.3 or later for Moodle 5.2 and 5.3)

Installation
============

Download or clone this repository into `mod/quizgame/` (under `public/` on Moodle 5.1+),
then log in as an administrator and go to *Site administration → Notifications* to run
the installation.

Setup
=====

1. Add a question bank to the course (or use a shared question bank you have access to)
   and create multiple choice, true/false or matching questions in it.
2. Add a *Quizventure* activity to the course and select the question category to use.
   Tick **Also include questions from subcategories** to use the category's whole tree.

The category picker lists the categories of question banks in this course, and of shared
question banks in other courses, from which you may use questions
(`moodle/question:useall`). Private banks of other courses' activities, such as a quiz's
own questions, are never offered and never played.

Players see each question many times with instant feedback, so do not point a game at a
category that a graded quiz also draws from.

How a game is scored
====================

The game runs in the browser, but the browser is never told which answers are correct.
Each answer ship carries an opaque token; every shot is checked by the server, which also
keeps the score.

| Event | Points |
|---|---|
| Shooting a correct answer | +1000 × the answer's grade (fraction) |
| Shooting a wrong or partial answer (the shot bounces back) | (fraction − 0.5) × 600 if that is negative, e.g. −300; otherwise 0 |
| Matching a stem with its answer | +1000 ÷ the number of stems |
| A correct answer (or unmatched pair) leaving the screen | minus what it was worth |

Each question can be played once per round; after every question has been played the
round starts again, faster. A recorded score never exceeds 1000 points per second that
levels were open, so even a player who knows every answer earns points at a human pace. When a recording player dies, the server stores the score it
computed. If the page is closed mid-game, that game is recorded when the player starts
the next one.

Guests can play, and get the same checking, but nothing is recorded: recording needs the
`mod/quizgame:play` capability (students, teachers and managers by default).

Grading
=======

Quizventure writes each player's best score to the gradebook. Under **Grade** in the
activity settings:

- **Maximum grade** — the gradebook grade ceiling (default 100). Choose *Point* or
  *None*; scales are not supported.
- **Grade category** and **Grade to pass** — the standard Moodle settings.
- **Game score for maximum grade** — the game score that earns the full grade. With a
  maximum grade of 100 and a target of 10 000, a player who scores 5 000 gets 50/100;
  10 000 or more gives 100/100. Set it to 0 to use the raw game score as the grade
  (capped at the maximum grade).

Students see the rule on the activity page ("Reach a game score of 10000 to earn the full
grade of 100"), or that their best score is their grade, together with their best score
so far.

Even with server-side checking, players have unlimited attempts and learn the answers as
they play, so use Quizventure grades for practice rather than high-stakes assessment.

Completion
==========

Quizventure supports automatic completion based on a minimum game score: enable
*Require score* in the activity completion settings and enter the score. A sensible
target is `(number of questions) × 1000`.

Question bank integration
=========================

Like a quiz's random questions, a game uses a whole category rather than individual
questions, so its questions do not count as *in use*: they can be deleted, edited or
moved in the question bank, and the game plays whatever ready questions the category
holds when the page is opened. Deleting the category (or its question bank) leaves the
game empty until another category is selected.

Backup, restore and reset
=========================

Activities back up and restore with their settings and, with user data, their scores.
On restore the question category is remapped to the restored question bank; a category
from a bank that was not in the backup is kept only if it is still usable in the target
course. Course reset can remove all scores, which also clears the grades.

Privacy
=======

This plugin stores the following personal data:

| Data | Purpose |
|---|---|
| User ID | Identifies whose score is recorded |
| Game score | Tracks performance |
| Timestamp | Records when the game was played |

The state of a game in progress is kept in the player's Moodle session only. All stored
data can be exported or deleted via Moodle's Privacy API
(*Site administration → Users → Privacy and policies → Data requests*).

Compatibility
=============

| Moodle | PHP | Status |
|---|---|---|
| 5.3 | 8.3, 8.4 | ✓ CI (on `main` until `MOODLE_503_STABLE` exists) |
| 5.2 | 8.3, 8.4 | ✓ CI |
| 5.1 | 8.2, 8.3 | ✓ CI |

CI runs on PostgreSQL 17 and MariaDB 11.4.

Languages
=========

English and Japanese are included. On sites with the Japanese language pack installed,
the pack's own Quizventure strings (from AMOS, the Moodle translation tool) take
precedence over the bundled ones for the strings it covers.

Credits
=======

Audiowide font by Astigmatic, licensed under the SIL Open Font License 1.1 (`fonts/OFL.txt`).

License
=======

GNU GPL v3 or later — see <https://www.gnu.org/licenses/gpl-3.0.html>.
