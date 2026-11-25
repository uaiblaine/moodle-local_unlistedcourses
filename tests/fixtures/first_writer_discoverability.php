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
 * A course state class whose first insert meets another writer, for tests
 *
 * @package    local_unlistedcourses
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\fixtures;

use local_unlistedcourses\discoverability;

/**
 * Plays a writer that inserts without the course's lock, as default_backfill does, just before set_state()'s insert.
 *
 * Calling set_state() on this class reaches {@see insert_row()} through late static binding;
 * everything else, the catch and the decision after it included, is the production code. Every
 * error is the database's own: a collision is a real duplicate of the unique key on courseid, and
 * the other failure a real NOT NULL violation, which leaves no row for the course behind.
 *
 * @package    local_unlistedcourses
 * @category   test
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class first_writer_discoverability extends discoverability {
    /** @var string Insert the backfill's unlisted row for the same course first, then the row asked for. */
    public const COLLIDE = 'collide';

    /** @var string Insert the row asked for with no course id, which the database refuses. */
    public const REFUSE = 'refuse';

    /** @var string|null What the next insert does, then forgotten; null inserts as production does. */
    public static ?string $next = null;

    /**
     * Insert a course's first state row, after another writer, or refused by the database.
     *
     * @param \stdClass $record The row: courseid, state, usermodified and timemodified.
     * @return int The new row's id.
     * @throws \dml_write_exception From the database, on the collision or the refusal.
     */
    protected static function insert_row(\stdClass $record): int {
        global $DB;

        $next = self::$next;
        self::$next = null;
        if ($next === self::COLLIDE) {
            $DB->insert_record(discoverability::TABLE, (object) [
                'courseid' => $record->courseid,
                'state' => discoverability::STATE_UNLISTED,
                'usermodified' => 0,
                'timemodified' => time(),
            ]);
        } else if ($next === self::REFUSE) {
            $record = clone $record;
            $record->courseid = null;
        }
        return parent::insert_row($record);
    }
}
