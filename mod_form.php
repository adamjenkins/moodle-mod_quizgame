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
        global $CFG, $COURSE;

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

        // Get the appropriate context for category selection.
        $context = null;
        if (!empty($this->current->instance)) {
            // Editing existing: Use module context.
            $cm = get_coursemodule_from_instance('quizgame', $this->current->instance, $COURSE->id, false, MUST_EXIST);
            $context = context_module::instance($cm->id);
        } else {
            // Creating new: Use course context.
            $context = context_course::instance($COURSE->id);
        }

        // Add logging for the determined context.
        echo '<!-- Quizgame mod_form: Determined context ID: ' . $context->id . ' -->';
        error_log('Quizgame mod_form: Determined context ID: ' . $context->id);
        
        // Debug: List available question categories using direct DB query
        global $DB;
        
        // First, show context information
        echo '<!-- Debug: Context ID: ' . $context->id . ' -->';
        echo '<!-- Debug: Context path: ' . $context->path . ' -->';
        
        // Get context information
        $contextinfo = $DB->get_record('context', ['id' => $context->id], '*', MUST_EXIST);
        echo '<!-- Debug: Context details: ' . print_r($contextinfo, true) . ' -->';
        
        // Check if question_categories table exists
        $dbman = $DB->get_manager();
        $table = new xmldb_table('question_categories');
        if ($dbman->table_exists($table)) {
            echo '<!-- Debug: question_categories table exists -->';
            
            // Get categories in this context
            $categories = $DB->get_records('question_categories', ['contextid' => $context->id]);
            echo '<!-- Debug: Found ' . count($categories) . ' categories in current context -->';
            
            // Get all categories to check if any exist at all
            $allcategories = $DB->get_records_sql('SELECT * FROM {question_categories} ORDER BY contextid, name', null, 0, 5);
            echo '<!-- Debug: First few categories in system: ' . print_r($allcategories, true) . ' -->';
            
            // Get parent context IDs
            $parentcontexts = $context->get_parent_context_ids();
            echo '<!-- Debug: Parent context IDs: ' . print_r($parentcontexts, true) . ' -->';
            
            // Try to find any categories in parent contexts
            if (!empty($parentcontexts)) {
                list($insql, $inparams) = $DB->get_in_or_equal($parentcontexts, SQL_PARAMS_NAMED);
                $parentcategories = $DB->get_records_sql(
                    "SELECT * FROM {question_categories} WHERE contextid $insql ORDER BY contextid, name", 
                    $inparams
                );
                echo '<!-- Debug: Found ' . count($parentcategories) . ' categories in parent contexts -->';
            }
        } else {
            echo '<!-- Debug: question_categories table does not exist! -->';
        }
        
        echo '<!-- End of category debug -->';

        // Add the standard question category selector element.
        // Include contexts where we know categories exist (16 and 18) along with the current context
        $contexts = [
            $context,  // Current context (17)
            context::instance_by_id(16),  // Wingfoiling course question bank
            context::instance_by_id(18)   // Wingfoil question bank
        ];
        
        $mform->addElement('questioncategory', 'questioncategory', get_string('questioncategory', 'quizgame'), [
            'contexts' => $contexts,
        ]);
        
        // Add help text to show where the categories are being pulled from
        $mform->addHelpButton('questioncategory', 'questioncategory', 'quizgame', '', true);
        echo '<!-- Quizgame mod_form: Added questioncategory element with context ID: ' . $context->id . ' -->';
        error_log('Quizgame mod_form: Added questioncategory element with context ID: ' . $context->id);
        $mform->addHelpButton('questioncategory', 'questioncategory', 'quizgame');
        $mform->addRule('questioncategory', null, 'required', null, 'client'); // Ensure a category is selected.

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
        $mform = &$this->_form;
        $group = [];
        $group[] = &$mform->createElement(
            'checkbox',
            'completionscoreenabled',
            '',
            get_string('completionscore', 'quizgame')
        );
        $group[] = &$mform->createElement('text', 'completionscore', '', ['size' => 3]);
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
        // We also make the default value (if you turn on the checkbox) for those
        // numbers to be 1, this will not apply unless checkbox is ticked.
        if (!empty($defaultvalues['completionscore'])) {
            $defaultvalues['completionscoreenabled'] = 1;
        } else {
            $defaultvalues['completionscoreenabled'] = 0;
        }
        if (empty($defaultvalues['completionscore'])) {
            $defaultvalues['completionscore'] = 10000;
        }
    }
}
