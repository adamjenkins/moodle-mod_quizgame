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
        $this->course = $this->getDataGenerator()->create_course(['summary' => '']);
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
     * Rewards are off unless the settings turn them on (a new instance has them on), so the scoring and
     * Sassy tests see unmultiplied points.
     *
     * @param array $settings activity settings (scoring, Sassy and rewards columns) to override the defaults
     * @param bool $clocked whether the session reads time from its public $clock property instead of the real clock
     * @return game_session
     */
    protected function start_session(array $settings = [], bool $clocked = false): game_session {
        $quizgame = $this->getDataGenerator()->create_module('quizgame', $settings + [
            'course' => $this->course->id,
            'questioncategory' => (string) $this->category->id,
            'rewards' => 0,
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
            'endinglines' => [],
            'multiplier' => 1,
            'combo' => '',
            'lives' => 0,
            'lifegained' => false,
            'bonusships' => [],
            'praise' => '',
        ];
    }

    /**
     * Rewards settings: rewards on, with each reward off unless the settings turn it on.
     *
     * @param array $settings further settings
     * @return array
     */
    protected function reward_settings(array $settings = []): array {
        return $settings + [
            'rewards' => 1,
            'rewardcombo' => 0,
            'rewardlife' => 0,
            'rewardbonus' => 0,
            'rewardpraise' => 0,
        ];
    }

    /**
     * The rewards part of a response's effects.
     *
     * @param array $result a response of game_session::answer()
     * @return array multiplier, combo, lives, lifegained, bonusships and praise
     */
    protected function rewards_of(array $result): array {
        $effects = $result['effects'];
        return [
            'multiplier' => $effects['multiplier'],
            'combo' => $effects['combo'],
            'lives' => $effects['lives'],
            'lifegained' => $effects['lifegained'],
            'bonusships' => $effects['bonusships'],
            'praise' => $effects['praise'],
        ];
    }

    /**
     * Values of a response (or its effects), the response's own keys first, then its effects' keys.
     *
     * @param array $response a response of game_session::answer(), or its effects
     * @param string ...$keys
     * @return array the values in key order
     */
    protected function pick(array $response, string ...$keys): array {
        $values = [];
        foreach ($keys as $key) {
            $values[] = array_key_exists($key, $response) ? $response[$key] : $response['effects'][$key];
        }
        return $values;
    }

    /**
     * The built-in praise lines.
     *
     * @return string[]
     */
    protected function default_praise(): array {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', get_string('rewards_praise_default', 'quizgame')))));
    }

    /**
     * Change the clean streak (and the combo count that follows it) of the current game directly (no game
     * flow can start a dodge round with a streak).
     *
     * @param game_session $session
     * @param int $streak
     */
    protected function set_streak(game_session $session, int $streak): void {
        global $SESSION;
        foreach ($SESSION->mod_quizgame as $id => $data) {
            if (!empty($data['attempt'])) {
                $SESSION->mod_quizgame[$id]['attempt']['rewardstreak'] = $streak;
                $SESSION->mod_quizgame[$id]['attempt']['combostreak'] = $streak;
            }
        }
    }

    /**
     * Give the current game a combo count without touching the clean streak (extra lives, bonus rounds).
     *
     * The combo starts with the second clean question; tests of its scoring start from one clean question so
     * the first hit they play already raises it.
     *
     * @param game_session $session
     * @param int $count
     */
    protected function seed_combo(game_session $session, int $count): void {
        global $SESSION;
        foreach ($SESSION->mod_quizgame as $id => $data) {
            if (!empty($data['attempt'])) {
                $SESSION->mod_quizgame[$id]['attempt']['combostreak'] = $count;
            }
        }
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
        $session = $this->start_session($this->sassy_settings($settings + ['sassybosslimit' => 2, 'sassybosspool' => 'big']), true);
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
        unset($quizgame->sassytaunts, $quizgame->sassydecoylabels, $quizgame->sassybosslines, $quizgame->rewardpraiselines);
        unset($quizgame->sassytowlines, $quizgame->sassydestructlines, $quizgame->sassyblackholelines);
        unset($quizgame->sassypumpkinlines);
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
        // Five levels of 3 x -300 + 1000; the clock never moved; no bonus points.
        $this->assertEquals([500.0, 0.0, 0.0], $session->finish());
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
            'Take your time. No, really, I\'ll wait!',
            'Read the question, then shoot only the ship with the correct answer. Ships appear in a moment.',
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
            ['boss', 'big', 0, ['One', 'Two', 'Three'], []],
            [$effects['penalty'], $effects['boss'], $effects['swarmsize'], $effects['messages'], $effects['endinglines']]
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
        [$session, $qid, $tokens, $boss] = $this->play_to_boss(['sassybosspool' => 'swarm', 'sassyswarmsize' => 15]);

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
     * The boss is one of the enabled bosses, all six by default.
     */
    public function test_boss_random_style(): void {
        [, , , $boss] = $this->play_to_boss(['sassybosspool' => 'big,swarm,tow,selfdestruct,blackhole,pumpkin']);
        $this->assertContains($boss['effects']['boss'], ['big', 'swarm', 'tow', 'selfdestruct', 'blackhole', 'pumpkin']);
        $this->assertEquals($boss['effects']['boss'] == 'swarm' ? 20 : 0, $boss['effects']['swarmsize']);
    }

    /**
     * With no boss enabled there is no boss finale: the offence at the limit is an ordinary penalty.
     */
    public function test_no_boss_enabled(): void {
        [$session, $qid, $tokens, $last] = $this->play_to_boss(['sassybosspool' => '']);

        $effects = $last['effects'];
        $this->assertEquals(['', 0, []], [$effects['boss'], $effects['swarmsize'], $effects['endinglines']]);
        $this->assertNotEquals('boss', $effects['penalty']);
        $this->assertNotEquals(0.0, $last['score']);
        // The game goes on.
        $this->assertEquals('started', $session->answer($qid, 4, 'levelstart')['result']);
        $this->assertEquals('hit', $session->answer($qid, 4, 'shoot', $tokens['1'][0])['result']);
    }

    /**
     * Each scripted boss: the same game over with score 0, the boss lines first, and its own default text.
     *
     * @param string $ending the boss key, enabled alone
     * @param string[] $lines its default text
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scripted_ending_provider')]
    public function test_boss_scripted_ending(string $ending, array $lines): void {
        [$session, $qid, $tokens, $boss] = $this->play_to_boss(['sassybosspool' => $ending, 'sassybosslines' => 'Bye']);

        $effects = $boss['effects'];
        $this->assertEquals(
            [0.0, 'boss', $ending, 0, ['Bye'], $lines],
            [$boss['score'], $effects['penalty'], $effects['boss'], $effects['swarmsize'], $effects['messages'],
                $effects['endinglines']]
        );
        $result = $session->answer($qid, 4, 'shoot', $tokens['1'][0]);
        $this->assertEquals(['done', 0.0, $ending], [$result['result'], $result['score'], $result['effects']['boss']]);
        $this->assertEquals(0.0, $session->finish()[0]);
    }

    /**
     * The four scripted bosses with their default text.
     *
     * @return array
     */
    public static function scripted_ending_provider(): array {
        return [
            'tow truck' => ['tow', ['Illegally parked in the guessing zone.']],
            'self-destruct' => ['selfdestruct', [
                'Guessing detected. Self-destruct sequence initiated.',
                'Well, that was anticlimactic. Like your effort.',
            ]],
            'black hole' => ['blackhole', ['Your points have crossed the event horizon.']],
            'jack-o-lantern' => ['pumpkin', ['Mwa-ha-ha-ha-ha!', 'Trick or treat? Definitely trick.']],
        ];
    }

    /**
     * A boss's text setting replaces its default, formatted like the other messages.
     */
    public function test_boss_ending_text(): void {
        [, , , $boss] = $this->play_to_boss([
            'sassybosspool' => 'tow',
            'sassytowlines' => "No <b>parking</b>\n\nTowed!",
        ]);
        $this->assertEquals(['No parking', 'Towed!'], $boss['effects']['endinglines']);
    }

    /**
     * The boss is picked at random from the enabled bosses only.
     */
    public function test_choose_ending(): void {
        $choose = function (array $settings): string {
            $method = new \ReflectionMethod(game_session::class, 'choose_ending');
            return $method->invoke(new game_session((object) $settings));
        };
        $seen = [];
        for ($i = 0; $i < 40; $i++) {
            $seen[$choose(['sassybosspool' => 'tow,blackhole'])] = true;
        }
        $this->assertEqualsCanonicalizing(['tow', 'blackhole'], array_keys($seen));
        $this->assertEquals('pumpkin', $choose(['sassybosspool' => 'pumpkin,nonsense']));
        // A record without the column has every boss enabled.
        $this->assertContains($choose([]), game_session::ENDINGS);
    }

    /**
     * The ending pool keeps only known endings, in their usual order.
     */
    public function test_ending_pool(): void {
        $this->assertEquals(['big', 'tow', 'blackhole'], game_session::ending_pool('blackhole, tow,bogus,big'));
        $this->assertEquals([], game_session::ending_pool(''));
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

    /**
     * A new instance has every reward on with the documented defaults, and its cap allows the maximum multiplier.
     */
    public function test_new_instance_reward_defaults(): void {
        global $DB;
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $this->course->id,
            'questioncategory' => (string) $this->category->id,
        ]);
        $record = $DB->get_record('quizgame', ['id' => $quizgame->id], '*', MUST_EXIST);
        $expected = [
            'rewards' => 1,
            'rewardcombo' => 1,
            'rewardmaxmult' => 4,
            'rewardlife' => 1,
            'rewardlifestreak' => 5,
            'rewardmaxlives' => 5,
            'rewardbonus' => 1,
            'rewardbonusstreak' => 5,
            'rewardbonuspoints' => 1000,
            'rewardpraise' => 1,
        ];
        foreach ($expected as $column => $value) {
            $this->assertEquals($value, $record->$column, $column);
        }
        $this->assertEquals(4000, (new game_session($record))->points_per_second());
    }

    /**
     * The per-second cap is the points for a correct hit, times the maximum multiplier only while combos are on.
     */
    public function test_points_per_second(): void {
        $this->question('truefalse', 'true');
        $this->assertEquals(2000, $this->start_session(['pointscorrect' => 2000])->points_per_second());
        $session = $this->start_session($this->reward_settings(['pointscorrect' => 2000, 'rewardmaxmult' => 3]));
        $this->assertEquals(2000, $session->points_per_second());
        $session = $this->start_session($this->reward_settings([
            'pointscorrect' => 2000,
            'rewardcombo' => 1,
            'rewardmaxmult' => 3,
        ]));
        $this->assertEquals(6000, $session->points_per_second());
        // Rewards off: the combo toggle alone does not raise the cap.
        $session = $this->start_session(['pointscorrect' => 2000, 'rewardcombo' => 1, 'rewardmaxmult' => 3]);
        $this->assertEquals(2000, $session->points_per_second());
    }

    /**
     * The clean streak rises on clean questions and raises the multiplier up to the maximum; a miss and an
     * unfinished level leave it alone; a wrong hit (deflect) breaks it. Only hits are multiplied: miss and
     * wrong-hit penalties and the points of an answer let go are not.
     */
    public function test_combo_streak_and_multiplier(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->reward_settings(['rewardcombo' => 1, 'rewardmaxmult' => 3, 'misspenalty' => 50]));
        $tokens = $this->answer_tokens($session, $qid);
        $right = fn($level) => $session->answer($qid, $level, 'shoot', $tokens['1'][0]);

        // The very first clean question earns no combo.
        $this->assertEquals([1000.0, 1, ''], $this->pick($right(1), 'points', 'multiplier', 'combo'));
        $session->start();
        $tokens = $this->answer_tokens($session, $qid);
        $this->seed_combo($session, 1);

        $result = $session->answer($qid, 1, 'levelstart');
        $this->assertEquals([1, ''], $this->pick($result, 'multiplier', 'combo'));
        // Each clean question raises the multiplier for the next hits, up to the maximum of 3.
        $expected = [[1000.0, 1000.0, 2, 'up'], [2000.0, 3000.0, 3, 'up'], [3000.0, 6000.0, 3, '']];
        foreach ($expected as $i => $values) {
            $level = $i + 1;
            $session->answer($qid, $level, 'levelstart');
            $result = $right($level);
            $this->assertEquals(
                $values,
                $this->pick($result, 'points', 'score', 'multiplier', 'combo'),
                'level ' . $level
            );
        }

        // A miss costs the plain miss penalty and keeps the streak.
        $session->answer($qid, 4, 'levelstart');
        $result = $session->answer($qid, 4, 'miss');
        $this->assertEquals(
            ['miss', -50.0, 5950.0, 3, ''],
            $this->pick($result, 'result', 'points', 'score', 'multiplier', 'combo')
        );
        // A wrong hit costs the plain penalty and breaks the combo.
        $result = $session->answer($qid, 4, 'shoot', $tokens['0'][0]);
        $this->assertEquals(
            ['deflect', -300.0, 5650.0, 1, 'break'],
            $this->pick($result, 'result', 'points', 'score', 'multiplier', 'combo')
        );
        // The answer after a wrong hit scores x1 and the level is not clean: the streak stays at 0.
        $result = $right(4);
        $this->assertEquals([1000.0, 6650.0, 1, ''], $this->pick($result, 'points', 'score', 'multiplier', 'combo'));

        // After the break the combo starts again from the second clean question.
        $session->answer($qid, 5, 'levelstart');
        $result = $right(5);
        $this->assertEquals([1000.0, 7650.0, 1, ''], $this->pick($result, 'points', 'score', 'multiplier', 'combo'));
        $session->answer($qid, 6, 'levelstart');
        $result = $right(6);
        $this->assertEquals([1000.0, 8650.0, 2, 'up'], $this->pick($result, 'points', 'score', 'multiplier', 'combo'));
        // Level 7 is let go: opening level 8 charges its answer unmultiplied (1000, not 2000) and keeps the streak.
        $session->answer($qid, 7, 'levelstart');
        $result = $session->answer($qid, 8, 'levelstart');
        $this->assertEquals([7650.0, 2, ''], $this->pick($result, 'score', 'multiplier', 'combo'));
        $this->assertEquals(2000.0, $right(8)['points']);
    }

    /**
     * Multi-answer questions: each correct half is multiplied, and the level is clean once both are shot.
     */
    public function test_combo_multi_answer(): void {
        $multi = $this->question('multichoice', 'two_of_four');
        $session = $this->start_session($this->reward_settings(['rewardcombo' => 1]));
        $tokens = $this->answer_tokens($session, $multi);
        $this->seed_combo($session, 1);

        $this->assertEquals(500.0, $session->answer($multi, 1, 'shoot', $tokens['0.5'][0])['points']);
        $this->assertEquals(1, $session->multiplier());
        $result = $session->answer($multi, 1, 'shoot', $tokens['0.5'][1]);
        $this->assertEquals([500.0, 2, 'up'], $this->pick($result, 'points', 'multiplier', 'combo'));
        $this->assertEquals(1000.0, $session->answer($multi, 2, 'shoot', $tokens['0.5'][0])['points']);
        // The half let go is charged unmultiplied: 1000 + 1000 - 500.
        $this->assertEquals(1500.0, $session->answer($multi, 2, 'levelend')['score']);
    }

    /**
     * Match: right pairs are multiplied, and a wrong pair is a wrong hit that breaks the combo.
     */
    public function test_combo_match(): void {
        global $DB;
        $qid = $this->question('match', 'foursubq');
        $session = $this->start_session($this->reward_settings(['rewardcombo' => 1]));
        $bytext = [];
        foreach ($DB->get_records('qtype_match_subquestions', ['questionid' => $qid], 'id') as $sub) {
            $bytext[trim(strip_tags($sub->questiontext))] = $sub;
        }
        $pair = fn($level, $stem, $answer) => $session->answer(
            $qid,
            $level,
            'shoot',
            $session->token('s', $bytext[$stem]->id),
            $session->token('m', $bytext[$answer]->id)
        );
        $this->seed_combo($session, 1);

        foreach (['frog', 'cat', 'newt'] as $stem) {
            $result = $pair(1, $stem, $stem);
            $this->assertEqualsWithDelta(1000 / 3, $result['points'], 0.001);
        }
        $this->assertEquals([true, 2, 'up'], $this->pick($result, 'levelcomplete', 'multiplier', 'combo'));

        $this->assertEqualsWithDelta(2000 / 3, $pair(2, 'frog', 'frog')['points'], 0.001);
        // The cat with an amphibian (the newt's answer ship): a wrong pair, no points, the combo breaks.
        $result = $pair(2, 'cat', 'newt');
        $this->assertEquals(['miss', 0.0, 1, 'break'], $this->pick($result, 'result', 'points', 'multiplier', 'combo'));
        $this->assertEqualsWithDelta(1000 / 3, $pair(2, 'cat', 'cat')['points'], 0.001);
    }

    /**
     * In cheater mode a clean question builds the streak (decoys still come); shooting a decoy is a wrong hit
     * that breaks it and costs the plain penalty.
     */
    public function test_streak_in_cheater_mode_and_decoy(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->reward_settings($this->sassy_settings([
            'sassypenalties' => 'decoys',
            'sassycleanneeded' => 5,
            'rewardcombo' => 1,
        ])), true);
        $tokens = $this->answer_tokens($session, $qid);

        // Offence 1 (Read it first, at level 2), then offence 2 (decoys) in level 2.
        $session->answer($qid, 1, 'levelstart');
        $session->answer($qid, 1, 'shoot', $tokens['0'][0]);
        $session->answer($qid, 2, 'levelstart');
        $session->clock += 100;
        $this->assertEquals('decoys', $session->answer($qid, 2, 'shoot', $tokens['0'][0])['effects']['penalty']);
        $session->answer($qid, 2, 'shoot', $tokens['1'][0]);

        // Level 3 is clean while a cheater: the streak grows (from one earlier clean question, so the combo rises).
        $this->assertCount(1, $session->answer($qid, 3, 'levelstart')['effects']['decoys']);
        $this->seed_combo($session, 1);
        $result = $session->answer($qid, 3, 'shoot', $tokens['1'][0]);
        $effects = $result['effects'];
        $this->assertEquals([1000.0, true, 2, 'up'], $this->pick($result, 'points', 'cheater', 'multiplier', 'combo'));

        $decoys = $session->answer($qid, 4, 'levelstart')['effects']['decoys'];
        $result = $session->answer($qid, 4, 'shoot', $decoys[0]['token']);
        $this->assertEquals(['deflect', -300.0, 1, 'break'], $this->pick($result, 'result', 'points', 'multiplier', 'combo'));
    }

    /**
     * Leaving cheater mode with a clean question builds the streak and gives the redemption praise, which
     * outranks the combo praise of the same response.
     */
    public function test_redemption_praise(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->reward_settings($this->sassy_settings([
            'sassypenalties' => 'returnfire',
            'sassycleanneeded' => 1,
            'rewardcombo' => 1,
            'rewardpraise' => 1,
        ])), true);
        $tokens = $this->answer_tokens($session, $qid);

        $session->answer($qid, 1, 'levelstart');
        $this->assertTrue($session->answer($qid, 1, 'shoot', $tokens['0'][0])['effects']['cheater']);
        $this->assertEquals('readfirst', $session->answer($qid, 2, 'levelstart')['effects']['penalty']);
        $session->clock += 100;
        // As if an earlier question had been clean, so this one raises the combo too.
        $this->seed_combo($session, 1);
        $effects = $session->answer($qid, 2, 'shoot', $tokens['1'][0])['effects'];
        $this->assertEquals(
            [false, 2, 'up', get_string('rewards_redemption_msg', 'quizgame')],
            $this->pick($effects, 'cheater', 'multiplier', 'combo', 'praise')
        );
        // The next combo rise gets a general praise line.
        $session->answer($qid, 3, 'levelstart');
        $praise = $session->answer($qid, 3, 'shoot', $tokens['1'][0])['effects']['praise'];
        $this->assertContains($praise, $this->default_praise());
    }

    /**
     * Shots and misses dodged in a dodge round are no wrong hits: the streak survives them.
     */
    public function test_dodge_rejection_keeps_streak(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->reward_settings($this->sassy_settings([
            'sassypenalties' => 'dodge',
            'sassycleanneeded' => 5,
            'rewardcombo' => 1,
        ])), true);
        $tokens = $this->answer_tokens($session, $qid);

        // Offence 1 (Read it first), then offence 2 (dodge, at level 3).
        $session->answer($qid, 1, 'levelstart');
        $session->answer($qid, 1, 'shoot', $tokens['0'][0]);
        $session->answer($qid, 2, 'levelstart');
        $session->clock += 100;
        $session->answer($qid, 2, 'shoot', $tokens['0'][0]);
        $this->assertTrue($session->answer($qid, 3, 'levelstart')['effects']['dodge']);
        // The offence reset the streak; give it one as if earlier levels were clean.
        $this->set_streak($session, 2);
        foreach ([['shoot', $tokens['0'][0]], ['miss', ''], ['shoot', $tokens['1'][0]]] as [$action, $token]) {
            $result = $session->answer($qid, 3, $action, $token);
            $this->assertEquals(['dodge', 2, ''], $this->pick($result, 'result', 'multiplier', 'combo'));
        }
    }

    /**
     * An extra life every N clean questions, never above the maximum; each lost life reported by the browser
     * counts down (not below 0, and never opening a level). A new game starts with 3 lives and no streak.
     */
    public function test_extra_lives(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->reward_settings([
            'rewardlife' => 1,
            'rewardlifestreak' => 2,
            'rewardmaxlives' => 4,
            'rewardpraise' => 1,
            'rewardpraiselines' => "Nice one\n\n",
        ]));
        $tokens = $this->answer_tokens($session, $qid);
        $clean = function (int $level) use ($session, $qid, $tokens): array {
            $session->answer($qid, $level, 'levelstart');
            return $session->answer($qid, $level, 'shoot', $tokens['1'][0]);
        };

        $this->assertEquals(3, $session->answer($qid, 1, 'levelstart')['effects']['lives']);
        $result = $clean(1);
        $this->assertEquals([1000.0, 3, false, ''], $this->pick($result, 'points', 'lives', 'lifegained', 'praise'));
        $effects = $clean(2)['effects'];
        // Combos are off: the multiplier stays 1 and no combo praise comes.
        $this->assertEquals(
            [4, true, 'Nice one', 1, ''],
            $this->pick($effects, 'lives', 'lifegained', 'praise', 'multiplier', 'combo')
        );
        $this->assertFalse($clean(3)['effects']['lifegained']);
        // At the maximum no life is awarded.
        $effects = $clean(4)['effects'];
        $this->assertEquals([4, false, ''], $this->pick($effects, 'lives', 'lifegained', 'praise'));

        // A lost life counts down, scores nothing and does not open a level (level 99 here).
        $result = $session->answer($qid, 99, 'lifelost');
        $this->assertEquals(['done', 0.0, 4000.0, 3], $this->pick($result, 'result', 'points', 'score', 'lives'));
        $this->assertFalse($clean(5)['effects']['lifegained']);
        $effects = $clean(6)['effects'];
        $this->assertEquals([4, true], $this->pick($effects, 'lives', 'lifegained'));
        for ($i = 0; $i < 6; $i++) {
            $lives = $session->answer($qid, 6, 'lifelost')['effects']['lives'];
        }
        $this->assertEquals(0, $lives);

        // A new game starts afresh.
        $session->start();
        $result = $session->answer($qid, 1, 'levelstart');
        $this->assertEquals([3, 1], $this->pick($result, 'lives', 'multiplier'));
        $this->assertFalse($session->answer($qid, 1, 'shoot', $tokens['1'][0])['effects']['lifegained']);
    }

    /**
     * The bonus round: after every N clean questions the next levelstart issues two bonus ships, each paying
     * the bonus points once and unmultiplied. A bonus shot is no wrong hit and does not complete the level;
     * unshot bonus ships cost nothing and their tokens die at the next levelstart.
     */
    public function test_bonus_round(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->reward_settings([
            'rewardcombo' => 1,
            'rewardbonus' => 1,
            'rewardbonusstreak' => 2,
            'rewardbonuspoints' => 750,
            'rewardpraise' => 1,
        ]));
        $tokens = $this->answer_tokens($session, $qid);
        $this->seed_combo($session, 1);

        $this->assertEquals([], $session->answer($qid, 1, 'levelstart')['effects']['bonusships']);
        $session->answer($qid, 1, 'shoot', $tokens['1'][0]);
        $this->assertEquals([], $session->answer($qid, 2, 'levelstart')['effects']['bonusships']);
        $result = $session->answer($qid, 2, 'shoot', $tokens['1'][0]);
        // The streak of 2 earns the bonus round, which waits for the next level.
        $this->assertEquals([3000.0, []], $this->pick($result, 'score', 'bonusships'));

        $result = $session->answer($qid, 3, 'levelstart');
        $ships = $result['effects']['bonusships'];
        $this->assertCount(2, $ships);
        $this->assertEquals(['+750', '+750'], array_column($ships, 'text'));
        foreach ($ships as $ship) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $ship['token']);
        }
        $this->assertNotEquals($ships[0]['token'], $ships[1]['token']);
        $this->assertEquals(get_string('rewards_bonus_msg', 'quizgame'), $result['effects']['praise']);

        // Paid once, unmultiplied (the multiplier is 3), and the streak and level are untouched.
        $result = $session->answer($qid, 3, 'shoot', $ships[0]['token']);
        $this->assertEquals(
            ['bonus', 750.0, 3750.0, false, 3, ''],
            $this->pick($result, 'result', 'points', 'score', 'levelcomplete', 'multiplier', 'combo')
        );
        $this->assertEquals([], $result['effects']['bonusships']);
        $used = $ships[0]['token'];
        $this->assert_refused('invalidgameanswer', fn() => $session->answer($qid, 3, 'shoot', $used));
        // The question's hit after the bonus is still clean and multiplied.
        $result = $session->answer($qid, 3, 'shoot', $tokens['1'][0]);
        $this->assertEquals([3000.0, 6750.0, 4, 'up'], $this->pick($result, 'points', 'score', 'multiplier', 'combo'));

        // The second bonus ship left unshot: nothing is charged, and its token dies with the level.
        $result = $session->answer($qid, 4, 'levelstart');
        $this->assertEquals([6750.0, []], $this->pick($result, 'score', 'bonusships'));
        $unshot = $ships[1]['token'];
        $this->assert_refused('invalidgameanswer', fn() => $session->answer($qid, 4, 'shoot', $unshot));
        $session->answer($qid, 4, 'shoot', $tokens['1'][0]);
        // Streak 4: the next bonus round.
        $this->assertCount(2, $session->answer($qid, 5, 'levelstart')['effects']['bonusships']);

        [$score, , $bonuspoints] = $session->finish();
        $this->assertEquals([10750.0, 750.0], [$score, $bonuspoints]);
    }

    /**
     * Bonus ships come for match questions too: shot alone (no second token), they pay and are no wrong pair.
     */
    public function test_bonus_round_match(): void {
        global $DB;
        $qid = $this->question('match', 'foursubq');
        $session = $this->start_session($this->reward_settings(['rewardbonus' => 1, 'rewardbonusstreak' => 1]));
        foreach ($DB->get_records('qtype_match_subquestions', ['questionid' => $qid], 'id') as $sub) {
            if ((string) $sub->questiontext !== '') {
                $session->answer($qid, 1, 'shoot', $session->token('s', $sub->id), $session->token('m', $sub->id));
            }
        }
        $ships = $session->answer($qid, 2, 'levelstart')['effects']['bonusships'];
        $this->assertCount(2, $ships);
        $result = $session->answer($qid, 2, 'shoot', $ships[1]['token'], '');
        $this->assertEquals(['bonus', 1000.0, false], $this->pick($result, 'result', 'points', 'levelcomplete'));
        $this->assertEqualsWithDelta(2000.0, $result['score'], 0.001);
    }

    /**
     * After the boss nothing pays, not even a bonus ship of the level, and the game's bonus points are void.
     */
    public function test_bonus_after_boss(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->reward_settings($this->sassy_settings([
            'sassybosslimit' => 2,
            'rewardbonus' => 1,
            'rewardbonusstreak' => 1,
        ])), true);
        $tokens = $this->answer_tokens($session, $qid);

        $session->answer($qid, 1, 'levelstart');
        $session->answer($qid, 1, 'shoot', $tokens['1'][0]);
        $ships = $session->answer($qid, 2, 'levelstart')['effects']['bonusships'];
        $this->assertEquals('bonus', $session->answer($qid, 2, 'shoot', $ships[0]['token'])['result']);
        // Two offences: the boss.
        $session->answer($qid, 2, 'shoot', $tokens['0'][0]);
        $this->assertEquals('boss', $session->answer($qid, 2, 'shoot', $tokens['0'][0])['effects']['penalty']);

        $result = $session->answer($qid, 2, 'shoot', $ships[1]['token']);
        $this->assertEquals(['done', 0.0, 0.0], $this->pick($result, 'result', 'points', 'score'));
        $this->assertEquals([], $session->answer($qid, 3, 'levelstart')['effects']['bonusships']);
        [$score, , $bonuspoints] = $session->finish();
        $this->assertEquals([0.0, 0.0], [$score, $bonuspoints]);
    }

    /**
     * Settings for test_disabled_reward: the reward switched off.
     *
     * @return array
     */
    public static function disabled_reward_provider(): array {
        return [
            'rewards off' => ['rewards'],
            'combo off' => ['rewardcombo'],
            'extra life off' => ['rewardlife'],
            'bonus round off' => ['rewardbonus'],
            'praise off' => ['rewardpraise'],
        ];
    }

    /**
     * A disabled reward (or rewards off) never fires, while the other rewards still do.
     *
     * @param string $off the setting switched off
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('disabled_reward_provider')]
    public function test_disabled_reward(string $off): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session([
            'rewards' => 1,
            'rewardcombo' => 1,
            'rewardlife' => 1,
            'rewardlifestreak' => 1,
            'rewardbonus' => 1,
            'rewardbonusstreak' => 1,
            'rewardpraise' => 1,
            $off => 0,
        ]);
        $tokens = $this->answer_tokens($session, $qid);

        $seen = ['multiplier' => [], 'combo' => [], 'lives' => [], 'lifegained' => [], 'bonusships' => [], 'praise' => []];
        $points = [];
        for ($level = 1; $level <= 4; $level++) {
            $responses = [$session->answer($qid, $level, 'levelstart')];
            $responses[] = $session->answer($qid, $level, 'shoot', $tokens['1'][0]);
            $points[] = $responses[1]['points'];
            foreach ($responses as $response) {
                foreach ($this->rewards_of($response) as $key => $value) {
                    $seen[$key][] = $value;
                }
            }
        }
        $fired = [
            'rewardcombo' => max($seen['multiplier']) > 1 || in_array('up', $seen['combo']),
            'rewardlife' => in_array(true, $seen['lifegained'], true),
            'rewardbonus' => count(array_filter($seen['bonusships'])) > 0,
            'rewardpraise' => count(array_filter($seen['praise'])) > 0,
        ];
        foreach ($fired as $toggle => $didfire) {
            $this->assertSame($off != 'rewards' && $off != $toggle, $didfire, $toggle);
        }
        if ($off == 'rewards' || $off == 'rewardcombo') {
            $this->assertEquals([1000.0, 1000.0, 1000.0, 1000.0], $points);
        }
        if ($off == 'rewards') {
            $this->assertEquals([0], array_unique($seen['lives']));
        } else if ($off == 'rewardlife') {
            $this->assertEquals([3], array_unique($seen['lives']));
        }
        $this->assertEquals(0.0, $session->finish()[2]);
    }

    /**
     * The starting lives and the number of bonus ships come from the activity.
     */
    public function test_start_lives_and_bonus_ship_count(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->reward_settings([
            'startlives' => 2,
            'rewardbonus' => 1,
            'rewardbonusstreak' => 1,
            'rewardbonusships' => 4,
        ]));
        $tokens = $this->answer_tokens($session, $qid);

        $this->assertEquals(2, $session->answer($qid, 1, 'levelstart')['effects']['lives']);
        $session->answer($qid, 1, 'shoot', $tokens['1'][0]);
        $ships = $session->answer($qid, 2, 'levelstart')['effects']['bonusships'];
        $this->assertCount(4, $ships);
        $this->assertCount(4, array_unique(array_column($ships, 'token')));
        foreach ($ships as $ship) {
            $this->assertEquals('bonus', $session->answer($qid, 2, 'shoot', $ship['token'])['result']);
        }

        // Out-of-range values saved while hidden are clamped to the form's range.
        $session = $this->start_session($this->reward_settings(['startlives' => 0, 'rewardbonusships' => 50]));
        $this->assertEquals(1, $session->answer($qid, 1, 'levelstart')['effects']['lives']);
    }

    /**
     * With "a miss resets the combo", a miss breaks the multiplier but keeps the clean streak that earns
     * extra lives and bonus rounds; without it a miss changes nothing.
     */
    public function test_miss_breaks_combo(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->reward_settings([
            'rewardcombo' => 1,
            'rewardmissbreak' => 1,
            'rewardbonus' => 1,
            'rewardbonusstreak' => 3,
        ]));
        $tokens = $this->answer_tokens($session, $qid);
        $this->seed_combo($session, 1);
        $clean = function (int $level) use ($session, $qid, $tokens): array {
            $session->answer($qid, $level, 'levelstart');
            return $session->answer($qid, $level, 'shoot', $tokens['1'][0]);
        };

        $this->assertEquals([2, 'up'], $this->pick($clean(1), 'multiplier', 'combo'));
        $this->assertEquals([3, 'up'], $this->pick($clean(2), 'multiplier', 'combo'));
        $session->answer($qid, 3, 'levelstart');
        $result = $session->answer($qid, 3, 'miss');
        $this->assertEquals(['miss', 0.0, 1, 'break'], $this->pick($result, 'result', 'points', 'multiplier', 'combo'));
        // A second miss has no combo left to break.
        $this->assertEquals([1, ''], $this->pick($session->answer($qid, 3, 'miss'), 'multiplier', 'combo'));
        // The hit pays x1 and the level is still clean: the combo starts counting again, and the streak of 3
        // (kept through the miss) earns the bonus round.
        $result = $session->answer($qid, 3, 'shoot', $tokens['1'][0]);
        $this->assertEquals([1000.0, 1, ''], $this->pick($result, 'points', 'multiplier', 'combo'));
        $this->assertCount(2, $session->answer($qid, 4, 'levelstart')['effects']['bonusships']);

        // Without the option (the default) a miss keeps the combo.
        $session = $this->start_session($this->reward_settings(['rewardcombo' => 1]));
        $tokens = $this->answer_tokens($session, $qid);
        $this->seed_combo($session, 1);
        $session->answer($qid, 1, 'levelstart');
        $session->answer($qid, 1, 'shoot', $tokens['1'][0]);
        $session->answer($qid, 2, 'levelstart');
        $this->assertEquals([2, ''], $this->pick($session->answer($qid, 2, 'miss'), 'multiplier', 'combo'));
    }

    /**
     * The browser's settings: misses are reported when they cost points or break the combo, and the speed-up
     * waits for the completion score, else the score for maximum grade.
     */
    public function test_client_config(): void {
        $config = $this->start_session()->client_config();
        $this->assertEquals([
            'reportmisses' => false,
            'sassy' => false,
            'startlives' => 3,
            'speedramp' => 0,
            'speedrampstart' => 0,
            'speedroundjump' => 0,
            'speedtarget' => 0,
            'bonusspeed' => 100,
            'playwidth' => 0,
        ], $config);

        $config = $this->start_session($this->reward_settings([
            'rewardcombo' => 1,
            'rewardmissbreak' => 1,
            'gradepassingscore' => 8000,
            'speedramp' => 10,
            'speedrampstart' => 1,
            'speedroundjump' => 0,
            'rewardbonusspeed' => 250,
            'startlives' => 5,
            'playwidth' => 20,
        ]))->client_config();
        $this->assertEquals(
            [true, 8000, 10, 1, 0, 250, 5, 20],
            [$config['reportmisses'], $config['speedtarget'], $config['speedramp'], $config['speedrampstart'],
                $config['speedroundjump'], $config['bonusspeed'], $config['startlives'], $config['playwidth']]
        );
        // The miss-break option only matters while combos are on.
        $config = $this->start_session($this->reward_settings(['rewardmissbreak' => 1]))->client_config();
        $this->assertFalse($config['reportmisses']);
        $config = $this->start_session(['completionscore' => 6000, 'gradepassingscore' => 8000])->client_config();
        $this->assertEquals(6000, $config['speedtarget']);
    }

    /**
     * Scoring settings outside the form's rules (e.g. from a crafted backup) are cleaned, and clamped when read.
     */
    public function test_settings_clamped(): void {
        $data = (object) [
            'pointscorrect' => '9999999999',
            'misspenalty' => '-5',
            'wrongpenalties' => '99999999999999999999,5',
            'sassybosslimit' => '1',
            'rewardmaxlives' => '250',
            'startlives' => '250',
            'name' => 'Kept',
        ];
        game_session::clean_settings($data);
        $this->assertEquals([100000, 0, '300', 2, 200, 200, 'Kept'], [
            $data->pointscorrect, $data->misspenalty, $data->wrongpenalties, $data->sassybosslimit, $data->rewardmaxlives,
            $data->startlives, $data->name,
        ]);
        $this->assertEquals('100,200', game_session::normalise_schedule(' 100, 200 '));
        $this->assertEquals('300', game_session::normalise_schedule(implode(',', array_fill(0, 21, '5'))));
        $this->assertEquals('300', game_session::normalise_schedule('100,,200'));

        // Read-time clamping protects records written before the restore cleaned them.
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session([
            'pointscorrect' => 9999999999,
            'misspenalty' => 9999999999,
            'wrongpenalties' => 'x',
        ]);
        $tokens = $this->answer_tokens($session, $qid);
        $this->assertEquals(100000, $session->points_correct());
        $session->answer($qid, 1, 'levelstart');
        $this->assertEquals(-100000.0, $session->answer($qid, 1, 'miss')['points']);
        $this->assertEquals(-300.0, $session->answer($qid, 1, 'shoot', $tokens['0'][0])['points']);
        $this->assertEquals(100000.0, $session->answer($qid, 1, 'shoot', $tokens['1'][0])['points']);
    }

    /**
     * The teacher's own "Read it first" messages replace the built-in ones, steps and all, formatted.
     */
    public function test_read_it_first_custom_messages(): void {
        $qid = $this->question('truefalse', 'true');
        $session = $this->start_session($this->sassy_settings([
            'sassyreadfirst' => "Whoa there, <b>pilot</b>! || Breathe.\n\nRead first, then shoot.",
        ]), true);
        $tokens = $this->answer_tokens($session, $qid);

        $session->answer($qid, 1, 'levelstart');
        $session->answer($qid, 1, 'shoot', $tokens['0'][0]);
        $result = $session->answer($qid, 2, 'levelstart');
        $this->assertEquals('readfirst', $result['effects']['penalty']);
        $this->assertSame(['Whoa there, pilot!', 'Breathe.', 'Read first, then shoot.'], $result['effects']['messages']);
    }
}
