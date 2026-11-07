@mod @mod_scormmakercmi5 @javascript
Feature: Rich report of a SCORM Maker package
  In order to see what happened inside a SCORM Maker course
  As a teacher
  I need a rich report that is shown only while the package is still verified

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
  Scenario: The teacher opens the rich report of a verified package and the detail of a learner
    Given the following "activities" exist:
      | activity       | course | idnumber | name     | signed |
      | scormmakercmi5 | C1     | cmi5s    | Assinado | 1      |
    And "student1" has SCORM Maker statements in "cmi5s"
    When I am on the "Assinado" "scormmakercmi5 activity" page logged in as "teacher1"
    And I follow "Rich report"
    Then I should see "Average time per lesson"
    And I should see "Lição 1" in the "Average time per lesson" "table"
    And I should see "30 secs" in the "Lição 1" "table_row"
    And I should see "Quanto é 1 + 1?" in the "Success rate per question" "table"
    And I should see "100.0%" in the "Quanto é 1 + 1?" "table_row"
    And I should see "Dois" in the "Quanto é 1 + 1?" "table_row"
    And I should not see "@template"
    And I should not see "Example context"
    And the page should meet accessibility standards
    And I follow "Student One"
    And I should not see "@template"
    And I should see "Dois" in the "Quanto é 1 + 1?" "table_row"
    And I should see "Lessons visited"
    And I should see "Lição 1" in the "Lessons visited" "table"
    And I should see "Right" in the "Quanto é 1 + 1?" "table_row"
    And I should see "Progress over time"
    And the page should meet accessibility standards
    And I follow "Back to the rich report"
    And I should see "Where learners stop"

  Scenario: Opening the rich report records nothing and shows the same verdict
    Given the following "activities" exist:
      | activity       | course | idnumber | name     | signed |
      | scormmakercmi5 | C1     | cmi5s    | Assinado | 1      |
    And "student1" has SCORM Maker statements in "cmi5s"
    When I am on the "cmi5s" "mod_scormmakercmi5 > Rich report" page logged in as "teacher1"
    Then I should see "Average time per lesson"
    And the signature of the "cmi5s" cmi5 package should not have been rechecked

  @accessibility
  Scenario: A package that is not signed shows the basic report with the reason, and only safe download formats
    Given the following "activities" exist:
      | activity       | course | idnumber | name      | package |
      | scormmakercmi5 | C1     | cmi5u    | Comum     | passed  |
    When I am on the "cmi5u" "mod_scormmakercmi5 > Rich report" page logged in as "teacher1"
    Then I should see "Not signed by SCORM Maker"
    And I should not see "Average time per lesson"
    And I should see "Student One" in the "generaltable" "table"
    And the "Download table data as" select box should contain "Comma separated values (.csv)"
    And the "Download table data as" select box should not contain "HTML table"
    And the "Download table data as" select box should not contain "Portable Document Format (PDF)"
    And the page should meet accessibility standards

  Scenario: In separate groups the rich report keeps a tutor to their group
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | tutor1   | Tutor     | One      | tutor1@example.com   |
      | student2 | Student   | Two      | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | tutor1   | C1     | teacher |
      | student2 | C1     | student |
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
      | activity       | course | idnumber | name     | signed | groupmode |
      | scormmakercmi5 | C1     | cmi5g    | Grupos   | 1      | 1         |
    And "student1" has SCORM Maker statements in "cmi5g"
    And "student2" has SCORM Maker statements in "cmi5g"
    When I am on the "cmi5g" "mod_scormmakercmi5 > Rich report" page logged in as "tutor1"
    Then I should see "Student One"
    And I should not see "Student Two"
    And opening the cmi5 rich learner page of "student2" in "cmi5g" should show "Sorry, but you do not currently have permissions to do that"
    And opening the cmi5 rich learner page of "student2" in "cmi5g" should not show "Student Two"
    And opening the cmi5 rich learner page of "student1" in "cmi5g" should show "Lessons visited"

  Scenario: A key revoked after the import turns the rich report into the basic report
    Given the following "activities" exist:
      | activity       | course | idnumber | name     | signed |
      | scormmakercmi5 | C1     | cmi5r    | Revogado | 1      |
    And the following config values are set as admin:
      | revokedkids | sm-test-1 | mod_scormmakercmi5 |
    When I am on the "cmi5r" "mod_scormmakercmi5 > Rich report" page logged in as "teacher1"
    Then I should see "The package was signed with a key that has been revoked."
    And I should see "Student One" in the "generaltable" "table"
