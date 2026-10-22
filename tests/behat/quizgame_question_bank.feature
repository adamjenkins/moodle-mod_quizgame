@mod @mod_quizgame
Feature: Teachers choose the question bank and category of a quizgame
  As a teacher
  I need to switch between the question banks of my course and pick a category of the bank shown.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | 1        | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category | summary |
      | Course 1 | C1        | 0        |         |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "activities" exist:
      | activity | name    | intro           | course | idnumber |
      | qbank    | Qbank 1 | Question bank 1 | C1     | qbank1   |
      | qbank    | Qbank 2 | Question bank 2 | C1     | qbank2   |
    And the following "question categories" exist:
      | contextlevel    | reference | name             |
      | Activity module | qbank1    | Bank 1 questions |
      | Activity module | qbank2    | Bank 2 questions |
    And the following "questions" exist:
      | questioncategory | qtype     | name | questiontext   |
      | Bank 1 questions | truefalse | TF1  | First question |
      | Bank 2 questions | truefalse | TF2  | Other question |

  @javascript
  Scenario: Switch bank lists the other bank's categories, and the chosen category is saved
    Given I log in as "teacher1"
    And I add a quizgame activity to course "Course 1" section "1"
    And I set the field "Quizventure name" to "Bank quizventure"
    Then I should see "Qbank 1" in the "#fgroup_id_qbankgroup" "css_element"
    And the "Question category" select box should contain "Bank 1 questions (1)"
    And the "Question category" select box should not contain "Bank 2 questions (1)"
    When I click on "Switch bank" "button"
    And I click on "Qbank 2" "link" in the "Select question bank" "dialogue"
    Then I should see "Qbank 2" in the "#fgroup_id_qbankgroup" "css_element"
    And the "Question category" select box should contain "Bank 2 questions (1)"
    And the "Question category" select box should not contain "Bank 1 questions (1)"
    # The values entered before switching are kept.
    And the field "Quizventure name" matches value "Bank quizventure"
    And I set the field "Question category" to "Bank 2 questions"
    And I press "Save and return to course"
    And I am on the "Bank quizventure" "quizgame activity editing" page
    Then I should see "Qbank 2" in the "#fgroup_id_qbankgroup" "css_element"
    And the field "Question category" matches value "Bank 2 questions (1)"
    And the "Question category" select box should not contain "Bank 1 questions (1)"

  @javascript
  Scenario: Going back in the bank switcher keeps the bank shown
    Given I log in as "teacher1"
    And I add a quizgame activity to course "Course 1" section "1"
    When I click on "Switch bank" "button"
    And I click on "Go back" "button" in the "Select question bank" "dialogue"
    Then I should see "Qbank 1" in the "#fgroup_id_qbankgroup" "css_element"
    And the "Question category" select box should contain "Bank 1 questions (1)"
