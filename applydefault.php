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
 * Course discoverability - Apply the default for new courses to the courses that predate it
 *
 * Linked from the plugin's settings page. It counts the courses the default would reach,
 * asks for confirmation, and queues {@see \local_unlistedcourses\task\apply_default_state};
 * no course state is written in this request.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_unlistedcourses\local\default_backfill;

// Relative on purpose: the same path resolves on the pre-split and split layouts.
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Logs the user in and refuses anybody without moodle/site:config, the external page's capability.
admin_externalpage_setup('local_unlistedcourses_applydefault');

$url = new moodle_url('/local/unlistedcourses/applydefault.php');
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'local_unlistedcourses']);

if (optional_param('confirm', 0, PARAM_BOOL) && data_submitted()) {
    require_sesskey();
    // The cutoff the confirmation showed; queue() refuses it once the default has been saved again.
    $confirmed = required_param('cutoff', PARAM_INT);
    if (default_backfill::queue((int) $USER->id, $confirmed)) {
        redirect($url);
    }
    redirect($url, get_string('applydefault_stale', 'local_unlistedcourses'), null, \core\output\notification::NOTIFY_WARNING);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('applydefault', 'local_unlistedcourses'));

$state = default_backfill::state_to_apply();
$task = default_backfill::queued_task();
if ($task) {
    echo $OUTPUT->render(new \core\output\task_indicator(
        $task,
        heading: get_string('applydefault', 'local_unlistedcourses'),
        message: get_string('applydefault_queued', 'local_unlistedcourses'),
        redirecturl: $url,
    ));
} else if ($state === null) {
    echo $OUTPUT->notification(get_string('applydefault_listed', 'local_unlistedcourses'), 'info', false);
    echo $OUTPUT->continue_button($settingsurl);
} else {
    $cutoff = default_backfill::cutoff();
    $count = default_backfill::count_pending($cutoff);
    $a = (object) [
        'count' => $count,
        'date' => userdate($cutoff),
        'state' => get_string('state_unlisted', 'local_unlistedcourses'),
    ];
    if ($count === 0) {
        echo $OUTPUT->notification(get_string('applydefault_none', 'local_unlistedcourses', $a), 'info', false);
        echo $OUTPUT->continue_button($settingsurl);
    } else {
        echo $OUTPUT->confirm(
            get_string('applydefault_confirm', 'local_unlistedcourses', $a),
            new moodle_url($url, ['confirm' => 1, 'cutoff' => $cutoff]),
            $settingsurl
        );
    }
}

echo $OUTPUT->footer();
