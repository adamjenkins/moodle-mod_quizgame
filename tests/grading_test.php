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

use grade_item;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quizgame/lib.php');
require_once($CFG->dirroot . '/mod/quizgame/locallib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Tests for the gradebook integration and the student-facing grading information.
 *
 * @package    mod_quizgame
 * @category   test
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('quizgame_grade_item_update')]
#[\PHPUnit\Framework\Attributes\CoversFunction('quizgame_update_grades')]
#[\PHPUnit\Framework\Attributes\CoversFunction('quizgame_scale_game_score')]
#[\PHPUnit\Framework\Attributes\CoversFunction('quizgame_reset_userdata')]
#[\PHPUnit\Framework\Attributes\CoversFunction('quizgame_delete_instance')]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_quizgame_renderer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_quizgame\task\update_grades::class)]
final class grading_test extends \advanced_testcase {
    /**
     * Fetch the grade item of a quizgame.
     *
     * @param \stdClass $quizgame
     * @return grade_item|false
     */
    protected function get_grade_item(\stdClass $quizgame) {
        return grade_item::fetch([
            'courseid' => $quizgame->course,
            'itemtype' => 'mod',
            'itemmodule' => 'quizgame',
            'iteminstance' => $quizgame->id,
            'itemnumber' => 0,
        ]);
    }

    /**
     * Get a user's gradebook grade for a quizgame.
     *
     * @param \stdClass $quizgame
     * @param int $userid
     * @return float|null
     */
    protected function get_user_grade(\stdClass $quizgame, int $userid): ?float {
        $grades = grade_get_grades($quizgame->course, 'mod', 'quizgame', $quizgame->id, $userid);
        $grade = $grades->items[0]->grades[$userid]->grade ?? null;
        return $grade === null ? null : (float) $grade;
    }

    /**
     * Score scaling: proportional to the target, capped at the maximum, never negative.
     */
    public function test_scale_game_score(): void {
        $this->assertEquals(50.0, quizgame_scale_game_score(5000, 10000, 100));
        $this->assertEquals(100.0, quizgame_scale_game_score(25000, 10000, 100));
        $this->assertEquals(0.0, quizgame_scale_game_score(-3000, 10000, 100));
        $this->assertEquals(7300.0, quizgame_scale_game_score(7300, 0, 100));
        $this->assertEquals(0.0, quizgame_scale_game_score(-300, 0, 100));
    }

    /**
     * Point grading creates a value grade item; "None" creates none.
     */
    public function test_grade_item_type(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $graded = $this->getDataGenerator()->create_module('quizgame', ['course' => $course->id, 'grade' => 80]);
        $item = $this->get_grade_item($graded);
        $this->assertNotEmpty($item);
        $this->assertEquals(GRADE_TYPE_VALUE, $item->gradetype);
        $this->assertEquals(80, (float) $item->grademax);

        $ungraded = $this->getDataGenerator()->create_module('quizgame', ['course' => $course->id, 'grade' => 0]);
        $this->assertFalse($this->get_grade_item($ungraded));
    }

    /**
     * The best score, scaled against the target, becomes the grade.
     */
    public function test_best_score_becomes_grade(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $course->id,
            'grade' => 100,
            'gradepassingscore' => 10000,
        ]);

        $this->setUser($student);
        quizgame_add_highscore($quizgame, 4000);
        quizgame_add_highscore($quizgame, 6000);
        quizgame_add_highscore($quizgame, 2000);

        $this->assertEquals(60.0, $this->get_user_grade($quizgame, $student->id));
    }

    /**
     * Course reset of scores also removes the grades built from them.
     */
    public function test_reset_scores_removes_grades(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $quizgame = $this->getDataGenerator()->create_module('quizgame', ['course' => $course->id, 'grade' => 100]);

        $this->setUser($student);
        quizgame_add_highscore($quizgame, 50);
        $this->assertEquals(50.0, $this->get_user_grade($quizgame, $student->id));

        $this->setAdminUser();
        $status = quizgame_reset_userdata((object) ['courseid' => $course->id, 'reset_quizgame_scores' => 1]);

        $this->assertCount(1, $status);
        $this->assertFalse($DB->record_exists('quizgame_scores', ['quizgameid' => $quizgame->id]));
        $this->assertNull($this->get_user_grade($quizgame, $student->id));
    }

    /**
     * Deleting the activity removes its grade item.
     */
    public function test_delete_instance_removes_grade_item(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quizgame = $this->getDataGenerator()->create_module('quizgame', ['course' => $course->id, 'grade' => 100]);
        $this->assertNotEmpty($this->get_grade_item($quizgame));

        $this->assertTrue(quizgame_delete_instance($quizgame->id));
        $this->assertFalse($this->get_grade_item($quizgame));
    }

    /**
     * The upgrade back-fill task creates missing grade items and pushes the best scores.
     */
    public function test_update_grades_task(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $course->id,
            'grade' => 100,
            'gradepassingscore' => 10000,
        ]);
        // A site from before the gradebook integration: scores, but no grade item.
        $DB->insert_record('quizgame_scores', (object) [
            'quizgameid' => $quizgame->id,
            'userid' => $student->id,
            'score' => 5000,
            'timecreated' => time(),
        ]);
        $DB->delete_records('grade_items', ['itemmodule' => 'quizgame', 'iteminstance' => $quizgame->id]);
        $this->assertFalse($this->get_grade_item($quizgame));

        (new \mod_quizgame\task\update_grades())->execute();

        $this->assertNotEmpty($this->get_grade_item($quizgame));
        $this->assertEquals(50.0, $this->get_user_grade($quizgame, $student->id));
    }

    /**
     * Students are told the score needed for the full grade, and their best score.
     */
    public function test_render_grading_info(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url('/mod/quizgame/view.php');
        $renderer = $PAGE->get_renderer('mod_quizgame');

        $quizgame = (object) ['grade' => 100, 'gradepassingscore' => 12000];
        $html = $renderer->render_grading_info($quizgame, 4500);
        $this->assertStringContainsString(
            get_string('gradetargetinfo', 'mod_quizgame', (object) ['target' => '12000', 'grade' => '100']),
            $html
        );
        $this->assertStringContainsString(get_string('yourbestscore', 'mod_quizgame', '4500'), $html);

        $quizgame = (object) ['grade' => 100, 'gradepassingscore' => 0];
        $html = $renderer->render_grading_info($quizgame, null);
        $this->assertStringContainsString(get_string('graderawinfo', 'mod_quizgame', '100'), $html);
        $this->assertStringNotContainsString(get_string('yourbestscore', 'mod_quizgame', ''), $html);

        $quizgame = (object) ['grade' => 0, 'gradepassingscore' => 12000];
        $this->assertSame('', $renderer->render_grading_info($quizgame, 4500));
    }
}
