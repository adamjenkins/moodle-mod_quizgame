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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/quizgame/mod_form.php');

/**
 * Tests for the question bank and category selection of the activity settings form.
 *
 * @package    mod_quizgame
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_quizgame_mod_form::class)]
final class mod_form_test extends \advanced_testcase {
    /**
     * Forget the simulated form submission.
     */
    #[\Override]
    protected function tearDown(): void {
        $_POST = [];
        parent::tearDown();
    }

    /**
     * Create a question bank activity with one category.
     *
     * @param \stdClass $course
     * @param string $categoryname
     * @return array [int $cmid, \stdClass $category]
     */
    protected function create_bank(\stdClass $course, string $categoryname): array {
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $category = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category([
            'contextid' => \context_module::instance($bank->cmid)->id,
            'name' => $categoryname,
        ]);
        return [(int) $bank->cmid, $category];
    }

    /**
     * Build the settings form of an existing activity the way course/modedit.php does.
     *
     * @param \stdClass $course
     * @param int $cmid the activity's course-module id
     * @param array|null $post submitted values (null: the form is not submitted)
     * @return array [\mod_quizgame_mod_form $form, \MoodleQuickForm $mform, \stdClass $data]
     */
    protected function build_form(\stdClass $course, int $cmid, ?array $post = null): array {
        global $COURSE, $PAGE;

        $course = get_course($course->id);
        $cm = get_coursemodule_from_id('quizgame', $cmid, 0, false, MUST_EXIST);
        [$cm, , , $data, $cw] = get_moduleinfo_data($cm, $course);
        // Submitted by the availability field of the real form; the tests call validation() with these data.
        $data->availabilityconditionsjson = (string) ($data->availabilityconditionsjson ?? '');

        $_POST = $post === null ? [] : $post + [
            '_qf__mod_quizgame_mod_form' => 1,
            'sesskey' => sesskey(),
        ];
        // The form reads the course from the page, as on course/modedit.php. A fresh page for every
        // form built: a page whose theme is set up refuses another set_course().
        $PAGE = new \moodle_page();
        $PAGE->set_url('/course/modedit.php', ['update' => $cm->id]);
        $PAGE->set_course($course);
        $COURSE = $course;

        $form = new \mod_quizgame_mod_form($data, $cw->section, $cm, $course);
        $form->set_data($data);
        if ($post !== null) {
            // As modedit.php does next: get_data() runs definition_after_data(), which selects the new
            // bank's default category after Switch bank. It returns null for a no-submit button press.
            $form->get_data();
        }
        $mform = (fn() => $this->_form)->call($form);
        return [$form, $mform, $data];
    }

    /**
     * The option keys of the question category select.
     *
     * @param \MoodleQuickForm $mform
     * @return string[] group label => list of "categoryid,contextid" keys
     */
    protected function category_options(\MoodleQuickForm $mform): array {
        $options = [];
        foreach ($mform->getElement('questioncategory')->_optGroups as $group) {
            $options[$group['attr']['label']] = array_map(
                fn($option) => (string) $option['attr']['value'],
                $group['options'] ?? []
            );
        }
        return $options;
    }

    /**
     * All option keys of the question category select, in one list.
     *
     * @param \MoodleQuickForm $mform
     * @return string[]
     */
    protected function all_category_keys(\MoodleQuickForm $mform): array {
        return array_merge([], ...array_values($this->category_options($mform)));
    }

    /**
     * Editing an activity shows the bank of its saved category and only that bank's categories, with
     * the saved category selected.
     */
    public function test_existing_activity_shows_saved_bank(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        [, $categorya] = $this->create_bank($course, 'Bank A questions');
        [$cmidb, $categoryb] = $this->create_bank($course, 'Bank B questions');
        $keyb = $categoryb->id . ',' . $categoryb->contextid;
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $course->id,
            'questioncategory' => $keyb,
        ]);

        [, $mform] = $this->build_form($course, $quizgame->cmid);

        $this->assertEquals($cmidb, $mform->getElementValue('qbankcmid'));
        $this->assertEquals([$keyb], $mform->getElement('questioncategory')->getValue());
        $keys = $this->all_category_keys($mform);
        $this->assertContains($keyb, $keys);
        $this->assertNotContains($categorya->id . ',' . $categorya->contextid, $keys);
        $this->assertTrue($mform->elementExists('qbankgroup'));
    }

    /**
     * Legacy stored values (category id only, or a stale context id) still resolve to the category's
     * bank and are rebuilt into a current option key.
     */
    public function test_legacy_values_resolve(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        $this->create_bank($course, 'Bank A questions');
        [$cmidb, $categoryb] = $this->create_bank($course, 'Bank B questions');
        $keyb = $categoryb->id . ',' . $categoryb->contextid;

        foreach ([(string) $categoryb->id, $categoryb->id . ',999999'] as $stored) {
            $quizgame = $this->getDataGenerator()->create_module('quizgame', [
                'course' => $course->id,
                'questioncategory' => $stored,
            ]);
            [, $mform] = $this->build_form($course, $quizgame->cmid);
            $this->assertEquals($cmidb, $mform->getElementValue('qbankcmid'), $stored);
            $this->assertEquals([$keyb], $mform->getElement('questioncategory')->getValue(), $stored);
        }
    }

    /**
     * After Switch bank, a submitted usable bank is shown with its own categories and its default
     * category selected; a category of another bank is then rejected by validation.
     */
    public function test_switch_to_usable_bank(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['summary' => '']);
        [, $categorya] = $this->create_bank($course, 'Bank A questions');
        [$cmidb, $categoryb] = $this->create_bank($course, 'Bank B questions');
        $keya = $categorya->id . ',' . $categorya->contextid;
        $quizgame = $this->getDataGenerator()->create_module('quizgame', [
            'course' => $course->id,
            'questioncategory' => $keya,
        ]);

        [$form, $mform, $data] = $this->build_form($course, $quizgame->cmid, [
            'qbankcmid' => $cmidb,
            'questioncategory' => $keya,
            'qbankswitch' => 'x',
        ]);

        $this->assertEquals($cmidb, $mform->getElementValue('qbankcmid'));
        $keys = $this->all_category_keys($mform);
        $this->assertNotContains($keya, $keys);
        $this->assertContains($categoryb->id . ',' . $categoryb->contextid, $keys);
        $default = question_get_default_category($categoryb->contextid);
        $this->assertEquals([$default->id . ',' . $default->contextid], $mform->getElement('questioncategory')->getValue());

        $errors = $form->validation(array_merge((array) $data, ['questioncategory' => $keya]), []);
        $this->assertArrayHasKey('questioncategory', $errors);
        $errors = $form->validation(
            array_merge((array) $data, ['questioncategory' => $categoryb->id . ',' . $categoryb->contextid]),
            []
        );
        $this->assertArrayNotHasKey('questioncategory', $errors);
    }

    /**
     * A bank the editor may not use is never offered: a saved category from such a bank is kept as
     * "Current category" only, and a submitted unusable bank falls back to the saved category's bank.
     */
    public function test_unusable_banks(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['summary' => '']);
        $othercourse = $generator->create_course(['summary' => '']);
        [, $categorya] = $this->create_bank($course, 'Bank A questions');
        // Shared banks in another course, where the teacher has no role (no moodle/question:useall).
        [$cmidc, $categoryc] = $this->create_bank($othercourse, 'Bank C questions');
        [$cmidd, $categoryd] = $this->create_bank($othercourse, 'Bank D questions');
        $keya = $categorya->id . ',' . $categorya->contextid;
        $keyc = $categoryc->id . ',' . $categoryc->contextid;
        $quizgame = $generator->create_module('quizgame', [
            'course' => $course->id,
            'questioncategory' => $keyc,
        ]);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        // The saved bank stays shown, but only its saved category is offered, as "Current category".
        [$form, $mform, $data] = $this->build_form($course, $quizgame->cmid);
        $this->assertEquals($cmidc, $mform->getElementValue('qbankcmid'));
        $options = $this->category_options($mform);
        $this->assertEquals([$keyc], $options[get_string('currentcategory', 'quizgame')]);
        $this->assertEquals([$keyc], $this->all_category_keys($mform));
        $this->assertEquals([$keyc], $mform->getElement('questioncategory')->getValue());
        $this->assertArrayNotHasKey('questioncategory', $form->validation((array) $data, []));

        // A submitted bank the teacher cannot use is replaced by the saved category's bank.
        [$form, $mform, $data] = $this->build_form($course, $quizgame->cmid, [
            'qbankcmid' => $cmidd,
            'questioncategory' => $keyc,
            'qbankswitch' => 'x',
        ]);
        $this->assertEquals($cmidc, $mform->getElementValue('qbankcmid'));
        $this->assertNotContains($categoryd->id . ',' . $categoryd->contextid, $this->all_category_keys($mform));
        $errors = $form->validation(
            array_merge((array) $data, ['questioncategory' => $categoryd->id . ',' . $categoryd->contextid]),
            []
        );
        $this->assertArrayHasKey('questioncategory', $errors);

        // Switching to the course's own bank lists its categories and still keeps the current one.
        $cmida = (int) \context::instance_by_id($categorya->contextid)->instanceid;
        [, $mform] = $this->build_form($course, $quizgame->cmid, [
            'qbankcmid' => $cmida,
            'questioncategory' => $keyc,
            'qbankswitch' => 'x',
        ]);
        $this->assertEquals($cmida, $mform->getElementValue('qbankcmid'));
        $keys = $this->all_category_keys($mform);
        $this->assertContains($keya, $keys);
        $this->assertContains($keyc, $keys);
        $this->assertNotEquals([$keyc], $mform->getElement('questioncategory')->getValue());

        // A game whose category is gone falls back to the first bank of the course.
        $quizgame2 = $generator->create_module('quizgame', [
            'course' => $course->id,
            'questioncategory' => '999999,1',
        ]);
        [, $mform] = $this->build_form($course, $quizgame2->cmid);
        $this->assertEquals($cmida, $mform->getElementValue('qbankcmid'));
        $this->assertNotContains($keyc, $this->all_category_keys($mform));
    }
}
