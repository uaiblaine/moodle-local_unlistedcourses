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
 * Course discoverability - One statement per chunk of an id list a caller sized
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\local;

/**
 * Runs a read once per chunk of an id list, so no statement carries more parameters than a driver takes.
 *
 * get_records_list() and get_in_or_equal() emit one bind parameter per id and never chunk, and
 * PostgreSQL refuses a statement with more than 65,535 of them. A listing that hands this plugin
 * every id of a population would otherwise fail on that site and work on a smaller one.
 *
 * A list of up to {@see SIZE} ids is handed to the read whole, in one call, so a page costs
 * exactly the statements it cost before; only a longer list pays one statement per chunk. The
 * merge is a union by key, so the read must return an array keyed by something that belongs to
 * one id of its chunk (the id itself, or a row that hangs off it): then every key comes from one
 * chunk, and the union equals what one unchunked statement would have returned. A statement that
 * aggregates ACROSS ids (a count over the whole list, a DISTINCT over all of them) cannot be
 * merged this way and must not be handed here.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class id_chunks {
    /** @var int The most ids one statement carries: far under 65,535, leaving room for the statement's other parameters. */
    public const SIZE = 20000;

    /**
     * Run a read over a list of ids, once if it fits in one statement and once per chunk if not.
     *
     * @param array $ids The ids, as the read would have been handed them.
     * @param callable $read Takes one list of ids and returns an array keyed as the class docblock says.
     * @return array The read's result for the whole list: the union of every chunk's, first chunk first.
     */
    public static function read(array $ids, callable $read): array {
        $ids = array_values($ids);
        if (count($ids) <= self::SIZE) {
            return $read($ids);
        }
        $merged = [];
        foreach (array_chunk($ids, self::SIZE) as $chunk) {
            $merged += $read($chunk);
        }
        return $merged;
    }
}
