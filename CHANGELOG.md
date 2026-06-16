# Changelog

All notable changes to Quizventure (mod_quizgame) are documented here.
Entries are ordered newest-first. Dates reflect the release or commit date.

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
