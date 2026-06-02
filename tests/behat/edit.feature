@qtype @qtype_codeatelier
Feature: Create and edit CodeAtelier questions
  As a teacher
  In order to set up in-browser coding practice
  I need to create CodeAtelier questions and have their settings saved

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email               |
      | teacher  | Teacher   | One      | teacher@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user    | course | role           |
      | teacher | C1     | editingteacher |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |

  @javascript
  Scenario: Create a function-scenario CodeAtelier question
    When I am on the "Course 1" "core_question > course question bank" page logged in as "teacher"
    And I add a "CodeAtelier" question filling the form with:
      | Question name     | Add two numbers                            |
      | Question text     | Write add(a, b) that returns their sum.    |
      | Language          | JavaScript                                 |
      | Scenario          | Function (call and check the return value) |
      | Function name     | add                                        |
      | id_testinput_0    | [2, 3]                                     |
      | id_testexpected_0 | 5                                          |
      | id_testweight_0   | 1                                          |
    Then I should see "Add two numbers" in the "categoryquestions" "table"

  @javascript
  Scenario: The function name is required for the function scenario
    When I am on the "Course 1" "core_question > course question bank" page logged in as "teacher"
    And I press "Create a new question ..."
    And I set the field "item_qtype_codeatelier" to "1"
    And I click on "Add" "button" in the "Choose a question type to add" "dialogue"
    And I set the field "Scenario" to "Function (call and check the return value)"
    And I set the field "Question name" to "Missing entry point"
    And I set the field "id_testinput_0" to "[1]"
    And I set the field "id_testexpected_0" to "1"
    And I press "id_submitbutton"
    Then I should see "Enter the function name for a function question."
