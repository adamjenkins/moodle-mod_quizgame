<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Internal library of functions for module quizgame
 *
 * All the quizgame specific functions, needed to implement the module
 * logic, should go here. Never include this file from your lib.php!
 *
 * @package    mod_quizgame
 * @copyright  2014 John Okely <john@moodle.com>
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/lib/completionlib.php');

/**
 * Turn question or answer text into plain text for the game canvas.
 *
 * Filters run first (multilang, etc.), then tags are stripped and entities decoded, because the
 * canvas draws text literally.
 *
 * @param string $text The text to be cleaned
 * @param int $format The text format (FORMAT_*)
 * @param context $context The context used for filtering
 * @return string Plain text on a single line
 */
function quizgame_cleanup($text, $format, $context) {
    $text = format_text((string) $text, $format, ['context' => $context, 'para' => false]);
    $text = core_text::entities_to_utf8(strip_tags($text));
    return trim(preg_replace('/\s+/u', ' ', $text));
}

/**
 * Get the question category id from the stored questioncategory value.
 *
 * The value is stored as "categoryid,contextid" by the settings form; older rows may hold the id alone.
 *
 * @param string|null $stored The quizgame.questioncategory value
 * @return int The category id, 0 if none
 */
function quizgame_get_category_id($stored) {
    return (int) explode(',', (string) $stored)[0];
}

/**
 * Whether a question category may feed a quizgame in the given course.
 *
 * Qualifying categories belong to a question bank activity in the same course, or to a shared
 * question bank (an activity that publishes questions) in another course. Private banks of other
 * courses' activities (e.g. a quiz's own questions) never qualify. This is checked when the game
 * is rendered; the settings form and restore additionally require the user to be allowed to use
 * the questions of a bank in another course.
 *
 * @param int $categoryid The question category id
 * @param int $courseid The course the quizgame belongs to
 * @return bool
 */
function quizgame_category_allowed($categoryid, $courseid) {
    global $DB;

    if (empty($categoryid)) {
        return false;
    }
    $contextid = $DB->get_field('question_categories', 'contextid', ['id' => $categoryid]);
    if (!$contextid) {
        return false;
    }
    $context = context::instance_by_id($contextid, IGNORE_MISSING);
    if (!$context || $context->contextlevel != CONTEXT_MODULE) {
        return false;
    }
    $cm = $DB->get_record_sql(
        'SELECT cm.course, m.name AS modname
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.id = :cmid',
        ['cmid' => $context->instanceid]
    );
    if (!$cm) {
        return false;
    }
    return $cm->course == $courseid || plugin_supports('mod', $cm->modname, FEATURE_PUBLISHES_QUESTIONS, false);
}

/**
 * Load the questions a game plays: multichoice, truefalse and match questions of its category.
 *
 * Returns server-side question objects (with answers and fractions). Never send these to the
 * browser; \mod_quizgame\local\game_session::prepare() builds the browser's copy.
 *
 * @param stdClass $quizgame The quizgame record
 * @return stdClass[] Questions keyed by id
 */
function quizgame_get_game_questions($quizgame) {
    $categoryid = quizgame_get_category_id($quizgame->questioncategory);
    if (!quizgame_category_allowed($categoryid, $quizgame->course)) {
        return [];
    }
    if (!empty($quizgame->questioncategorysubcats)) {
        // Subcategories always share the parent's context, so the check above covers them.
        $categoryids = array_values(question_categorylist($categoryid));
    } else {
        $categoryids = [$categoryid];
    }
    $questionids = question_bank::get_finder()->get_questions_from_categories($categoryids, '');
    if (!$questionids) {
        return [];
    }
    $questions = question_load_questions($questionids);
    if (!is_array($questions)) {
        // Question_load_questions() returns an error string when the options fail to load.
        debugging('mod_quizgame: ' . $questions, DEBUG_DEVELOPER);
        return [];
    }
    return array_filter($questions, function ($question) {
        if ($question->qtype == 'match') {
            // At least one real stem (subquestions without text are distractors).
            foreach ($question->options->subquestions ?? [] as $sub) {
                if ((string) $sub->questiontext !== '') {
                    return true;
                }
            }
            return false;
        }
        return in_array($question->qtype, ['multichoice', 'truefalse']) && !empty($question->options->answers);
    });
}

/**
 * Function to add the students score to the DB.
 * @param stdClass $quizgame
 * @param float $score
 * @return int
 */
function quizgame_add_highscore($quizgame, $score) {
    global $USER, $DB;

    $cm = get_coursemodule_from_instance('quizgame', $quizgame->id, 0, false, MUST_EXIST);
    $course     = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $context = context_module::instance($cm->id);

    // Write the high score to the DB.
    $record = new stdClass();
    $record->quizgameid = $quizgame->id;
    $record->userid = $USER->id;
    $record->score = $score;
    $record->timecreated = time();
    $record->id = $DB->insert_record('quizgame_scores', $record);

    // Trigger the game score added event.
    $event = \mod_quizgame\event\game_score_added::create(
        ['objectid' => $record->id,
        'context' => $context,
        'other' => ['score' => $score],
        ]
    );

    $event->add_record_snapshot('quizgame', $quizgame);
    $event->add_record_snapshot('quizgame_scores', $record);
    $event->trigger();

    // Update completion state.
    $completion = new completion_info($course);
    if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC && $quizgame->completionscore) {
        $completion->update_state($cm, COMPLETION_COMPLETE, $record->userid);
    }

    // Push the new high score to the gradebook.
    quizgame_update_grades($quizgame, $USER->id);

    return $record->id;
}

/**
 * Function to record the player starting the quizgame.
 * @param stdClass $quizgame
 * @return boolean
 */
function quizgame_log_game_start($quizgame) {
    global $DB;

    $cm = get_coursemodule_from_instance('quizgame', $quizgame->id, 0, false, MUST_EXIST);
    $course     = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $context = context_module::instance($cm->id);

    // Trigger the game score added event.
    $event = \mod_quizgame\event\game_started::create(
        ['objectid' => $quizgame->id,
        'context' => $context,
        ]
    );

    $event->add_record_snapshot('quizgame', $quizgame);
    $event->trigger();

    return true;
}
