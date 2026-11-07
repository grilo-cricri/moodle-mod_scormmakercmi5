@mod @mod_scormmakercmi5 @javascript
Feature: Basic report of a cmi5 activity
  In order to follow the learners of any cmi5 course
  As a teacher
  I need a report of the learners, restricted to my groups, with the detail of each learner and the actions on it

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | tutor1   | Tutor     | One      | tutor1@example.com   |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | tutor1   | C1     | teacher        |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group 1 | C1     | G1       |
      | Group 2 | C1     | G2       |
    And the following "group members" exist:
      | user     | group |
      | tutor1   | G1    |
      | student1 | G1    |
      | student2 | G2    |
    And the following "activities" exist:
      | activity       | course | idnumber | name      | package | groupmode |
      | scormmakercmi5 | C1     | cmi5a    | Activity one | passed  | 1         |

  Scenario: A learner cannot open the report
    When I am on the "Activity one" "scormmakercmi5 activity" page logged in as "student1"
    Then opening the cmi5 "Report" page of "cmi5a" should show "Sorry, but you do not currently have permissions to do that"

  @accessibility
  Scenario: A teacher in separate groups sees only the learners of their group
    When I am on the "cmi5a" "mod_scormmakercmi5 > Report" page logged in as "tutor1"
    Then I should see "Student One" in the "generaltable" "table"
    And I should not see "Student Two"
    And I should see "Not started" in the "Student One" "table_row"
    And the "Status" select box should contain "Satisfied"
    And the page should meet accessibility standards
    And I set the field "Status" to "Satisfied"
    And I should see "Nothing to display"
    And opening the cmi5 learner page of "student2" in "cmi5a" should show "Sorry, but you do not currently have permissions to do that"
    And opening the cmi5 learner page of "student2" in "cmi5a" should not show "Student Two"
    And opening the cmi5 learner page of "student1" in "cmi5a" should show "Student One"
    And I am on the "cmi5a" "mod_scormmakercmi5 > Report" page logged in as "teacher1"
    And I should see "Student One"
    And I should see "Student Two"

  @accessibility
  Scenario: The teacher opens the detail of a learner and deletes the attempt
    Given the following "mod_scormmakercmi5 > registrations" exist:
      | activity | user     | satisfied |
      | cmi5a    | student1 | 1         |
    And I am on the "Activity one" "scormmakercmi5 activity" page logged in as "teacher1"
    And I navigate to "Reports" in current page administration
    And I should see "Satisfied" in the "Student One" "table_row"
    When I follow "Student One"
    Then I should see "Satisfied"
    And the page should meet accessibility standards
    And I follow "Delete attempt"
    And I press "Delete attempt"
    And I should see "The attempt of Student One was deleted."
    And I should see "Not started"
    And I should not see "Satisfied"
    And "Delete attempt" "link" should not exist
    And "student1" should not have a cmi5 attempt in "cmi5a"

  @accessibility
  Scenario: The teacher waives a unit from the detail of a learner and sees its statements
    Given I am on the "cmi5a" "mod_scormmakercmi5 > Report" page logged in as "teacher1"
    And I follow "Student One"
    When I click on "Waive" "link" in the "Unidade 1" "table_row"
    And I set the field "Reason" to "Administrative"
    And I press "Waive"
    Then I should see "The unit \"Unidade 1\" was waived."
    And I should see "Waived" in the "Unidade 1" "table_row"
    And I should see "Administrative" in the "Unidade 1" "table_row"
    And I should see "Not started" in the "Unidade 2" "table_row"
    And "Waive" "link" should not exist in the "Unidade 1" "table_row"
    And "Waive" "link" should exist in the "Unidade 2" "table_row"
    And I follow "View statements"
    And I should see "waived"
    And the page should meet accessibility standards
