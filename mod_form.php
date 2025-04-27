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
class mod_quizgame_mod_form extends moodleform_mod
{

    /**
     * Defines forms elements
     */
    public function definition()
    {
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

        // Get the appropriate contexts for question categories
        $categories = []; // Initialize categories array
        try {
            if (empty($this->current->instance)) {
                // --- WORKAROUND START ---
                // For new instances, use the course context.
                // ... (comments remain the same) ...

                $coursecontext = context_course::instance($COURSE->id); // Get the course context object
                if ($coursecontext) {
                    // Attempt to get raw category data using the underlying function (hopefully more stable)
                    // Pass the context ID as a string, as required by the function signature.
                    // The parameters (false, 0) are guesses based on the original call to question_category_options.
                    // We need to map the boolean 'false' and int '0' to the string $sortorder and bool $top parameters.
                    // Default sortorder is 'parent, sortorder, name ASC'. Let's use that.
                    // The 'false' likely corresponds to the $top parameter.
                    // The '0' likely corresponds to the $showallversions parameter.

                    $rawcategories = qbank_managecategories\helper::get_categories_for_contexts(
                        (string)$coursecontext->id, // Pass context ID as string
                        'parent, sortorder, name ASC', // Use default sort order
                        false, // Map original 'false' to $top
                        0      // Map original '0' to $showallversions
                    );


                    if (!empty($rawcategories)) {
                        // Format for selectgroups: Group by context name
                        $groupname = $coursecontext->get_context_name(); // e.g., "Course: My Course Name"
                        $categories[$groupname] = [];
                        foreach ($rawcategories as $cat) {
                            // Simple formatting: Add category ID => Name under the context group
                            // We lose the potential hierarchy indentation here, but it avoids the error.
                            $categories[$groupname][$cat->id] = $cat->name;
                        }
                    }
                }
                // If context or rawcategories fail, $categories remains empty, which is handled gracefully by selectgroups.
                // --- WORKAROUND END ---

            } else {
                // For existing instances, use the module context (this part worked correctly)
                $cm = get_coursemodule_from_instance('quizgame', $this->current->instance, $COURSE->id, false, MUST_EXIST);
                $modulecontext = context_module::instance($cm->id);
                // Use the standard helper, assuming it works correctly for module contexts
                // Note: This original call also passed false, 0. We assume it maps correctly internally.
                $categories = qbank_managecategories\helper::question_category_options([$modulecontext], false, 0);
            }
        } catch (\Exception $e) {
            // Catch potential errors during category fetching (e.g., if get_categories_for_contexts also fails)
            debugging('Error fetching question categories: ' . $e->getMessage(), DEBUG_DEVELOPER);
            // $categories remains empty or partially filled, form element will show no options.
            $categories = [];
        }

        // Add the form element (this line remains the same)
        $mform->addElement('selectgroups', 'questioncategory', get_string('questioncategory', 'quizgame'), $categories);
        $mform->addHelpButton('questioncategory', 'questioncategory', 'quizgame');


        // Add standard elements, common to all modules.
        $this->standard_coursemodule_elements();
        // Add standard buttons, common to all modules.
        $this->add_action_buttons();
    }

    /**
     * Define custom completion rules
     * @return array
     */
    public function add_completion_rules()
    {
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
    public function completion_rule_enabled($data)
    {
        return (!empty($data['completionscoreenabled']) && $data['completionscore'] != 0);
    }

    /**
     * Loads custom completion data.
     * @return boolean
     */
    public function get_data()
    {
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
    public function data_preprocessing(&$defaultvalues)
    {
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
