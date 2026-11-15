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
 * Course discoverability - Event observers
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses;

/**
 * Observers of core events.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Drop the state row of a course core has deleted.
     *
     * The event, not the before_course_deleted hook: with asynchronous course deletion core
     * runs the hook and the pre_course_delete callbacks when the deletion is requested, hides
     * the course, and does not run them again when the cron deletes it. A row dropped at
     * request time would leave a course that still exists, and may never finish deleting,
     * without its state. course_deleted fires once, after the course is gone, in both modes.
     *
     * @param \core\event\course_deleted $event The event.
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        discoverability::on_course_deleted((int) $event->objectid);
    }

    /**
     * Drop the state row of a course category core has deleted.
     *
     * The event, not the pre_course_category_delete and pre_course_category_delete_move
     * callbacks: core runs those first thing, and the deletion can still fail after them -
     * delete_full() throws on a course in the subtree whose deletion is refused (one with a
     * backup or restore pending, say) and delete_move() can return false. A row dropped there
     * would leave a live category, and with delete_full() each of its ancestors, without its
     * state: an unlisted one would list itself and its courses again. course_category_deleted
     * fires once per category, after its row and context are gone. delete_full() fires it from
     * the recursive call of each descendant, so nothing here walks the subtree; delete_move()
     * fires it for the deleted category alone, whose children keep their own rows.
     *
     * Then, for a delete_move(), what the move made public goes back to listed: the public rows
     * below the category that local_unlistedcourses_pre_course_category_delete_move() recorded
     * as inert, asked again now that the category's own row is gone and the plugin's request caches are reset
     * ({@see local\delete_move_guard}). This is the first moment the deletion is certain, which
     * is why the callback only reads.
     *
     * Registered as internal in db/events.php, like {@see course_deleted()}: it runs at once,
     * inside the deletion's transaction when a caller opened one (core_course_delete_categories
     * does), so the row and the caches go in the same unit of work as the category and roll back
     * with it. An external observer would wait for the commit and run outside it, and a failure
     * then would leave the row behind.
     *
     * @param \core\event\course_category_deleted $event The event.
     * @return void
     */
    public static function course_category_deleted(\core\event\course_category_deleted $event): void {
        $categoryid = (int) $event->objectid;
        category_discoverability::on_category_deleted($categoryid);
        // A deletion that moved the contents names their new parent; delete_full() does not.
        $moved = isset($event->other['contentmovedcategoryid']);
        local\delete_move_guard::settle($categoryid, $moved, (int) $event->userid);
    }
}
