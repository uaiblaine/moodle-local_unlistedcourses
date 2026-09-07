<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Behat step definitions for local_unlistedcourses.
 *
 * @package    local_unlistedcourses
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL check here, this file is included by behat before config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Puts a course category into a discoverability state before a scenario runs.
 *
 * There is no data generator for the state, and there should not be one: the
 * table holds a row only for a non-default state and every write goes through
 * the capability gate in category_discoverability::set_state(). The step calls
 * that gate as the site administrator, so a scenario arranges a starting state
 * the same way an administrator would, rather than by writing the table.
 *
 * @package    local_unlistedcourses
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_unlistedcourses extends behat_base {
    /**
     * Set the discoverability state of a category identified by its idnumber.
     *
     * @Given /^the category "(?P<idnumber>(?:[^"]|\\")*)" is "(?P<state>listed|unlisted)" for discoverability$/
     * @param string $idnumber The category's idnumber.
     * @param string $state Either "listed" or "unlisted".
     * @return void
     */
    public function the_category_is_for_discoverability(string $idnumber, string $state): void {
        global $DB;

        $categoryid = (int) $DB->get_field('course_categories', 'id', ['idnumber' => $idnumber], MUST_EXIST);
        $value = $state === 'unlisted'
            ? \local_unlistedcourses\category_discoverability::STATE_UNLISTED
            : \local_unlistedcourses\category_discoverability::STATE_DEFAULT;
        \local_unlistedcourses\category_discoverability::set_state($categoryid, $value, (int) get_admin()->id);
    }
}
