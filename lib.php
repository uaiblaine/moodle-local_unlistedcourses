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
 * Only the callbacks that have no hook equivalent live here. Course and
 * category deletion go through the course_deleted and course_category_deleted
 * events (db/events.php): core runs the pre-delete callbacks before the deletion
 * is certain ({@see \local_unlistedcourses\observer}), so the one of them
 * implemented here only reads.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\local\delete_move_guard;

/**
 * Put the category discoverability page in a course category's settings menu.
 *
 * There is no hook and no callback on the category settings form:
 * course/editcategory.php builds core_course_editcategory_form and dispatches
 * nothing, and categories have no custom field handler either. So the control
 * is a page of this plugin's own, hung on the 'categorysettings' container
 * core builds in settings_navigation::load_category_settings(), which
 * secondary::load_category_navigation() sweeps into the category page's "More"
 * menu. The default placement is what puts it there, so
 * set_show_in_secondary_navigation(false) is deliberately not called.
 *
 * This runs on every page of the site, so the cheapest guard comes first: the
 * context class before the capability, because has_capability() resolves role
 * definitions that every course, activity and profile page would otherwise pay
 * for, and the container lookup last, because find() walks the tree.
 *
 * @param settings_navigation $settingsnav The settings navigation being built.
 * @param \core\context|null $context The context of the page being rendered.
 * @return void
 */
function local_unlistedcourses_extend_settings_navigation(settings_navigation $settingsnav, ?\core\context $context): void {
    if (!$context instanceof \core\context\coursecat) {
        return;
    }
    if (!has_capability(category_discoverability::CAPABILITY_MANAGE, $context)) {
        return;
    }
    $node = $settingsnav->find('categorysettings', \navigation_node::TYPE_CONTAINER);
    if (!$node) {
        return;
    }
    $node->add(
        get_string('categorystate', 'local_unlistedcourses'),
        new moodle_url('/local/unlistedcourses/category.php', ['id' => $context->instanceid]),
        \navigation_node::TYPE_SETTING,
        null,
        'unlistedcoursescatstate',
        new pix_icon('i/hide', '')
    );
}

/**
 * Record, before core deletes a category and moves its contents, which public rows below it are inert.
 *
 * Reads only: core can still abort the deletion after this runs, and a state written here would
 * then be wrong for a category that still exists. The course_category_deleted observer settles
 * what is recorded once the category is gone ({@see delete_move_guard}).
 *
 * @param core_course_category $category The category being deleted.
 * @param core_course_category $newparent The category its contents move to.
 * @return void
 */
function local_unlistedcourses_pre_course_category_delete_move(
    core_course_category $category,
    core_course_category $newparent
): void {
    delete_move_guard::record($category);
}
