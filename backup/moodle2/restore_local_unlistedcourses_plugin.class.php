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
 * not $USER - in the target course. Nobody consents to publishing a course by
 * restoring a backup: when set_state() refuses, the target keeps the state it
 * already had (listed for a new course, whatever it was for an overwrite) and
 * the refusal is logged. There is deliberately no clamp of an incoming public
 * state to listed ahead of that call: on an overwrite restore into an unlisted
 * course, such a clamp would turn a refusal into an un-hiding write.
 *
 * The element is written for every course ({@see backup_local_unlistedcourses_plugin}),
 * so the state follows the rule core applies to the visible flag - with one
 * exception: on a tool_uploadcourse template restore, Moodle 5.2 keeps a visible
 * value given in the CSV over the template's, and no CSV column can do the same
 * for this state.
 *
 * A backup taken on a site without this plugin carries no element at all. When
 * such a backup is restored as a new course, the course receives the site's
 * restore default ({@see discoverability::get_restore_default()}) from
 * after_execute_course(), which core calls on this object whether or not the
 * element was in course.xml. A restore into an existing course never receives
 * it: that course keeps the state it has.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_unlistedcourses_plugin extends restore_local_plugin {
    /** @var bool Whether course.xml carried the state element, whatever its value. */
    protected bool $stateinbackup = false;

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
        // Any element counts as a value, an unknown one included: the backup's site had the plugin.
        $this->stateinbackup = true;
        $data = (object) $data;
        $state = (int) $data->state;
        if (!in_array($state, discoverability::states(), true)) {
            // A value this version does not know is skipped: the target keeps the state it has.
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

    /**
     * Give a new course restored from a backup without the element the site's restore default.
     *
     * Core launches this after parsing course.xml for every processing object
     * registered on the course element (restore_structure_step::execute() calls
     * launch_after_execute_methods()), so it runs when the element was absent
     * too, which is the case it exists for. The course step runs only for a
     * new course, an overwrite of configuration or a template restore
     * ({@see restore_course_task::build()}); only the first gets the default.
     *
     * @return void
     */
    protected function after_execute_course() {
        if ($this->stateinbackup || $this->task->get_target() != backup::TARGET_NEW_COURSE) {
            return;
        }
        $courseid = (int) $this->task->get_courseid();
        $userid = (int) $this->task->get_userid();
        // The default is never public, so the publish gate in set_state() is never reached from here.
        discoverability::set_state($courseid, discoverability::get_restore_default(), $userid);
    }
}
