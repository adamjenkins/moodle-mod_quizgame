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

use mod_quizgame\local\game_session;
use mod_quizgame_external;

/**
 * Tests for the quizgame web services.
 *
 * @package    mod_quizgame
 * @category   test
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mod_quizgame_external::class)]
final class external_test extends \core_external\tests\externallib_testcase {
    /** @var \stdClass the course */
    protected $course;

    /** @var \stdClass the quizgame */
    protected $quizgame;

    /** @var \stdClass the true/false question (true is correct) */
    protected $question;

    /** @var \stdClass an enrolled student */
    protected $student;

    /**
     * Create a course with a one-question quizgame and an enrolled student.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $this->course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $this->question = $generator->create_question('truefalse', 'true', ['category' => $category->id]);
        $this->quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $this->course->id,
            'questioncategory' => (string) $category->id,
        ]);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
    }

    /**
     * Render the game for the current user, as view.php does, and return the browser's questions.
     *
     * @return array
     */
    protected function render_game(): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quizgame/locallib.php');
        return (new game_session($this->quizgame))->prepare(\context_module::instance($this->quizgame->cmid));
    }

    /**
     * Token of the correct (fraction 1) or wrong answer.
     *
     * @param bool $correct
     * @return string
     */
    protected function token(bool $correct): string {
        global $DB;
        $answerid = $DB->get_field('question_answers', 'id', [
            'question' => $this->question->id,
            'fraction' => $correct ? 1 : 0,
        ]);
        return (new game_session($this->quizgame))->token('a', $answerid);
    }

    /**
     * The recorded score is the server's, whatever the client sends.
     */
    public function test_server_computes_the_score(): void {
        global $DB;
        $this->setUser($this->student);
        $this->render_game();

        $this->assertTrue(mod_quizgame_external::start_game($this->quizgame->id));
        $result = mod_quizgame_external::answer($this->quizgame->id, $this->question->id, 1, 'shoot', $this->token(false));
        $this->assertEquals('deflect', $result['result']);
        $result = mod_quizgame_external::answer($this->quizgame->id, $this->question->id, 1, 'shoot', $this->token(true));
        $this->assertEquals('hit', $result['result']);
        $this->assertEquals(700, $result['score']);

        $id = mod_quizgame_external::update_score($this->quizgame->id, 999999);
        $this->assertEquals(700, $DB->get_field('quizgame_scores', 'score', ['id' => $id]));
        $this->assertEquals($this->student->id, $DB->get_field('quizgame_scores', 'userid', ['id' => $id]));

        // The game is finished: a second score needs a new game.
        try {
            mod_quizgame_external::update_score($this->quizgame->id);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertEquals('nogamestarted', $e->errorcode);
        }
        $this->assertEquals(1, $DB->count_records('quizgame_scores', ['quizgameid' => $this->quizgame->id]));
    }

    /**
     * A score without a started game is refused; a negative game records 0.
     */
    public function test_update_score_requires_started_game(): void {
        global $DB;
        $this->setUser($this->student);
        $this->render_game();

        try {
            mod_quizgame_external::update_score($this->quizgame->id, 500);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertEquals('nogamestarted', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('quizgame_scores', ['quizgameid' => $this->quizgame->id]));

        mod_quizgame_external::start_game($this->quizgame->id);
        mod_quizgame_external::answer($this->quizgame->id, $this->question->id, 1, 'shoot', $this->token(false));
        $id = mod_quizgame_external::update_score($this->quizgame->id);
        $this->assertEquals(0, $DB->get_field('quizgame_scores', 'score', ['id' => $id]));
    }

    /**
     * An unfinished game (e.g. the tab was closed) is recorded when the next game starts.
     */
    public function test_unfinished_game_recorded_on_next_start(): void {
        global $DB;
        $this->setUser($this->student);
        $this->render_game();

        mod_quizgame_external::start_game($this->quizgame->id);
        mod_quizgame_external::answer($this->quizgame->id, $this->question->id, 1, 'shoot', $this->token(true));
        $this->assertFalse($DB->record_exists('quizgame_scores', ['quizgameid' => $this->quizgame->id]));

        mod_quizgame_external::start_game($this->quizgame->id);
        $scores = $DB->get_fieldset_select('quizgame_scores', 'score', 'quizgameid = ?', [$this->quizgame->id]);
        $this->assertEquals([1000], $scores);
    }

    /**
     * An unfinished game that ended at zero or below is not recorded.
     */
    public function test_unfinished_zero_game_not_recorded(): void {
        global $DB;
        $this->setUser($this->student);
        $this->render_game();

        mod_quizgame_external::start_game($this->quizgame->id);
        mod_quizgame_external::answer($this->quizgame->id, $this->question->id, 1, 'shoot', $this->token(false));
        mod_quizgame_external::start_game($this->quizgame->id);
        $this->assertFalse($DB->record_exists('quizgame_scores', ['quizgameid' => $this->quizgame->id]));
    }

    /**
     * Scores are rounded and kept within 0 .. 1000 x (whole seconds levels were open + 1).
     */
    public function test_bounded_score(): void {
        $this->assertSame(700, mod_quizgame_external::bounded_score(700.4, 0.2));
        $this->assertSame(3000, mod_quizgame_external::bounded_score(5000, 2.9));
        $this->assertSame(0, mod_quizgame_external::bounded_score(-250, 10));
    }

    /**
     * Guests get their shots checked but cannot record scores.
     */
    public function test_guest_plays_without_recording(): void {
        global $DB;
        $enrol = enrol_get_plugin('guest');
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'guest'], '*', MUST_EXIST);
        $enrol->update_status($instance, ENROL_INSTANCE_ENABLED);
        $this->setGuestUser();
        $this->render_game();

        $this->assertTrue(mod_quizgame_external::start_game($this->quizgame->id));
        $result = mod_quizgame_external::answer($this->quizgame->id, $this->question->id, 1, 'shoot', $this->token(true));
        $this->assertEquals('hit', $result['result']);

        $this->expectException(\required_capability_exception::class);
        mod_quizgame_external::update_score($this->quizgame->id);
    }

    /**
     * Users without the play capability cannot record scores.
     */
    public function test_play_capability_required(): void {
        global $DB;
        $context = \context_module::instance($this->quizgame->cmid);
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability('mod/quizgame:play', CAP_PROHIBIT, $studentrole, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->student);
        $this->assertFalse(has_capability('mod/quizgame:play', $context));
        $this->render_game();
        mod_quizgame_external::start_game($this->quizgame->id);

        $this->expectException(\required_capability_exception::class);
        mod_quizgame_external::update_score($this->quizgame->id);
    }

    /**
     * Unknown actions are refused.
     */
    public function test_invalid_action(): void {
        $this->setUser($this->student);
        $this->render_game();
        mod_quizgame_external::start_game($this->quizgame->id);

        $this->expectException(\invalid_parameter_exception::class);
        mod_quizgame_external::answer($this->quizgame->id, $this->question->id, 1, 'cheat');
    }
}
