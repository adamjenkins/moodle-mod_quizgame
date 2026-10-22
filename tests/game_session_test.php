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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quizgame/locallib.php');

/**
 * Tests for the server-side game rules and scoring.
 *
 * @package    mod_quizgame
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(game_session::class)]
final class game_session_test extends \advanced_testcase {
    /** @var \stdClass the question category */
    protected $category;

    /** @var \core_question_generator */
    protected $generator;

    /** @var \stdClass the course */
    protected $course;

    /**
     * Set up a course with a question bank category.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $this->course->id]);
        $this->generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $this->category = $this->generator->create_question_category([
            'contextid' => \context_module::instance($qbank->cmid)->id,
        ]);
        $this->setAdminUser();
    }

    /**
     * Create a question in the category.
     *
     * @param string $qtype
     * @param string $which test question variant
     * @param array $overrides form data overrides
     * @return int question id
     */
    protected function question(string $qtype, string $which, array $overrides = []): int {
        $overrides['category'] = $this->category->id;
        return (int) $this->generator->create_question($qtype, $which, $overrides)->id;
    }

    /**
     * Create the game for the category, prepare it as the page does, and start a game.
     *
     * @param array $settings activity settings (scoring and Sassy columns) to override the defaults
     * @param bool $clocked whether the session reads time from its public $clock property instead of the real clock
     * @return game_session
     */
    protected function start_session(array $settings = [], bool $clocked = false): game_session {
        $quizgame = $this->getDataGenerator()->create_module('quizgame', $settings + [
            'course' => $this->course->id,
            'questioncategory' => (string) $this->category->id,
        ]);
        $session = $clocked ? $this->clocked_session($quizgame) : new game_session($quizgame);
        $session->prepare(\context_module::instance($quizgame->cmid));
        $session->start();
        return $session;
    }

    /**
     * A game session whose clock the test sets (the public $clock property, in seconds).
     *
     * @param \stdClass $quizgame
     * @return game_session
     */
    protected function clocked_session(\stdClass $quizgame): game_session {
        return new class ($quizgame) extends game_session {
            /** @var float The time now() returns. */
            public $clock = 1000.0;

            /**
             * The test's clock.
             *
             * @return float
             */
            protected function now(): float {
                return $this->clock;
            }
        };
    }

    /**
     * Sassy settings for a game where every wrong hit is an offence.
     *
     * @param array $settings further settings
     * @return array
     */
    protected function sassy_settings(array $settings = []): array {
        return $settings + [
            'sassy' => 1,
            'sassywrongtrigger' => 1,
            'sassybosslimit' => 20,
        ];
    }

    /**
     * The effects of a response when nothing Sassy happens.
     *
     * @return array
     */
    protected function no_effects(): array {
        return [
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
        ];
    }

    /**
     * Play one wrong shot per level and collect the penalties started, in order, until enough were seen.
     *
     * Each level is started, the clock moves past any reading time, and a wrong answer is shot once
     * (in a dodge round that shot is not a wrong hit, so no offence happens on that level). It stops
     * right after the response that started the last penalty wanted.
     *
     * @param game_session $session a clocked session
     * @param int $questionid the game's only question
     * @param string $wrongtoken token of a wrong answer
     * @param int $count penalties to collect
     * @return string[] penalty keys, 'boss' for the boss
     */
    protected function penalty_sequence(game_session $session, int $questionid, string $wrongtoken, int $count): array {
        $sequence = [];
        for ($level = 1; count($sequence) < $count && $level <= 100; $level++) {
            $effects = $session->answer($questionid, $level, 'levelstart')['effects'];
            if ($effects['penalty'] !== '') {
                $sequence[] = $effects['penalty'];
                // Level-scoped penalties show in the level they apply to.
                $this->assertSame($effects['penalty'] == 'reversed', $effects['reversed']);
                $this->assertSame($effects['penalty'] == 'dodge', $effects['dodge']);
                if (count($sequence) >= $count) {
                    break;
                }
            }
            $session->clock += 100;
            $effects = $session->answer($questionid, $level, 'shoot', $wrongtoken)['effects'];
            if ($effects['penalty'] !== '') {
                $sequence[] = $effects['penalty'];
            }
            if ($effects['boss'] !== '') {
                break;
            }
        }
        return array_slice($sequence, 0, $count);
    }

    /**
     * Play a true/false game with a boss limit of 2 to the boss: 1000 points, then two offences.
     *
     * @param array $settings further settings
     * @return array [game_session $session, int $questionid, array $tokens, array $bossresponse]
     */
    protected function play_to_boss(array $settings = []): array {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->sassy_settings($settings + ['sassybosslimit' => 2]), true);
        $tokens = $this->answer_tokens($session, $qid);

        $session->answer($qid, 1, 'levelstart');
        $this->assertEquals(1000.0, $session->answer($qid, 1, 'shoot', $tokens['1'][0])['score']);
        $session->answer($qid, 2, 'levelstart');
        $this->assertEquals(700.0, $session->answer($qid, 2, 'shoot', $tokens['0'][0])['score']);
        $session->answer($qid, 3, 'levelstart');
        $session->clock += 100;
        $boss = $session->answer($qid, 3, 'shoot', $tokens['0'][0]);
        return [$session, $qid, $tokens, $boss];
    }

    /**
     * Tokens of a choice question's answers, keyed by fraction (as string), in id order.
     *
     * @param game_session $session
     * @param int $questionid
     * @return array fraction => list of tokens
     */
    protected function answer_tokens(game_session $session, int $questionid): array {
        global $DB;
        $tokens = [];
        foreach ($DB->get_records('question_answers', ['question' => $questionid], 'id') as $answer) {
            $tokens[(string) (float) $answer->fraction][] = $session->token('a', $answer->id);
        }
        return $tokens;
    }

    /**
     * Assert that an action is refused with an error code.
     *
     * @param string $errorcode
     * @param callable $action
     */
    protected function assert_refused(string $errorcode, callable $action): void {
        try {
            $action();
            $this->fail('Expected moodle_exception ' . $errorcode);
        } catch (\moodle_exception $e) {
            $this->assertEquals($errorcode, $e->errorcode);
        }
    }

    /**
     * Truefalse: a wrong shot deflects for -300, the right one scores 1000 and completes the level.
     */
    public function test_truefalse(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session();
        $tokens = $this->answer_tokens($session, $qid);

        $result = $session->answer($qid, 1, 'shoot', $tokens['0'][0]);
        $this->assertEquals(['deflect', -300.0, false], [$result['result'], $result['score'], $result['levelcomplete']]);
        $result = $session->answer($qid, 1, 'shoot', $tokens['1'][0]);
        $this->assertEquals(['hit', 700.0, true], [$result['result'], $result['score'], $result['levelcomplete']]);
        // Shooting again on a completed level scores nothing.
        $result = $session->answer($qid, 1, 'shoot', $tokens['1'][0]);
        $this->assertEquals(['done', 700.0], [$result['result'], $result['score']]);
    }

    /**
     * Multi-answer multichoice: each correct half scores 500; the level completes after both.
     */
    public function test_multichoice_multi(): void {
        $qid = $this->question('multichoice', 'two_of_four');
        $session = $this->start_session();
        $tokens = $this->answer_tokens($session, $qid);

        $result = $session->answer($qid, 1, 'shoot', $tokens['0.5'][0]);
        $this->assertEquals(['hit', 500.0, false], [$result['result'], $result['score'], $result['levelcomplete']]);
        $this->assertEquals('done', $session->answer($qid, 1, 'shoot', $tokens['0.5'][0])['result']);
        $result = $session->answer($qid, 1, 'shoot', $tokens['0.5'][1]);
        $this->assertEquals(['hit', 1000.0, true], [$result['result'], $result['score'], $result['levelcomplete']]);
    }

    /**
     * Single-answer multichoice: partial credit deflects, and a deflect never pays (not even repeated).
     */
    public function test_multichoice_single_partial_credit(): void {
        $qid = $this->question('multichoice', 'one_of_four', ['fraction' => ['1.0', '0.9', '0.0', '-0.5', '0.0']]);
        $session = $this->start_session();
        $tokens = $this->answer_tokens($session, $qid);

        $result = $session->answer($qid, 1, 'shoot', $tokens['0.9'][0]);
        $this->assertEquals(['deflect', 0.0], [$result['result'], $result['score']]);
        $result = $session->answer($qid, 1, 'shoot', $tokens['0.9'][0]);
        $this->assertEquals(['deflect', 0.0], [$result['result'], $result['score']]);
        // A negative answer costs (f - 0.5) x 600 = -600.
        $this->assertEquals(-600.0, $session->answer($qid, 1, 'shoot', $tokens['-0.5'][0])['score']);
        $result = $session->answer($qid, 1, 'shoot', $tokens['1'][0]);
        $this->assertEquals(['hit', 400.0, true], [$result['result'], $result['score'], $result['levelcomplete']]);
    }

    /**
     * Leaving a level unfinished costs the correct answers that got away, whether the client says
     * "levelend" or simply moves on; a level that was completed costs nothing.
     */
    public function test_unfinished_level_penalty(): void {
        $multi = $this->question('multichoice', 'two_of_four');
        $single = $this->question('multichoice', 'one_of_four');
        $tf = $this->question('truefalse', 'true');
        $session = $this->start_session();
        $multitokens = $this->answer_tokens($session, $multi);

        $this->assertEquals(500.0, $session->answer($multi, 1, 'shoot', $multitokens['0.5'][0])['score']);
        // Moving on without levelend: the multi level's missed half costs 500; ending the next level
        // with its fully correct answer missed costs 1000.
        $this->assertEquals(-1000.0, $session->answer($single, 2, 'levelend')['score']);
        // A completed level costs nothing when it ends.
        $tftokens = $this->answer_tokens($session, $tf);
        $session->answer($tf, 3, 'shoot', $tftokens['1'][0]);
        $this->assertEquals(0.0, $session->answer($tf, 3, 'levelend')['score']);
        // A late shot on a closed level scores nothing.
        $this->assertEquals('done', $session->answer($tf, 3, 'shoot', $tftokens['1'][0])['result']);
    }

    /**
     * A question opens once per round, a new round replays them, and earlier levels cannot be reopened.
     */
    public function test_rounds_and_levels(): void {
        $tf = $this->question('truefalse', 'true');
        $multi = $this->question('multichoice', 'two_of_four');
        $session = $this->start_session();
        $tftokens = $this->answer_tokens($session, $tf);

        $session->answer($tf, 1, 'shoot', $tftokens['1'][0]);
        $this->assert_refused('invalidgamequestion', fn() => $session->answer($tf, 2, 'shoot', $tftokens['1'][0]));
        $session->answer($multi, 2, 'levelend');
        // Every question was played: the next round may replay the true/false question.
        $this->assertEquals('hit', $session->answer($tf, 3, 'shoot', $tftokens['1'][0])['result']);
        // An earlier level number is a late or forged call.
        $this->assert_refused('invalidgamequestion', fn() => $session->answer($multi, 2, 'levelend'));
    }

    /**
     * A game with a single question scores it again on every level.
     */
    public function test_single_question_game(): void {
        $tf = $this->question('truefalse', 'true');
        $session = $this->start_session();
        $tokens = $this->answer_tokens($session, $tf);

        for ($level = 1; $level <= 3; $level++) {
            $result = $session->answer($tf, $level, 'shoot', $tokens['1'][0]);
            $this->assertEquals(['hit', 1000.0 * $level], [$result['result'], $result['score']]);
        }
    }

    /**
     * Match: right pairs score 1000 / stems, wrong pairs miss, distractors are decoys only, and an
     * unfinished match level costs the unmatched pairs.
     */
    public function test_match(): void {
        global $DB;
        $qid = $this->question('match', 'foursubq');
        $session = $this->start_session();
        $bytext = [];
        foreach ($DB->get_records('qtype_match_subquestions', ['questionid' => $qid], 'id') as $sub) {
            $bytext[trim(strip_tags($sub->questiontext))] = $sub;
        }
        $stem = fn($text) => $session->token('s', $bytext[$text]->id);
        $choice = fn($text) => $session->token('m', $bytext[$text]->id);

        // Frog (amphibian) with the cat's answer (mammal): miss.
        $this->assertEquals('miss', $session->answer($qid, 1, 'shoot', $stem('frog'), $choice('cat'))['result']);
        // Frog with the newt's answer (also amphibian): hit, three real stems.
        $result = $session->answer($qid, 1, 'shoot', $stem('frog'), $choice('newt'));
        $this->assertEquals('hit', $result['result']);
        $this->assertEqualsWithDelta(1000 / 3, $result['score'], 0.001);
        // That answer ship is used up.
        $this->assertEquals('done', $session->answer($qid, 1, 'shoot', $stem('newt'), $choice('newt'))['result']);
        // The distractor ("insect", no stem text) is not a stem.
        $this->assert_refused('invalidgameanswer', fn() => $session->answer($qid, 1, 'shoot', $stem(''), $choice('')));

        // Two pairs unmatched when the level ends: -2 x 1000 / 3.
        $result = $session->answer($qid, 1, 'levelend');
        $this->assertEqualsWithDelta(-1000 / 3, $result['score'], 0.001);
        // Ending an already closed level again changes nothing, nor does a late pairing.
        $this->assertEqualsWithDelta(-1000 / 3, $session->answer($qid, 1, 'levelend')['score'], 0.001);
        $this->assertEquals('done', $session->answer($qid, 1, 'shoot', $stem('cat'), $choice('cat'))['result']);
    }

    /**
     * Tokens only work for their own question and session.
     */
    public function test_foreign_tokens(): void {
        global $SESSION;
        $tf = $this->question('truefalse', 'true');
        $multi = $this->question('multichoice', 'two_of_four');
        $session = $this->start_session();
        $multitokens = $this->answer_tokens($session, $multi);

        // A correct answer of another question.
        $this->assert_refused('invalidgameanswer', fn() => $session->answer($tf, 1, 'shoot', $multitokens['0.5'][0]));

        // A new browser session gets a new secret, so old tokens are rejected.
        unset($SESSION->mod_quizgame);
        $session = $this->start_session();
        $this->assert_refused('invalidgameanswer', fn() => $session->answer($multi, 1, 'shoot', $multitokens['0.5'][0]));
    }

    /**
     * Ending the game does not charge the ships still on screen, and reports the time levels were open.
     */
    public function test_finish(): void {
        $multi = $this->question('multichoice', 'two_of_four');
        $session = $this->start_session();
        $tokens = $this->answer_tokens($session, $multi);
        $session->answer($multi, 1, 'shoot', $tokens['0.5'][0]);

        [$score, $leveltime] = $session->finish();
        $this->assertEquals(500.0, $score);
        $this->assertGreaterThanOrEqual(0.0, $leveltime);
        $this->assertLessThan(60.0, $leveltime);
        $this->assertNull($session->finish());
    }

    /**
     * The browser copy never carries correctness: tokens only, kinds distinct, answer order shuffled.
     */
    public function test_browser_copy(): void {
        global $DB;
        $match = $this->question('match', 'foursubq');
        $tf = $this->question('truefalse', 'true');
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $this->course->id,
            'questioncategory' => (string) $this->category->id,
        ]);
        $session = new game_session($quizgame);
        $context = \context_module::instance($quizgame->cmid);

        $client = array_column($session->prepare($context), null, 'id');
        $this->assertStringNotContainsString('fraction', json_encode($client));
        $this->assertCount(3, $client[$match]['stems']);
        $this->assertCount(4, $client[$match]['answers']);
        $stemtokens = array_column($client[$match]['stems'], 'token');
        $answertokens = array_column($client[$match]['answers'], 'token');
        $this->assertEmpty(array_intersect($stemtokens, $answertokens));

        // Over 20 page views the answers of the true/false question are not always in database order.
        $idorder = [];
        foreach ($DB->get_records('question_answers', ['question' => $tf], 'id') as $answer) {
            $idorder[] = $session->token('a', $answer->id);
        }
        $orders = [];
        for ($i = 0; $i < 20; $i++) {
            $client = array_column($session->prepare($context), null, 'id');
            $orders[] = array_column($client[$tf]['answers'], 'token') === $idorder;
        }
        $this->assertContains(false, $orders);
    }

    /**
     * Questions the page did not offer, and actions without a started game, are refused.
     */
    public function test_refusals(): void {
        $tf = $this->question('truefalse', 'true');
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $this->course->id,
            'questioncategory' => (string) $this->category->id,
        ]);
        $session = new game_session($quizgame);
        $session->prepare(\context_module::instance($quizgame->cmid));
        $this->assert_refused('nogamestarted', fn() => $session->answer($tf, 1, 'levelend'));
        $session->start();
        $this->assert_refused('invalidgamequestion', fn() => $session->answer(999999, 1, 'levelend'));
    }

    /**
     * A record kept from before the scoring columns existed scores exactly as the default settings do.
     */
    public function test_record_without_new_columns(): void {
        $qid = $this->question('multichoice', 'one_of_four', ['fraction' => ['1.0', '0.9', '0.0', '-0.5', '0.0']]);
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $this->course->id,
            'questioncategory' => (string) $this->category->id,
        ]);
        foreach (array_keys(game_session::DEFAULTS) as $column) {
            unset($quizgame->$column);
        }
        unset($quizgame->sassytaunts, $quizgame->sassydecoylabels, $quizgame->sassybosslines);
        $session = new game_session($quizgame);
        $session->prepare(\context_module::instance($quizgame->cmid));
        $session->start();
        $tokens = $this->answer_tokens($session, $qid);

        $this->assertEquals(0.0, $session->answer($qid, 1, 'shoot', $tokens['0.9'][0])['score']);
        $this->assertEquals(-600.0, $session->answer($qid, 1, 'shoot', $tokens['-0.5'][0])['score']);
        $this->assertEquals(-900.0, $session->answer($qid, 1, 'shoot', $tokens['0'][0])['score']);
        $result = $session->answer($qid, 1, 'shoot', $tokens['1'][0]);
        $this->assertEquals(['hit', 100.0, true], [$result['result'], $result['score'], $result['levelcomplete']]);
        $this->assertSame($this->no_effects(), $result['effects']);
        $this->assertEquals(1000, $session->points_correct());
    }

    /**
     * The wrong-hit schedule escalates within a level, repeats its last value, and starts again each level.
     */
    public function test_wrong_hit_schedule(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session(['wrongpenalties' => '100,200,400']);
        $wrong = $this->answer_tokens($session, $qid)['0'][0];

        $points = [];
        for ($i = 0; $i < 4; $i++) {
            $points[] = $session->answer($qid, 1, 'shoot', $wrong)['points'];
        }
        $this->assertEquals([-100.0, -200.0, -400.0, -400.0], $points);
        // A new level starts the schedule again.
        $this->assertEquals(-100.0, $session->answer($qid, 2, 'shoot', $wrong)['points']);
        $this->assertEquals(-200.0, $session->answer($qid, 2, 'shoot', $wrong)['points']);
    }

    /**
     * Points for a correct hit scale hits and missed answers; wrong hits cost S[n] x (1 - 2 x fraction), never a gain.
     */
    public function test_partial_credit_formula(): void {
        $single = $this->question('multichoice', 'one_of_four', ['fraction' => ['1.0', '0.25', '0.0', '-0.5', '0.0']]);
        $multi = $this->question('multichoice', 'two_of_four');
        $session = $this->start_session(['pointscorrect' => 2000, 'wrongpenalties' => '400,1000']);
        $tokens = $this->answer_tokens($session, $single);
        $multitokens = $this->answer_tokens($session, $multi);

        $points = [];
        $points[] = $session->answer($single, 1, 'shoot', $tokens['0.25'][0])['points'];
        $points[] = $session->answer($single, 1, 'shoot', $tokens['-0.5'][0])['points'];
        $points[] = $session->answer($single, 1, 'shoot', $tokens['0'][0])['points'];
        $points[] = $session->answer($single, 1, 'shoot', $tokens['1'][0])['points'];
        // 400 x (1 - 0.5); 1000 x (1 + 1); 1000 x 1; then 2000 x 1 for the correct answer.
        $this->assertEquals([-200.0, -2000.0, -1000.0, 2000.0], $points);
        $this->assertEquals(-1200.0, $session->answer($single, 1, 'levelend')['score']);

        // Multi-answer: each half earns 2000 x 0.5, and the half that got away costs the same.
        $this->assertEquals(1000.0, $session->answer($multi, 2, 'shoot', $multitokens['0.5'][0])['points']);
        $this->assertEquals(-1200.0, $session->answer($multi, 2, 'levelend')['score']);
    }

    /**
     * A shot that hits nothing costs the miss penalty and is never a wrong hit.
     */
    public function test_miss_penalty(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session(['misspenalty' => 50, 'wrongpenalties' => '100,200']);
        $tokens = $this->answer_tokens($session, $qid);

        // A miss never opens a level.
        $this->assertEquals(['done', 0.0], array_values(array_slice($session->answer($qid, 1, 'miss'), 0, 2)));
        $this->assertEquals('started', $session->answer($qid, 1, 'levelstart')['result']);
        $result = $session->answer($qid, 1, 'miss');
        $this->assertEquals(['miss', -50.0, -50.0], [$result['result'], $result['points'], $result['score']]);
        $session->answer($qid, 1, 'miss');
        // The misses did not move the wrong-hit schedule on.
        $this->assertEquals(-100.0, $session->answer($qid, 1, 'shoot', $tokens['0'][0])['points']);
        $session->answer($qid, 1, 'shoot', $tokens['1'][0]);
        // A completed level takes no more misses.
        $result = $session->answer($qid, 1, 'miss');
        $this->assertEquals(['done', 0.0, 800.0], [$result['result'], $result['points'], $result['score']]);
        // A miss for a level that is not the current one is ignored.
        $this->assertEquals('done', $session->answer($qid, 2, 'miss')['result']);
    }

    /**
     * With Sassy off nothing changes however much the player guesses: no penalties, no effects.
     */
    public function test_sassy_off_changes_nothing(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session(['sassy' => 0, 'sassywrongtrigger' => 1, 'sassybosslimit' => 2], true);
        $tokens = $this->answer_tokens($session, $qid);

        for ($level = 1; $level <= 5; $level++) {
            $result = $session->answer($qid, $level, 'levelstart');
            $this->assertSame($this->no_effects(), $result['effects']);
            for ($i = 0; $i < 3; $i++) {
                $result = $session->answer($qid, $level, 'shoot', $tokens['0'][0]);
                $this->assertEquals('deflect', $result['result']);
                $this->assertSame($this->no_effects(), $result['effects']);
            }
            $this->assertEquals('hit', $session->answer($qid, $level, 'shoot', $tokens['1'][0])['result']);
        }
        // Five levels of 3 x -300 + 1000; the clock never moved.
        $this->assertEquals([500.0, 0.0], $session->finish());
    }

    /**
     * Cheater mode starts after N wrong hits across questions; a clean question resets the count; enough
     * clean questions end it, but the offence count stays for the game.
     */
    public function test_cheater_mode(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session([
            'sassy' => 1,
            'sassywrongtrigger' => 3,
            'sassycleanneeded' => 2,
            'sassypenalties' => 'returnfire',
            'sassybosslimit' => 10,
        ], true);
        $tokens = $this->answer_tokens($session, $qid);
        $wrong = fn($level) => $session->answer($qid, $level, 'shoot', $tokens['0'][0])['effects'];
        $right = fn($level) => $session->answer($qid, $level, 'shoot', $tokens['1'][0])['effects'];
        $start = fn($level) => $session->answer($qid, $level, 'levelstart')['effects'];

        // Level 1: two wrong hits, then the answer (not a clean question).
        $start(1);
        $wrong(1);
        $this->assertFalse($wrong(1)['cheater']);
        $right(1);
        // Level 2 is clean: the count starts again, so two more wrong hits are not enough.
        $start(2);
        $right(2);
        $start(3);
        $wrong(3);
        $this->assertFalse($wrong(3)['cheater']);
        // A level closed unfinished changes no counter; the third wrong hit in a row is the first offence.
        $start(4);
        $effects = $wrong(4);
        $this->assertTrue($effects['cheater']);
        // Read it first waits for the next level.
        $this->assertEquals(['', []], [$effects['penalty'], $effects['messages']]);

        $effects = $start(5);
        $this->assertEquals('readfirst', $effects['penalty']);
        $session->clock += 100;
        // One clean question of the two needed: still a cheater.
        $this->assertTrue($right(5)['cheater']);

        // The second offence starts return fire at once, with a taunt.
        $start(6);
        $wrong(6);
        $wrong(6);
        $effects = $wrong(6);
        $this->assertEquals(['returnfire', true, 1], [$effects['penalty'], $effects['cheater'], $effects['aggression']]);
        $this->assertNotEmpty($effects['messages']);
        // A further wrong hit escalates it.
        $this->assertEquals(2, $wrong(6)['aggression']);

        // The offence reset the clean count: two clean questions are needed again.
        $start(7);
        $effects = $right(7);
        $this->assertEquals([true, 2], [$effects['cheater'], $effects['aggression']]);
        $start(8);
        $effects = $right(8);
        $this->assertEquals([false, 0, ''], [$effects['cheater'], $effects['aggression'], $effects['penalty']]);

        // Offences are not reset: the next one is the third, return fire again, not Read it first.
        $start(9);
        $wrong(9);
        $wrong(9);
        $effects = $wrong(9);
        $this->assertEquals(['returnfire', 1], [$effects['penalty'], $effects['aggression']]);
    }

    /**
     * Read it first is the first offence whatever the settings; shots wait for the reading time, which
     * does not count as time the level was open.
     */
    public function test_read_it_first(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->sassy_settings([
            'sassypenalties' => 'drain',
            'sassyorder' => 1,
            'sassyreadtime' => 7,
            'misspenalty' => 50,
        ]), true);
        $tokens = $this->answer_tokens($session, $qid);

        $this->assertEquals(0, $session->answer($qid, 1, 'levelstart')['effects']['readtime']);
        $effects = $session->answer($qid, 1, 'shoot', $tokens['0'][0])['effects'];
        $this->assertEquals(['', 0, 0.0], [$effects['penalty'], $effects['readtime'], $effects['drainrate']]);

        // Starting level 2 closes level 1, whose correct answer was let go: -1000 more, -1300 in all.
        $result = $session->answer($qid, 2, 'levelstart');
        $this->assertEquals('started', $result['result']);
        $this->assertEquals('readfirst', $result['effects']['penalty']);
        $this->assertEquals(7, $result['effects']['readtime']);
        $this->assertSame([
            get_string('sassy_readfirst_msg', 'quizgame'),
            get_string('sassy_readfirst_help', 'quizgame'),
        ], $result['effects']['messages']);

        // While reading, shots and misses are blocked: no points and no wrong hit (it would start the drain).
        $session->clock = 1003.0;
        $result = $session->answer($qid, 2, 'shoot', $tokens['0'][0]);
        $this->assertEquals(['blocked', 0.0, -1300.0], [$result['result'], $result['points'], $result['score']]);
        $effects = $result['effects'];
        $this->assertEquals(['', 0.0, []], [$effects['penalty'], $effects['drainrate'], $effects['messages']]);
        $result = $session->answer($qid, 2, 'miss');
        $this->assertEquals(['blocked', -1300.0], [$result['result'], $result['score']]);

        $session->clock = 1007.0;
        $this->assertEquals('hit', $session->answer($qid, 2, 'shoot', $tokens['1'][0])['result']);
        $session->clock = 1009.0;
        $session->answer($qid, 2, 'levelend');
        // Level 1 was open 0 s, level 2 from the end of reading (1007) to 1009.
        [$score, $leveltime] = $session->finish();
        $this->assertEquals(-300.0, $score);
        $this->assertEqualsWithDelta(2.0, $leveltime, 0.0001);
    }

    /**
     * In order: Read it first, then the enabled penalties in severity order, round and round.
     */
    public function test_penalties_in_order(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->sassy_settings(), true);
        $wrong = $this->answer_tokens($session, $qid)['0'][0];

        $this->assertEquals(
            ['readfirst', 'decoys', 'reversed', 'dodge', 'returnfire', 'drain', 'decoys', 'reversed'],
            $this->penalty_sequence($session, $qid, $wrong, 8)
        );
    }

    /**
     * Random order picks only enabled penalties, and the boss comes at the limit.
     */
    public function test_penalties_random(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->sassy_settings([
            'sassypenalties' => 'reversed,drain',
            'sassyorder' => 1,
        ]), true);
        $wrong = $this->answer_tokens($session, $qid)['0'][0];

        $sequence = $this->penalty_sequence($session, $qid, $wrong, 20);
        $this->assertCount(20, $sequence);
        $this->assertEquals('readfirst', array_shift($sequence));
        $this->assertEquals('boss', array_pop($sequence));
        $this->assertEmpty(array_diff($sequence, ['reversed', 'drain']));
        // 18 random picks of two: both show up (all the same has a chance of 2 in 2^18).
        $this->assertCount(2, array_unique($sequence));
    }

    /**
     * Disabled penalties are never chosen; with none enabled every offence is Read it first.
     */
    public function test_disabled_penalties(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->sassy_settings(['sassypenalties' => 'drain']), true);
        $wrong = $this->answer_tokens($session, $qid)['0'][0];
        $this->assertEquals(['readfirst', 'drain', 'drain', 'drain'], $this->penalty_sequence($session, $qid, $wrong, 4));

        $session = $this->start_session($this->sassy_settings(['sassypenalties' => '']), true);
        $wrong = $this->answer_tokens($session, $qid)['0'][0];
        $this->assertEquals(['readfirst', 'readfirst', 'readfirst'], $this->penalty_sequence($session, $qid, $wrong, 3));
    }

    /**
     * Decoy ships of choice questions: shooting one is a wrong hit worth fraction 0, more come with each
     * wrong hit up to the maximum, and an earlier level's decoys are refused.
     */
    public function test_decoys_choice(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->sassy_settings([
            'sassypenalties' => 'decoys',
            'sassymaxdecoys' => 2,
        ]), true);
        $tokens = $this->answer_tokens($session, $qid);

        $session->answer($qid, 1, 'levelstart');
        $session->answer($qid, 1, 'shoot', $tokens['0'][0]);
        $session->answer($qid, 2, 'levelstart');
        $session->clock += 100;
        $effects = $session->answer($qid, 2, 'shoot', $tokens['0'][0])['effects'];
        $this->assertEquals(['decoys', []], [$effects['penalty'], $effects['decoys']]);
        $this->assertNotEmpty($effects['messages']);

        $decoys = $session->answer($qid, 3, 'levelstart')['effects']['decoys'];
        $this->assertCount(1, $decoys);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $decoys[0]['token']);
        $this->assertContains($decoys[0]['text'], $session->decoy_labels());
        $result = $session->answer($qid, 3, 'shoot', $decoys[0]['token']);
        $this->assertEquals(['deflect', -300.0, 'decoys'], [$result['result'], $result['points'], $result['effects']['penalty']]);
        $olddecoy = $decoys[0]['token'];

        // One more decoy per wrong hit, never more than the maximum.
        $decoys = $session->answer($qid, 4, 'levelstart')['effects']['decoys'];
        $this->assertCount(2, $decoys);
        $this->assertNotContains($olddecoy, array_column($decoys, 'token'));
        $this->assert_refused('invalidgameanswer', fn() => $session->answer($qid, 4, 'shoot', $olddecoy));
        $this->assertEquals('deflect', $session->answer($qid, 4, 'shoot', $decoys[1]['token'])['result']);
        $this->assertCount(2, $session->answer($qid, 5, 'levelstart')['effects']['decoys']);
    }

    /**
     * Decoy ships of match questions are answer-side ships: pairing one is a wrong pair (0 points, a wrong hit).
     */
    public function test_decoys_match(): void {
        global $DB;
        $qid = $this->question('match', 'foursubq');
        $session = $this->start_session($this->sassy_settings(['sassypenalties' => 'decoys']), true);
        $bytext = [];
        foreach ($DB->get_records('qtype_match_subquestions', ['questionid' => $qid], 'id') as $sub) {
            $bytext[trim(strip_tags($sub->questiontext))] = $sub;
        }
        $stem = fn($text) => $session->token('s', $bytext[$text]->id);
        $choice = fn($text) => $session->token('m', $bytext[$text]->id);

        // A wrong pair is a wrong hit: offence 1 (Read it first), then offence 2 (decoys).
        $session->answer($qid, 1, 'levelstart');
        $this->assertEquals('miss', $session->answer($qid, 1, 'shoot', $stem('frog'), $choice('cat'))['result']);
        $session->answer($qid, 2, 'levelstart');
        $session->clock += 100;
        $result = $session->answer($qid, 2, 'shoot', $stem('frog'), $choice('cat'));
        $this->assertEquals(['miss', 0.0, 'decoys'], [$result['result'], $result['points'], $result['effects']['penalty']]);

        $decoys = $session->answer($qid, 3, 'levelstart')['effects']['decoys'];
        $this->assertCount(1, $decoys);
        $before = $session->answer($qid, 3, 'levelstart')['score'];
        $result = $session->answer($qid, 3, 'shoot', $stem('frog'), $decoys[0]['token']);
        $this->assertEquals(
            ['miss', 0.0, $before, 'decoys'],
            [$result['result'], $result['points'], $result['score'], $result['effects']['penalty']]
        );
        // The decoy may come first; it still pairs with the real stem, and is still wrong.
        $result = $session->answer($qid, 3, 'shoot', $decoys[0]['token'], $stem('cat'));
        $this->assertEquals(['miss', 'decoys'], [$result['result'], $result['effects']['penalty']]);
        // Two decoys never make a pair.
        $decoy = $decoys[0]['token'];
        $this->assert_refused('invalidgameanswer', fn() => $session->answer($qid, 3, 'shoot', $decoy, $decoy));
        $this->assertEquals('hit', $session->answer($qid, 3, 'shoot', $stem('frog'), $choice('newt'))['result']);
    }

    /**
     * The score drain is computed from timestamps, speeds up with each wrong hit, and never takes the score below 0.
     */
    public function test_drain(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->sassy_settings([
            'sassypenalties' => 'drain',
            'sassydrainrate' => 100,
            'sassydrainincrease' => 50,
        ]), true);
        $tokens = $this->answer_tokens($session, $qid);
        $wrong = $tokens['0'][0];
        $right = $tokens['1'][0];

        $session->answer($qid, 1, 'levelstart');
        $session->answer($qid, 1, 'shoot', $right);
        $session->answer($qid, 2, 'levelstart');
        $this->assertEquals(700.0, $session->answer($qid, 2, 'shoot', $wrong)['score']);
        // Complete level 2, so that starting level 3 does not charge its missed correct answer.
        $this->assertEquals(1700.0, $session->answer($qid, 2, 'shoot', $right)['score']);
        $session->answer($qid, 3, 'levelstart');
        $session->clock = 1005.0;
        $result = $session->answer($qid, 3, 'shoot', $wrong);
        $effects = $result['effects'];
        $this->assertEquals(['drain', 100.0, 1400.0], [$effects['penalty'], $effects['drainrate'], $result['score']]);

        // 2 s at 100 points/s, then the hit.
        $session->clock = 1007.0;
        $this->assertEquals(2200.0, $session->answer($qid, 3, 'shoot', $right)['score']);
        $session->clock = 1008.0;
        $this->assertEquals(2100.0, $session->answer($qid, 4, 'levelstart')['score']);
        // A wrong hit settles the drain, then raises the rate by 50 %.
        $result = $session->answer($qid, 4, 'shoot', $wrong);
        $this->assertEquals([1800.0, 150.0], [$result['score'], $result['effects']['drainrate']]);

        // 12 s at 150 points/s would be 1800: the score stops at 0.
        $session->clock = 1020.0;
        $result = $session->answer($qid, 4, 'miss');
        $this->assertEquals(['miss', 0.0], [$result['result'], $result['score']]);
        // A score at or below 0 does not drain further.
        $this->assertEquals(-300.0, $session->answer($qid, 4, 'shoot', $wrong)['score']);
        $session->clock = 1030.0;
        $this->assertEquals(-300.0, $session->answer($qid, 4, 'miss')['score']);
    }

    /**
     * The drain is settled when the game finishes.
     */
    public function test_drain_settled_on_finish(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->sassy_settings(['sassypenalties' => 'drain', 'sassydrainrate' => 10]), true);
        $tokens = $this->answer_tokens($session, $qid);

        $session->answer($qid, 1, 'levelstart');
        $session->answer($qid, 1, 'shoot', $tokens['1'][0]);
        $session->answer($qid, 2, 'levelstart');
        $session->answer($qid, 2, 'shoot', $tokens['0'][0]);
        // Complete level 2, so that starting level 3 does not charge its missed correct answer.
        $session->answer($qid, 2, 'shoot', $tokens['1'][0]);
        $session->answer($qid, 3, 'levelstart');
        $session->clock = 1010.0;
        $this->assertEquals(10.0, $session->answer($qid, 3, 'shoot', $tokens['0'][0])['effects']['drainrate']);
        $session->clock = 1030.0;
        // 1400 - 20 s x 10 points/s; the game's end charges nothing for the unfinished level 3.
        $this->assertEquals(1200.0, $session->finish()[0]);
    }

    /**
     * In a dodge round every shot is dodged: no points, no wrong hit, and the level cannot be completed.
     */
    public function test_dodge_round(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->sassy_settings(['sassypenalties' => 'dodge', 'misspenalty' => 50]), true);
        $tokens = $this->answer_tokens($session, $qid);

        $session->answer($qid, 1, 'levelstart');
        $session->answer($qid, 1, 'shoot', $tokens['0'][0]);
        $session->answer($qid, 2, 'levelstart');
        $session->clock += 100;
        // The dodge round is level-scoped: it waits for the next level.
        $this->assertEquals('', $session->answer($qid, 2, 'shoot', $tokens['0'][0])['effects']['penalty']);

        $result = $session->answer($qid, 3, 'levelstart');
        $this->assertEquals(['dodge', true], [$result['effects']['penalty'], $result['effects']['dodge']]);
        $this->assertNotEmpty($result['effects']['messages']);
        $score = $result['score'];
        foreach ([['shoot', $tokens['1'][0]], ['miss', ''], ['shoot', $tokens['0'][0]]] as [$action, $token]) {
            $result = $session->answer($qid, 3, $action, $token);
            $this->assertEquals(
                ['dodge', 0.0, $score, false, ''],
                [$result['result'], $result['points'], $result['score'], $result['levelcomplete'], $result['effects']['penalty']]
            );
        }
        // The answer that got away is charged as usual.
        $this->assertEquals($score - 1000, $session->answer($qid, 3, 'levelend')['score']);
        // The dodged wrong shot was no offence: nothing waits for the next level, which is a normal one.
        $effects = $session->answer($qid, 4, 'levelstart')['effects'];
        $this->assertEquals(['', false], [$effects['penalty'], $effects['dodge']]);
    }

    /**
     * Reversed controls apply to the next level only, with their message.
     */
    public function test_reversed(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->sassy_settings(['sassypenalties' => 'reversed']), true);
        $tokens = $this->answer_tokens($session, $qid);

        $this->assertEquals(['readfirst', 'reversed'], $this->penalty_sequence($session, $qid, $tokens['0'][0], 2));
        // The sequence ended on the levelstart of the reversed level (3).
        $effects = $session->answer($qid, 3, 'shoot', $tokens['1'][0])['effects'];
        $this->assertTrue($effects['reversed']);
        $effects = $session->answer($qid, 4, 'levelstart')['effects'];
        $this->assertEquals(['', false], [$effects['penalty'], $effects['reversed']]);
    }

    /**
     * The big boss: score 0, the boss lines, and every later action is done with score 0, including finish().
     */
    public function test_boss_big(): void {
        [$session, $qid, $tokens, $boss] = $this->play_to_boss(['sassybosslines' => "One || Two\n\nThree"]);

        $this->assertEquals(['deflect', 0.0], [$boss['result'], $boss['score']]);
        $effects = $boss['effects'];
        $this->assertEquals(
            ['boss', 'big', 0, ['One', 'Two', 'Three']],
            [$effects['penalty'], $effects['boss'], $effects['swarmsize'], $effects['messages']]
        );

        $actions = [
            [3, 'shoot', $tokens['1'][0]],
            [3, 'miss', ''],
            [3, 'levelend', ''],
            [4, 'levelstart', ''],
            [4, 'shoot', $tokens['1'][0]],
        ];
        foreach ($actions as [$level, $action, $token]) {
            $result = $session->answer($qid, $level, $action, $token);
            $this->assertEquals(
                ['done', 0.0, 0.0, 'big', []],
                [$result['result'], $result['points'], $result['score'], $result['effects']['boss'], $result['effects']['messages']]
            );
        }
        $this->assertEquals(0.0, $session->finish()[0]);
    }

    /**
     * The swarm boss: same game over, with the swarm size; the default boss lines are used when none are set.
     */
    public function test_boss_swarm(): void {
        [$session, $qid, $tokens, $boss] = $this->play_to_boss(['sassybossstyle' => 1, 'sassyswarmsize' => 15]);

        $effects = $boss['effects'];
        $this->assertEquals(
            [0.0, 'boss', 'swarm', 15],
            [$boss['score'], $effects['penalty'], $effects['boss'], $effects['swarmsize']]
        );
        // Two default lines, the second a two-step prompt.
        $this->assertCount(3, $boss['effects']['messages']);
        $this->assertStringStartsWith('This game is becoming', $boss['effects']['messages'][0]);
        $this->assertEquals('Are you ready?', $boss['effects']['messages'][1]);
        $this->assertEquals('done', $session->answer($qid, 4, 'shoot', $tokens['1'][0])['result']);
        $this->assertEquals(0.0, $session->finish()[0]);
    }

    /**
     * The random boss style is one of the two.
     */
    public function test_boss_random_style(): void {
        [, , , $boss] = $this->play_to_boss(['sassybossstyle' => 2]);
        $this->assertContains($boss['effects']['boss'], ['big', 'swarm']);
        $this->assertEquals($boss['effects']['boss'] == 'swarm' ? 20 : 0, $boss['effects']['swarmsize']);
    }

    /**
     * Starting a new game clears all cheater mode state.
     */
    public function test_new_game_resets_cheater_mode(): void {
        [$session, $qid, $tokens] = $this->play_to_boss();
        $session->start();
        $result = $session->answer($qid, 1, 'shoot', $tokens['1'][0]);
        $this->assertEquals(['hit', 1000.0], [$result['result'], $result['score']]);
        $this->assertSame($this->no_effects(), $result['effects']);
    }
}
