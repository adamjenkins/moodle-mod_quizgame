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

/**
 * Unit tests for the quizgame events.
 *
 * @package    mod_quizgame
 * @category   test
 * @copyright  2015 Stephen Bourget
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_quizgame;
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quizgame/locallib.php');

/**
 * Unit tests for quizgame events.
 *
 *
 * @package    mod_quizgame
 * @category   test
 * @copyright  2015 Stephen Bourget
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_quizgame\event\course_module_viewed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_quizgame\event\course_module_instance_list_viewed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_quizgame\event\game_score_added::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_quizgame\event\game_started::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_quizgame\event\game_scores_viewed::class)]
final class events_test extends \advanced_testcase {
    /**
     * Test setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Test the course_module_viewed event.
     */
    public function test_course_module_viewed(): void {
        global $DB;
        // There is no proper API to call to trigger this event, so what we are
        // doing here is simply making sure that the events returns the right information.

        $course = $this->getDataGenerator()->create_course();
        $quizgame = $this->getDataGenerator()->create_module('quizgame', ['course' => $course->id]);

        $dbcourse = $DB->get_record('course', ['id' => $course->id]);
        $dbquizgame = $DB->get_record('quizgame', ['id' => $quizgame->id]);
        $context = \context_module::instance($quizgame->cmid);

        $event = \mod_quizgame\event\course_module_viewed::create([
            'objectid' => $dbquizgame->id,
            'context' => $context,
        ]);

        $event->add_record_snapshot('course', $dbcourse);
        $event->add_record_snapshot('quizgame', $dbquizgame);

        // Triggering and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_quizgame\event\course_module_viewed', $event);
        $this->assertEquals(CONTEXT_MODULE, $event->contextlevel);
        $this->assertEquals($quizgame->cmid, $event->contextinstanceid);
        $this->assertEquals($quizgame->id, $event->objectid);
        $this->assertEquals(new \moodle_url('/mod/quizgame/view.php', ['id' => $quizgame->cmid]), $event->get_url());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the course_module_instance_list_viewed event.
     */
    public function test_course_module_instance_list_viewed(): void {
        // There is no proper API to call to trigger this event, so what we are
        // doing here is simply making sure that the events returns the right information.

        $course = $this->getDataGenerator()->create_course();

        $event = \mod_quizgame\event\course_module_instance_list_viewed::create([
            'context' => \context_course::instance($course->id),
        ]);

        // Triggering and capturing the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_quizgame\event\course_module_instance_list_viewed', $event);
        $this->assertEquals(CONTEXT_COURSE, $event->contextlevel);
        $this->assertEquals($course->id, $event->contextinstanceid);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the score_added event.
     */
    public function test_score_added(): void {

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quizgame = $this->getDataGenerator()->create_module('quizgame', ['course' => $course]);
        $context = \context_module::instance($quizgame->cmid);
        $score = mt_rand(0, 50000);

        $sink = $this->redirectEvents();
        $result = quizgame_add_highscore($quizgame, $score);

        // Recording a score also updates the gradebook, which triggers its own events.
        $events = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof \mod_quizgame\event\game_score_added;
        }));
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_quizgame\event\game_score_added', $event);
        $this->assertEquals(CONTEXT_MODULE, $event->contextlevel);
        $this->assertEquals($quizgame->cmid, $event->contextinstanceid);
        $this->assertEquals($score, $event->other['score']);
    }

    /**
     * Test the game_started event.
     */
    public function test_game_started(): void {

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quizgame = $this->getDataGenerator()->create_module('quizgame', ['course' => $course]);
        $context = \context_module::instance($quizgame->cmid);

        $sink = $this->redirectEvents();
        $result = quizgame_log_game_start($quizgame);

        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_quizgame\event\game_started', $event);
        $this->assertEquals(CONTEXT_MODULE, $event->contextlevel);
        $this->assertEquals($quizgame->cmid, $event->contextinstanceid);
    }

    /**
     * Test the game_scores_viewed event.
     */
    public function test_game_scores_viewed(): void {
        // There is no proper API to call to trigger this event, so what we are
        // doing here is simply making sure that the events returns the right information.

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quizgame = $this->getDataGenerator()->create_module('quizgame', ['course' => $course]);
        $context = \context_module::instance($quizgame->cmid);

        $quizgamegenerator = $this->getDataGenerator()->get_plugin_generator('mod_quizgame');
        $scores = $quizgamegenerator->create_content($quizgame);

        $event = \mod_quizgame\event\game_scores_viewed::create([
            'objectid' => $quizgame->id,
            'context' => $context,
        ]);

        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $event = reset($events);

        // Checking that the event contains the expected values.
        $this->assertInstanceOf('\mod_quizgame\event\game_scores_viewed', $event);
        $this->assertEquals(CONTEXT_MODULE, $event->contextlevel);
        $this->assertEquals($quizgame->cmid, $event->contextinstanceid);
    }
}
