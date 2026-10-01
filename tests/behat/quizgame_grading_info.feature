@mod @mod_quizgame
Feature: Students see how their game score becomes a grade
  In order to know what to aim for
  As a student
  I need to see the game score that earns the full grade, and my best score.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | 1        | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype     | name | questiontext   |
      | Test questions   | truefalse | TF1  | First question |

  Scenario: The target score and the best score are shown
    Given the following "activities" exist:
      | activity | course | idnumber | name          | questioncategory | grade | gradepassingscore |
      | quizgame | C1     | qg1      | Target game   | Test questions   | 100   | 10000             |
    And user "student1" has played "Target game" with a score of "7500"
    When I am on the "qg1" "Activity" page logged in as "student1"
    Then I should see "Reach a game score of 10000 to earn the full grade of 100."
    And I should see "Your best score so far: 7500"

  Scenario: Without a target the raw score is the grade
    Given the following "activities" exist:
      | activity | course | idnumber | name     | questioncategory | grade | gradepassingscore |
      | quizgame | C1     | qg2      | Raw game | Test questions   | 50    | 0                 |
    When I am on the "qg2" "Activity" page logged in as "student1"
    Then I should see "Your best game score is your grade, up to the maximum grade of 50."
    And I should not see "Your best score so far"

  Scenario: Ungraded games show no grading information
    Given the following "activities" exist:
      | activity | course | idnumber | name          | questioncategory | grade |
      | quizgame | C1     | qg3      | Ungraded game | Test questions   | 0     |
    When I am on the "qg3" "Activity" page logged in as "student1"
    Then I should not see "Reach a game score"
    And I should not see "Your best game score is your grade"
