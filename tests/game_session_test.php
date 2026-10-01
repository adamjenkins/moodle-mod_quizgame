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
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
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
     * @return game_session
     */
    protected function start_session(): game_session {
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $this->course->id,
            'questioncategory' => (string) $this->category->id,
        ]);
        $session = new game_session($quizgame);
        $session->prepare(\context_module::instance($quizgame->cmid));
        $session->start();
        return $session;
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
}
