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
 * Course discoverability - Plugin callbacks core looks up by name
 *
 * Only the callbacks that have no hook equivalent live here. Course deletion
 * goes through the before_course_deleted hook (db/hooks.php); category
 * deletion has no hook and is announced through the two legacy callbacks
 * below, which core_course_category::delete_full() and delete_move() find
 * through get_plugins_with_function().
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_unlistedcourses\category_discoverability;

/**
 * A category is about to be deleted together with its contents.
 *
 * Core passes the category's database record. delete_full() recurses into
 * the children and calls this once per category, so nothing here walks the
 * subtree.
 *
 * @param stdClass $category The category record being deleted.
 * @return void
 */
function local_unlistedcourses_pre_course_category_delete($category): void {
    category_discoverability::on_category_deleted((int) $category->id);
}

/**
 * A category is about to be deleted with its contents moved to another category.
 *
 * Core passes the core_course_category object and the new parent. The
 * children and courses survive under the new parent, whose own state - if
 * any - is what applies to them from then on; only the deleted category's row
 * goes.
 *
 * @param core_course_category $category The category being deleted.
 * @param core_course_category $newparentcat The category receiving its contents.
 * @return void
 */
function local_unlistedcourses_pre_course_category_delete_move($category, $newparentcat): void {
    category_discoverability::on_category_deleted((int) $category->id);
}
