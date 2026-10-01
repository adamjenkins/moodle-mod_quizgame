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
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/lib/questionlib.php');
require_once($CFG->dirroot . '/mod/quizgame/locallib.php');

/**
 * Module instance settings form
 * @copyright  2014 John Okely <john@moodle.com>
 * @copyright  2026 Adam Jenkins <hama.history@gmail.com>
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

        // Add standard elements, common to all modules.
        $this->standard_coursemodule_elements();
        // Add standard buttons, common to all modules.
        $this->add_action_buttons();
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
