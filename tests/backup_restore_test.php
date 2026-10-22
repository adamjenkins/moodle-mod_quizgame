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
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
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
     * Create a quizgame with the given settings, back up and restore its course, and assert every setting survived.
     *
     * @param array $settings quizgame column => value to create the activity with
     * @param string $label prefix for the assertion messages
     */
    protected function assert_settings_restore(array $settings, string $label = ''): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $category = $this->create_category($course);
        $this->getDataGenerator()->create_module('quizgame', $settings + [
            'course' => $course->id,
            'questioncategory' => $category->id . ',' . $category->contextid,
        ]);

        $newcourseid = $this->backup_and_restore(backup::TYPE_1COURSE, $course->id, $course->category, get_admin()->id);

        $restored = $DB->get_record('quizgame', ['course' => $newcourseid], '*', MUST_EXIST);
        foreach ($settings as $column => $value) {
            $this->assertEquals($value, (string) $restored->$column, $label . $column);
        }
    }

    /**
     * The category is remapped to the restored course's bank, settings survive, and links are decoded.
     */
    public function test_course_restore(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['summary' => '']);
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

        $bankcourse = $this->getDataGenerator()->create_course(['summary' => '']);
        $category = $this->create_category($bankcourse);
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
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

    /**
     * Every scoring and Sassy setting survives a course backup and restore.
     */
    public function test_scoring_and_sassy_settings_restore(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $settings = [
            'pointscorrect' => 2500,
            'wrongpenalties' => '100,250,600',
            'misspenalty' => 40,
            'sassy' => 1,
            'sassywrongtrigger' => 5,
            'sassycleanneeded' => 2,
            'sassybosslimit' => 6,
            'sassyreadtime' => 9,
            'sassypenalties' => 'decoys,drain',
            'sassyorder' => 1,
            'sassymaxdecoys' => 4,
            'sassydrainrate' => 250,
            'sassydrainincrease' => 75,
            'sassybossstyle' => 2,
            'sassyswarmsize' => 30,
            'sassytaunts' => "Taunt one\nTaunt || two",
            'sassydecoylabels' => "Nope\nNah",
            'sassybosslines' => 'The end || Really',
            'sassyreadfirst' => 'Slow down || Read it',
        ];
        $this->assert_settings_restore($settings);
    }

    /**
     * Every rewards setting survives a course backup and restore, whether rewards are on or off.
     */
    public function test_rewards_settings_restore(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Values other than the defaults, so a column lost on the way would come back changed.
        $variants = [
            [
                'rewards' => 1,
                'rewardcombo' => 0,
                'rewardmaxmult' => 7,
                'rewardlife' => 0,
                'rewardlifestreak' => 3,
                'rewardmaxlives' => 8,
                'rewardbonus' => 0,
                'rewardbonusstreak' => 9,
                'rewardbonuspoints' => 2500,
                'rewardpraise' => 0,
                'rewardpraiselines' => "Great flying\nSuper",
                'rewardmissbreak' => 1,
                'rewardbonusships' => 7,
                'rewardbonusspeed' => 250,
                'startlives' => 6,
                'speedramp' => 12,
                'speedrampstart' => 2,
                'speedroundjump' => 0,
            ],
            [
                'rewards' => 0,
                'rewardcombo' => 1,
                'rewardmaxmult' => 2,
                'rewardlife' => 1,
                'rewardlifestreak' => 12,
                'rewardmaxlives' => 3,
                'rewardbonus' => 1,
                'rewardbonusstreak' => 1,
                'rewardbonuspoints' => 99999,
                'rewardpraise' => 1,
                'rewardpraiselines' => '',
                'rewardmissbreak' => 0,
                'rewardbonusships' => 1,
                'rewardbonusspeed' => 25,
                'startlives' => 1,
                'speedramp' => 0,
                'speedrampstart' => 1,
                'speedroundjump' => 200,
            ],
        ];
        foreach ($variants as $i => $settings) {
            $this->assert_settings_restore($settings, 'variant ' . $i . ': ');
        }
    }

    /**
     * A backup is untrusted input: settings outside the form's rules come back cleaned.
     */
    public function test_restore_cleans_settings(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $category = $this->create_category($course);
        // The generator writes the record as given, like a hand-edited backup would.
        $this->getDataGenerator()->create_module('quizgame', [
            'course' => $course->id,
            'questioncategory' => $category->id . ',' . $category->contextid,
            'pointscorrect' => 9999999999,
            'misspenalty' => 9999999999,
            'wrongpenalties' => '1,2,x',
            'rewardbonusships' => 500,
        ]);

        $newcourseid = $this->backup_and_restore(backup::TYPE_1COURSE, $course->id, $course->category, get_admin()->id);

        $restored = $DB->get_record('quizgame', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertEquals(
            [100000, 100000, '300', 10],
            [$restored->pointscorrect, $restored->misspenalty, $restored->wrongpenalties, $restored->rewardbonusships]
        );
    }
}
