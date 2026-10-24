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
Bourget, updated for Moodle 5.2–5.3.

Requirements
============

- Moodle 5.2 or 5.3 (questions come from 5.x question bank activities)
- PHP 8.3 or later

Installation
============

Download or clone this repository into `mod/quizgame/` (under `public/`),
then log in as an administrator and go to *Site administration → Notifications* to run
the installation.

Setup
=====

1. Add a question bank to the course (or use a shared question bank you have access to)
   and create multiple choice, true/false or matching questions in it.
2. Add a *Quizventure* activity to the course. The **Question bank** line shows the bank
   the game takes its questions from (a new activity starts with the first bank of the
   course); press **Switch bank** to pick another one, then select the question category
   of that bank to use. Tick **Also include questions from subcategories** to use the
   category's whole tree.

Like the quiz's bank switcher, **Switch bank** offers the question banks in this course,
and shared question banks in other courses, from which you may use questions
(`moodle/question:useall`); the **Question category** list shows the categories of the
current bank only. Private banks of other courses' activities, such as a quiz's own
questions, are never offered and never played. Editing an existing activity shows its
saved bank and category; if you may not use that bank yourself, its category stays
selectable as *Current category* so saving other settings does not change the questions.

Players see each question many times with instant feedback, so do not point a game at a
category that a graded quiz also draws from.

How a game is scored
====================

The game runs in the browser, but the browser is never told which answers are correct.
Each answer ship carries an opaque token; every shot is checked by the server, which also
keeps the score.

The points are set per activity under **Scoring** in the activity settings. *P* is
**Points for a correct hit** (default 1000); *S* is the **Wrong-hit penalty schedule**
(default `300`), a comma-separated list of penalties for the 1st, 2nd, 3rd... wrong hit
within one question, whose last value repeats (e.g. `300,600,1000`).

| Event | Points |
|---|---|
| Shooting a correct answer | +*P* × the answer's grade (fraction) × the combo multiplier *m* |
| Shooting a wrong or partial answer (the shot bounces back) | −*S*[n] × (1 − 2 × fraction) for the n-th wrong hit in the question, if that is negative (e.g. −300 for a wrong answer); otherwise 0 |
| Matching a stem with its answer | +*P* ÷ the number of stems × *m* (a wrong pair scores 0) |
| A correct answer (or unmatched pair) leaving the screen | minus what it was worth |
| A shot that hits nothing | −**Miss penalty** (default 0) |
| Shooting a bonus ship (bonus round, see *Rewards*) | +**Points per bonus ship** (default 1000), never multiplied |

*m* is 1 unless the combo multiplier of *Rewards* is on (see below); penalties are never
multiplied.

The defaults reproduce the scoring of earlier versions, under which guessing pays: with
four answers, shooting at random still averages a profit. A schedule such as
`300,600,1000` makes random guessing lose points on average. Misses are reported by the
browser (the server cannot see a shot that hits nothing), so a tampered client can skip
them, which only spares itself the penalty.

Each question can be played once per round; after every question has been played the
round starts again, faster. A recorded score never exceeds *P* points per second that
levels were open (*P* × the **Maximum multiplier** when the combo multiplier is on; bonus
ship points have a separate allowance of the same size, so a bonus round is never
clipped), so even a player who knows every answer earns points at a human
pace. When a recording player dies, the server stores the score it
computed. If the page is closed mid-game, that game is recorded when the player starts
the next one.

Guests can play, and get the same checking, but nothing is recorded: recording needs the
`mod/quizgame:play` capability (students, teachers and managers by default).

Sassy enhancements
==================

An optional system, off by default (**Sassy enhancements** in the activity settings),
that catches players who shoot at random instead of reading the question. Every rule is
applied by the server, which keeps the score; the browser only plays the effects it is
told to. Nothing is stored or reported: the state lives in the player's session and
resets every game.

- **Cheater mode** starts after *Wrong hits to trigger cheater mode* (default 3) wrong
  hits counted across questions; decoy ships count, misses never do. A question answered
  with no wrong hits resets the count, and *Clean questions to leave cheater mode*
  (default 1) such questions end cheater mode. Each start is an **offence**; in cheater
  mode every further run of that many wrong hits is another offence.
- **Offence 1 is always "Read it first"**: a snarky message, then the question is shown on
  its own for *Reading time* (default 5 s, not counted towards the points-per-second
  limit) with firing disabled.
