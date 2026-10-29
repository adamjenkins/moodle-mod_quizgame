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
 * The main quizgame configuration form
 *
 * It uses the standard core Moodle formslib. For more info about them, please
 * visit: http://docs.moodle.org/en/Development:lib/formslib.php
 *
 * @package    mod_quizgame
 * @copyright  2014 John Okely <john@moodle.com>
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/lib/questionlib.php');
require_once($CFG->dirroot . '/mod/quizgame/lib.php');
require_once($CFG->dirroot . '/mod/quizgame/locallib.php');

use mod_quizgame\local\game_session;

/**
 * Module instance settings form
 * @copyright  2014 John Okely <john@moodle.com>
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_quizgame_mod_form extends moodleform_mod {
    /** @var int[] Play area widths offered, in ships (0, fill the screen, is offered too). */
    const PLAY_WIDTHS = [12, 15, 20, 25, 30, 40];

    /** @var array|null Category ids the current user may choose, keyed by id (see get_category_options()). */
    protected $allowedcategoryids = null;

    /** @var array Category ids of the shown question bank's own categories, keyed by id. */
    protected $bankcategoryids = [];

    /** @var string Option key ("categoryid,contextid") of the shown bank's default category, '' if none. */
    protected $defaultcategorykey = '';

    /** @var int Course-module id of the question bank whose categories the form shows, 0 if none. */
    protected $qbankcmid = 0;

    /**
     * Defines forms elements
     */
    public function definition() {
        $mform = $this->_form;

        // Adding the "general" fieldset, where all the common settings are showed.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Adding the standard "name" field.
        $mform->addElement('text', 'name', get_string('quizgamename', 'quizgame'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'quizgamename', 'quizgame');

        // Adding the standard "intro" and "introformat" fields.
        $this->standard_intro_elements();

        $this->add_question_bank_elements();

        // Grade settings: type/max, category, grade-to-pass (standard Moodle grade elements).
        $this->standard_grading_coursemodule_elements();

        // Target game score: the game score that earns the maximum grade.
        $mform->addElement('text', 'gradepassingscore', get_string('gradepassingscore', 'quizgame'), ['size' => '8']);
        $mform->setType('gradepassingscore', PARAM_INT);
        $mform->setDefault('gradepassingscore', 0);
        $mform->addHelpButton('gradepassingscore', 'gradepassingscore', 'quizgame');
        $mform->hideIf('gradepassingscore', 'grade[modgrade_type]', 'neq', 'point');

        $this->add_scoring_elements();
        $this->add_sassy_elements();
        $this->add_rewards_elements();
        $this->add_difficulty_elements();

        // Add standard elements, common to all modules.
        $this->standard_coursemodule_elements();
        // Add standard buttons, common to all modules.
        $this->add_action_buttons();
    }

    /**
     * Add the Scoring section: points for a correct hit, the wrong-hit penalty schedule, the miss penalty.
     */
    protected function add_scoring_elements(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'scoringhdr', get_string('scoringhdr', 'quizgame'));

        $mform->addElement('text', 'pointscorrect', get_string('pointscorrect', 'quizgame'), ['size' => '8']);
        $mform->setType('pointscorrect', PARAM_INT);
        $mform->setDefault('pointscorrect', 1000);
        $mform->addHelpButton('pointscorrect', 'pointscorrect', 'quizgame');

        $mform->addElement('text', 'wrongpenalties', get_string('wrongpenalties', 'quizgame'), ['size' => '32']);
        $mform->setType('wrongpenalties', PARAM_TEXT);
        $mform->setDefault('wrongpenalties', '300');
        $mform->addHelpButton('wrongpenalties', 'wrongpenalties', 'quizgame');

        $mform->addElement('text', 'misspenalty', get_string('misspenalty', 'quizgame'), ['size' => '8']);
        $mform->setType('misspenalty', PARAM_INT);
        $mform->setDefault('misspenalty', 0);
        $mform->addHelpButton('misspenalty', 'misspenalty', 'quizgame');
    }

    /**
     * Add the Sassy enhancements section. Every field but the master toggle is hidden while it is off.
     */
    protected function add_sassy_elements(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'sassyhdr', get_string('sassyhdr', 'quizgame'));

        $mform->addElement('advcheckbox', 'sassy', get_string('sassy', 'quizgame'), get_string('sassy_enable', 'quizgame'));
        $mform->setDefault('sassy', 0);
        $mform->addHelpButton('sassy', 'sassy', 'quizgame');

        $sassyfields = [];
        foreach (self::sassy_int_fields() as $field => $spec) {
            $mform->addElement('text', $field, get_string($field, 'quizgame'), ['size' => '6']);
            $mform->setType($field, PARAM_INT);
            $mform->setDefault($field, $spec[0]);
            $mform->addHelpButton($field, $field, 'quizgame');
            $sassyfields[] = $field;
            if ($field === 'sassyreadtime') {
                // The penalty choice and selection order follow the tier settings.
                $this->add_sassy_penalty_elements();
                $sassyfields[] = 'sassypenaltiesgroup';
                $sassyfields[] = 'sassyorder';
            } else if ($field === 'sassydrainincrease') {
                $this->add_sassy_ending_elements();
                $sassyfields[] = 'sassyendingsgroup';
            }
        }

        $textfields = ['sassyreadfirst', 'sassytaunts', 'sassydecoylabels', 'sassybosslines'];
        foreach (array_merge($textfields, game_session::ENDING_LINES) as $field) {
            $mform->addElement('textarea', $field, get_string($field, 'quizgame'), ['rows' => 6, 'cols' => 60]);
            $mform->setType($field, PARAM_TEXT);
            // New instances start with the built-in list, so teachers can see and edit it.
            $mform->setDefault($field, get_string('sassy_' . substr($field, 5) . '_default', 'quizgame'));
            $mform->addHelpButton($field, $field, 'quizgame');
            $sassyfields[] = $field;
        }

        foreach ($sassyfields as $field) {
            $mform->hideIf($field, 'sassy', 'notchecked');
        }
        // The swarm size and each boss's text only matter while that boss is enabled.
        $mform->hideIf('sassyswarmsize', 'sassyending_swarm', 'notchecked');
        foreach (game_session::ENDING_LINES as $ending => $field) {
            $mform->hideIf($field, 'sassyending_' . $ending, 'notchecked');
        }
    }

    /**
     * Add the enabled-boss checkboxes (sassyending_<key>): the boss finale picks one of them at random.
     */
    protected function add_sassy_ending_elements(): void {
        $mform = $this->_form;

        $checkboxes = [];
        foreach (quizgame_sassy_ending_keys() as $key) {
            $checkboxes[] = $mform->createElement(
                'advcheckbox',
                'sassyending_' . $key,
                '',
                get_string('sassyboss_' . $key, 'quizgame')
            );
        }
        $mform->addGroup($checkboxes, 'sassyendingsgroup', get_string('sassybosspool', 'quizgame'), '<br>', false);
        $mform->addHelpButton('sassyendingsgroup', 'sassybosspool', 'quizgame');
        foreach (quizgame_sassy_ending_keys() as $key) {
            $mform->setDefault('sassyending_' . $key, 1);
        }
    }

    /**
     * Add the Rewards section. Every field but the master toggle is hidden while it is off, and each
     * reward's own settings are also hidden while that reward's toggle is off.
     */
    protected function add_rewards_elements(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'rewardshdr', get_string('rewardshdr', 'quizgame'));

        $mform->addElement('advcheckbox', 'rewards', get_string('rewards', 'quizgame'), get_string('rewards_enable', 'quizgame'));
        $mform->setDefault('rewards', 1);
        $mform->addHelpButton('rewards', 'rewards', 'quizgame');

        $intfields = self::reward_int_fields();
        foreach (self::reward_toggles() as $toggle => $fields) {
            $mform->addElement('advcheckbox', $toggle, get_string($toggle, 'quizgame'));
            $mform->setDefault($toggle, 1);
            $mform->addHelpButton($toggle, $toggle, 'quizgame');
            $mform->hideIf($toggle, 'rewards', 'notchecked');

            foreach ($fields as $field) {
                if ($field === 'rewardmissbreak') {
                    $mform->addElement('advcheckbox', $field, get_string($field, 'quizgame'));
                    $mform->setDefault($field, 0);
                } else if ($field === 'rewardpraiselines') {
                    $mform->addElement('textarea', $field, get_string($field, 'quizgame'), ['rows' => 6, 'cols' => 60]);
                    $mform->setType($field, PARAM_TEXT);
                    // New instances start with the built-in list, so teachers can see and edit it.
                    $mform->setDefault($field, get_string('rewards_praise_default', 'quizgame'));
                } else {
                    $mform->addElement('text', $field, get_string($field, 'quizgame'), ['size' => '6']);
                    $mform->setType($field, PARAM_INT);
                    $mform->setDefault($field, $intfields[$field][0]);
                }
                $mform->addHelpButton($field, $field, 'quizgame');
                $mform->hideIf($field, 'rewards', 'notchecked');
                $mform->hideIf($field, $toggle, 'notchecked');
            }
        }
    }

    /**
     * The reward toggles, in form order, with the settings that belong to each.
     *
     * @return array toggle name => list of field names
     */
    protected static function reward_toggles(): array {
        return [
            'rewardcombo' => ['rewardmaxmult', 'rewardmissbreak'],
            'rewardlife' => ['rewardlifestreak'],
            'rewardbonus' => ['rewardbonusstreak', 'rewardbonuspoints', 'rewardbonusships', 'rewardbonusspeed'],
            'rewardpraise' => ['rewardpraiselines'],
        ];
    }

    /**
     * The whole-number reward settings with their default and allowed range.
     *
     * @return array field name => [default, minimum, maximum]
     */
    protected static function reward_int_fields(): array {
        return [
            'rewardmaxmult' => [4, 1, 10],
            'rewardlifestreak' => [5, 1, 50],
            'rewardbonusstreak' => [5, 1, 50],
            'rewardbonuspoints' => [1000, 1, 100000],
            'rewardbonusships' => [2, 1, 10],
            'rewardbonusspeed' => [100, 25, 400],
        ];
    }

    /**
     * Add the Difficulty section: starting and maximum lives, how the enemy ships speed up and the play area width.
     */
    protected function add_difficulty_elements(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'speedhdr', get_string('speedhdr', 'quizgame'));

        $intfields = self::difficulty_int_fields();
        foreach (['startlives', 'rewardmaxlives', 'speedramp'] as $field) {
            $mform->addElement('text', $field, get_string($field, 'quizgame'), ['size' => '6']);
            $mform->setType($field, PARAM_INT);
            $mform->setDefault($field, $intfields[$field][0]);
            $mform->addHelpButton($field, $field, 'quizgame');
        }
        // The maximum only matters while extra lives can be won.
        $mform->hideIf('rewardmaxlives', 'rewards', 'notchecked');
        $mform->hideIf('rewardmaxlives', 'rewardlife', 'notchecked');

        $options = [
            0 => get_string('speedrampstart_target', 'quizgame'),
            1 => get_string('speedrampstart_firstround', 'quizgame'),
            2 => get_string('speedrampstart_second', 'quizgame'),
        ];
        $mform->addElement('select', 'speedrampstart', get_string('speedrampstart', 'quizgame'), $options);
        $mform->setDefault('speedrampstart', 0);
        $mform->addHelpButton('speedrampstart', 'speedrampstart', 'quizgame');
        $mform->hideIf('speedrampstart', 'speedramp', 'eq', 0);

        $mform->addElement('text', 'speedroundjump', get_string('speedroundjump', 'quizgame'), ['size' => '6']);
        $mform->setType('speedroundjump', PARAM_INT);
        $mform->setDefault('speedroundjump', $intfields['speedroundjump'][0]);
        $mform->addHelpButton('speedroundjump', 'speedroundjump', 'quizgame');

        $options = [0 => get_string('playwidth_fill', 'quizgame')];
        foreach (self::PLAY_WIDTHS as $ships) {
            $options[$ships] = get_string('playwidth_ships', 'quizgame', $ships);
        }
        $mform->addElement('select', 'playwidth', get_string('playwidth', 'quizgame'), $options);
        $mform->setDefault('playwidth', 0);
        $mform->addHelpButton('playwidth', 'playwidth', 'quizgame');
    }

    /**
     * The whole-number Difficulty settings with their default and allowed range.
     *
     * @return array field name => [default, minimum, maximum]
     */
    protected static function difficulty_int_fields(): array {
        return [
            'startlives' => [3, 1, 200],
            // Validation also keeps it at least the starting lives (only checked while extra lives are on).
            'rewardmaxlives' => [5, 1, 200],
            'speedramp' => [0, 0, 50],
            'speedroundjump' => [0, 0, 200],
        ];
    }

    /**
     * Add the enabled-penalty checkboxes (sassypenalty_<key>) and the selection-order select.
     */
    protected function add_sassy_penalty_elements(): void {
        $mform = $this->_form;

        $checkboxes = [];
        foreach (quizgame_sassy_penalty_keys() as $key) {
            $checkboxes[] = $mform->createElement(
                'advcheckbox',
                'sassypenalty_' . $key,
                '',
                get_string('sassypenalty_' . $key, 'quizgame')
            );
        }
        $mform->addGroup($checkboxes, 'sassypenaltiesgroup', get_string('sassypenalties', 'quizgame'), '<br>', false);
        $mform->addHelpButton('sassypenaltiesgroup', 'sassypenalties', 'quizgame');
        foreach (quizgame_sassy_penalty_keys() as $key) {
            $mform->setDefault('sassypenalty_' . $key, 1);
        }

        $mform->addElement('select', 'sassyorder', get_string('sassyorder', 'quizgame'), [
            0 => get_string('sassyorder_inorder', 'quizgame'),
            1 => get_string('sassyorder_random', 'quizgame'),
        ]);
        $mform->setDefault('sassyorder', 0);
        $mform->addHelpButton('sassyorder', 'sassyorder', 'quizgame');
    }

    /**
     * The whole-number Sassy settings, in form order, with their default and allowed range.
     *
     * @return array field name => [default, minimum, maximum]
     */
    protected static function sassy_int_fields(): array {
        return [
            'sassywrongtrigger' => [3, 1, 20],
            'sassycleanneeded' => [1, 1, 10],
            'sassybosslimit' => [4, 2, 20],
            'sassyreadtime' => [5, 1, 30],
            'sassymaxdecoys' => [3, 1, 10],
            'sassydrainrate' => [100, 1, 10000],
            'sassydrainincrease' => [50, 0, 500],
            'sassyswarmsize' => [20, 5, 40],
        ];
    }

    /**
     * Add an error to $errors when a submitted whole number is missing or outside a range.
     *
     * @param array $errors errors keyed by element name, changed in place
     * @param array $data submitted data
     * @param string $field element name
     * @param int $min smallest allowed value
     * @param int $max largest allowed value
     */
    protected static function validate_range(array &$errors, array $data, string $field, int $min, int $max): void {
        $value = $data[$field] ?? null;
        if (!is_numeric($value) || (int) $value != $value || $value < $min || $value > $max) {
            $errors[$field] = get_string('errorrange', 'quizgame', (object) ['min' => $min, 'max' => $max]);
        }
    }

    /**
     * Add the question bank line (bank name and Switch bank button), the hidden current bank, the
     * category select for that bank and the subcategories checkbox.
     *
     * The bank is not stored: the game stores only its category ("categoryid,contextid"), and the
     * bank is derived from the category's context. A reload by the Switch bank button submits the
     * new bank's course-module id in qbankcmid, which is accepted only for a bank the user may use.
     */
    protected function add_question_bank_elements(): void {
        global $PAGE;

        $mform = $this->_form;
        $courseid = $this->_course->id;

        $banks = $this->get_usable_banks();
        $savedid = quizgame_get_category_id($this->current->questioncategory ?? '');
        $savedbank = $this->get_category_bank_cmid($savedid);
        $this->qbankcmid = self::choose_bank($banks, $savedbank, (int) $this->optional_param('qbankcmid', 0, PARAM_INT));

        $mform->addElement('hidden', 'qbankcmid');
        $mform->setType('qbankcmid', PARAM_INT);
        // A constant, so a submitted bank that was rejected above is replaced by the bank shown.
        $mform->setConstant('qbankcmid', $this->qbankcmid);

        $categories = $this->get_category_options($banks, $savedid, $savedbank);

        if ($this->qbankcmid) {
            // Pressed by mod_quizgame/bank_selector after a bank is chosen: reloads the form with the
            // entered values and the new bank, without saving.
            $mform->registerNoSubmitButton('qbankswitch');
            $bankline = [
                $mform->createElement('static', 'qbankname', '', html_writer::span(
                    $this->get_bank_name($this->qbankcmid),
                    'me-3',
                    ['data-region' => 'mod_quizgame-bankname']
                )),
                $mform->createElement('button', 'qbankswitchbutton', get_string('switchbank', 'quizgame'), [
                    'data-action' => 'mod_quizgame-switchbank',
                ]),
                $mform->createElement('submit', 'qbankswitch', get_string('switchbankupdate', 'quizgame'), [
                    'class' => 'd-none',
                ]),
            ];
            $mform->addGroup($bankline, 'qbankgroup', get_string('questionbank', 'quizgame'), ' ', false);
            $mform->addHelpButton('qbankgroup', 'questionbank', 'quizgame');

            $PAGE->requires->js_call_amd('mod_quizgame/bank_selector', 'init', [
                (int) $courseid,
                (int) context_course::instance($courseid)->id,
                (int) $this->qbankcmid,
                // The banks route needs a module context: this activity, or the bank shown when adding one.
                (int) (!empty($this->_cm->id) ? $this->_cm->id : $this->qbankcmid),
            ]);
        }

        if (!$categories) {
            $mform->addElement(
                'static',
                'noquestionbanks',
                get_string('questioncategory', 'quizgame'),
                get_string('noquestionbanks', 'quizgame')
            );
        }
        $mform->addElement('selectgroups', 'questioncategory', get_string('questioncategory', 'quizgame'), $categories);
        $mform->addRule('questioncategory', null, 'required', null, 'client');
        $mform->addHelpButton('questioncategory', 'questioncategory', 'quizgame');
        if ($this->defaultcategorykey !== '') {
            // New activities start on the default category of the bank shown.
            $mform->setDefault('questioncategory', $this->defaultcategorykey);
        }

        $mform->addElement(
            'advcheckbox',
            'questioncategorysubcats',
            '',
            get_string('questioncategorysubcats', 'quizgame')
        );
        $mform->addHelpButton('questioncategorysubcats', 'questioncategorysubcats', 'quizgame');
    }

    /**
     * The question banks the current user may take a game's questions from: banks in this course's
     * activities, and shared question banks in other courses, with moodle/question:useall in the
     * bank. Private banks of other courses' activities (e.g. a quiz's own questions) never qualify.
     *
     * Ordered shared banks of this course first, then the other banks of this course (such as a
     * quiz's own questions), then shared banks of other courses; by course-module id within each.
     *
     * @return context_module[] bank contexts keyed by course-module id
     */
    protected function get_usable_banks(): array {
        global $DB;

        $courseid = $this->_course->id;
        $sharedmods = \core_question\local\bank\question_bank_helper::get_activity_types_with_shareable_questions();
        $params = ['ctxlevel' => CONTEXT_MODULE, 'courseid' => $courseid];
        $sharedsql = '';
        if ($sharedmods) {
            [$modsql, $modparams] = $DB->get_in_or_equal($sharedmods, SQL_PARAMS_NAMED, 'mod');
            $sharedsql = "OR m.name $modsql";
            $params += $modparams;
        }
        // Banks in this course, plus shared banks anywhere (private banks of other courses never).
        $records = $DB->get_records_sql(
            "SELECT DISTINCT cm.id AS cmid, ctx.id AS contextid, cm.course, m.name AS modname
               FROM {question_categories} qc
               JOIN {context} ctx ON ctx.id = qc.contextid AND ctx.contextlevel = :ctxlevel
               JOIN {course_modules} cm ON cm.id = ctx.instanceid AND cm.deletioninprogress = 0
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid $sharedsql",
            $params
        );
        $rank = function ($record) use ($courseid, $sharedmods): int {
            if ($record->course == $courseid) {
                return in_array($record->modname, $sharedmods, true) ? 0 : 1;
            }
            return 2;
        };
        usort($records, function ($a, $b) use ($rank): int {
            return [$rank($a), (int) $a->cmid] <=> [$rank($b), (int) $b->cmid];
        });

        $banks = [];
        foreach ($records as $record) {
            $context = context::instance_by_id($record->contextid, IGNORE_MISSING);
            if ($context && has_capability('moodle/question:useall', $context)) {
                $banks[(int) $record->cmid] = $context;
            }
        }
        return $banks;
    }

    /**
     * The course-module id of the question bank holding a category, if that category may feed a
     * game in this course (quizgame_category_allowed()).
     *
     * @param int $categoryid question category id, 0 for none
     * @return int course-module id, 0 if none
     */
    protected function get_category_bank_cmid(int $categoryid): int {
        global $DB;

        if (!$categoryid || !quizgame_category_allowed($categoryid, $this->_course->id)) {
            return 0;
        }
        $contextid = $DB->get_field('question_categories', 'contextid', ['id' => $categoryid]);
        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        return $context ? (int) $context->instanceid : 0;
    }

    /**
     * Choose the question bank the form shows.
     *
     * A submitted bank (after Switch bank) is accepted when the user may use it, or when it is the
     * bank of the activity's saved category; otherwise the saved category's bank is shown, and for a
     * new activity (or a category that is gone) the first usable bank.
     *
     * @param context_module[] $banks usable banks keyed by course-module id (get_usable_banks())
     * @param int $savedbank course-module id of the saved category's bank, 0 if none
     * @param int $submitted course-module id submitted in qbankcmid, 0 if none
     * @return int course-module id, 0 if there is no bank to show
     */
    protected static function choose_bank(array $banks, int $savedbank, int $submitted): int {
        if ($submitted > 0 && (isset($banks[$submitted]) || $submitted === $savedbank)) {
            return $submitted;
        }
        if ($savedbank) {
            return $savedbank;
        }
        return (int) (array_key_first($banks) ?? 0);
    }

    /**
     * The name of a question bank as shown on the Question bank line, with its course's short name
     * when the bank is in another course.
     *
     * @param int $cmid the bank's course-module id
     * @return string HTML-safe name
     */
    protected function get_bank_name(int $cmid): string {
        [$course, $cm] = get_course_and_cm_from_cmid($cmid);
        $name = $cm->get_formatted_name();
        if ($course->id != $this->_course->id) {
            $name = get_string('coursenamebankname', 'question', (object) [
                'coursename' => format_string($course->shortname, true, ['context' => context_course::instance($course->id)]),
                'bankname' => $name,
            ]);
        }
        return $name;
    }

    /**
     * Build the question category options: the categories of the bank shown ($this->qbankcmid),
     * plus the game's saved category when it is in a bank the current user may not use, so saving
     * other settings never silently switches the questions.
     *
     * Option keys are "categoryid,contextid", the format the questioncategory field stores.
     *
     * @param context_module[] $banks usable banks keyed by course-module id (get_usable_banks())
     * @param int $savedid the activity's saved category id, 0 if none
     * @param int $savedbank course-module id of the saved category's bank, 0 if none
     * @return array Options for a selectgroups element
     */
    protected function get_category_options(array $banks, int $savedid, int $savedbank): array {
        global $DB;

        $this->allowedcategoryids = [];
        $this->bankcategoryids = [];
        $this->defaultcategorykey = '';
        $categories = [];
        if (isset($banks[$this->qbankcmid])) {
            $bankcontext = $banks[$this->qbankcmid];
            $categories = qbank_managecategories\helper::question_category_options([$bankcontext], false, 0);
            foreach ($categories as $options) {
                foreach (array_keys($options) as $key) {
                    $id = quizgame_get_category_id($key);
                    $this->allowedcategoryids[$id] = true;
                    $this->bankcategoryids[$id] = true;
                    if ($this->defaultcategorykey === '') {
                        $this->defaultcategorykey = (string) $key;
                    }
                }
            }
            $default = question_get_default_category($bankcontext->id);
            if ($default && isset($this->bankcategoryids[$default->id])) {
                $this->defaultcategorykey = $default->id . ',' . $default->contextid;
            }
        }

        // Keep the game's current category selectable even if this editor may not use its bank.
        if ($savedbank && !isset($banks[$savedbank])) {
            $current = $DB->get_record('question_categories', ['id' => $savedid], 'id, name, contextid', MUST_EXIST);
            $categories = [get_string('currentcategory', 'quizgame') => [
                $current->id . ',' . $current->contextid => format_string($current->name),
            ]] + $categories;
            $this->allowedcategoryids[$savedid] = true;
        }
        return $categories;
    }

    /**
     * After Switch bank, select the new bank's default category instead of the previous bank's one.
     */
    public function definition_after_data() {
        parent::definition_after_data();

        $mform = $this->_form;
        if (
            $this->defaultcategorykey === '' || !$mform->elementExists('questioncategory')
            || $this->optional_param('qbankswitch', '', PARAM_RAW) === ''
        ) {
            return;
        }
        $value = $mform->getElement('questioncategory')->getValue();
        $value = is_array($value) ? (string) reset($value) : (string) $value;
        if (!isset($this->bankcategoryids[quizgame_get_category_id($value)])) {
            // A constant, so set_data() does not put the submitted category back. This request
            // never saves (the button is a no-submit button), and the next one starts afresh.
            $mform->setConstant('questioncategory', $this->defaultcategorykey);
        }
    }

    /**
     * Form validation.
     *
     * @param array $data submitted data
     * @param array $files submitted files
     * @return array errors keyed by element name
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $categoryid = quizgame_get_category_id($data['questioncategory'] ?? '');
        if (
            $this->_form->elementExists('questioncategory')
            && (!$categoryid || !isset($this->allowedcategoryids[$categoryid]))
        ) {
            $errors['questioncategory'] = get_string('invalidquestioncategory', 'quizgame');
        }

        if (isset($data['grade']) && $data['grade'] < 0) {
            $errors['grade'] = get_string('scalesnotsupported', 'quizgame');
        }

        if (isset($data['gradepassingscore']) && $data['gradepassingscore'] < 0) {
            $errors['gradepassingscore'] = get_string('gradepassingscorenegative', 'quizgame');
        }

        if (array_key_exists('pointscorrect', $data)) {
            self::validate_range($errors, $data, 'pointscorrect', 1, 100000);
        }
        if (array_key_exists('misspenalty', $data)) {
            self::validate_range($errors, $data, 'misspenalty', 0, 100000);
        }
        if (array_key_exists('wrongpenalties', $data)) {
            $schedule = preg_replace('/\s+/', '', (string) $data['wrongpenalties']);
            $values = explode(',', $schedule);
            $valid = preg_match('/^\d+(,\d+)*$/', $schedule) && count($values) <= 20;
            foreach ($values as $value) {
                if (strlen($value) > 6 || (int) $value > 100000) {
                    $valid = false;
                }
            }
            if (!$valid) {
                $errors['wrongpenalties'] = get_string('errorwrongpenalties', 'quizgame');
            }
        }

        // The Sassy fields are hidden while the toggle is off, so only check them when it is on.
        if (!empty($data['sassy'])) {
            foreach (self::sassy_int_fields() as $field => [, $min, $max]) {
                self::validate_range($errors, $data, $field, $min, $max);
            }
        }

        // Likewise each reward's fields are only checked when rewards and that reward are on.
        if (!empty($data['rewards'])) {
            $ranges = self::reward_int_fields();
            foreach (self::reward_toggles() as $toggle => $fields) {
                if (empty($data[$toggle])) {
                    continue;
                }
                foreach ($fields as $field) {
                    if (isset($ranges[$field])) {
                        [, $min, $max] = $ranges[$field];
                        self::validate_range($errors, $data, $field, $min, $max);
                    }
                }
            }
        }

        foreach (self::difficulty_int_fields() as $field => [, $min, $max]) {
            $extralives = !empty($data['rewards']) && !empty($data['rewardlife']);
            if ($field === 'rewardmaxlives' && !$extralives) {
                continue;
            }
            if (array_key_exists($field, $data)) {
                self::validate_range($errors, $data, $field, $min, $max);
            }
        }
        if (
            !empty($data['rewards']) && !empty($data['rewardlife']) && !isset($errors['rewardmaxlives'])
            && isset($data['rewardmaxlives'], $data['startlives']) && (int) $data['rewardmaxlives'] < (int) $data['startlives']
        ) {
            $errors['rewardmaxlives'] = get_string('errormaxlives', 'quizgame');
        }

        return $errors;
    }

    /**
     * Define custom completion rules
     * @return array
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();

        $completionscoreenabledel = 'completionscoreenabled' . $suffix;
        $completionscoreel = 'completionscore' . $suffix;
        $completionscoregroupel = 'completionscoregroup' . $suffix;

        $group = [];
        $group[] =& $mform->createElement(
            'checkbox',
            $completionscoreenabledel,
            '',
            get_string('completionscore', 'quizgame')
        );
        $group[] =& $mform->createElement('text', $completionscoreel, '', ['size' => 6]);
        $mform->setType($completionscoreel, PARAM_INT);
        $mform->addGroup(
            $group,
            $completionscoregroupel,
            get_string('completionscoregroup', 'quizgame'),
            [' '],
            false
        );
        $mform->disabledIf($completionscoreel, $completionscoreenabledel, 'notchecked');
        $mform->addHelpButton($completionscoregroupel, 'completionscoregroup', 'quizgame');
        return [$completionscoregroupel];
    }

    /**
     * Determines if custom criteria is active.
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        $suffix = $this->get_suffix();
        return !empty($data['completionscoreenabled' . $suffix]) && !empty($data['completionscore' . $suffix]);
    }

    /**
     * Turn off the score completion rule when its checkbox is not ticked.
     *
     * @param stdClass $data passed by reference
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $completion = $data->{'completion' . $suffix} ?? null;
            $autocompletion = !empty($completion) && $completion == COMPLETION_TRACKING_AUTOMATIC;
            if (empty($data->{'completionscoreenabled' . $suffix}) || !$autocompletion) {
                $data->{'completionscore' . $suffix} = 0;
            }
        }
    }

    /**
     * Used to pre-populate mform.
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        global $DB;

        parent::data_preprocessing($defaultvalues);

        // Set up the completion checkboxes which aren't part of standard data.
        $suffix = $this->get_suffix();
        $completionscoreel = 'completionscore' . $suffix;
        $defaultvalues['completionscoreenabled' . $suffix] = !empty($defaultvalues[$completionscoreel]) ? 1 : 0;
        if (empty($defaultvalues[$completionscoreel])) {
            $defaultvalues[$completionscoreel] = 10000;
        }

        if (!isset($defaultvalues['gradepassingscore'])) {
            $defaultvalues['gradepassingscore'] = 0;
        }

        // The enabled Sassy penalties are stored as a comma-separated list of keys; the form shows a
        // checkbox per key. New instances have no list yet and keep the checkboxes' defaults (all on).
        if (isset($defaultvalues['sassypenalties'])) {
            $enabled = array_filter(array_map('trim', explode(',', (string) $defaultvalues['sassypenalties'])));
            foreach (quizgame_sassy_penalty_keys() as $key) {
                $defaultvalues['sassypenalty_' . $key] = in_array($key, $enabled, true) ? 1 : 0;
            }
        }

        // Likewise the enabled bosses.
        if (isset($defaultvalues['sassybosspool'])) {
            $enabled = game_session::ending_pool((string) $defaultvalues['sassybosspool']);
            foreach (quizgame_sassy_ending_keys() as $key) {
                $defaultvalues['sassyending_' . $key] = in_array($key, $enabled, true) ? 1 : 0;
            }
        }

        // The stored context id goes stale when a bank moves (5.0 upgrade, restore), so rebuild the
        // "categoryid,contextid" option key from the category's current context.
        if (!empty($defaultvalues['questioncategory'])) {
            $categoryid = quizgame_get_category_id($defaultvalues['questioncategory']);
            $contextid = $DB->get_field('question_categories', 'contextid', ['id' => $categoryid]);
            if ($contextid) {
                $defaultvalues['questioncategory'] = $categoryid . ',' . $contextid;
            }
        }

        // The bank shown is derived from the stored category in definition() (the category list
        // depends on it); qbankcmid is a constant, this only keeps the defaults complete.
        $defaultvalues['qbankcmid'] = $this->qbankcmid;
    }
}
