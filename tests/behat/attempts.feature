@mod @mod_scormmakercmi5 @javascript
Feature: Waive a unit and delete the attempt of a learner
  In order to handle exemptions and restart learners
  As a teacher with mod/scormmakercmi5:manageattempts
  I need to waive an assignable unit with a cmi5 reason and to delete the attempt of a learner, both confirmed

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity       | course | idnumber | name      | package |
      | scormmakercmi5 | C1     | cmi5a    | Activity one | passed  |

  @accessibility
  Scenario: The teacher waives a unit with a reason and the learner sees it waived
    Given I log in as "teacher1"
    When I open the cmi5 waive page of "student1" for "Unidade 1" in "cmi5a"
    Then I should see "Waive assignable unit"
    And I should see "Student One"
    And I should see "Unidade 1"
    And I should see "The unit will count as satisfied for this learner"
    And the "Reason" select box should contain "Tested out"
    And the "Reason" select box should contain "Equivalent assignable unit"
    And the "Reason" select box should contain "Equivalent outside activity"
    And the "Reason" select box should contain "Administrative"
    And the page should meet accessibility standards
    And I set the field "Reason" to "Administrative"
    And I press "Waive"
    And I should see "The unit \"Unidade 1\" was waived."
    And I open the cmi5 waive page of "student1" for "Unidade 1" in "cmi5a"
    And I set the field "Reason" to "Tested out"
    And I press "Waive"
    And I should see "This assignable unit was already waived for this learner."
    And I am on the "Activity one" "scormmakercmi5 activity" page logged in as "student1"
    And I should see "Waived" in the "Unidade 1" "table_row"
    And I should see "Not started" in the "Unidade 2" "table_row"
    And I am on the "Activity one" "scormmakercmi5 activity" page logged in as "student2"
    And I should see "Not started" in the "Unidade 1" "table_row"

  Scenario: Cancelling the waiver changes nothing
    Given I log in as "teacher1"
    And I open the cmi5 waive page of "student1" for "Unidade 1" in "cmi5a"
    When I press "Cancel"
    # Back on the learner's detail in the report.
    Then I should see "Student One" in the "region-main" "region"
    And I should see "Not started" in the "Unidade 1" "table_row"
    And "student1" should not have a cmi5 attempt in "cmi5a"

  @accessibility
  Scenario: The teacher deletes the attempt of one learner after confirming
    Given the following "mod_scormmakercmi5 > registrations" exist:
      | activity | user     |
      | cmi5a    | student1 |
      | cmi5a    | student2 |
    And I log in as "teacher1"
    When I open the cmi5 attempt deletion page of "student1" in "cmi5a"
    Then I should see "Delete the attempt of Student One in this activity?"
    And the page should meet accessibility standards
    And "student1" should have a cmi5 attempt in "cmi5a"
    And I press "Delete attempt"
    And I should see "The attempt of Student One was deleted."
    And "student1" should not have a cmi5 attempt in "cmi5a"
    And "student2" should have a cmi5 attempt in "cmi5a"
    And I open the cmi5 attempt deletion page of "student1" in "cmi5a"
    And I should see "This learner has no attempt in this activity."

  Scenario: The pages of a learner name nobody for a user of the site who is not a learner
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | outsider | Out       | Sider    | outsider@example.com |
    And I am on the "Activity one" "scormmakercmi5 activity" page logged in as "teacher1"
    Then opening the cmi5 waive page of "outsider" in "cmi5a" should show "This user is not an active participant of this activity who can take it."
    And opening the cmi5 waive page of "outsider" in "cmi5a" should not show "Out Sider"
    And opening the cmi5 attempt deletion page of "outsider" in "cmi5a" should show "This learner has no attempt in this activity."
    And opening the cmi5 attempt deletion page of "outsider" in "cmi5a" should not show "Out Sider"
    And opening the cmi5 learner page of "outsider" in "cmi5a" should show "This user is not an active participant of this activity who can take it."
    And opening the cmi5 learner page of "outsider" in "cmi5a" should not show "Out Sider"
