@mod @mod_scormmakercmi5 @_file_upload @javascript
Feature: Create a cmi5 activity from a package and replace its package
  In order to deliver a cmi5 course
  As a teacher
  I need to upload the package in the activity form and replace it with confirmation

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

  @accessibility
  Scenario: Add the activity with an empty name, which comes from cmi5.xml
    Given I log in as "teacher1"
    And I add a "scormmakercmi5" activity to course "Course 1" section "1"
    And I upload "mod/scormmakercmi5/tests/fixtures/packages/minimal.zip" file to "Package file" filemanager
    When I press "Save and display"
    Then I should see "Curso mínimo"
    And I should see "Descrição do curso mínimo"
    And I should see "Unidade 1" in the "Assignable units" "table"
    And I should see "Not signed by SCORM Maker"
    And the page should meet accessibility standards
    And I log out
    And I am on the "Curso mínimo" "scormmakercmi5 activity" page logged in as "student1"
    And I should see "Unidade 1"
    And I should not see "Not signed by SCORM Maker"

  Scenario: A package with an AU at an http address outside it is refused and nothing is created
    Given I log in as "teacher1"
    And I add a "scormmakercmi5" activity to course "Course 1" section "1"
    And I set the field "Name" to "External activity"
    And I upload "mod/scormmakercmi5/tests/fixtures/packages/absolute_url.zip" file to "Package file" filemanager
    When I press "Save and return to course"
    Then I should see "The URL of an AU must be a path inside the package or an https address (https only)"
    And I am on "Course 1" course homepage
    And I should not see "External activity"

  Scenario: The package is required when adding the activity
    Given I log in as "teacher1"
    And I add a "scormmakercmi5" activity to course "Course 1" section "1"
    And I set the field "Name" to "Packageless activity"
    When I press "Save and return to course"
    Then I should see "No package file was uploaded."

  Scenario: Replacing the package of an activity with registrations needs confirmation
    Given the following "activities" exist:
      | activity       | name           | course | idnumber |
      | scormmakercmi5 | Course activity | C1     | cmi5a    |
    And the following "mod_scormmakercmi5 > registrations" exist:
      | activity | user     |
      | cmi5a    | student1 |
    And I am on the "Course activity" "scormmakercmi5 activity editing" page logged in as "teacher1"
    And I should see "attempts in progress continue with the previous package until they finish"
    And I delete "pacote.zip" from "Package file" filemanager
    And I upload "mod/scormmakercmi5/tests/fixtures/packages/minimal_v2.zip" file to "Package file" filemanager
    When I press "Save and display"
    Then I should see "To replace the package of an activity that learners have started, confirm the replacement."
    And I set the field "Replace the package" to "1"
    And I press "Save and display"
    And I should see "Unidade 2" in the "Assignable units" "table"
    And I should see "Unidade 1" in the "Assignable units" "table"

  Scenario: Without registrations the package is replaced with no confirmation
    Given the following "activities" exist:
      | activity       | name           | course | idnumber |
      | scormmakercmi5 | Course activity | C1     | cmi5a    |
    And I am on the "Course activity" "scormmakercmi5 activity editing" page logged in as "teacher1"
    And I should not see "attempts in progress continue with the previous package until they finish"
    And "Replace the package" "field" should not exist
    And I delete "pacote.zip" from "Package file" filemanager
    And I upload "mod/scormmakercmi5/tests/fixtures/packages/minimal_v2.zip" file to "Package file" filemanager
    When I press "Save and display"
    Then I should see "Unidade 2" in the "Assignable units" "table"

  Scenario: Teachers are warned when slash arguments are off
    Given the following config values are set as admin:
      | slasharguments | 0 |
    And the following "activities" exist:
      | activity       | name           | course | idnumber |
      | scormmakercmi5 | Course activity | C1     | cmi5a    |
    When I am on the "Course activity" "scormmakercmi5 activity" page logged in as "teacher1"
    Then I should see "Use slash arguments"
    And I log out
    And I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    And I should not see "Use slash arguments"
