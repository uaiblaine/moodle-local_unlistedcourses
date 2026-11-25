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
 * Course discoverability - The event of a public state kept after a category deletion with a move
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\event;

/**
 * Fired when a category deletion with a move leaves a public course or category public because its lock was busy.
 *
 * {@see \local_unlistedcourses\local\delete_move_guard::settle()} takes back to listed every public
 * row a deletion with a move made effective. A row whose lock another writer holds past the wait is
 * skipped and keeps its public state, which may now let visitors who are not logged in reach it.
 * debugging() says so only where developer debugging is on; this event puts it in the site log of
 * every site, for an administrator to decide.
 *
 * Nothing was written, so it is a read with no object: the context is the course or category that
 * kept its state, and 'other' carries the deleted category's id ('deletedcategoryid') and which of
 * the two the context is ('kind', {@see KIND_COURSE} or {@see KIND_CATEGORY}). The user is the one
 * who deleted the category.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class public_state_kept_after_move extends \core\event\base {
    /** @var string The kind of a report whose context is a course's. */
    public const KIND_COURSE = 'course';

    /** @var string The kind of a report whose context is a course category's. */
    public const KIND_CATEGORY = 'category';

    /**
     * Set the basic properties.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * The localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_public_state_kept_after_move', 'local_unlistedcourses');
    }

    /**
     * A non-localised description of what happened.
     *
     * @return string
     */
    public function get_description() {
        $kind = $this->other['kind'] === self::KIND_COURSE ? 'course' : 'course category';
        return "The {$kind} with id '{$this->contextinstanceid}' kept its public discoverability state after the user " .
            "with id '{$this->userid}' deleted the course category with id '{$this->other['deletedcategoryid']}' and moved " .
            "its contents, because another writer held the lock of that state. A deletion with a move takes back to listed " .
            "what the move makes public; this one was skipped, and may be reached by visitors who are not logged in.";
    }

    /**
     * The URL related to the event: the page where the state that was kept is edited.
     *
     * @return \moodle_url
     */
    public function get_url() {
        if ($this->other['kind'] === self::KIND_COURSE) {
            return new \moodle_url('/course/edit.php', ['id' => $this->contextinstanceid]);
        }
        return new \moodle_url('/local/unlistedcourses/category.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Validate the custom data.
     *
     * @return void
     * @throws \coding_exception When 'deletedcategoryid' is missing, or 'kind' does not name the context's level.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['deletedcategoryid'])) {
            throw new \coding_exception("The 'deletedcategoryid' value must be set in other.");
        }
        $level = match ($this->other['kind'] ?? null) {
            self::KIND_COURSE => CONTEXT_COURSE,
            self::KIND_CATEGORY => CONTEXT_COURSECAT,
            default => null,
        };
        if ($level === null || (int) $this->contextlevel !== $level) {
            throw new \coding_exception("The 'kind' value in other must be 'course' or 'category' and name the context's level.");
        }
    }

    /**
     * Mapping of the other fields for backup and restore of logs.
     *
     * Categories are not backed up, so the deleted category's id has no restore mapping - the same
     * answer core's own category events give for their object.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['deletedcategoryid' => ['db' => 'course_categories', 'restore' => \core\event\base::NOT_MAPPED]];
    }
}
