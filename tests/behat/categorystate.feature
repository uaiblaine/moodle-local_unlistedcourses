@local @local_unlistedcourses
Feature: The discoverability page of a course category
  In order to decide who may learn that a course category exists
  As a manager of that category
  I need its settings menu to offer me the state, and to tell me who it leaves the category visible to

  Background:
    Given the following "categories" exist:
      | name       | category | idnumber |
      | Category 1 | 0        | CAT1     |
      | Category 2 | 0        | CAT2     |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | CAT1     |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | manager1 | Molly     | Manager  | manager1@example.com |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | Category     | CAT1      |
      | manager1 | manager | Category     | CAT2      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "cohorts" exist:
      | name     | idnumber | contextlevel | reference |
      | Cohort 1 | COH1     | Category     | CAT1      |
    And the following "cohort members" exist:
      | user     | cohort |
      | student1 | COH1   |

  Scenario: A manager unlists a category and is told who will still see it
    Given I am on the "CAT1" "Category" page logged in as "manager1"
    When I navigate to "Discoverability" in current page administration
    Then I should see "Who sees this category while it is unlisted"
    And I should see "Cohort 1"
    And I should see "Members: 1"
    When I set the field "Discoverability" to "Unlisted"
    And I press "Save changes"
    Then I should see "Category discoverability saved"
    And the field "Discoverability" matches value "Unlisted"

  Scenario: A category with no cohort of its own shows the stored state and says so
    Given the category "CAT2" is "unlisted" for discoverability
    And I am on the "CAT2" "Category" page logged in as "manager1"
    When I navigate to "Discoverability" in current page administration
    Then the field "Discoverability" matches value "Unlisted"
    And I should see "No cohort is defined at this category"

  Scenario: A teacher of a course inside the category is not offered the page
    Given I am on the "CAT1" "Category" page logged in as "teacher1"
    # The control. "should not exist in current page administration" returns quietly
    # when it finds no menu at all, so without this the scenario could pass on a page
    # that renders nothing. Core adds the "Category" node to a category page's
    # secondary navigation whatever the viewer holds, so its presence proves the menu
    # the next step searches is really there for this user.
    Then "Category" "link" should exist in the ".secondary-navigation" "css_element"
    And "Discoverability" "link" should not exist in current page administration
