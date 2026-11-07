@mod @mod_scormmakercmi5 @javascript
Feature: Activity inside the lessons of a SCORM Maker package
  In order to see what the class does inside the course
  As a teacher
  I need the block activity report, with tables for every chart and no fine data per learner

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
    And the following "activities" exist:
      | activity       | course | idnumber | name     | signed |
      | scormmakercmi5 | C1     | cmi5s    | Assinado | 1      |

  @accessibility
  Scenario: The teacher sees the activity of the class per block and the summary of a learner
    Given "student1" has SCORM Maker block statements in "cmi5s"
    When I am on the "Assinado" "scormmakercmi5 activity" page logged in as "teacher1"
    And I follow "Rich report"
    Then I should see "Activity inside the lessons"
    And I should see "Lição 1"
    And I should see "100%" in the "Vídeo 1" "table_row"
    And I should see "Not reached" in the "=HYPERLINK(\"https://exemplo.com\")" "table_row"
    And I should see "Retention per 5-second band"
    And I should see "Self-assessment per card (hardest first)"
    And I should see "<img src=x onerror=alert(1)>"
    And I should see "Row 3, column 4" in the "Marcadores 1 — Clicks on the image" "table"
    And I should see "Scene: Sala"
    And I should not see "The time visible of a block includes the time its nested content"
    And I should not see "@template"
    And the page should meet accessibility standards
    And I follow "Student One"
    And I should see "Blocks reached"
    And I should see "Decisions"
    And I should see "Knew" in the "Decisions" "table"
    And I should see "Final feliz" in the "Decisions" "table"
    And I should not see "Row 3, column 4"
    And I should not see "Clicks on the image"
    And I should not see "@template"
    And the page should meet accessibility standards

  @accessibility
  Scenario: A block nested in another one comes right after its parent, indented and labelled with it
    Given "student1" has SCORM Maker block statements of the contract case "blocos-aninhados" in "cmi5s"
    When I am on the "Assinado" "scormmakercmi5 activity" page logged in as "teacher1"
    And I follow "Rich report"
    Then I should see "Activity inside the lessons"
    And I should see "100%" in the "Mapa 1 › Vídeo dentro" "table_row"
    And I should see "Mapa 1 › Cartões dentro"
    And "Mapa 1 › Vídeo dentro" "table_row" should appear after "Mapa 1" "table_row"
    And "Mapa 1 › Cartões dentro" "table_row" should appear after "Mapa 1 › Vídeo dentro" "table_row"
    And I should see "Mapa 1 › Vídeo dentro — Retention per 5-second band"
    And the block row "Mapa 1 › Vídeo dentro" should have a start padding of "24px"
    And the block row "Mapa 1 › Cartões dentro" should have a start padding of "24px"
    And the block row "Mapa 1" should have a start padding of "12px"
    And I should see "The time visible of a block includes the time its nested content (indented rows) was open."
    And the page should meet accessibility standards
    And I follow "Student One"
    And I should see "Mapa 1 › Vídeo dentro" in the "Blocks reached" "table"
    And I should see "Knew" in the "Decisions" "table"
    And the page should meet accessibility standards

  Scenario: The teacher downloads the nine tables of the block activity: one sheet per table, CSV as a zip
    Given "student1" has SCORM Maker block statements in "cmi5s"
    When I am on the "Assinado" "scormmakercmi5 activity" page logged in as "teacher1"
    And I follow "Rich report"
    Then I should see "Download the activity of the blocks (one sheet per table; CSV: a zip with one file per table)"
    And downloading the block activity as "excel" should give "Blocks, Items, Retention, Choices, Endings, Scenario drop-off, Cards, Tour points, Heat maps"
    And downloading the block activity as "ods" should give "Blocks, Items, Retention, Choices, Endings, Scenario drop-off, Cards, Tour points, Heat maps"
    And downloading the block activity as "csv" should give "blocos.csv, itens.csv, retencao.csv, escolhas.csv, finais.csv, abandono.csv, cartoes.csv, pontos.csv, mapas.csv"
    And downloading the block activity as "excel" should have "'=HYPERLINK" in "Blocks"
    And downloading the block activity as "ods" should have "'=HYPERLINK" in "Blocks"
    And downloading the block activity as "csv" should have "'=HYPERLINK" in "blocos.csv"
    And downloading the block activity as "excel" should have "Final feliz" in "Endings"
    And downloading the block activity as "ods" should have "Sala" in "Heat maps"
    And downloading the block activity as "csv" should have "Lesson,Block,Scene,Row,Column,Clicks" in "mapas.csv"

  Scenario: A verified package without the analysis structure says the block report is unavailable
    Given "student1" has SCORM Maker statements in "cmi5s"
    When I am on the "Assinado" "scormmakercmi5 activity" page logged in as "teacher1"
    And I follow "Rich report"
    Then I should see "Average time per lesson"
    And I should see "The activity inside the lessons is unavailable for this version"
    And I follow "Student One"
    And I should see "The activity inside the lessons is unavailable for this version"

  Scenario: An activity with a long accented name still downloads the block activity
    Given the following "activities" exist:
      | activity       | course | idnumber | name | signed |
      | scormmakercmi5 | C1     | cmi5long | Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação Avaliação | 1      |
    And "student1" has SCORM Maker block statements in "cmi5long"
    When I am on the "cmi5long" "scormmakercmi5 activity" page logged in as "teacher1"
    And I follow "Rich report"
    Then downloading the block activity as "csv" should give "blocos.csv, itens.csv, retencao.csv, escolhas.csv, finais.csv, abandono.csv, cartoes.csv, pontos.csv, mapas.csv"
    And downloading the block activity as "excel" should give "Blocks, Items, Retention, Choices, Endings, Scenario drop-off, Cards, Tour points, Heat maps"
    And downloading the block activity as "ods" should give "Blocks, Items, Retention, Choices, Endings, Scenario drop-off, Cards, Tour points, Heat maps"
