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
 * Library of interface functions and constants for module quizgame
 *
 * All the core Moodle functions, neeeded to allow the module to work
 * integrated in Moodle should be placed here.
 * All the quizgame specific functions, needed to implement all the module
 * logic, should go to locallib.php. This will help to save some memory when
 * Moodle is performing actions across all modules.
 *
 * @package    mod_quizgame
 * @copyright  2014 John Okely <john@moodle.com>
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Returns the information on whether the module supports a feature
 *
 * @see plugin_supports() in lib/moodlelib.php
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed true if the feature is supported, null if unknown
 */
function quizgame_supports($feature) {
    switch ($feature) {
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_USES_QUESTIONS:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Saves a new instance of the quizgame into the database
 *
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param stdClass $quizgame An object from the form in mod_form.php
 * @param ?mod_quizgame_mod_form $mform The add instance form
 * @return int The id of the newly inserted quizgame record
 */
function quizgame_add_instance(stdClass $quizgame, ?mod_quizgame_mod_form $mform = null) {
    global $DB;

    $quizgame->timecreated = time();
    $quizgame->id = $DB->insert_record('quizgame', $quizgame);

    quizgame_grade_item_update($quizgame);

    return $quizgame->id;
}

/**
 * Updates an instance of the quizgame in the database
 *
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param stdClass $quizgame An object from the form in mod_form.php
 * @param ?mod_quizgame_mod_form $mform The add instance form
 * @return boolean Success/Fail
 */
function quizgame_update_instance(stdClass $quizgame, ?mod_quizgame_mod_form $mform = null) {
    global $DB;

    $quizgame->timemodified = time();
    $quizgame->id = $quizgame->instance;

    $result = $DB->update_record('quizgame', $quizgame);

    quizgame_grade_item_update($quizgame);
    quizgame_update_grades($quizgame);

    return $result;
}

/**
 * Removes an instance of the quizgame from the database
 *
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id Id of the module instance
 * @return boolean Success/Failure
 */
function quizgame_delete_instance($id) {
    global $DB;

    if (! $quizgame = $DB->get_record('quizgame', ['id' => $id])) {
        return false;
    }

    $DB->delete_records('quizgame_scores', ['quizgameid' => $quizgame->id]);
    quizgame_grade_item_delete($quizgame);
    $DB->delete_records('quizgame', ['id' => $quizgame->id]);

    return true;
}

/**
 * Returns a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @param stdClass $course The course record.
 * @param stdClass $user The user record.
 * @param cm_info|stdClass $mod The course module info object or record.
 * @param stdClass $quizgame The quizgame instance record.
 * @return stdclass|null
 */
function quizgame_user_outline($course, $user, $mod, $quizgame) {

    global $DB;
    if ($game = $DB->count_records('quizgame_scores', ['quizgameid' => $quizgame->id, 'userid' => $user->id])) {
        $result = new stdClass();

        if ($game > 0) {
            $games = $DB->get_records(
                'quizgame_scores',
                ['quizgameid' => $quizgame->id, 'userid' => $user->id],
                'timecreated DESC',
                '*',
                0,
                1
            );
            foreach ($games as $last) {
                $data = new stdClass();
                $data->score = $last->score;
                $data->times = $game;
                $result->info = get_string("playedxtimeswithhighscore", "quizgame", $data);
                $result->time = $last->timecreated;
            }
        } else {
            $result->info = get_string("notyetplayed", "quizgame");
        }

        return $result;
    }
    return null;
}

/**
 * Prints a detailed representation of what a user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param stdClass $course the current course record
 * @param stdClass $user the record of the user we are generating report for
 * @param cm_info $mod course module info
 * @param stdClass $quizgame the module instance record
 * @return void, is supposed to echo directly
 */
function quizgame_user_complete($course, $user, $mod, $quizgame) {
    global $DB;

    if (
        $games = $DB->get_records(
            'quizgame_scores',
            ['quizgameid' => $quizgame->id, 'userid' => $user->id],
            'timecreated ASC'
        )
    ) {
        $attempt = 1;
        foreach ($games as $game) {
            echo get_string('attempt', 'quizgame', $attempt++) . ': ';
            echo get_string('achievedhighscoreof', 'quizgame', $game->score);
            echo ' - ' . userdate($game->timecreated) . '<br />';
        }
    } else {
        print_string("notyetplayed", "quizgame");
    }
}

// Gradebook API.

/**
 * Is a given scale used by the instance of quizgame?
 *
 * This function returns if a scale is being used by one quizgame
 * if it has support for grading and scales. Commented code should be
 * modified if necessary. See forum, glossary or journal modules
 * as reference.
 *
 * @param int $quizgameid ID of an instance of this module
 * @param int $scaleid ID of the scale
 * @return bool true if the scale is used by the given quizgame instance
 */
function quizgame_scale_used($quizgameid, $scaleid) {
    global $DB;

    if ($scaleid && $DB->record_exists('quizgame', ['id' => $quizgameid, 'grade' => -$scaleid])) {
        return true;
    } else {
        return false;
    }
}

/**
 * Checks if scale is being used by any instance of quizgame.
 *
 * This is used to find out if scale used anywhere.
 *
 * @param int $scaleid The id of the scale
 * @return boolean true if the scale is used by any quizgame instance
 */
function quizgame_scale_used_anywhere($scaleid) {
    global $DB;

    return $scaleid && $DB->record_exists('quizgame', ['grade' => -$scaleid]);
}

/**
 * Creates or updates grade item for the give quizgame instance
 *
 * Needed by grade_update_mod_grades() in lib/gradelib.php
 *
 * @param stdClass $quizgame instance object with extra cmidnumber and modname property
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function quizgame_grade_item_update(stdClass $quizgame, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $item = [];
    $item['itemname'] = clean_param($quizgame->name, PARAM_NOTAGS);
    if (isset($quizgame->cmidnumber)) {
        $item['idnumber'] = $quizgame->cmidnumber;
    }

    if ($quizgame->grade > 0) {
        $item['gradetype'] = GRADE_TYPE_VALUE;
        $item['grademax']  = $quizgame->grade;
        $item['grademin']  = 0;
    } else {
        // Scales are refused by the settings form: a game score has no meaningful scale mapping.
        $item['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $item['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/quizgame', $quizgame->course, 'mod', 'quizgame', $quizgame->id, 0, $grades, $item);
}

/**
 * Delete the grade item of a quizgame instance.
 *
 * @param stdClass $quizgame The quizgame record
 * @return int 0 if ok, error code otherwise
 */
function quizgame_grade_item_delete(stdClass $quizgame) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/quizgame', $quizgame->course, 'mod', 'quizgame', $quizgame->id, 0, null, ['deleted' => 1]);
}

/**
 * Convert a raw game score to a Moodle gradebook rawgrade.
 *
 * When $target > 0 the score is scaled proportionally: reaching $target game
 * points earns the full $grademax; going past it is still capped at $grademax.
 * When $target == 0 the raw game score is returned unchanged (legacy behaviour —
 * Moodle's grademax cap still applies in the gradebook). Negative scores give 0.
 *
 * @param float $gamescore The player's best game score.
 * @param float $target gradepassingscore setting (0 = no scaling).
 * @param float $grademax The activity's maximum gradebook grade.
 * @return float
 */
function quizgame_scale_game_score(float $gamescore, float $target, float $grademax): float {
    $gamescore = max(0.0, $gamescore);
    if ($target > 0.0) {
        return min($gamescore / $target * $grademax, $grademax);
    }
    return $gamescore;
}

/**
 * Update quizgame grades in the gradebook
 *
 * Needed by grade_update_mod_grades() in lib/gradelib.php
 *
 * @param stdClass $quizgame instance object with extra cmidnumber and modname property
 * @param int $userid update grade of specific user only, 0 means all participants
 * @param bool $nullifnone If a single user has no score, clear their grade rather than leave it
 * @return void
 */
function quizgame_update_grades(stdClass $quizgame, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if ($quizgame->grade <= 0) {
        quizgame_grade_item_update($quizgame);
        return;
    }

    $target = !empty($quizgame->gradepassingscore) ? (float) $quizgame->gradepassingscore : 0.0;

    if ($userid) {
        $maxscore = $DB->get_field_sql(
            'SELECT MAX(score) FROM {quizgame_scores} WHERE quizgameid = :qid AND userid = :uid',
            ['qid' => $quizgame->id, 'uid' => $userid]
        );
        $grade = new stdClass();
        $grade->userid = $userid;
        if ($maxscore !== null && $maxscore !== false) {
            $grade->rawgrade = quizgame_scale_game_score((float) $maxscore, $target, (float) $quizgame->grade);
            $grades = [$userid => $grade];
        } else if ($nullifnone) {
            $grade->rawgrade = null;
            $grades = [$userid => $grade];
        } else {
            $grades = [];
        }
    } else {
        $records = $DB->get_records_sql(
            'SELECT userid, MAX(score) AS maxscore FROM {quizgame_scores} WHERE quizgameid = :qid GROUP BY userid',
            ['qid' => $quizgame->id]
        );
        $grades = [];
        foreach ($records as $record) {
            $grade = new stdClass();
            $grade->userid = $record->userid;
            $grade->rawgrade = quizgame_scale_game_score((float) $record->maxscore, $target, (float) $quizgame->grade);
            $grades[$record->userid] = $grade;
        }
    }

    quizgame_grade_item_update($quizgame, $grades);
}

// File API.

/**
 * Serves the files from the quizgame file areas
 *
 * @package mod_quizgame
 * @category files
 *
 * @param stdClass $course the course object
 * @param stdClass $cm the course module object
 * @param stdClass $context the quizgame's context
 * @param string $filearea the name of the file area
 * @param array $args extra arguments (itemid, path)
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 */
function quizgame_pluginfile($course, $cm, $context, $filearea, array $args, $forcedownload, array $options = []) {

    if ($context->contextlevel != CONTEXT_MODULE) {
        send_file_not_found();
    }

    require_login($course, true, $cm);

    send_file_not_found();
}

// Navigation API.

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the quizgame.
 * @param stdClass $mform form passed by reference
 */
function quizgame_reset_course_form_definition(&$mform) {

    $mform->addElement('header', 'quizgameheader', get_string('modulenameplural', 'quizgame'));
    $mform->addElement('advcheckbox', 'reset_quizgame_scores', get_string('removescores', 'quizgame'));
}

/**
 * Course reset form defaults.
 * @param stdClass $course
 * @return array
 */
function quizgame_reset_course_form_defaults($course) {
    return ['reset_quizgame_scores' => 1];
}

/**
 * Actual implementation of the rest coures functionality, delete all the
 * quizgame responses for course $data->courseid.
 *
 * @param stdClass $data the data submitted from the reset course.
 * @return array status array
 */
function quizgame_reset_userdata($data) {
    global $DB;

    $componentstr = get_string('modulenameplural', 'quizgame');
    $status = [];

    if (!empty($data->reset_quizgame_scores)) {
        $quizgameids = $DB->get_fieldset_select('quizgame', 'id', 'course = ?', [$data->courseid]);
        if ($quizgameids) {
            [$insql, $inparams] = $DB->get_in_or_equal($quizgameids);
            $DB->delete_records_select('quizgame_scores', "quizgameid $insql", $inparams);
        }
        // Grades come from the scores, so they go too (unless the whole gradebook is reset anyway).
        if (empty($data->reset_gradebook_grades)) {
            quizgame_reset_gradebook($data->courseid);
        }
        $status[] = ['component' => $componentstr, 'item' => get_string('removescores', 'quizgame'), 'error' => false];
    }

    return $status;
}

/**
 * Removes all grades from gradebook
 *
 * @param int $courseid
 * @param string $type (Optional)
 */
function quizgame_reset_gradebook($courseid, $type = '') {
    global $DB;

    $sql = "SELECT g.*, cm.idnumber as cmidnumber, g.course as courseid
              FROM {quizgame} g, {course_modules} cm, {modules} m
             WHERE m.name='quizgame' AND m.id=cm.module AND cm.instance=g.id AND g.course=?";

    if ($quizgames = $DB->get_records_sql($sql, [$courseid])) {
        foreach ($quizgames as $quizgame) {
            quizgame_grade_item_update($quizgame, 'reset');
        }
    }
}

/**
 * This function receives a calendar event and returns the action associated with it, or null if there is none.
 *
 * This is used by block_myoverview in order to display the event appropriately. If null is returned then the event
 * is not displayed on the block.
 *
 * @param calendar_event $event
 * @param \core_calendar\action_factory $factory
 * @param int $userid User id to use for all capability checks, etc. Set to 0 for current user (default).
 * @return \core_calendar\local\event\entities\action_interface|null
 */
function mod_quizgame_core_calendar_provide_event_action(
    calendar_event $event,
    \core_calendar\action_factory $factory,
    int $userid = 0
) {
    global $USER;
    if (!$userid) {
        $userid = $USER->id;
    }
    $cm = get_fast_modinfo($event->courseid, $userid)->instances['quizgame'][$event->instance];
    if (!$cm->uservisible) {
        // The module is not visible to the user for any reason.
        return null;
    }
    $completion = new \completion_info($cm->get_course());
    $completiondata = $completion->get_data($cm, false, $userid);
    if ($completiondata->completionstate != COMPLETION_INCOMPLETE) {
        return null;
    }
    return $factory->create_instance(
        get_string('view'),
        new \moodle_url('/mod/quizgame/view.php', ['id' => $cm->id]),
        1,
        true
    );
}

/**
 * Callback which returns human-readable strings describing the active completion custom rules for the module instance.
 *
 * @param object $cm the cm_info object.
 * @return array $descriptions the array of descriptions for the custom rules.
 */
function mod_quizgame_get_completion_active_rule_descriptions($cm) {
    // Values will be present in cm_info, and we assume these are up to date.
    if (
        !$cm instanceof cm_info || !isset($cm->customdata['customcompletionrules'])
        || $cm->completion != COMPLETION_TRACKING_AUTOMATIC
    ) {
        return [];
    }

    $descriptions = [];
    foreach ($cm->customdata['customcompletionrules'] as $key => $val) {
        switch ($key) {
            case 'completionscore':
                if (!empty($val)) {
                    $descriptions[] = get_string('completionscoredesc', 'quizgame', $val);
                }
                break;
            default:
                break;
        }
    }
    return $descriptions;
}

/**
 * Add a get_coursemodule_info function in case any pcast type wants to add 'extra' information
 * for the course (see resource).
 *
 * Given a course_module object, this function returns any "extra" information that may be needed
 * when printing this activity in a course listing.  See get_array_of_activities() in course/lib.php.
 *
 * @param stdClass $coursemodule The coursemodule object (record).
 * @return cached_cm_info An object on information that the courses
 *                        will know about (most noticeably, an icon).
 */
function quizgame_get_coursemodule_info($coursemodule) {
    global $DB;

    $dbparams = ['id' => $coursemodule->instance];
    $fields = 'id, name, intro, introformat, completionscore';
    if (!$quizgame = $DB->get_record('quizgame', $dbparams, $fields)) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $quizgame->name;

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $result->content = format_module_intro('quizgame', $quizgame, $coursemodule->id, false);
    }

    // Populate the custom completion rules as key => value pairs, but only if the completion mode is 'automatic'.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionscore'] = $quizgame->completionscore;
    }

    return $result;
}
