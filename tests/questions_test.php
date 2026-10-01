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
 * Tests for the questions a game plays and the categories it may use.
 *
 * @package    mod_quizgame
 * @category   test
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('quizgame_get_game_questions')]
#[\PHPUnit\Framework\Attributes\CoversFunction('quizgame_category_allowed')]
#[\PHPUnit\Framework\Attributes\CoversFunction('quizgame_cleanup')]
#[\PHPUnit\Framework\Attributes\CoversClass(game_session::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_quizgame_renderer::class)]
final class questions_test extends \advanced_testcase {
    /**
     * Create a question bank activity in a course with one category holding a true/false question.
     *
     * @param \stdClass $course
     * @param string $questiontext
     * @param string $modname the bank activity type
     * @return array [stdClass $category, stdClass $question]
     */
    protected function create_bank_category(\stdClass $course, string $questiontext, string $modname = 'qbank'): array {
        $bank = $this->getDataGenerator()->create_module($modname, ['course' => $course->id]);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category([
            'contextid' => \context_module::instance($bank->cmid)->id,
        ]);
        $question = $questiongenerator->create_question('truefalse', null, [
            'category' => $category->id,
            'questiontext' => ['text' => $questiontext, 'format' => FORMAT_HTML],
        ]);
        return [$category, $question];
    }

    /**
     * Questions come from a bank in the same course; the browser copy is plain text with tokens only.
     */
    public function test_same_course_category(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$category, $question] = $this->create_bank_category($course, '<p>Is 5 &lt; 6 &amp; 7?</p>');
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $course->id,
            'questioncategory' => $category->id . ',' . $category->contextid,
        ]);
        $context = \context_module::instance($quizgame->cmid);

        $this->assertTrue(quizgame_category_allowed($category->id, $course->id));
        $this->assertArrayHasKey($question->id, quizgame_get_game_questions($quizgame));

        $client = (new game_session($quizgame))->prepare($context);
        $this->assertCount(1, $client);
        $this->assertEquals('truefalse', $client[0]['type']);
        $this->assertEquals('Is 5 < 6 & 7?', $client[0]['question']);
        $this->assertCount(2, $client[0]['answers']);
        // Nothing in the browser copy says which answer is correct.
        $json = json_encode($client);
        $this->assertStringNotContainsString('fraction', $json);
        foreach ($client[0]['answers'] as $answer) {
            $this->assertEquals(['text', 'token'], array_keys($answer));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $answer['token']);
        }
    }

    /**
     * A category of another course's private bank (e.g. from a crafted form post or backup) yields no
     * questions; a shared bank in another course qualifies.
     */
    public function test_other_course_categories(): void {
        $this->resetAfterTest();
        $othercourse = $this->getDataGenerator()->create_course();
        [$privatecategory] = $this->create_bank_category($othercourse, 'Secret question', 'quiz');
        [$sharedcategory] = $this->create_bank_category($othercourse, 'Shared question', 'qbank');

        $course = $this->getDataGenerator()->create_course();
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $course->id,
            'questioncategory' => (string) $privatecategory->id,
        ]);

        $this->assertFalse(quizgame_category_allowed($privatecategory->id, $course->id));
        $this->assertSame([], quizgame_get_game_questions($quizgame));
        $this->assertTrue(quizgame_category_allowed($sharedcategory->id, $course->id));
    }

    /**
     * Missing or empty categories yield no questions.
     */
    public function test_missing_category(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->assertFalse(quizgame_category_allowed(0, $course->id));
        $this->assertFalse(quizgame_category_allowed(-5, $course->id));
        $this->assertSame(0, quizgame_get_category_id(''));
        $this->assertSame(12, quizgame_get_category_id('12,345'));
        $this->assertSame(12, quizgame_get_category_id('12'));
    }

    /**
     * The rendered game carries the questions in a data attribute that survives any text (even a literal
     * numeric entity) and holds no correctness information.
     */
    public function test_rendered_game(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        [$category] = $this->create_bank_category($course, '<p>Say &#34;hi&#34; &amp; &lt;b&gt;</p>');
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $course->id,
            'questioncategory' => (string) $category->id,
        ]);
        $context = \context_module::instance($quizgame->cmid);
        $PAGE->set_url('/mod/quizgame/view.php', ['id' => $quizgame->cmid]);
        $PAGE->set_context($context);

        $html = $PAGE->get_renderer('mod_quizgame')->render_game($quizgame, $context);

        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NOERROR);
        $canvas = $doc->getElementById('mod_quizgame_game');
        $questions = json_decode($canvas->getAttribute('data-questions'), true);
        $this->assertIsArray($questions);
        $this->assertEquals('Say "hi" & <b>', $questions[0]['question']);
        $this->assertStringNotContainsString('fraction', $canvas->getAttribute('data-questions'));
    }
}
