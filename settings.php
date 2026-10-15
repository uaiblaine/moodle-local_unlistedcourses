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
 * Course discoverability - Admin settings
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_unlistedcourses', new lang_string('pluginname', 'local_unlistedcourses'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        // Listed and unlisted only: see discoverability::default_states() for why public is not a choice.
        $choices = [
            \local_unlistedcourses\discoverability::STATE_DEFAULT => new lang_string('state_default', 'local_unlistedcourses'),
            \local_unlistedcourses\discoverability::STATE_UNLISTED => new lang_string('state_unlisted', 'local_unlistedcourses'),
        ];

        $settings->add(new admin_setting_configselect(
            'local_unlistedcourses/' . \local_unlistedcourses\discoverability::SETTING_DEFAULT,
            new lang_string('defaultstate', 'local_unlistedcourses'),
            new lang_string('defaultstate_desc', 'local_unlistedcourses'),
            \local_unlistedcourses\discoverability::STATE_DEFAULT,
            $choices
        ));

        $settings->add(new admin_setting_configselect(
            'local_unlistedcourses/' . \local_unlistedcourses\discoverability::SETTING_RESTORE_DEFAULT,
            new lang_string('restoredefaultstate', 'local_unlistedcourses'),
            new lang_string('restoredefaultstate_desc', 'local_unlistedcourses'),
            \local_unlistedcourses\discoverability::STATE_DEFAULT,
            $choices
        ));
    }
}
