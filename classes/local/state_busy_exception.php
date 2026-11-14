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
 * Course discoverability - Another writer holds a state's lock
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\local;

/**
 * Thrown when another writer of the same course or category state holds its lock past the wait.
 *
 * A class of its own, so a caller that must carry on - the restore, which must not fail a whole
 * course over its discoverability - catches exactly this and nothing else a write can throw.
 * Nothing was written when it is thrown.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class state_busy_exception extends \moodle_exception {
    /**
     * The plugin's own message, which tells the person to try again.
     */
    public function __construct() {
        parent::__construct('statebusy', 'local_unlistedcourses');
    }
}
