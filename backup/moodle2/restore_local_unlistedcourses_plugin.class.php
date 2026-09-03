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
 * Course discoverability - Course restore support
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_unlistedcourses\discoverability;

/**
 * Restores the discoverability state into the target course.
 *
 * The write goes through set_state() like every other writer, so the publish
 * capability is checked there, against the restoring user - the task's user,
 * not $USER - in the TARGET course. Nobody consents to publishing a course by
 * restoring a backup: when set_state() refuses, the target keeps the state it
 * already had (listed for a new course, whatever it was for an overwrite) and
 * the refusal is logged. There is deliberately no clamp of an incoming PUBLIC
 * to listed ahead of that call: on an overwrite restore into an unlisted
 * course, such a clamp would have turned a refusal into an un-hiding write.
 *
 * Core processes course.xml only into a new course or when "overwrite course
 * configuration" is on, and the element is written for every course, so the
 * state follows exactly the rule core applies to the visible flag.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_unlistedcourses_plugin extends restore_local_plugin {
    /**
     * Declare the state path inside course.xml.
     *
     * @return restore_path_element[] The paths this plugin handles.
     */
    protected function define_course_plugin_structure() {
        return [
            new restore_path_element($this->get_namefor('state'), $this->get_pathfor('/state')),
        ];
    }

    /**
     * Restore the state row.
     *
     * @param array $data The parsed state element.
     * @return void
     */
    public function process_local_unlistedcourses_state($data) {
        $data = (object) $data;
        $state = (int) $data->state;
        if (!in_array($state, discoverability::states(), true)) {
            // A value this version does not know: fail closed by leaving the default.
            return;
        }

        $courseid = (int) $this->task->get_courseid();
        $userid = (int) $this->task->get_userid();

        try {
            discoverability::set_state($courseid, $state, $userid);
        } catch (\required_capability_exception $e) {
            $key = ($state === discoverability::STATE_PUBLIC) ? 'restore_publicclamped' : 'restore_statenotapplied';
            $this->task->log(get_string($key, 'local_unlistedcourses'), backup::LOG_WARNING);
        }
    }
}
