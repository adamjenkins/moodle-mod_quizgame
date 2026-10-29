@mod @mod_quizgame
Feature: Teachers can set the scoring and Sassy enhancements of a quizgame
  As a teacher
  I need the Sassy settings to appear only when I enable them, with sensible defaults.

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
  Scenario: The Sassy fields are hidden until the toggle is ticked, and the defaults are saved
    Given I log in as "teacher1"
    And I add a quizgame activity to course "Course 1" section "1"
    And I expand all fieldsets
    Then the following fields match these values:
      | Points for a correct hit   | 1000 |
      | Wrong-hit penalty schedule | 300  |
      | Miss penalty               | 0    |
      | Sassy enhancements         | 0    |
    And I should not see "Wrong hits to trigger cheater mode"
    And I should not see "Clean questions to leave cheater mode"
    And I should not see "Offences before the boss finale"
    And I should not see "Enabled penalties"
    And I should not see "Penalty selection"
    And I should not see "Maximum decoy ships"
    And I should not see "Enabled bosses"
    And I should not see "Swarm size"
    And I should not see "Taunt messages"
    And I should not see "Decoy ship labels"
    And I should not see "Boss finale lines"
    And I set the following fields to these values:
      | Quizventure name          | Sassy quizventure |
      | Question category         | Test questions    |
      | Sassy enhancements        | 1                 |
    And I should see "Wrong hits to trigger cheater mode"
    And I should see "Enabled penalties"
    And I should see "Enabled bosses"
    And I should see "Taunt messages"
    # Every boss starts enabled, so the swarm size and each boss's text show.
    And I should see "Swarm size"
    And I should see "Jack-o'-lantern text"
    And the following fields match these values:
      | Wrong hits to trigger cheater mode         | 3        |
      | Clean questions to leave cheater mode      | 1        |
      | Offences before the boss finale            | 4        |
      | id_sassyreadtime                           | 5        |
      | Decoy ships                                | 1        |
      | Reversed controls                          | 1        |
      | Dodge round                                | 1        |
      | Return fire                                | 1        |
      | id_sassypenalty_drain                      | 1        |
      | Penalty selection                          | In order |
      | Maximum decoy ships                        | 3        |
      | Score drain start rate (points per second) | 100      |
      | id_sassydrainincrease                      | 50       |
      | id_sassyending_big                         | 1        |
      | id_sassyending_pumpkin                     | 1        |
    And I press "Save and return to course"
    And I am on the "Sassy quizventure" "quizgame activity editing" page
    And I expand all fieldsets
    And the following fields match these values:
      | Points for a correct hit                   | 1000     |
      | Wrong-hit penalty schedule                 | 300      |
      | Miss penalty                               | 0        |
      | Sassy enhancements                         | 1        |
      | Wrong hits to trigger cheater mode         | 3        |
      | Clean questions to leave cheater mode      | 1        |
      | Offences before the boss finale            | 4        |
      | id_sassyreadtime                           | 5        |
      | Decoy ships                                | 1        |
      | Reversed controls                          | 1        |
      | Dodge round                                | 1        |
      | Return fire                                | 1        |
      | id_sassypenalty_drain                      | 1        |
      | Penalty selection                          | In order |
      | Maximum decoy ships                        | 3        |
      | Score drain start rate (points per second) | 100      |
      | id_sassydrainincrease                      | 50       |
      | id_sassyending_big                         | 1        |
      | id_sassyending_pumpkin                     | 1        |
    And the field "Swarm size" matches value "20"
    And I set the field "id_sassyending_swarm" to "0"
    And I should not see "Swarm size"

  @javascript
  Scenario: Sassy enhancements saved off stay off and hidden
    Given I log in as "teacher1"
    And I add a quizgame activity to course "Course 1" section "1" and I fill the form with:
      | Quizventure name  | Plain quizventure |
      | Question category | Test questions    |
    And I am on the "Plain quizventure" "quizgame activity editing" page
    And I expand all fieldsets
    Then the field "Sassy enhancements" matches value "0"
    And I should not see "Wrong hits to trigger cheater mode"
    And I should not see "Taunt messages"

  @javascript
  Scenario: Teachers switch each boss on or off and edit its text
    Given I log in as "teacher1"
    And I add a quizgame activity to course "Course 1" section "1"
    And I expand all fieldsets
    And I set the following fields to these values:
      | Quizventure name   | Boss quizventure |
      | Question category  | Test questions   |
      | Sassy enhancements | 1                |
    Then the following fields match these values:
      | id_sassyending_big          | 1                                                  |
      | id_sassyending_swarm        | 1                                                  |
      | id_sassyending_tow          | 1                                                  |
      | id_sassyending_selfdestruct | 1                                                  |
      | id_sassyending_blackhole    | 1                                                  |
      | id_sassyending_pumpkin      | 1                                                  |
      | Tow truck text              | Illegally parked in the guessing zone.             |
      | Black hole text             | Your points have crossed the event horizon.        |
    And I should not see "Score eater"
    And I set the field "id_sassyending_tow" to "0"
    And I should not see "Tow truck text"
    And I set the following fields to these values:
      | id_sassyending_big          | 0             |
      | id_sassyending_swarm        | 0             |
      | id_sassyending_selfdestruct | 0             |
      | id_sassyending_blackhole    | 0             |
      | Jack-o'-lantern text        | Boo! \|\| Yum |
    And I press "Save and return to course"
    And I am on the "Boss quizventure" "quizgame activity editing" page
    And I expand all fieldsets
    And the following fields match these values:
      | id_sassyending_big          | 0          |
      | id_sassyending_swarm        | 0          |
      | id_sassyending_tow          | 0          |
      | id_sassyending_selfdestruct | 0          |
      | id_sassyending_blackhole    | 0          |
      | id_sassyending_pumpkin      | 1          |
      | Jack-o'-lantern text        | Boo! \|\| Yum |
    # No boss at all is allowed: the boss finale is then off.
    And I set the field "id_sassyending_pumpkin" to "0"
    And I should not see "Jack-o'-lantern text"
    And I press "Save and return to course"
    And I am on the "Boss quizventure" "quizgame activity editing" page
    And I expand all fieldsets
    And the field "id_sassyending_pumpkin" matches value "0"
