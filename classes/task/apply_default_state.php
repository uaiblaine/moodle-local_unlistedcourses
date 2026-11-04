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
 * Course discoverability - Adhoc task that applies the default state to older courses
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\task;

use core\task\adhoc_task;
use core\task\stored_progress_task_trait;
use local_unlistedcourses\discoverability;
use local_unlistedcourses\local\default_backfill;

/**
 * Applies the default for new courses to every course that predates it and has no state.
 *
 * Queued from the plugin's "apply the default" page by {@see default_backfill::queue()},
 * which records the state, the cutoff and the administrator as they were when the
 * administrator confirmed the count. The task re-reads the default before writing: when it
 * no longer holds the state queued, the administrator has changed their mind since, and
 * nothing is written. It never throws over that, nor over custom data it cannot read,
 * because a throwing adhoc task is retried for ever.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_default_state extends adhoc_task {
    use stored_progress_task_trait;

    /**
     * Build the task for one request.
     *
     * @param int $state The default to apply.
     * @param int $cutoff Only courses created strictly before this time are changed.
     * @param int $userid The administrator who asked for it.
     * @return self
     */
    public static function create(int $state, int $cutoff, int $userid): self {
        $task = new self();
        $task->set_component('local_unlistedcourses');
        $task->set_custom_data(['state' => $state, 'cutoff' => $cutoff, 'userid' => $userid]);
        return $task;
    }

    /**
     * The task's name, as the task logs and the adhoc task list show it.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('applydefault_task', 'local_unlistedcourses');
    }

    /**
     * Apply the default.
     *
     * @return void
     */
    public function execute(): void {
        $this->start_stored_progress();

        $data = $this->get_custom_data();
        $state = isset($data->state) ? (int) $data->state : discoverability::STATE_DEFAULT;
        $cutoff = isset($data->cutoff) ? (int) $data->cutoff : 0;
        $userid = isset($data->userid) ? (int) $data->userid : 0;

        $progress = $this->get_progress();
        if ($state !== default_backfill::state_to_apply()) {
            mtrace(get_string('applydefault_changed', 'local_unlistedcourses'));
            // Complete the bar the page is polling, or it would wait on a task that is gone.
            $progress->start_progress('', 1);
            $progress->end_progress();
            return;
        }

        $counts = default_backfill::run($state, $cutoff, $userid, $progress);
        mtrace(get_string('applydefault_done', 'local_unlistedcourses', (object) $counts));
    }
}
