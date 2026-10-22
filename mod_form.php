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

/**
 * Module instance settings form
 * @copyright  2014 John Okely <john@moodle.com>
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_quizgame_mod_form extends moodleform_mod {
    /** @var array|null Category ids the current user may choose, keyed by id (see get_category_options()). */
    protected $allowedcategoryids = null;

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

        $categories = $this->get_category_options();
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

        $mform->addElement(
            'advcheckbox',
            'questioncategorysubcats',
            '',
            get_string('questioncategorysubcats', 'quizgame')
        );
        $mform->addHelpButton('questioncategorysubcats', 'questioncategorysubcats', 'quizgame');

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
                $mform->addElement('select', 'sassybossstyle', get_string('sassybossstyle', 'quizgame'), [
                    0 => get_string('sassybossstyle_big', 'quizgame'),
                    1 => get_string('sassybossstyle_swarm', 'quizgame'),
                    2 => get_string('sassybossstyle_random', 'quizgame'),
                ]);
                $mform->setDefault('sassybossstyle', 0);
                $mform->addHelpButton('sassybossstyle', 'sassybossstyle', 'quizgame');
                $sassyfields[] = 'sassybossstyle';
            }
        }

        foreach (['sassytaunts', 'sassydecoylabels', 'sassybosslines'] as $field) {
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
        // The swarm size only matters when a swarm can happen.
        $mform->hideIf('sassyswarmsize', 'sassybossstyle', 'eq', 0);
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
                if ($field === 'rewardpraiselines') {
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
            'rewardcombo' => ['rewardmaxmult'],
            'rewardlife' => ['rewardlifestreak', 'rewardmaxlives'],
            'rewardbonus' => ['rewardbonusstreak', 'rewardbonuspoints'],
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
            // A game starts with 3 lives, so a lower maximum would never award one.
            'rewardmaxlives' => [5, 3, 10],
            'rewardbonusstreak' => [5, 1, 50],
            'rewardbonuspoints' => [1000, 1, 100000],
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
     * Build the question category options: categories of question banks in this course's
     * activities, and of shared question banks in other courses, that the current user may use
     * questions from.
     *
     * Option keys are "categoryid,contextid", the format the questioncategory field stores.
     *
     * @return array Options for a selectgroups element
     */
    protected function get_category_options(): array {
        global $COURSE, $DB;

        $this->allowedcategoryids = [];
        $sharedmods = \core_question\local\bank\question_bank_helper::get_activity_types_with_shareable_questions();
        $params = ['ctxlevel' => CONTEXT_MODULE, 'courseid' => $COURSE->id];
        $sharedsql = '';
        if ($sharedmods) {
            [$modsql, $modparams] = $DB->get_in_or_equal($sharedmods, SQL_PARAMS_NAMED, 'mod');
            $sharedsql = "OR m.name $modsql";
            $params += $modparams;
        }
        // Banks in this course, plus shared banks anywhere (private banks of other courses never).
        $contextids = $DB->get_fieldset_sql(
            "SELECT DISTINCT qc.contextid
               FROM {question_categories} qc
               JOIN {context} ctx ON ctx.id = qc.contextid AND ctx.contextlevel = :ctxlevel
               JOIN {course_modules} cm ON cm.id = ctx.instanceid AND cm.deletioninprogress = 0
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid $sharedsql",
            $params
        );
        $contexts = [];
        foreach ($contextids as $contextid) {
            $context = context::instance_by_id($contextid, IGNORE_MISSING);
            if ($context && has_capability('moodle/question:useall', $context)) {
                $contexts[] = $context;
            }
        }
        $categories = $contexts ? qbank_managecategories\helper::question_category_options($contexts, false, 0) : [];
        foreach ($categories as $options) {
            foreach (array_keys($options) as $key) {
                $this->allowedcategoryids[quizgame_get_category_id($key)] = true;
            }
        }

        // Keep the game's current category selectable even if this editor may not use its bank,
        // so saving other settings never silently switches the questions.
        $currentid = quizgame_get_category_id($this->current->questioncategory ?? '');
        if ($currentid && !isset($this->allowedcategoryids[$currentid]) && quizgame_category_allowed($currentid, $COURSE->id)) {
            $current = $DB->get_record('question_categories', ['id' => $currentid], 'id, name, contextid', MUST_EXIST);
            $categories = [get_string('currentcategory', 'quizgame') => [
                $current->id . ',' . $current->contextid => format_string($current->name),
            ]] + $categories;
            $this->allowedcategoryids[$currentid] = true;
        }
        return $categories;
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

        // The stored context id goes stale when a bank moves (5.0 upgrade, restore), so rebuild the
        // "categoryid,contextid" option key from the category's current context.
        if (!empty($defaultvalues['questioncategory'])) {
            $categoryid = quizgame_get_category_id($defaultvalues['questioncategory']);
            $contextid = $DB->get_field('question_categories', 'contextid', ['id' => $categoryid]);
            if ($contextid) {
                $defaultvalues['questioncategory'] = $categoryid . ',' . $contextid;
            }
        }
    }
}