- **Later offences** apply one of the enabled penalties, *In order* (decoy ships,
  reversed controls, dodge round, return fire, score drain) or at *Random*:
  - *Decoy ships*: joke-labelled extra ships that are always wrong, one more per further
    wrong hit, up to *Maximum decoy ships* (default 3).
  - *Reversed controls*: left and right swapped for one question.
  - *Dodge round*: for one question every ship dodges every shot (and the player's ship),
    so its points are lost.
  - *Return fire*: enemies fire faster and home in on the player, more with each wrong hit.
  - *Score drain*: the score ticks down at *Score drain start rate* (default 100 points per
    second), *Score drain increase* (default 50 %) faster per wrong hit, never below 0.

  Decoys, return fire and score drain last until the player leaves cheater mode.
- **Boss finale**: the offence numbered *Offences before the boss finale* (default 4)
  ends the game with a score of 0 after the boss lines ("This game is becoming more and
  more like life. And you know what? Life ain't fair."). *Boss finale style*:
  - *Big boss*: a boss ship twice the normal size weaves above the player for 3 to 5
    seconds (the player's shots spark off it), then destroys the player's ship with a huge
    laser beam.
  - *Swarm*: *Swarm size* (default 20) small ships pour in and attack non-stop until the
    player has lost every life.
  - *Random*: either, chosen by the server.

Every penalty start pauses the game on a message until the player presses fire (presses in
the first second of each message are ignored, so mashing fire does not skip it unread). The
*Read it first messages*, *Taunt messages*, *Decoy ship labels* and *Boss finale lines*
are editable lists, one entry per line; `Line one || Line two` is a two-step prompt, and
an empty list uses the built-in defaults. The reversed-controls line is a language string
(`sassy_reversed_msg`), changeable through language customisation.

Rewards
=======

Rewards for players who read the question and shoot the right answer (**Rewards** in the
activity settings; on by default for new activities, **off for activities created before
this version**, so their scores and grade targets do not change until a teacher turns them
on). Each reward has its own switch. Like Sassy, every rule is applied by the server; the
state lives in the player's session and resets every game. Rewards work with or without
Sassy enhancements.

All rewards follow the **clean streak**: the number of questions in a row answered with
no wrong hits. A wrong hit (a wrong answer, a decoy, a wrong pair) resets it to 0; misses
and questions whose ships simply escaped neither extend nor break it. A clean question in
cheater mode builds the streak as usual, so redemption starts earning rewards.

- **Combo multiplier**: points for correct hits and matched pairs are multiplied by
  the clean streak, so the combo starts with the second clean question in a row (×1, then
  ×2 after two clean questions, ×3 after three...), up to
  *Maximum multiplier* (default 4). The HUD shows "COMBO ×3" and flashes when it rises
  or breaks. Penalties, score drain, missed-at-close points and bonus ships are never
  multiplied. Combos can multiply a perfect game's score up to the maximum, so raise the
  *Game score for maximum grade* and any completion score to match. With *A miss resets
  the combo* ticked (off by default), a shot that hits nothing also sets the multiplier
  back to ×1, while the clean streak towards extra lives and bonus rounds is kept.
- **Extra life**: one more life every *Clean questions in a row per extra life* (default
  5), while the player has fewer than *Maximum lives* (default 5; at least the starting
  lives, see *Difficulty*).
- **Bonus round**: after every *Clean questions in a row for bonus round* (default 5),
  the next question is preceded by a bonus round ("Now THAT's how you play! Bonus
  round!"): *Bonus ships per round* (default 2) bonus ships in different colours, worth
  *Points per bonus ship* (default 1000) each and flying at *Bonus ship speed* (a
  percentage of the enemy ships' current speed, speed-ups included; default 100, as fast
  as the enemy ships), which never fire, cannot hurt the
  player, never dodge and cost nothing if they leave. With the
  defaults the extra life and the bonus round arrive together; the life is shown first.
- **Praise messages**: a short line shown without pausing the game when the combo rises,
  a life is earned, a bonus round starts or the player leaves cheater mode. The
  *Praise messages* list (one per line, empty for the built-in ones) is used for the combo
  and the extra life; the bonus-round and redemption lines are language strings
  (`rewards_bonus_msg`, `rewards_redemption_msg`).

Difficulty
==========

Under **Difficulty** in the activity settings:

- **Starting lives** (default 3): the lives a game starts with.
- **Speed-up per question** (default 5%): once started, every new question makes the
  enemy ships this much faster, so the game keeps getting harder until it ends. 0 turns it
  off. **Speed-up starts**: once the score target is reached (the default; the completion
  score if the activity has one, otherwise the game score for maximum grade, and after the
  first round when there is neither), after the first round, or from the second question.
  Once started it runs to the end of the game.
- **Speed-up per round** (default 30%, as in earlier versions): each time every question
  has been played and the questions start again, the ships get this much faster. 0 turns
  it off.

Speed only makes the game harder, never adds points, so it is applied by the browser; the
server's scoring and points-per-second limit are unchanged.

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
target is `(number of questions) × the points for a correct hit` (1000 by default).

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

Moodle 5.1 and earlier are not supported (the bank selector uses the
`core_question/bank_switcher` module added in 5.2).

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
