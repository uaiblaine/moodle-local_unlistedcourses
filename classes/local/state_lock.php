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
 * Course discoverability - The lock that serialises the writes of one state
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\local;

/**
 * One core lock per course or category state, held from the read to the write.
 *
 * set_state() reads the row, decides on its capabilities from what it read, and writes. Two
 * writers of the same course or category interleave there at READ COMMITTED, which a delegated
 * transaction would not prevent: both could read "no row" and the second insert would break the
 * unique key, or a writer could remove a public row it read as unlisted, deciding on a row that
 * was no longer there. Under this lock the second writer reads what the first one wrote.
 *
 * A core lock and not flock(): the dataroot may be NFS and the writers different web nodes. The
 * site's lock factory decides where the lock lives (an advisory lock of the database by default),
 * so every node sees it. The wait is bounded, and a timeout is a {@see state_busy_exception} rather
 * than a write.
 *
 * A caller inside a delegated transaction keeps its row uncommitted past the release, so for it
 * the lock narrows the window rather than closing it: the next writer then waits on the unique
 * key instead. Of core's writers only core_course_create_courses opens one, around a course that
 * did not exist before it, so no other writer can know its id yet.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class state_lock {
    /** @var string The lock type: the namespace of every resource below. */
    public const TYPE = 'local_unlistedcourses';

    /** @var int Seconds a writer waits for another writer of the same state. A write takes milliseconds. */
    public const TIMEOUT = 5;

    /** @var int Seconds after which a lock left by a dead process expires, where the factory needs one. */
    public const LIFETIME = 60;

    /**
     * Take the lock on one course's state.
     *
     * @param int $courseid The course id.
     * @return \core\lock\lock The lock, to be released in a finally block.
     * @throws state_busy_exception When another writer holds it past the timeout.
     */
    public static function course(int $courseid): \core\lock\lock {
        return self::acquire('course_' . $courseid);
    }

    /**
     * Take the lock on one category's state.
     *
     * @param int $categoryid The category id.
     * @return \core\lock\lock The lock, to be released in a finally block.
     * @throws state_busy_exception When another writer holds it past the timeout.
     */
    public static function category(int $categoryid): \core\lock\lock {
        return self::acquire('category_' . $categoryid);
    }

    /**
     * Take one lock of this plugin's type.
     *
     * @param string $resource The resource, unique within the type.
     * @return \core\lock\lock The lock.
     * @throws state_busy_exception When another writer holds it past the timeout.
     */
    private static function acquire(string $resource): \core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory(self::TYPE);
        $lock = $factory->get_lock($resource, self::TIMEOUT, self::LIFETIME);
        if (!$lock) {
            throw new state_busy_exception();
        }
        return $lock;
    }
}
