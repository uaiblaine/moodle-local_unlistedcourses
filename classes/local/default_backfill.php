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
 * Course discoverability - Apply the default state to the courses that predate it
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\local;

use local_unlistedcourses\access;
use local_unlistedcourses\discoverability;
use local_unlistedcourses\event\course_state_updated;
use local_unlistedcourses\task\apply_default_state;

/**
 * Gives the courses that predate the default for new courses that default.
 *
 * The default (local_unlistedcourses/defaultstate) reaches a course only when it is
 * created, so the courses a site had before the plugin was installed, or before an
 * administrator chose unlisted as the default, stay listed. This brings them into line,
 * once, on an administrator's request, from an adhoc task.
 *
 * A course qualifies when it has no row in the state table and was created before the
 * default took its current value. Both halves are what "nobody chose this course's state"
 * can be read from. A row is a state somebody or something stored, whatever its value,
 * and is never overwritten. Listed is the absence of a row, so a course somebody set to
 * listed looks exactly like a course nobody touched; the creation time tells them apart
 * for every course created since the default took effect, because each of those was
 * given the default at creation (a row, when the default is unlisted) unless somebody
 * chose listed on the form or a restore default said listed. Only a course that is older
 * than the default and was moved back to listed by hand cannot be told from a legacy one,
 * and the confirmation says so.
 *
 * When the default took effect is read from config_log, where core records every change
 * an administrator makes through the settings pages, install defaults included. A value
 * written by set_config() or the CLI leaves no record; then the latest record does not
 * hold the current value, and the cutoff is the moment the task is queued, so every
 * existing course counts as older than the default.
 *
 * Listed is the absence of a row, so a listed default has nothing to apply. Public is
 * never a default ({@see discoverability::default_states()}), so in practice this writes
 * unlisted rows only. Listed to unlisted is a transition {@see discoverability::set_state()}
 * deliberately does not gate, so writing the rows here bypasses no capability; the writes
 * are direct inserts, chunked, because the unique key on courseid is what keeps a course
 * whose state somebody set while the task ran from being overwritten. Each row fires
 * {@see course_state_updated}, as set_state() would, which is the log of what changed and
 * what block_compass's catalogue cache is purged by.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class default_backfill {
    /** @var int Courses written per transaction. */
    public const CHUNK_SIZE = 200;

    /**
     * The state the backfill would apply now, or null when there is nothing to apply.
     *
     * @return int|null {@see discoverability::STATE_UNLISTED}, or null when the default is listed.
     */
    public static function state_to_apply(): ?int {
        $state = discoverability::get_creation_default();
        return $state === discoverability::STATE_DEFAULT ? null : $state;
    }

    /**
     * When the default for new courses took its current value, as the settings page recorded it.
     *
     * The latest config_log record of the setting, when it holds the value the setting holds
     * now; null when there is no record or the latest one holds another value, as after a
     * set_config() or a CLI write (see the class docblock).
     *
     * @return int|null A timestamp, or null.
     */
    public static function recorded_cutoff(): ?int {
        global $DB;

        $current = get_config('local_unlistedcourses', discoverability::SETTING_DEFAULT);
        $records = $DB->get_records(
            'config_log',
            ['plugin' => 'local_unlistedcourses', 'name' => discoverability::SETTING_DEFAULT],
            'timemodified DESC, id DESC',
            'id, value, timemodified',
            0,
            1
        );
        $latest = reset($records);
        if ($latest && $current !== false && (string) $latest->value === (string) $current) {
            return (int) $latest->timemodified;
        }
        return null;
    }

    /**
     * The cutoff to offer now: the recorded one, or now when there is none.
     *
     * @return int A timestamp.
     */
    public static function cutoff(): int {
        return self::recorded_cutoff() ?? \core\di::get(\core\clock::class)->time();
    }

    /**
     * Whether a cutoff shown on an earlier page may still be queued.
     *
     * With a record, only that record's time: a default saved again since moved it. Without
     * one the cutoff offered was the moment the page was shown, so any moment up to now is
     * what the administrator saw, and nothing later is.
     *
     * @param int $cutoff The cutoff the confirmation showed.
     * @return bool True when it may be queued.
     */
    public static function is_current_cutoff(int $cutoff): bool {
        $recorded = self::recorded_cutoff();
        if ($recorded !== null) {
            return $cutoff === $recorded;
        }
        return $cutoff <= \core\di::get(\core\clock::class)->time();
    }

    /**
     * The statement that reads the courses the backfill applies to, from an id onwards.
     *
     * No row in the state table, not the site course, created before the cutoff, in id
     * order. The count reads the same join as the rows, so a course the run cannot log
     * (one without a context) is not promised on the confirmation either. The course
     * context columns ride along so the event of each row finds its context without a
     * statement of its own.
     *
     * @param int $cutoff Only courses created strictly before this time qualify.
     * @param int $afterid Only courses with a greater id qualify.
     * @param bool $count True for the COUNT, false for the rows.
     * @return array The SQL and its parameters, as [string, array].
     */
    private static function pending_sql(int $cutoff, int $afterid, bool $count): array {
        $select = $count
            ? 'COUNT(1)'
            : 'c.id, ' . \context_helper::get_preload_record_columns_sql('ctx');
        $sql = "SELECT {$select}
                  FROM {course} c
                  JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel
             LEFT JOIN {" . discoverability::TABLE . "} s ON s.courseid = c.id
                 WHERE s.id IS NULL
                   AND c.id <> :siteid
                   AND c.timecreated < :cutoff
                   AND c.id > :afterid";
        if (!$count) {
            $sql .= ' ORDER BY c.id';
        }
        return [$sql, [
            'contextlevel' => CONTEXT_COURSE,
            'siteid' => SITEID,
            'cutoff' => $cutoff,
            'afterid' => $afterid,
        ]];
    }

    /**
     * How many courses the backfill would change if it ran now with this cutoff.
     *
     * @param int $cutoff Only courses created strictly before this time count.
     * @return int The number of courses.
     */
    public static function count_pending(int $cutoff): int {
        global $DB;

        [$sql, $params] = self::pending_sql($cutoff, 0, true);
        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * The backfill task already queued or running, if there is one.
     *
     * @return apply_default_state|null The task, or null.
     */
    public static function queued_task(): ?apply_default_state {
        $tasks = \core\task\manager::get_adhoc_tasks('\\' . apply_default_state::class);
        $task = reset($tasks);
        return $task ?: null;
    }

    /**
     * Queue the backfill for the current default, unless one is already queued.
     *
     * The page calls this after its sesskey check, with the cutoff its confirmation showed;
     * it writes no course state itself. Two checks it can make itself are made here rather
     * than left to the caller: the user asking must hold moodle/site:config, the page's own
     * capability, because the task records them on every row it writes and runs with nobody
     * logged in; and the cutoff must still be the current one, because the administrator
     * confirmed the count of courses created before that date, and a default saved again
     * since would move it.
     *
     * @param int $userid The administrator asking for it, recorded as the one who changed each course.
     * @param int $cutoff The cutoff the confirmation showed.
     * @return apply_default_state|null The queued task, or null when the default is listed, a task is
     *     already queued, or the cutoff is no longer the current one.
     * @throws \required_capability_exception When the user may not configure the site.
     */
    public static function queue(int $userid, int $cutoff): ?apply_default_state {
        require_capability('moodle/site:config', \core\context\system::instance(), $userid);

        $state = self::state_to_apply();
        if ($state === null || self::queued_task() || !self::is_current_cutoff($cutoff)) {
            return null;
        }

        $task = apply_default_state::create($state, $cutoff, $userid);
        $taskid = \core\task\manager::queue_adhoc_task($task, true);
        if (!$taskid) {
            return null;
        }
        $task->set_id($taskid);
        $task->initialise_stored_progress();
        return $task;
    }

    /**
     * Give every qualifying course the state, a chunk at a time.
     *
     * Each chunk is read fresh, so a course that gained a state since the previous one is
     * skipped, and written in one transaction. A dml_write_exception inside a transaction
     * poisons it on PostgreSQL, so on one the chunk is rolled back and replayed course by
     * course without a transaction; there the exception is either a concurrent writer's row
     * (the unique key on courseid) or a genuine failure, and the table says which. The
     * events fire after the chunk commits, so a rolled-back write is never logged.
     *
     * @param int $state The state to apply; must be a default other than listed.
     * @param int $cutoff Only courses created strictly before this time qualify.
     * @param int $userid The user recorded as having changed each course.
     * @param \core\progress\base|null $progress Progress to report to, one step per chunk.
     * @param int $chunksize Courses per transaction.
     * @return array Counts keyed 'applied' (rows written), 'skipped' (a state appeared meanwhile) and
     *     'failed' (written by nobody).
     * @throws \coding_exception When the state is not a default other than listed.
     */
    public static function run(
        int $state,
        int $cutoff,
        int $userid,
        ?\core\progress\base $progress = null,
        int $chunksize = self::CHUNK_SIZE
    ): array {
        global $DB;

        if ($state === discoverability::STATE_DEFAULT || !in_array($state, discoverability::default_states(), true)) {
            throw new \coding_exception("The state '{$state}' is not a default the backfill may apply.");
        }

        $counts = ['applied' => 0, 'skipped' => 0, 'failed' => 0];
        // One step per chunk. A course created with a back-dated timecreated while this runs could
        // add a chunk, so the steps stop at the count announced rather than overflow it.
        $steps = max(1, (int) ceil(self::count_pending($cutoff) / $chunksize));
        $done = 0;
        if ($progress) {
            $progress->start_progress('', $steps);
        }

        $afterid = 0;
        while (true) {
            [$sql, $params] = self::pending_sql($cutoff, $afterid, false);
            $records = $DB->get_records_sql($sql, $params, 0, $chunksize);
            if (!$records) {
                break;
            }
            $courseids = [];
            foreach ($records as $record) {
                \context_helper::preload_from_record($record);
                $courseids[] = (int) $record->id;
            }
            $afterid = max($courseids);

            $applied = self::write_chunk($courseids, $state, $userid, $counts);
            foreach ($applied as $courseid) {
                self::log_change($courseid, $state, $userid);
            }
            if ($progress && $done < $steps) {
                $progress->increment_progress();
                $done++;
            }
        }

        // The discoverability answers of this process were computed from the old rows.
        access::reset_caches();
        if ($progress) {
            $progress->end_progress();
        }
        return $counts;
    }

    /**
     * Write one chunk, in a transaction, replaying it course by course if the transaction fails.
     *
     * @param array $courseids The course ids of the chunk.
     * @param int $state The state to write.
     * @param int $userid The user recorded on each row.
     * @param array $counts The running counts, updated in place.
     * @return array The course ids whose row this call wrote.
     */
    private static function write_chunk(array $courseids, int $state, int $userid, array &$counts): array {
        global $DB;

        $now = \core\di::get(\core\clock::class)->time();
        $transaction = $DB->start_delegated_transaction();
        try {
            foreach ($courseids as $courseid) {
                $DB->insert_record(discoverability::TABLE, self::row($courseid, $state, $userid, $now), false);
            }
            $transaction->allow_commit();
            $counts['applied'] += count($courseids);
            return $courseids;
        } catch (\dml_write_exception $e) {
            try {
                $transaction->rollback($e);
            } catch (\dml_write_exception $ignored) {
                // Rollback rethrows; the replay below recovers.
                $ignored = null;
            }
        }

        $applied = [];
        foreach ($courseids as $courseid) {
            try {
                $DB->insert_record(discoverability::TABLE, self::row($courseid, $state, $userid, $now), false);
                $applied[] = $courseid;
                $counts['applied']++;
            } catch (\dml_write_exception $e) {
                // A duplicate key is somebody else's state, set since the chunk was read: keep it.
                if ($DB->record_exists(discoverability::TABLE, ['courseid' => $courseid])) {
                    $counts['skipped']++;
                } else {
                    $counts['failed']++;
                }
            }
        }
        return $applied;
    }

    /**
     * The state row of one course.
     *
     * @param int $courseid The course id.
     * @param int $state The state.
     * @param int $userid The user recorded as having changed it.
     * @param int $now The time of the change.
     * @return \stdClass The row.
     */
    private static function row(int $courseid, int $state, int $userid, int $now): \stdClass {
        return (object) [
            'courseid' => $courseid,
            'state' => $state,
            'usermodified' => $userid,
            'timemodified' => $now,
        ];
    }

    /**
     * Fire the event set_state() fires for the change it would have made.
     *
     * @param int $courseid The course id.
     * @param int $state The new state.
     * @param int $userid The user recorded as having changed it.
     * @return void
     */
    private static function log_change(int $courseid, int $state, int $userid): void {
        $context = \core\context\course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return;
        }
        course_state_updated::create([
            'context' => $context,
            'objectid' => $courseid,
            'userid' => $userid,
            'other' => ['oldstate' => discoverability::STATE_DEFAULT, 'newstate' => $state],
        ])->trigger();
    }
}
