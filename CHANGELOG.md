# Changelog

All notable changes to Quizventure (mod_quizgame) are documented here.
Entries are ordered newest-first. Dates reflect the release or commit date.

---

## [2026100101] — Unreleased — Moodle 5.3 support, server-side answer checking, grade target shown to students

### Added
- **Server-side answer checking.** The browser no longer receives which answers are
  correct (answer fractions and match pairings used to be in the page). Each ship carries
  an opaque per-session token, every shot is checked by the new `mod_quizgame_answer`
  web service, and the server keeps the score; `update_score` records the server's score
  and ignores the one the client sends. Each question can be played once per round, and an
  unfinished game (page closed) is recorded when the next game starts. Guests get the
  same checking without recording.
- **Shared question banks** from other courses can be chosen, when you may use their
  questions (`moodle/question:useall`).
- **Grade target on the activity page** — students see the game score that earns the
  full grade ("Reach a game score of 10000 to earn the full grade of 100"), or that their
  best score is their grade when no target is set, plus their best score so far.
- **Japanese language pack** (`lang/ja`).
- `mod/quizgame:play` capability (write; student, teacher, editing teacher, manager).
  Recording a score needs it, so guests can play but no longer write scores and grades,
  and frozen courses no longer accept scores.
- Moodle 5.3 support: `$plugin->supported = [501, 503]`, CI rows for 5.3.
- PHPUnit coverage for the game rules, the web services, grading, reset, deletion,
  backup/restore and the question category check; Behat coverage for the grading
  information.

### Changed
- Requires Moodle 5.1 (`$plugin->requires` was 4.1, but the question bank code already
  needed 5.x module-level banks).
- Scores are computed by the server (see Added), stored once per started game and clamped
  to 0 and to 1000 points per second played. Previously any integer was stored and graded,
  and a huge value made the gradebook write fail.
- Questions only come from question banks in the activity's course or shared banks,
  checked when the game is rendered, so a crafted form post or restored backup cannot
  expose another course's private questions. The settings form validates the category and
  requires one; restore keeps a category outside the backup only if it is usable there.
- Match questions: distractor subquestions (no stem text) are decoy answers only; before
  they appeared as stem ships with no text.
- Scoring changes from evaluating the rules on the server (scores can differ from older
  games): a deflect never earns points (partial credit above 0.5 on a single-answer
  question used to pay +((f − 0.5) × 600) on every shot); correct ships that fall are
  charged when the level ends, and not at all if the player dies first; a single-answer
  question ends on its 100% answer; multi-answer levels with fractions like 1/3 now end;
  match pairs are worth 1000 ÷ real stems. The recorded score is bounded by 1000 points
  per second that levels were open (idle time does not count).
- The questions reach the game through a data attribute and `js_call_amd` instead of an
  inline script; the Audiowide font is served from `fonts/` by Moodle's theme font
  handler instead of the `font.php` script (removed); the game loop stops after game over
  and pauses while the page is hidden.
- Removed the 1.9-era `db/log.php`.
- Question and answer text is filtered and entity-decoded for the canvas (`&lt;` showed
  literally before; multilang now works).
- External functions use `core_external` classes (the global aliases are deprecated in 5.3).
- CI: PostgreSQL 17 / MariaDB 11.4, phpdoc fails on warnings, Behat checks SCSS
  deprecations, the Grunt stale-build check runs on 5.2 only.

### Fixed
- Gradebook: "None" grade type created a value grade item; course reset and "Remove all
  user scores" left grades behind; deleting an activity left its grade item; scales in use
  were reported unused. Scales are now refused by the settings form.
- Existing installs get their gradebook grades back-filled on upgrade.
- "Also include questions from subcategories" could not be turned off once enabled.
- Restore: links to Quizventure activities were left as `$@QUIZGAMEVIEWBYID*…@$` text;
  legacy id-only categories broke the restore; the restored category kept a temporary
  context id, so editing the activity silently switched category.
- Completion rule now works with "Default activity completion" and bulk editing
  (form element suffixes).
- Game: multi-answer multichoice questions could freeze the level so the game never
  ended and the score was never sent; the game captured the space and arrow keys in every
  text field on the page; holding space at game over skipped the score screen; the
  fullscreen exit handler was inverted.
- `scores.php` called the removed `error()`; `index.php` used a missing string; player
  names on the scores page were not escaped.
- Three PHPUnit files were never run (`*_testcase.php`); the completion tests failed on 5.2.

---

## [2026062200] — 2026-06-22 — v5.0 — Subcategories, CI cleanup, copyright

### Added
- **Subcategory questions** — new "Also include questions from subcategories"
  checkbox (`questioncategorysubcats`) on the activity settings form. When
  enabled, questions are drawn from the selected category and all of its
  subcategories instead of just the top category.
- `questioncategorysubcats` database column (upgrade savepoint 2026062200),
  included in backup/restore.
