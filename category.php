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
 * Course category discoverability - The page that sets one category's state
 *
 * Reached from the category's own settings menu, which core sweeps into the
 * "More" menu of the category page. It exists because core dispatches no hook
 * on the category settings form, and it carries what that form never could: a
 * preview of who will still see the category once it is unlisted.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\local\form\categorystate;

// Relative on purpose: the same path resolves on the pre-split and split layouts.
require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

require_login();

/* Core's own visibility answer FIRST. set_category_by_id() reads the raw record and
   checks nothing, so this get() is the only thing standing between a category the
   viewer may not see and a page naming it. Never reorder these two. */
$category = core_course_category::get($id, MUST_EXIST);
$context = \core\context\coursecat::instance($id);

// Sets the page context to the category's own, so no set_context() call is needed here.
$PAGE->set_category_by_id($id);
$PAGE->set_url(new moodle_url('/local/unlistedcourses/category.php', ['id' => $id]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('categorystate', 'local_unlistedcourses'));
// Escaped spelling: the page heading is rendered raw.
$PAGE->set_heading($category->get_formatted_name());

/* UX only: nobody is shown a form their save will refuse. The boundary is
   category_discoverability::set_state(), which checks this same capability on every
   real transition whatever route reached it. */
require_capability(category_discoverability::CAPABILITY_MANAGE, $context);

$form = new categorystate($PAGE->url, [
    'id' => $id,
    'category' => $category,
    'context' => $context,
]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/index.php', ['categoryid' => $id]));
} else if ($data = $form->get_data()) {
    category_discoverability::set_state($id, (int) $data->state);
    /* Back to this page rather than to the category, so the preview is re-read against
       the state just saved. A save that changes nothing still confirms: set_state()
       short-circuits, and "nothing happened" is not what an administrator asked for. */
    redirect(
        $PAGE->url,
        get_string('categorystate_saved', 'local_unlistedcourses'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('categorystate', 'local_unlistedcourses'));
$form->display();
echo $OUTPUT->footer();
