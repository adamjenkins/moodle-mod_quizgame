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
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
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
        $this->course = $this->getDataGenerator()->create_course(['summary' => '']);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $this->course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $this->question = $generator->create_question('truefalse', 'true', ['category' => $category->id]);
        // Rewards off unless a test turns them on, so the scoring and Sassy tests see unmultiplied points.
        $this->quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $this->course->id,
            'questioncategory' => (string) $category->id,
            'rewards' => 0,
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

    /**
     * Change settings of the test quizgame in the database (the web services read the record from there).
     *
     * @param array $settings column => value
     */
    protected function set_settings(array $settings): void {
        global $DB;
        foreach ($settings as $column => $value) {
            $DB->set_field('quizgame', $column, $value, ['id' => $this->quizgame->id]);
        }
    }

    /**
     * Call the answer web service and check the response against its returns description.
     *
     * @param int $level
     * @param string $action
     * @param string $token
     * @return array the cleaned response
     */
    protected function answer(int $level, string $action, string $token = ''): array {
        $result = mod_quizgame_external::answer($this->quizgame->id, $this->question->id, $level, $action, $token);
        return \core_external\external_api::clean_returnvalue(mod_quizgame_external::answer_returns(), $result);
    }

    /**
     * The rewards effects of a response while rewards are off.
     *
     * @return array
     */
    protected function no_rewards(): array {
        return [
            'multiplier' => 1,
            'combo' => '',
            'lives' => 0,
            'lifegained' => false,
            'bonusships' => [],
            'praise' => '',
        ];
    }

    /**
     * With Sassy off every response carries empty effects, also for the levelstart action.
     */
    public function test_answer_effects_sassy_off(): void {
        $this->setUser($this->student);
        $this->render_game();
        mod_quizgame_external::start_game($this->quizgame->id);

        $none = [
            'messages' => [],
            'penalty' => '',
            'cheater' => false,
            'readtime' => 0,
            'decoys' => [],
            'dodge' => false,
            'reversed' => false,
            'aggression' => 0,
            'drainrate' => 0.0,
            'boss' => '',
            'swarmsize' => 0,
        ] + $this->no_rewards();
        $result = $this->answer(1, 'levelstart');
        $this->assertEquals(
            ['started', 0.0, 0.0, false],
            [$result['result'], $result['points'], $result['score'], $result['levelcomplete']]
        );
        $this->assertEquals($none, $result['effects']);
        for ($i = 0; $i < 5; $i++) {
            $this->assertEquals($none, $this->answer(1, 'shoot', $this->token(false))['effects']);
        }
        $this->assertEquals('hit', $this->answer(1, 'shoot', $this->token(true))['result']);
    }

    /**
     * The effects payload of the shoot that starts cheater mode and of the levelstart that applies Read it first.
     */
    public function test_answer_effects_payload(): void {
        $this->set_settings(['sassy' => 1, 'sassywrongtrigger' => 1, 'sassyreadtime' => 6]);
        $this->setUser($this->student);
        $this->render_game();
        mod_quizgame_external::start_game($this->quizgame->id);

        $result = $this->answer(1, 'levelstart');
        $this->assertEquals('started', $result['result']);
        $this->assertFalse($result['effects']['cheater']);

        // The first offence: cheater mode starts, Read it first waits for the next level.
        $result = $this->answer(1, 'shoot', $this->token(false));
        $this->assertEquals(['deflect', -300.0], [$result['result'], $result['score']]);
        $this->assertEquals([
            'messages' => [],
            'penalty' => '',
            'cheater' => true,
            'readtime' => 0,
            'decoys' => [],
            'dodge' => false,
            'reversed' => false,
            'aggression' => 0,
            'drainrate' => 0.0,
            'boss' => '',
            'swarmsize' => 0,
        ] + $this->no_rewards(), $result['effects']);

        // Starting level 2 closes level 1, whose correct answer was let go: -1000 more, -1300 in all.
        $result = $this->answer(2, 'levelstart');
        $this->assertEquals('started', $result['result']);
        $this->assertEquals([
            'messages' => [
                'Take your time. No, really, I\'ll wait!',
                'Read the question, then shoot only the ship with the correct answer. Ships appear in a moment.',
            ],
            'penalty' => 'readfirst',
            'cheater' => true,
            'readtime' => 6,
            'decoys' => [],
            'dodge' => false,
            'reversed' => false,
            'aggression' => 0,
            'drainrate' => 0.0,
            'boss' => '',
            'swarmsize' => 0,
        ] + $this->no_rewards(), $result['effects']);

        // Shooting during the reading time is blocked, and the one-off effects are gone.
        $result = $this->answer(2, 'shoot', $this->token(true));
        $this->assertEquals(['blocked', 0.0, -1300.0], [$result['result'], $result['points'], $result['score']]);
        $effects = $result['effects'];
        $this->assertEquals(['', 0, []], [$effects['penalty'], $effects['readtime'], $effects['messages']]);
        $this->assertTrue($result['effects']['cheater']);
    }

    /**
     * The boss response (swarm style) passes the returns description and sets the score to 0.
     */
    public function test_answer_effects_boss(): void {
        global $DB;
        $this->set_settings([
            'sassy' => 1,
            'sassywrongtrigger' => 1,
            'sassybosslimit' => 2,
            'sassybossstyle' => 1,
            'sassyswarmsize' => 25,
            'sassypenalties' => 'returnfire',
            'sassybosslines' => 'Bye',
        ]);
        $this->setUser($this->student);
        $this->render_game();
        mod_quizgame_external::start_game($this->quizgame->id);

        $this->assertEquals(1000.0, $this->answer(1, 'shoot', $this->token(true))['score']);
        // Offence 1 (Read it first, waiting for level 3), then offence 2 in the same level: the boss.
        $this->assertEquals(700.0, $this->answer(2, 'shoot', $this->token(false))['score']);
        $result = $this->answer(2, 'shoot', $this->token(false));
        $this->assertEquals(0.0, $result['score']);
        $this->assertEquals([
            'messages' => ['Bye'],
            'penalty' => 'boss',
            'cheater' => true,
            'readtime' => 0,
            'decoys' => [],
            'dodge' => false,
            'reversed' => false,
            'aggression' => 0,
            'drainrate' => 0.0,
            'boss' => 'swarm',
            'swarmsize' => 25,
        ] + $this->no_rewards(), $result['effects']);

        // Everything after the boss is done, and the recorded score is 0.
        $result = $this->answer(3, 'levelstart');
        $effects = $result['effects'];
        $this->assertEquals(
            ['done', 0.0, 'swarm', ''],
            [$result['result'], $result['score'], $effects['boss'], $effects['penalty']]
        );
        $id = mod_quizgame_external::update_score($this->quizgame->id, 5000);
        $this->assertEquals(0, $DB->get_field('quizgame_scores', 'score', ['id' => $id]));
    }

    /**
     * The per-second cap uses the activity's points for a correct hit.
     */
    public function test_points_cap_uses_pointscorrect(): void {
        global $DB;
        $this->assertSame(10000, mod_quizgame_external::bounded_score(12000, 1.5, 5000));
        $this->assertSame(2000, mod_quizgame_external::bounded_score(12000, 1.5));

        $this->set_settings(['pointscorrect' => 5000]);
        $this->setUser($this->student);
        $this->render_game();
        mod_quizgame_external::start_game($this->quizgame->id);
        $this->assertEquals(5000, $this->answer(1, 'shoot', $this->token(true))['score']);
        // Under the old fixed cap of 1000 points per second this quick game would record 1000.
        $id = mod_quizgame_external::update_score($this->quizgame->id);
        $this->assertEquals(5000, $DB->get_field('quizgame_scores', 'score', ['id' => $id]));
    }

    /**
     * Scores are capped per second at the points times the maximum multiplier; bonus points have their own allowance.
     */
    public function test_bounded_score_rewards(): void {
        // Maximum-multiplier play (x4 of 1000 in the first second) is not clipped, as it would be by the plain cap.
        $this->assertSame(4000, mod_quizgame_external::bounded_score(4000, 0.5, 4000));
        $this->assertSame(1000, mod_quizgame_external::bounded_score(4000, 0.5));
        // Bonus points are outside the cap: 4000 capped + 2000 bonus.
        $this->assertSame(6000, mod_quizgame_external::bounded_score(6000, 0.5, 4000, 2000));
        $this->assertSame(6000, mod_quizgame_external::bounded_score(9000, 0.5, 4000, 2000));
        $this->assertSame(3500, mod_quizgame_external::bounded_score(3500, 0.5, 4000, 2000));
        // Bonus points have an allowance of their own, the same size as the cap: 4000 capped + 4000 of the bonus.
        $this->assertSame(8000, mod_quizgame_external::bounded_score(1004000, 0.5, 4000, 1000000));
        // A negative game with a bonus still records 0 at worst.
        $this->assertSame(0, mod_quizgame_external::bounded_score(-500, 1, 1000, 1000));
    }

    /**
     * A quick combo game is recorded in full under the raised cap.
     */
    public function test_combo_game_recorded_under_raised_cap(): void {
        global $DB;
        $this->set_settings(['rewards' => 1, 'rewardcombo' => 1, 'rewardmaxmult' => 10, 'rewardbonus' => 0]);
        $this->setUser($this->student);
        $this->render_game();
        mod_quizgame_external::start_game($this->quizgame->id);

        // A one-question game: 1000 x1, x1 (the combo starts with the second clean question), x2, x3.
        $points = [];
        for ($level = 1; $level <= 4; $level++) {
            $points[] = $this->answer($level, 'shoot', $this->token(true))['points'];
        }
        $this->assertEquals([1000.0, 1000.0, 2000.0, 3000.0], $points);
        // Within a second the plain cap would record 1000; the cap of 10 x 1000 per second keeps all 7000.
        $id = mod_quizgame_external::update_score($this->quizgame->id);
        $this->assertEquals(7000, $DB->get_field('quizgame_scores', 'score', ['id' => $id]));
    }

    /**
     * The answer responses carry the reward effects: combo, extra life, praise, bonus round, lost lives,
     * and the bonus points are recorded outside the cap.
     */
    public function test_answer_effects_rewards(): void {
        global $DB;
        $this->set_settings([
            'rewards' => 1,
            'rewardcombo' => 1,
            'rewardmaxmult' => 4,
            'rewardlife' => 1,
            'rewardlifestreak' => 1,
            'rewardmaxlives' => 5,
            'rewardbonus' => 1,
            'rewardbonusstreak' => 1,
            'rewardbonuspoints' => 1000,
            'rewardpraise' => 1,
            'rewardpraiselines' => 'Well flown',
        ]);
        $this->setUser($this->student);
        $this->render_game();
        mod_quizgame_external::start_game($this->quizgame->id);

        $result = $this->answer(1, 'levelstart');
        $this->assertEquals([
            'multiplier' => 1,
            'combo' => '',
            'lives' => 3,
            'lifegained' => false,
            'bonusships' => [],
            'praise' => '',
        ], array_intersect_key($result['effects'], $this->no_rewards()));

        // A clean question: a life is earned; the combo only starts with the second clean question.
        $result = $this->answer(1, 'shoot', $this->token(true));
        $this->assertEquals(['hit', 1000.0], [$result['result'], $result['points']]);
        $this->assertEquals([
            'multiplier' => 1,
            'combo' => '',
            'lives' => 4,
            'lifegained' => true,
            'bonusships' => [],
            'praise' => 'Well flown',
        ], array_intersect_key($result['effects'], $this->no_rewards()));

        // The next levelstart starts the bonus round.
        $result = $this->answer(2, 'levelstart');
        $effects = $result['effects'];
        $this->assertCount(2, $effects['bonusships']);
        $this->assertEquals(['+1000', '+1000'], array_column($effects['bonusships'], 'text'));
        $this->assertEquals(
            [1, '', 4, false, get_string('rewards_bonus_msg', 'quizgame')],
            [$effects['multiplier'], $effects['combo'], $effects['lives'], $effects['lifegained'], $effects['praise']]
        );
        $result = $this->answer(2, 'shoot', $effects['bonusships'][0]['token']);
        $this->assertEquals(['bonus', 1000.0, 2000.0], [$result['result'], $result['points'], $result['score']]);

        // A lost life counts down.
        $result = $this->answer(2, 'lifelost');
        $this->assertEquals(['done', 0.0, 3], [$result['result'], $result['points'], $result['effects']['lives']]);

        // 1000 points within the first second are under the cap; the 1000 bonus points are added outside it.
        $id = mod_quizgame_external::update_score($this->quizgame->id);
        $this->assertEquals(2000, $DB->get_field('quizgame_scores', 'score', ['id' => $id]));
    }
}
