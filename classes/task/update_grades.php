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

namespace mod_quizgame\task;

/**
 * Create the grade items of all quizgames and push every player's best score to the gradebook.
 *
 * Queued by the 2026100100 upgrade step: the gradebook API cannot run during an upgrade
 * (grade_update() reaches get_fast_modinfo(), which refuses while $CFG->upgraderunning).
 *
 * @package    mod_quizgame
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_grades extends \core\task\adhoc_task {
    /**
     * Update the grades of every quizgame.
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quizgame/lib.php');

        $sql = "SELECT q.*, cm.idnumber AS cmidnumber
                  FROM {quizgame} q
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quizgame'";
        $quizgames = $DB->get_recordset_sql($sql);
        foreach ($quizgames as $quizgame) {
            quizgame_grade_item_update($quizgame);
            quizgame_update_grades($quizgame);
        }
        $quizgames->close();
    }
}
