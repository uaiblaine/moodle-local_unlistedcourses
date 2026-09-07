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
 * Course category discoverability - The form on the category page
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\local\form;

use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\output\category_preview;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The discoverability state of one course category, plus the preview beside it.
 *
 * Two options and nothing else: a category has no public state, because nothing
 * serves a category page to a visitor who is not logged in. The strings are the
 * course side's own - a listed category and a listed course mean the same thing
 * to a reader, and two vocabularies for one idea would be worse than a shared one.
 *
 * NONE OF THIS IS A SECURITY BOUNDARY. The boundary is
 * {@see category_discoverability::set_state()}, which checks the manage
 * capability on every real transition whatever route reached it; the page checks
 * it too, so that nobody is shown a form their save will refuse.
 *
 * The preview is a static element rather than markup of the page, so that it sits
 * inside the form beside the control it explains, and so that it is rendered from
 * a template like everything else - the way core embeds a rendered widget in a
 * form row.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class categorystate extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition(): void {
        global $OUTPUT;

        $mform = $this->_form;
        $id = (int) $this->_customdata['id'];
        $category = $this->_customdata['category'];
        $context = $this->_customdata['context'];

        $mform->addElement('hidden', 'id', $id);
        $mform->setType('id', PARAM_INT);

        $options = [
            category_discoverability::STATE_DEFAULT => get_string('state_default', 'local_unlistedcourses'),
            category_discoverability::STATE_UNLISTED => get_string('state_unlisted', 'local_unlistedcourses'),
        ];
        $mform->addElement('select', 'state', get_string('categorystate', 'local_unlistedcourses'), $options);
        $mform->setType('state', PARAM_INT);
        $mform->setDefault('state', category_discoverability::get_state($id));
        $mform->addHelpButton('state', 'categorystate', 'local_unlistedcourses');

        /* Trusted server-rendered HTML: a static element writes its content raw, and this
           content is a template of this plugin's own with every name already escaped for
           the stash it lands in. */
        $preview = $OUTPUT->render(new category_preview($category, $context));
        $mform->addElement('static', 'preview', '', $preview);

        $this->add_action_buttons(true, get_string('savechanges'));
    }
}
