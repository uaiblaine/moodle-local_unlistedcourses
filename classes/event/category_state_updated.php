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
 * Course category discoverability - The state-changed event
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\event;

/**
 * Fired whenever a course category's discoverability state changes.
 *
 * Withholding a whole category from the site's listings is an act an
 * administrator wants in the log, and restoring it just as much. The object
 * is the category itself; the old and new states travel in 'other'.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class category_state_updated extends \core\event\base {
    /**
     * Set the basic properties.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'course_categories';
    }

    /**
     * The localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_category_state_updated', 'local_unlistedcourses');
    }

    /**
     * A non-localised description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' changed the discoverability state of the course category with id " .
            "'{$this->objectid}' from '{$this->other['oldstate']}' to '{$this->other['newstate']}'.";
    }

    /**
     * The URL related to the event: the category itself.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/course/index.php', ['categoryid' => $this->objectid]);
    }

    /**
     * Validate the custom data.
     *
     * @return void
     * @throws \coding_exception When 'oldstate' or 'newstate' is missing from other.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['oldstate']) || !isset($this->other['newstate'])) {
            throw new \coding_exception("The 'oldstate' and 'newstate' values must be set in other.");
        }
    }

    /**
     * Mapping of the object id for backup and restore of logs.
     *
     * Categories are not backed up, so the id has no restore mapping - the
     * same answer core's own category events give.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'course_categories', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Mapping of the other fields for backup and restore of logs: no ids in there.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
