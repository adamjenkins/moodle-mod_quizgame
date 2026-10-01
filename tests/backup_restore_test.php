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

namespace mod_quizgame;

use backup;
use backup_controller;
use restore_controller;
use restore_dbops;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/mod/quizgame/locallib.php');
require_once($CFG->dirroot . '/mod/quizgame/backup/moodle2/restore_quizgame_activity_task.class.php');

/**
 * Backup and restore of quizgame activities.
 *
 * @package    mod_quizgame
 * @category   test
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_quizgame_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_quizgame_activity_task::class)]
final class backup_restore_test extends \advanced_testcase {
    /**
     * Create a question bank in a course with a category holding a true/false question.
     *
     * @param \stdClass $course
     * @return \stdClass the category
     */
    protected function create_category(\stdClass $course): \stdClass {
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category([
            'contextid' => \context_module::instance($qbank->cmid)->id,
        ]);
        $generator->create_question('truefalse', 'true', ['category' => $category->id]);
        return $category;
    }

    /**
     * Back up a course or activity and restore it into a new course.
     *
     * @param string $type backup::TYPE_1COURSE or backup::TYPE_1ACTIVITY
     * @param int $id course or course module id
     * @param int $categoryid category for the new course
     * @param int $restoreuserid user who restores (admin backs up)
     * @return int the new course id
     */
    protected function backup_and_restore(string $type, int $id, int $categoryid, int $restoreuserid): int {
        global $DB;
        $admin = get_admin();
        $bc = new backup_controller(
            $type,
            $id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $admin->id
        );
        $bc->execute_plan();
        $backupid = $bc->get_backupid();
        $bc->destroy();

        $newcourseid = restore_dbops::create_new_course('Restored', 'RESTORED' . $id, $categoryid);
        if ($restoreuserid != $admin->id) {
            // The bare course from create_new_course() has no enrolment instances: assign the role directly.
            $role = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
            role_assign($role, $restoreuserid, \context_course::instance($newcourseid));
        }
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $restoreuserid,
            backup::TARGET_NEW_COURSE
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();
        return $newcourseid;
    }

    /**
     * The category is remapped to the restored course's bank, settings survive, and links are decoded.
     */
    public function test_course_restore(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $category = $this->create_category($course);
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $course->id,
            'questioncategory' => $category->id . ',' . $category->contextid,
            'questioncategorysubcats' => 1,
            'grade' => 80,
            'gradepassingscore' => 9000,
        ]);
        $link = (new \moodle_url('/mod/quizgame/view.php', ['id' => $quizgame->cmid]))->out(false);
        $DB->set_field('quizgame', 'intro', '<a href="' . $link . '">Play</a>', ['id' => $quizgame->id]);

        $newcourseid = $this->backup_and_restore(backup::TYPE_1COURSE, $course->id, $course->category, get_admin()->id);

        $restored = $DB->get_record('quizgame', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertEquals(80, $restored->grade);
        $this->assertEquals(9000, $restored->gradepassingscore);
        $this->assertEquals(1, $restored->questioncategorysubcats);

        // The category is the copy in the restored course's question bank, not the original.
        $newcategoryid = quizgame_get_category_id($restored->questioncategory);
        $this->assertNotEquals($category->id, $newcategoryid);
        $newcategory = $DB->get_record('question_categories', ['id' => $newcategoryid], '*', MUST_EXIST);
        $newcontext = \context::instance_by_id($newcategory->contextid);
        $this->assertEquals(CONTEXT_MODULE, $newcontext->contextlevel);
        $this->assertEquals($newcourseid, $newcontext->get_course_context()->instanceid);
        $this->assertCount(1, quizgame_get_game_questions($restored));

        // The link to the activity points at the restored activity.
        $newcm = get_coursemodule_from_instance('quizgame', $restored->id, $newcourseid, false, MUST_EXIST);
        $this->assertStringContainsString('view.php?id=' . $newcm->id, $restored->intro);
        $this->assertStringNotContainsString('$@QUIZGAME', $restored->intro);
    }

    /**
     * A shared bank's category that is not in the backup is kept only for a user who may use its questions.
     */
    public function test_activity_restore_shared_bank(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $bankcourse = $this->getDataGenerator()->create_course();
        $category = $this->create_category($bankcourse);
        $course = $this->getDataGenerator()->create_course();
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $course->id,
            'questioncategory' => $category->id . ',' . $category->contextid,
        ]);

        // The administrator may use the shared bank: the category is kept.
        $admincourseid = $this->backup_and_restore(backup::TYPE_1ACTIVITY, $quizgame->cmid, $course->category, get_admin()->id);
        $restored = $DB->get_record('quizgame', ['course' => $admincourseid], '*', MUST_EXIST);
        $this->assertEquals($category->id, quizgame_get_category_id($restored->questioncategory));

        // A teacher with no access to the bank's course: the category must be re-selected.
        $teacher = $this->getDataGenerator()->create_user();
        $teachercourseid = $this->backup_and_restore(backup::TYPE_1ACTIVITY, $quizgame->cmid, $course->category, $teacher->id);
        $restored = $DB->get_record('quizgame', ['course' => $teachercourseid], '*', MUST_EXIST);
        $this->assertSame('', (string) $restored->questioncategory);
    }
}
