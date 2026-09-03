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
 * Course discoverability - Course backup support
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds the discoverability state to course backups.
 *
 * Without this the state is lost on every course duplicate and restore -
 * silently, and in the un-hiding direction. Only the state travels; who set
 * it and when are stamped afresh by the restore.
 *
 * The element is written for EVERY course, a listed one included, even though
 * a listed course has no row: core processes course.xml only into a new
 * course or when "overwrite course configuration" is on, and in both cases it
 * rewrites every course setting from the backup. An absent element would
 * instead mean "keep whatever the target already has", so restoring a listed
 * course over an unlisted or public one would leave the stale state behind.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_unlistedcourses_plugin extends backup_local_plugin {
    /**
     * Attach the state to the course element.
     *
     * @return backup_plugin_element The plugin element.
     */
    protected function define_course_plugin_structure() {
        $plugin = $this->get_plugin_element();

        $pluginwrapper = new backup_nested_element($this->get_recommended_name());
        $plugin->add_child($pluginwrapper);

        $state = new backup_nested_element('state', ['id'], ['state']);
        $pluginwrapper->add_child($state);
        $state->set_source_sql(
            "SELECT c.id, COALESCE(s.state, 0) AS state
               FROM {course} c
          LEFT JOIN {local_unlistedcourses_state} s ON s.courseid = c.id
              WHERE c.id = ?",
            [backup::VAR_COURSEID]
        );

        return $plugin;
    }
}
