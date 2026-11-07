@mod @mod_scormmakercmi5
Feature: List the cmi5 activities of a course
  In order to find the cmi5 activities of a course
  As a learner
  I need the list page to be shown inside the course, like the other activity pages

  Scenario: The list page uses the course layout and links to the activity
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity       | name           | course | idnumber | package |
      | scormmakercmi5 | Listed course  | C1     | cmi5list | passed  |
    When I am on the "C1" "mod_scormmakercmi5 > index" page logged in as "student1"
    Then "body.pagelayout-incourse" "css_element" should exist
    And I should see "Listed course" in the "generaltable" "table"
