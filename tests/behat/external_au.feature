@mod @mod_scormmakercmi5 @_file_upload @javascript
Feature: A package with an AU outside it
  In order to deliver a cmi5 course whose content is hosted on another site
  As a teacher
  I need to import a package whose AU has an https address, knowing it is unsigned and has no block report

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    # Off by default: the administrator opts in.
    And the following config values are set as admin:
      | allowexternalau | 1 | mod_scormmakercmi5 |

  @accessibility
  Scenario: The teacher imports the package, sees the notice, and the learner can start the unit
    Given I log in as "teacher1"
    And I add a "scormmakercmi5" activity to course "Course 1" section "1"
    And I set the field "Name" to "External activity"
    And I upload "mod/scormmakercmi5/tests/fixtures/packages/external_au.zip" file to "Package file" filemanager
    When I press "Save and display"
    Then I should see "Unidade externa" in the "Assignable units" "table"
    And I should see "Not signed by SCORM Maker"
    And I should see "This package opens content outside Moodle (https://127.0.0.1:9). It is treated as unsigned and has no block activity report."
    And "Rich report" "link" should not exist
    And the page should meet accessibility standards
    And I log out
    And I am on the "External activity" "scormmakercmi5 activity" page logged in as "student1"
    And "Start" "button" should exist in the "Unidade externa" "table_row"
    And I should not see "This package opens content outside Moodle"
    And I should see "This activity is hosted outside Moodle (https://127.0.0.1:9)."
    And the page should meet accessibility standards
    And I click on "Start" "button" in the "Unidade externa" "table_row"
    And I should see "This activity is hosted outside Moodle (https://127.0.0.1:9)."
    And "Back to the activity" "link" should exist
    # An AU outside the package never answers the request to close: the link still leaves after the timeout.
    And I leave the cmi5 player by its back link, which takes between 3.9 and 12 seconds
    And "Continue" "button" should exist in the "Unidade externa" "table_row"

  Scenario: With AUs outside the package turned off after the import, the learner cannot start the unit
    Given I log in as "teacher1"
    And I add a "scormmakercmi5" activity to course "Course 1" section "1"
    And I set the field "Name" to "External activity"
    And I upload "mod/scormmakercmi5/tests/fixtures/packages/external_au.zip" file to "Package file" filemanager
    And I press "Save and display"
    And I should see "Unidade externa" in the "Assignable units" "table"
    And I log out
    And the following config values are set as admin:
      | allowexternalau | 0 | mod_scormmakercmi5 |
    When I am on the "External activity" "scormmakercmi5 activity" page logged in as "student1"
    And I click on "Start" "button" in the "Unidade externa" "table_row"
    Then I should see "This unit is hosted outside Moodle, and this site does not open content outside Moodle at the moment. Tell your teacher."
    And I should not see "Ask the administrator"
    And "iframe" "css_element" should not exist

  Scenario: With AUs outside the package turned off by the admin, the import is refused
    Given the following config values are set as admin:
      | allowexternalau | 0 | mod_scormmakercmi5 |
    And I log in as "teacher1"
    And I add a "scormmakercmi5" activity to course "Course 1" section "1"
    And I set the field "Name" to "External activity"
    And I upload "mod/scormmakercmi5/tests/fixtures/packages/external_au.zip" file to "Package file" filemanager
    When I press "Save and display"
    Then I should see "This package has an AU outside it (an https address), and this site does not allow content outside Moodle."
    And I should not see "Unidade externa"
