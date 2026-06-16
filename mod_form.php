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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/lib/questionlib.php');

/**
 * Module instance settings form
 * @copyright  2014 John Okely <john@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_quizgame_mod_form extends moodleform_mod {
    /**
     * Defines forms elements
     */
    public function definition() {
        global $CFG, $COURSE, $DB;

        $mform = $this->_form;

        // Adding the "general" fieldset, where all the common settings are showed.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Adding the standard "name" field.
        $mform->addElement('text', 'name', get_string('quizgamename', 'quizgame'), ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEAN);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'quizgamename', 'quizgame');

        // Adding the standard "intro" and "introformat" fields.
        if ($CFG->branch >= 29) {
            $this->standard_intro_elements();
        } else {
            $this->add_intro_editor();
        }

        // Moodle 5.x question_category_options only accepts CONTEXT_MODULE contexts.
        // Collect all module-level contexts within this course that contain question categories.
        $contextids = $DB->get_fieldset_sql(
            "SELECT DISTINCT qc.contextid
               FROM {question_categories} qc
               JOIN {context} ctx ON ctx.id = qc.contextid AND ctx.contextlevel = :ctxlevel
               JOIN {course_modules} cm ON cm.id = ctx.instanceid AND cm.course = :courseid",
            ['ctxlevel' => CONTEXT_MODULE, 'courseid' => $COURSE->id]
        );
        if ($contextids) {
            $contexts = array_map(fn($id) => context::instance_by_id($id), $contextids);
            $categories = qbank_managecategories\helper::question_category_options($contexts, false, 0);
        } else {
            $categories = [];
        }

        $mform->addElement('selectgroups', 'questioncategory', get_string('questioncategory', 'quizgame'), $categories);
        $mform->addHelpButton('questioncategory', 'questioncategory', 'quizgame');

        // Grade settings: type/max, category, grade-to-pass (standard Moodle grade elements).
        $this->standard_grading_coursemodule_elements();

        // Target game score: the game score that earns the maximum grade.
        $mform->addElement('text', 'gradepassingscore', get_string('gradepassingscore', 'quizgame'), ['size' => '8']);
        $mform->setType('gradepassingscore', PARAM_INT);
        $mform->setDefault('gradepassingscore', 0);
        $mform->addHelpButton('gradepassingscore', 'gradepassingscore', 'quizgame');
        $mform->hideIf('gradepassingscore', 'grade[modgrade_type]', 'eq', 'none');

        // Add standard elements, common to all modules.
        $this->standard_coursemodule_elements();
        // Add standard buttons, common to all modules.
        $this->add_action_buttons();
    }

    /**
     * Define custom completion rules
     * @return array
     */
    public function add_completion_rules() {
        $mform =& $this->_form;
        $group = [];
        $group[] =& $mform->createElement(
            'checkbox',
            'completionscoreenabled',
            '',
            get_string('completionscore', 'quizgame')
        );
        $group[] =& $mform->createElement('text', 'completionscore', '', ['size' => 3]);
        $mform->setType('completionscore', PARAM_INT);
        $mform->addGroup(
            $group,
            'completionscoregroup',
            get_string('completionscoregroup', 'quizgame'),
            [' '],
            false
        );
        $mform->disabledIf('completionscore', 'completionscoreenabled', 'notchecked');
        $mform->addHelpButton('completionscoregroup', 'completionscoregroup', 'quizgame');
        return ['completionscoregroup'];
    }

    /**
     * Determines if custom criteria is active.
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return (!empty($data['completionscoreenabled']) && $data['completionscore'] != 0);
    }

    /**
     * Loads custom completion data.
     * @return boolean
     */
    public function get_data() {
        $data = parent::get_data();
        if (!$data) {
            return false;
        }
        if (!empty($data->completionunlocked)) {
            // Turn off completion settings if the checkboxes aren't ticked.
            $autocompletion = !empty($data->completion) && $data->completion == COMPLETION_TRACKING_AUTOMATIC;
            if (empty($data->completionscoreenabled) || !$autocompletion) {
                $data->completionscore = 0;
            }
        }
        return $data;
    }

    /**
     * Used to pre-populate mform.
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);

        // Set up the completion checkboxes which aren't part of standard data.
        if (!empty($defaultvalues['completionscore'])) {
            $defaultvalues['completionscoreenabled'] = 1;
        } else {
            $defaultvalues['completionscoreenabled'] = 0;
        }
        if (empty($defaultvalues['completionscore'])) {
            $defaultvalues['completionscore'] = 10000;
        }

        if (!isset($defaultvalues['gradepassingscore'])) {
            $defaultvalues['gradepassingscore'] = 0;
        }
    }
}