- Copyright attribution for Adam Jenkins added to file headers across the
  codebase (existing copyright lines preserved).

### Changed
- CI matrix narrowed to Moodle 5.1 and 5.2 only (PHP 8.2–8.3); Moodle 4.0–5.0
  combinations removed.
- The two CI workflow files (`moodle-ci.yml`, `moodle5-ci.yml`) were merged
  into a single `moodle-ci.yml`.
- Release tagged v5.0.

### Verified
- Ran moodle-plugin-ci locally (phplint, validate, savepoints, codechecker,
  phpdoc, mustache, grunt/eslint/stylelint) — all clean.
- Reviewed all raw SQL in the plugin for injection risk — every query uses
  parameterised placeholders or `get_in_or_equal()`; no string-concatenated
  user input found.

---

## [2026061600] — 2026-06-16 — Moodle 5.x support + gradebook integration

### Added
- **Gradebook integration** — scores are now written to the Moodle gradebook
  immediately after each game, using the student's personal best.
- **Grade settings** — activity form now includes the standard Moodle grade
  section (maximum grade, grade category, grade to pass) via
  `FEATURE_GRADE_HAS_GRADE` and `standard_grading_coursemodule_elements()`.
- **Game score for maximum grade** (`gradepassingscore`) — new field that maps
  a target game score to the gradebook maximum. For example, setting 10 000
  means a student scoring 5 000 earns 50 % of the maximum grade. Set to 0 to
  store the raw game score directly.
- `gradepassingscore` database column (upgrade savepoint 2026061600).
- `gradepassingscore` included in backup/restore.
- CI matrix entries for Moodle 5.1 (PHP 8.2, 8.3) and 5.2 (PHP 8.3).

### Fixed
- **Moodle 5.x question category context** — the settings form now queries
  all module-level contexts in the course instead of passing a course context
  to `question_category_options()`, which threw
  `Invalid context id specified context::instance_by_id()` in Moodle 5.x.
- **Gradebook not updated on score save** — `quizgame_add_highscore()` now
  calls `quizgame_update_grades()` after each game.
- **Grade item never created** — `quizgame_add_instance()` now calls
  `quizgame_grade_item_update()` so the gradebook item exists from the moment
  the activity is created.
- **PHP 8.4 implicit-nullable deprecation** — function signatures updated.
- **PHPUnit 11 deprecation** — test class updated to avoid deprecated assertions.
- **`js_call_amd` size limit** — switched to `js_init_code` (no 1024-character
  argument cap), fixing a silent failure in developer-debug mode.
- **SQL injection risk in `quizgame_reset_userdata()`** — replaced subquery
  string concatenation with `get_fieldset_select()` + `get_in_or_equal()`.
- **XSS risk in inline script block** — added `JSON_HEX_TAG` to
  `json_encode()` so `</script>` inside question text cannot break the page.
- **Magic-quotes dead code in `quizgame_cleanup()`** — removed a PHP 4/5
  `stripslashes()` / `preg_replace()` pair whose effects cancelled for `"`
  but silently destroyed backslashes in question text (e.g. LaTeX).

---

## [2022112200] — 2024-10-07 — CI for Moodle 4.2–4.4 (PR #84)

### Added
- GitHub Actions CI matrix extended to cover Moodle 4.2, 4.3, 4.4 with
  PHP 8.0–8.3 and both PostgreSQL and MariaDB.

---

## [2022112200] — 2023-01-27 — Moodle 4.1 fixes (PR #80)

### Fixed
- Compatibility fixes for Moodle 4.1 API changes.

---

## [2022111800] — 2022-03-27 — Moodle 4.0 fixes (PR #77)

### Fixed
- Compatibility fixes for Moodle 4.0 API changes.

---

## [2021112200] — 2021-05-21 — Moodle 3.11 (PR #73)

### Added
- **Activity completion** — automatic completion based on a minimum game score
  (`completionscore` field). Each correct first-try answer is worth 1 000 pts.
- **Privacy API provider** — personal data (user ID, score, timestamp) can now
  be exported or deleted via Moodle's Privacy and Policies tools.

### Fixed
- Deprecated function replaced (`get_context_instance` → `context_module::instance`).
- Migrated from Travis CI to GitHub Actions.
- Various Moodle-CI coding-style fixes (PHPDoc, whitespace).

---

## [2019052000] — 2019-05-18 — Moodle 3.7 (PR #68)

### Added
- **True/false question type** support.
- **Improved multiple-choice and matching** question handling.
- **Mobile / touch support** — fullscreen mode on iOS and Android; touch events.
- **Text wrapping** for long questions and answers.
- **Behat tests** for core gameplay scenarios.
- Activity appears correctly on the Moodle Dashboard (upcoming events, etc.).

### Fixed
- Score incrementing broken for true/false and multichoice questions.
- CSS rule too broad (unintended style bleed).
- Audiowide font loaded over HTTPS to avoid mixed-content warnings.
