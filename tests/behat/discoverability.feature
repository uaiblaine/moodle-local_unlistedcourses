@local @local_unlistedcourses
Feature: The discoverability control in the course settings form
  In order to decide who may learn that a course exists
  As a course editor or a manager
  I need the course settings to offer me exactly the states I may set

  Background:
    Given the following "categories" exist:
      | name     | category | idnumber |
      | Category | 0        | CAT1     |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | CAT1     |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | manager1 | Molly     | Manager  | manager1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | Category     | CAT1      |

  Scenario: An editing teacher may unlist a course but is not offered publishing
    Given I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Settings" in current page administration
    Then the "Discoverability" select box should contain "Unlisted"
    And the "Discoverability" select box should not contain "Public"
    When I set the field "Discoverability" to "Unlisted"
    And I press "Save and display"
    And I navigate to "Settings" in current page administration
    Then the field "Discoverability" matches value "Unlisted"

  Scenario: A manager publishes a course and a teacher saving something else does not un-publish it
    Given I am on the "C1" "Course" page logged in as "manager1"
    And I navigate to "Settings" in current page administration
    And I set the field "Discoverability" to "Public"
    And I press "Save and display"
    And I log out
    When I am on the "C1" "Course" page logged in as "teacher1"
    And I navigate to "Settings" in current page administration
    Then I should see "Public" in the "Discoverability" "form_row"
    And "select[name=local_unlistedcourses_state]" "css_element" should not exist
    When I set the field "Course full name" to "Course 1 renamed"
    And I press "Save and display"
    And I log out
    And I am on the "C1" "Course" page logged in as "manager1"
    And I navigate to "Settings" in current page administration
    Then the field "Discoverability" matches value "Public"
    And the field "Course full name" matches value "Course 1 renamed"
