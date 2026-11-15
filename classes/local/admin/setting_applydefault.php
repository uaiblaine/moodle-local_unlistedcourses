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

namespace local_unlistedcourses\local\admin;

/**
 * The "Apply the default to existing courses" item of the settings page.
 *
 * Core's description setting prints its text in one paragraph, which hid the action as a link
 * inside it. This one keeps what that setting is (no stored value, no form control, the title in
 * the label column, the row markup of core_admin/setting_description) and renders two paragraphs
 * and a button to the confirmation page instead.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_applydefault extends \admin_setting_description {
    /**
     * Builds the item.
     *
     * The description the parent keeps is the two paragraphs as plain text, so the admin search
     * finds the item by what it says, as it finds a description setting.
     *
     * @param string $name Setting name, with its plugin prefix.
     * @param string $visiblename The title shown in the label column.
     */
    public function __construct($name, $visiblename) {
        parent::__construct(
            $name,
            $visiblename,
            get_string('applydefault_reach', 'local_unlistedcourses') . ' ' .
                get_string('applydefault_next', 'local_unlistedcourses')
        );
    }

    /**
     * Returns the row, with the button that leads to the confirmation page.
     *
     * @param mixed $data Ignored: the item has no value.
     * @param string $query The admin search query, ignored.
     * @return string The row's HTML.
     */
    public function output_html($data, $query = '') {
        global $OUTPUT;

        return $OUTPUT->render_from_template('local_unlistedcourses/applydefault_setting', [
            'title' => $this->visiblename,
            'reach' => get_string('applydefault_reach', 'local_unlistedcourses'),
            'next' => get_string('applydefault_next', 'local_unlistedcourses'),
            'url' => (new \moodle_url('/local/unlistedcourses/applydefault.php'))->out(false),
            'action' => get_string('applydefault_action', 'local_unlistedcourses'),
            'savefirst' => get_string('applydefault_savefirst', 'local_unlistedcourses'),
        ]);
    }
}
