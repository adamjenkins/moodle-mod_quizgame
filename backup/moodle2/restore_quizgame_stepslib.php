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
 * Structure step to restore one quizgame activity.
 *
 * @package mod_quizgame
 * @subpackage backup-moodle2
 * @copyright 2018 Stephen Bourget
 * @copyright 2026 Adam Jenkins <hama.history@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Structure step to restore one quizgame activity.
 *
 * @package mod_quizgame
 * @subpackage backup-moodle2
 * @copyright 2018 Stephen Bourget
 * @copyright 2026 Adam Jenkins <hama.history@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_quizgame_activity_structure_step extends restore_activity_structure_step {
    /**
     * DB structure for a quizgame.
     */
    protected function define_structure() {

        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('quizgame', '/activity/quizgame');
        if ($userinfo) {
            $paths[] = new restore_path_element('quizgame_score', '/activity/quizgame/scores/score');
        }

        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Function to restore the quizgame activity
     * @param StdClass $data
     */
    protected function process_quizgame($data) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quizgame/locallib.php');

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        // Map the question category. The value is "categoryid,contextid" (older backups: the id alone).
        // Only the id is stored: the context id is still temporary at this point of the restore, and the
        // settings form rebuilds it from the category.
        $oldcategoryid = quizgame_get_category_id($data->questioncategory);
        $newcategoryid = $oldcategoryid ? $this->get_mappingid('question_category', $oldcategoryid) : false;
        if ($newcategoryid) {
            $data->questioncategory = (string) $newcategoryid;
        } else if ($oldcategoryid && $this->task->is_samesite() && $this->category_usable($oldcategoryid, $data->course)) {
            // The bank was not in the backup but is on this site and usable here, so it still applies.
            $data->questioncategory = (string) $oldcategoryid;
        } else {
            if ($oldcategoryid) {
                $this->log('question category ' . $oldcategoryid . ' was associated with the quizgame ' .
                    $oldid . ' but cannot be used as it is not available in this backup or course. ' .
                    'The category needs to be re-selected.', backup::LOG_INFO);
            }
            $data->questioncategory = '';
        }

        // Insert the quizgame record.
        $newitemid = $DB->insert_record('quizgame', $data);
        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);
        $this->set_mapping('quizgame', $oldid, $newitemid);
    }

    /**
     * Whether a question category that was not in the backup may be kept for the restored game.
     *
     * Its bank must qualify for the target course, and a bank in another course (a shared bank)
     * only if the user restoring may use its questions, as in the settings form. The backup file
     * is untrusted input, so the category id in it proves nothing.
     *
     * @param int $categoryid question category id from the backup
     * @param int $courseid target course id
     * @return bool
     */
    protected function category_usable(int $categoryid, int $courseid): bool {
        global $DB;

        if (!quizgame_category_allowed($categoryid, $courseid)) {
            return false;
        }
        $context = context::instance_by_id($DB->get_field('question_categories', 'contextid', ['id' => $categoryid]));
        if ($context->get_course_context()->instanceid == $courseid) {
            return true;
        }
        return has_capability('moodle/question:useall', $context, $this->task->get_userid());
    }

    /**
     * Function to restore a single play through
     * @param StdClass $data
     */
    protected function process_quizgame_score($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;

        $data->quizgameid = $this->get_new_parentid('quizgame');
        $data->userid = $this->get_mappingid('user', $data->userid);

        $newitemid = $DB->insert_record('quizgame_scores', $data);
        $this->set_mapping('quizgame_scores', $oldid, $newitemid, true); // Files by this itemname.
    }

    /**
     * After restore hook, process file attachments.
     */
    protected function after_execute() {
        // Add quizgame related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_quizgame', 'intro', null);
    }
}
