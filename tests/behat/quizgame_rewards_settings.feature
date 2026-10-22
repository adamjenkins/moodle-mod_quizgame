@mod @mod_quizgame
Feature: Teachers can set the rewards for good play of a quizgame
  As a teacher
  I need each reward's settings to appear only while that reward is on, with sensible defaults.

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
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype     | name | questiontext   |
      | Test questions   | truefalse | TF1  | First question |

  @javascript
  Scenario: The rewards fields hide and show with their toggles, and the defaults are saved
    Given I log in as "teacher1"
    And I add a quizgame activity to course "Course 1" section "1"
    And I expand all fieldsets
    Then the following fields match these values:
      | Rewards for good play                    | 1    |
      | Enable combo multiplier                  | 1    |
      | Maximum multiplier                       | 4    |
      | Enable extra life                        | 1    |
      | Clean questions in a row per extra life  | 5    |
      | Maximum lives                            | 5    |
      | Enable bonus round                       | 1    |
      | Clean questions in a row for bonus round | 5    |
      | Points per bonus ship                    | 1000 |
      | Enable praise messages                   | 1    |
    And "#id_rewardpraiselines" "css_element" should be visible
    # Each reward's own fields follow its toggle.
    And I set the field "Enable combo multiplier" to "0"
    And "#id_rewardmaxmult" "css_element" should not be visible
    And "#id_rewardlifestreak" "css_element" should be visible
    And I set the field "Enable extra life" to "0"
    And "#id_rewardlifestreak" "css_element" should not be visible
    And "#id_rewardmaxlives" "css_element" should not be visible
    And I set the field "Enable bonus round" to "0"
    And "#id_rewardbonusstreak" "css_element" should not be visible
    And "#id_rewardbonuspoints" "css_element" should not be visible
    And I set the field "Enable praise messages" to "0"
    And "#id_rewardpraiselines" "css_element" should not be visible
    And I set the following fields to these values:
      | Enable combo multiplier | 1 |
      | Enable extra life       | 1 |
      | Enable bonus round      | 1 |
      | Enable praise messages  | 1 |
    And "#id_rewardmaxmult" "css_element" should be visible
    And "#id_rewardmaxlives" "css_element" should be visible
    And "#id_rewardbonuspoints" "css_element" should be visible
    And "#id_rewardpraiselines" "css_element" should be visible
    # The master toggle hides every reward field.
    And I set the field "Rewards for good play" to "0"
    And "#id_rewardcombo" "css_element" should not be visible
    And "#id_rewardmaxmult" "css_element" should not be visible
    And "#id_rewardlife" "css_element" should not be visible
    And "#id_rewardlifestreak" "css_element" should not be visible
    And "#id_rewardmaxlives" "css_element" should not be visible
    And "#id_rewardbonus" "css_element" should not be visible
    And "#id_rewardbonusstreak" "css_element" should not be visible
    And "#id_rewardbonuspoints" "css_element" should not be visible
    And "#id_rewardpraise" "css_element" should not be visible
    And "#id_rewardpraiselines" "css_element" should not be visible
    And I set the field "Rewards for good play" to "1"
    And "#id_rewardcombo" "css_element" should be visible
    And "#id_rewardmaxmult" "css_element" should be visible
    And I set the following fields to these values:
      | Quizventure name  | Rewarding quizventure |
      | Question category | Test questions        |
    And I press "Save and return to course"
    And I am on the "Rewarding quizventure" "quizgame activity editing" page
    And I expand all fieldsets
    And the following fields match these values:
      | Rewards for good play                    | 1    |
      | Enable combo multiplier                  | 1    |
      | Maximum multiplier                       | 4    |
      | Enable extra life                        | 1    |
      | Clean questions in a row per extra life  | 5    |
      | Maximum lives                            | 5    |
      | Enable bonus round                       | 1    |
      | Clean questions in a row for bonus round | 5    |
      | Points per bonus ship                    | 1000 |
      | Enable praise messages                   | 1    |
    And the field "id_rewardpraiselines" matches multiline:
      """
      Look who actually read the question!
      Now THAT's how you play!
      Brains AND reflexes? Impressive.
      Combo! Keep it going!
      You're on fire!
      Reading pays off, doesn't it?
      """

  @javascript
  Scenario: Rewards saved off stay off and hidden
    Given I log in as "teacher1"
    And I add a quizgame activity to course "Course 1" section "1" and I fill the form with:
      | Quizventure name      | Quiet quizventure |
      | Question category     | Test questions    |
      | Rewards for good play | 0                 |
    And I am on the "Quiet quizventure" "quizgame activity editing" page
    And I expand all fieldsets
    Then the field "Rewards for good play" matches value "0"
    And "#id_rewardcombo" "css_element" should not be visible
    And "#id_rewardmaxmult" "css_element" should not be visible
    And "#id_rewardpraiselines" "css_element" should not be visible
