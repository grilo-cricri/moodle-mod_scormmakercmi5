@mod @mod_scormmakercmi5 @_file_upload @javascript
Feature: Grade in points and completion by satisfying the cmi5 course
  In order to see the cmi5 result in the gradebook and in the activity completion
  As a teacher
  I need a grade in points and the completion rule "satisfy the cmi5 course"

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |

  Scenario: A scale is refused: the grade comes from the score of the units
    Given I log in as "teacher1"
    And I add a "scormmakercmi5" activity to course "Course 1" section "1"
    And I upload "mod/scormmakercmi5/tests/fixtures/packages/minimal.zip" file to "Package file" filemanager
    And I expand all fieldsets
    And I set the field "grade[modgrade_type]" to "Scale"
    When I press "Save and display"
    Then I should see "This activity grades in points"
    And I set the field "grade[modgrade_type]" to "Point"
    And I press "Save and display"
    And I should see "Curso mínimo"

  Scenario: A satisfied course whose completion was not updated is completed when the learner views the activity
    # The generator creates the registration without launching: the course (one NotApplicable AU) is satisfied,
    # but nothing has updated the completion yet, as when the update after the commit fails.
    Given the following "activities" exist:
      | activity       | course | idnumber | name      | completion | completionview | completionsatisfied |
      | scormmakercmi5 | C1     | cmi5a    | Activity one | 2          | 0              | 1                   |
    And the following "mod_scormmakercmi5 > registrations" exist:
      | activity | user     |
      | cmi5a    | student1 |
    And I am on the "Course 1" course page logged in as "teacher1"
    And I navigate to "Reports" in current page administration
    And I click on "Activity completion" "link"
    And "Student One, Activity one: Not completed" "icon" should exist in the "Student One" "table_row"
    And I log out
    When I am on the "Activity one" "scormmakercmi5 activity" page logged in as "student1"
    Then the "Satisfy the cmi5 course" completion condition of "Activity one" is displayed as "done"
    And I log out
    And I am on the "Course 1" course page logged in as "teacher1"
    And I navigate to "Reports" in current page administration
    And I click on "Activity completion" "link"
    And "Student One, Activity one: Completed" "icon" should exist in the "Student One" "table_row"
