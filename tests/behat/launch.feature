@mod @mod_scormmakercmi5 @javascript
Feature: Launch an assignable unit and follow its status
  In order to take a cmi5 course
  As a learner
  I need to start each unit in the player and see the status of the units and of the course

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity       | name           | course | idnumber | package | browsemode | reviewmode |
      | scormmakercmi5 | Course activity | C1     | cmi5a    | completed | 0          | 0          |

  @accessibility
  Scenario: A learner starts a unit in the player and then sees it in progress
    Given I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    Then I should see "Not started" in the "Unidade 1" "table_row"
    And "Start" "button" should exist in the "Unidade 1" "table_row"
    And I should see "Course status: Not started"
    And "Review" "button" should not exist
    And "Browse" "button" should not exist
    And the page should meet accessibility standards
    When I press "Start"
    Then "iframe[title='Unidade 1']" "css_element" should exist
    And "Back to the activity" "link" should exist
    And "Full screen" "button" should exist
    And the "aria-pressed" attribute of "Full screen" "button" should contain "false"
    And axe is loaded into the cmi5 player frame
    And the page should meet accessibility standards
    When I follow "Back to the activity"
    Then I should see "In progress" in the "Unidade 1" "table_row"
    And "Continue" "button" should exist in the "Unidade 1" "table_row"
    And I should see "Course status: In progress"

  Scenario: The back link asks the unit to close and leaves as soon as the unit answers
    Given I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    And I press "Start"
    And "iframe[title='Unidade 1']" "css_element" should exist
    And the AU in the cmi5 player answers the request to close
    When I leave the cmi5 player by its back link, which takes between 0 and 3 seconds
    Then the cmi5 AU should have received the request to close
    And I should see "In progress" in the "Unidade 1" "table_row"

  Scenario: The back link leaves after the timeout when the unit never answers
    Given I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    And I press "Start"
    And "iframe[title='Unidade 1']" "css_element" should exist
    When I leave the cmi5 player by its back link, which takes between 3.9 and 12 seconds
    Then I should see "In progress" in the "Unidade 1" "table_row"

  Scenario: Reloading the player after the launch shows that the session has ended
    Given I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    And I press "Start"
    And "iframe[title='Unidade 1']" "css_element" should exist
    When I reload the page
    Then I should see "This session has ended"
    And "iframe" "css_element" should not exist
    And I follow "Back to the activity"
    And I should see "In progress" in the "Unidade 1" "table_row"

  Scenario: A launch from another window ends the session open in the player
    Given I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    And I press "Start"
    And "iframe[title='Unidade 1']" "css_element" should exist
    When I open the "cmi5a" cmi5 activity in a window named "segunda"
    And I switch to "segunda" window
    And I press "Continue"
    And "iframe[title='Unidade 1']" "css_element" should exist
    And I switch to the main window
    And I return to the cmi5 player
    Then I should see "This session has ended"
    And "iframe" "css_element" should not exist
    And "Back to the activity" "link" should exist

  Scenario: A unit with launchMethod OwnWindow opens in its own window
    Given the following "activities" exist:
      | activity       | name          | course | idnumber | package   |
      | scormmakercmi5 | Own window | C1     | cmi5own  | ownwindow |
    And I am on the "Own window" "scormmakercmi5 activity" page logged in as "student1"
    When I press "Start"
    And I switch to a second window
    Then I should see "Unidade 1" in the "h1" "css_element"
    And I close all opened windows
    And I reload the page
    And I should see "In progress" in the "Unidade 1" "table_row"

  Scenario: A unit without moveOn shows Satisfied once its registration exists
    Given the following "activities" exist:
      | activity       | name        | course | idnumber | package |
      | scormmakercmi5 | Sem moveOn  | C1     | cmi5na   | minimal |
    When I am on the "Sem moveOn" "scormmakercmi5 activity" page logged in as "student1"
    Then I should see "Not started" in the "Unidade 1" "table_row"
    When I press "Start"
    Then "iframe[title='Unidade 1']" "css_element" should exist
    When I follow "Back to the activity"
    Then I should see "Satisfied" in the "Unidade 1" "table_row"
    And I should see "Course status: Satisfied"
    And "Continue" "button" should exist in the "Unidade 1" "table_row"
    And "student1" should have 1 cmi5 session in "cmi5na"

  Scenario: Browse and Review are offered only when the activity allows them
    Given the following "activities" exist:
      | activity       | name        | course | idnumber | package | browsemode | reviewmode |
      | scormmakercmi5 | Com modos   | C1     | cmi5b    | completed | 1          | 1          |
    When I am on the "Course activity" "scormmakercmi5 activity" page logged in as "teacher1"
    Then "Start" "button" should exist in the "Unidade 1" "table_row"
    And "Browse" "button" should not exist
    And "Review" "button" should not exist
    And I am on the "Com modos" "scormmakercmi5 activity" page
    And "Browse" "button" should exist in the "Unidade 1" "table_row"
    And "Review" "button" should exist in the "Unidade 1" "table_row"

  Scenario: The AU going full screen by itself keeps the focus
    Given I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    And I press "Start"
    When the AU in the cmi5 player goes full screen by itself
    Then the focus should be in the cmi5 player frame
    And the "aria-pressed" attribute of "Full screen" "button" should contain "false"
    When the AU in the cmi5 player leaves full screen
    Then the focus should be in the cmi5 player frame
    And the "aria-pressed" attribute of "Full screen" "button" should contain "false"

  Scenario: The AU going full screen inside the full screen player keeps the focus and the button state
    Given I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    And I press "Start"
    And I press "Full screen"
    And the "aria-pressed" attribute of "Full screen" "button" should contain "true"
    When the AU in the cmi5 player goes full screen by itself
    Then the focus should be in the cmi5 player frame
    And the "aria-pressed" attribute of "Full screen" "button" should contain "true"
    When the AU in the cmi5 player leaves full screen
    Then the focus should be in the cmi5 player frame
    And the "aria-pressed" attribute of "Full screen" "button" should contain "true"

  Scenario: launch.php launches only on a POST with the right sesskey
    Given I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    When I open the cmi5 launch page of "cmi5a" without posting
    Then I should see "Not started" in the "Unidade 1" "table_row"
    When a cmi5 launch of "cmi5a" posted with a wrong sesskey is refused
    Then "student1" should have 0 cmi5 sessions in "cmi5a"
    And I post a cmi5 launch of "cmi5a"
    And "iframe[title='Unidade 1']" "css_element" should exist
    And "student1" should have 1 cmi5 session in "cmi5a"

  Scenario: No launch without the view capability
    Given I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    And the following "permission overrides" exist:
      | capability              | permission | role    | contextlevel    | reference |
      | mod/scormmakercmi5:view | Prohibit   | student | Activity module | cmi5a     |
    When I post a cmi5 launch of "cmi5a"
    Then "iframe" "css_element" should not exist
    And "student1" should have 0 cmi5 sessions in "cmi5a"

  Scenario: No launch of a hidden activity
    Given I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    And the "cmi5a" cmi5 activity is hidden
    When I post a cmi5 launch of "cmi5a"
    Then "iframe" "css_element" should not exist
    And "student1" should have 0 cmi5 sessions in "cmi5a"

  Scenario: No launch of an activity outside its access restriction
    Given I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    And the "cmi5a" cmi5 activity is available only from tomorrow
    When I post a cmi5 launch of "cmi5a"
    Then "iframe" "css_element" should not exist
    And I should see "Available from"
    And "student1" should have 0 cmi5 sessions in "cmi5a"

  Scenario: A guest sees the units but cannot launch them
    Given guest access is enabled in course "C1"
    And the following "permission overrides" exist:
      | capability              | permission | role  | contextlevel | reference |
      | mod/scormmakercmi5:view | Allow      | guest | Course       | C1        |
    And I log in as "guest"
    When I am on the "Course activity" "scormmakercmi5 activity" page
    Then I should see "Guests cannot take this activity"
    And I should see "Unidade 1"
    And "Start" "button" should not exist
    When I post a cmi5 launch of "cmi5a"
    Then I should see "Guests cannot take this activity"
    And "iframe" "css_element" should not exist
    And "guest" should have 0 cmi5 sessions in "cmi5a"

  Scenario: The player of another learner's session shows that the session has ended
    Given I am on the "Course activity" "scormmakercmi5 activity" page logged in as "student1"
    And I press "Start"
    And I log out
    And I log in as "student2"
    When I open the cmi5 player of the last session of "student1" in "cmi5a"
    Then I should see "This session has ended"
    And "iframe" "css_element" should not exist
