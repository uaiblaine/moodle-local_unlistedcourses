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
 * Course discoverability - Hook callbacks
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses;

use local_unlistedcourses\local\courseform;

/**
 * Hook callbacks: the course form control, its persistence, and course deletion.
 *
 * Thin on purpose. The form logic lives in {@see courseform} so it can be
 * tested against a bare form, and the persistence is one call into
 * {@see discoverability::set_state()}, which owns the capability check.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Add the discoverability control to the course settings form.
     *
     * @param \core_course\hook\after_form_definition $hook The hook.
     * @return void
     */
    public static function after_form_definition(\core_course\hook\after_form_definition $hook): void {
        courseform::extend($hook->formwrapper->get_course(), $hook->formwrapper->get_context(), $hook->mform);
    }

    /**
     * Persist the submitted state.
     *
     * Dispatched from create_course() and update_course(), so this runs for
     * the web service and tool_uploadcourse as well as for the form - which
     * is why the capability check is in set_state() and not here.
     *
     * @param \core_course\hook\after_form_submission $hook The hook.
     * @return void
     */
    public static function after_form_submission(\core_course\hook\after_form_submission $hook): void {
        courseform::save($hook->get_data());
    }

    /**
     * Drop the state row of a course that is being deleted.
     *
     * @param \core_course\hook\before_course_deleted $hook The hook.
     * @return void
     */
    public static function before_course_deleted(\core_course\hook\before_course_deleted $hook): void {
        discoverability::on_course_deleted((int) $hook->course->id);
    }
}
