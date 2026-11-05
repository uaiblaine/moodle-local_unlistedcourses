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

namespace local_unlistedcourses\local;

use local_unlistedcourses\discoverability;
use local_unlistedcourses\event\course_state_updated;
use local_unlistedcourses\task\apply_default_state;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Course discoverability - Tests for applying the default to the courses that predate it
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(default_backfill::class)]
#[CoversClass(apply_default_state::class)]
final class default_backfill_test extends \advanced_testcase {
    /** @var int When the tests choose the default: every "legacy" course is created before it. */
    private const CHOSEN = 1800000000;

    /**
     * Choose the default for new courses the way the settings page does: the value and its config_log record.
     *
     * @param int $state The default.
     * @param int $when When the change is recorded as made.
     * @return void
     */
    private function choose_default(int $state, int $when = self::CHOSEN): void {
        global $DB;

        $old = (string) get_config('local_unlistedcourses', discoverability::SETTING_DEFAULT);
        set_config(discoverability::SETTING_DEFAULT, $state, 'local_unlistedcourses');
        add_to_config_log(discoverability::SETTING_DEFAULT, $old, (string) $state, 'local_unlistedcourses');
        $id = $DB->get_field_sql('SELECT MAX(id) FROM {config_log}');
        $DB->set_field('config_log', 'timemodified', $when, ['id' => $id]);
    }

    /**
     * A course created at a given time.
     *
     * @param int $timecreated When the course is recorded as created.
     * @return int The course id.
     */
    private function course_created_at(int $timecreated): int {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $DB->set_field('course', 'timecreated', $timecreated, ['id' => $course->id]);
        return (int) $course->id;
    }

    /**
     * The stored row of a course, or false.
     *
     * @param int $courseid The course id.
     * @return \stdClass|false The row.
     */
    private function row(int $courseid) {
        global $DB;

        return $DB->get_record(discoverability::TABLE, ['courseid' => $courseid]);
    }

    /**
     * The course_state_updated events of a sink, keyed by course id.
     *
     * @param \phpunit_event_sink $sink The sink.
     * @return array Map of courseid => event.
     */
    private function state_events(\phpunit_event_sink $sink): array {
        $events = [];
        foreach ($sink->get_events() as $event) {
            if ($event instanceof course_state_updated) {
                $events[(int) $event->objectid] = $event;
            }
        }
        return $events;
    }

    /**
     * Only a course that predates the default and has no row gets it; every row and every newer course is left alone.
     *
     * @return void
     */
    public function test_only_older_courses_without_a_state_get_the_default(): void {
        global $DB;

        $this->resetAfterTest();
        $legacy = $this->course_created_at(self::CHOSEN - 1000);
        $public = $this->course_created_at(self::CHOSEN - 1000);
        $unlisted = $this->course_created_at(self::CHOSEN - 1000);
        $unknown = $this->course_created_at(self::CHOSEN - 1000);
        $newer = $this->course_created_at(self::CHOSEN);
        $this->setAdminUser();
        discoverability::set_state($public, discoverability::STATE_PUBLIC);
        discoverability::set_state($unlisted, discoverability::STATE_UNLISTED);
        $DB->insert_record(discoverability::TABLE, (object) [
            'courseid' => $unknown, 'state' => 7, 'usermodified' => 0, 'timemodified' => 1,
        ]);
        $DB->set_field(discoverability::TABLE, 'usermodified', 0, ['courseid' => $unlisted]);
        $this->choose_default(discoverability::STATE_UNLISTED);
        $admin = (int) get_admin()->id;

        $this->assertSame(1, default_backfill::count_pending(default_backfill::cutoff()));
        // Memoised as listed, so the read after the run proves the memo was dropped.
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state($legacy));

        $sink = $this->redirectEvents();
        $counts = default_backfill::run(discoverability::STATE_UNLISTED, default_backfill::cutoff(), $admin);
        $events = $this->state_events($sink);
        $sink->close();

        $this->assertSame(['applied' => 1, 'skipped' => 0, 'failed' => 0], $counts);
        $this->assertSame(discoverability::STATE_UNLISTED, (int) $this->row($legacy)->state);
        $this->assertSame($admin, (int) $this->row($legacy)->usermodified);
        $this->assertSame(discoverability::STATE_PUBLIC, (int) $this->row($public)->state);
        $this->assertSame(0, (int) $this->row($unlisted)->usermodified, 'A stored unlisted row was rewritten.');
        $this->assertSame(7, (int) $this->row($unknown)->state, 'A row holding an unknown value was overwritten.');
        $this->assertFalse($this->row($newer), 'A course created once the default was in force lost its listed choice.');
        $this->assertFalse($this->row(SITEID), 'The site course was given a state.');
        $this->assertSame(discoverability::STATE_UNLISTED, discoverability::get_state($legacy));

        $this->assertSame([$legacy], array_keys($events));
        $this->assertSame($admin, (int) $events[$legacy]->userid);
        $this->assertSame(
            ['oldstate' => discoverability::STATE_DEFAULT, 'newstate' => discoverability::STATE_UNLISTED],
            $events[$legacy]->other
        );
    }

    /**
     * The site course is never counted nor written, even when it predates the default.
     *
     * The control is an ordinary course of the same age, which is.
     *
     * @return void
     */
    public function test_the_site_course_is_never_given_the_default(): void {
        global $DB;

        $this->resetAfterTest();
        $DB->set_field('course', 'timecreated', self::CHOSEN - 1000, ['id' => SITEID]);
        $control = $this->course_created_at(self::CHOSEN - 1000);
        $this->choose_default(discoverability::STATE_UNLISTED);

        $this->assertSame(1, default_backfill::count_pending(self::CHOSEN));
        default_backfill::run(discoverability::STATE_UNLISTED, self::CHOSEN, (int) get_admin()->id);

        $this->assertFalse($this->row(SITEID));
        $this->assertNotFalse($this->row($control));
    }

    /**
     * A second run changes nothing and logs nothing.
     *
     * @return void
     */
    public function test_a_second_run_changes_nothing(): void {
        $this->resetAfterTest();
        $first = $this->course_created_at(self::CHOSEN - 1000);
        $second = $this->course_created_at(self::CHOSEN - 1000);
        $this->choose_default(discoverability::STATE_UNLISTED);
        $admin = (int) get_admin()->id;

        $counts = default_backfill::run(discoverability::STATE_UNLISTED, self::CHOSEN, $admin);
        $this->assertSame(2, $counts['applied']);
        $before = [$this->row($first), $this->row($second)];

        $sink = $this->redirectEvents();
        $counts = default_backfill::run(discoverability::STATE_UNLISTED, self::CHOSEN, $admin);
        $events = $this->state_events($sink);
        $sink->close();

        $this->assertSame(['applied' => 0, 'skipped' => 0, 'failed' => 0], $counts);
        $this->assertSame([], $events);
        $this->assertEquals($before, [$this->row($first), $this->row($second)]);
        $this->assertSame(0, default_backfill::count_pending(self::CHOSEN));
    }

    /**
     * Data for the chunk boundary test: course counts around a chunk size of two.
     *
     * @return array
     */
    public static function chunk_provider(): array {
        return [
            'fewer than one chunk' => [1],
            'exactly one chunk' => [2],
            'one more than a chunk' => [3],
            'exactly two chunks' => [4],
            'two chunks and one' => [5],
        ];
    }

    /**
     * Every qualifying course is written whatever the number of courses against the chunk size.
     *
     * A course that already has a state sits between them, so a chunk is read past it.
     *
     * @param int $howmany How many qualifying courses.
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('chunk_provider')]
    public function test_every_course_is_written_across_chunk_boundaries(int $howmany): void {
        $this->resetAfterTest();
        $courseids = [];
        for ($i = 0; $i < $howmany; $i++) {
            $courseids[] = $this->course_created_at(self::CHOSEN - 1000);
            if ($i === 0) {
                $set = $this->course_created_at(self::CHOSEN - 1000);
                $this->setAdminUser();
                discoverability::set_state($set, discoverability::STATE_PUBLIC);
            }
        }
        $this->choose_default(discoverability::STATE_UNLISTED);

        $sink = $this->redirectEvents();
        $counts = default_backfill::run(discoverability::STATE_UNLISTED, self::CHOSEN, (int) get_admin()->id, null, 2);
        $events = $this->state_events($sink);
        $sink->close();

        $this->assertSame($howmany, $counts['applied']);
        $this->assertEqualsCanonicalizing($courseids, array_keys($events));
        foreach ($courseids as $courseid) {
            $this->assertSame(discoverability::STATE_UNLISTED, (int) $this->row($courseid)->state);
        }
        $this->assertSame(discoverability::STATE_PUBLIC, (int) $this->row($set)->state);
    }

    /**
     * A chunk whose transaction fails on a row somebody wrote meanwhile is replayed course by course.
     *
     * The second course gains its row after the chunk was read, which is what a teacher saving the
     * course form while the task runs does: its insert fails on the unique key. The replay keeps that
     * row and still writes the first course, and only the course actually written is logged.
     *
     * @return void
     */
    public function test_a_row_written_meanwhile_is_kept_and_the_rest_of_the_chunk_written(): void {
        global $DB;

        $this->resetAfterTest();
        // The replay needs a real rollback, which a transaction nested in the test's own never gets.
        $this->preventResetByRollback();
        $first = $this->course_created_at(self::CHOSEN - 1000);
        $raced = $this->course_created_at(self::CHOSEN - 1000);
        $DB->insert_record(discoverability::TABLE, (object) [
            'courseid' => $raced, 'state' => discoverability::STATE_PUBLIC, 'usermodified' => 0, 'timemodified' => 1,
        ]);

        $method = new \ReflectionMethod(default_backfill::class, 'write_chunk');
        $counts = ['applied' => 0, 'skipped' => 0, 'failed' => 0];
        $applied = $method->invokeArgs(null, [[$first, $raced], discoverability::STATE_UNLISTED, 2, &$counts]);

        $this->assertSame([$first], $applied);
        $this->assertSame(['applied' => 1, 'skipped' => 1, 'failed' => 0], $counts);
        $this->assertSame(discoverability::STATE_UNLISTED, (int) $this->row($first)->state);
        $this->assertSame(discoverability::STATE_PUBLIC, (int) $this->row($raced)->state);
    }

    /**
     * Only a default other than listed may be applied.
     *
     * @return void
     */
    public function test_run_refuses_a_state_that_is_not_an_unlisted_default(): void {
        $this->resetAfterTest();
        $courseid = $this->course_created_at(self::CHOSEN - 1000);

        foreach ([discoverability::STATE_DEFAULT, discoverability::STATE_PUBLIC] as $state) {
            try {
                default_backfill::run($state, self::CHOSEN, 2);
                $this->fail("The state {$state} was applied.");
            } catch (\coding_exception $e) {
                $this->assertStringContainsString('not a default the backfill may apply', $e->getMessage());
            }
        }
        $this->assertFalse($this->row($courseid));

        // Control: the unlisted default is applied.
        default_backfill::run(discoverability::STATE_UNLISTED, self::CHOSEN, 2);
        $this->assertNotFalse($this->row($courseid));
    }

    /**
     * The cutoff is when the settings page recorded the current value, and now when it recorded none.
     *
     * @return void
     */
    public function test_the_cutoff_is_when_the_current_default_was_recorded(): void {
        $this->resetAfterTest();
        $clock = $this->mock_clock_with_frozen(self::CHOSEN + 5000);

        $this->choose_default(discoverability::STATE_UNLISTED, self::CHOSEN);
        $this->assertSame(self::CHOSEN, default_backfill::cutoff());

        // A later record of another value, then the value set again without a record: now.
        $this->choose_default(discoverability::STATE_DEFAULT, self::CHOSEN + 100);
        set_config(discoverability::SETTING_DEFAULT, discoverability::STATE_UNLISTED, 'local_unlistedcourses');
        $this->assertSame($clock->time(), default_backfill::cutoff());

        // The latest record wins over an older one holding the same value.
        $this->choose_default(discoverability::STATE_UNLISTED, self::CHOSEN + 200);
        $this->assertSame(self::CHOSEN + 200, default_backfill::cutoff());
    }

    /**
     * A listed default has nothing to apply and queues nothing; an unlisted one queues one task, once.
     *
     * @return void
     */
    public function test_queue_needs_an_unlisted_default_and_queues_once(): void {
        $this->resetAfterTest();
        $admin = (int) get_admin()->id;

        $this->assertNull(default_backfill::state_to_apply());
        $this->assertNull(default_backfill::queue($admin, default_backfill::cutoff()));
        $this->assertNull(default_backfill::queued_task());

        $this->choose_default(discoverability::STATE_UNLISTED);
        $task = default_backfill::queue($admin, self::CHOSEN);
        $this->assertInstanceOf(apply_default_state::class, $task);
        $this->assertEquals(
            (object) ['state' => discoverability::STATE_UNLISTED, 'cutoff' => self::CHOSEN, 'userid' => $admin],
            $task->get_custom_data()
        );
        $this->assertNotNull(default_backfill::queued_task());

        // A later record gives a different cutoff, so core's own duplicate check would let a second task in.
        $this->choose_default(discoverability::STATE_UNLISTED, self::CHOSEN + 50);
        $this->assertNull(default_backfill::queue($admin, self::CHOSEN + 50), 'A second task was queued beside the first.');
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\\' . apply_default_state::class));
    }

    /**
     * Only the cutoff the confirmation showed is queued, and only while it is still the current one.
     *
     * @return void
     */
    public function test_queue_refuses_a_cutoff_that_is_no_longer_current(): void {
        $this->resetAfterTest();
        $admin = (int) get_admin()->id;
        $clock = $this->mock_clock_with_frozen(self::CHOSEN + 5000);

        // Recorded: the page showed CHOSEN, then the default was saved again.
        $this->choose_default(discoverability::STATE_UNLISTED);
        $this->choose_default(discoverability::STATE_UNLISTED, self::CHOSEN + 100);
        $this->assertNull(default_backfill::queue($admin, self::CHOSEN), 'A stale confirmation was queued.');
        $this->assertNull(default_backfill::queued_task());

        // Not recorded: the page showed a moment that has passed, which is still what the admin saw; never a later one.
        $this->choose_default(discoverability::STATE_DEFAULT, self::CHOSEN + 200);
        set_config(discoverability::SETTING_DEFAULT, discoverability::STATE_UNLISTED, 'local_unlistedcourses');
        $this->assertNull(default_backfill::recorded_cutoff());
        $this->assertNull(default_backfill::queue($admin, $clock->time() + 1), 'A cutoff in the future was queued.');
        $this->assertNotNull(default_backfill::queue($admin, $clock->time() - 10));
        $this->assertSame($clock->time() - 10, (int) default_backfill::queued_task()->get_custom_data()->cutoff);
    }

    /**
     * Queueing asks moodle/site:config of the user it records, whoever calls it.
     *
     * @return void
     */
    public function test_queue_refuses_a_user_who_may_not_configure_the_site(): void {
        global $DB;

        $this->resetAfterTest();
        $this->choose_default(discoverability::STATE_UNLISTED);
        $manager = $this->getDataGenerator()->create_user();
        role_assign(
            (int) $DB->get_field('role', 'id', ['shortname' => 'manager']),
            $manager->id,
            \core\context\system::instance()
        );

        try {
            default_backfill::queue((int) $manager->id, self::CHOSEN);
            $this->fail('A manager queued the backfill.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertNull(default_backfill::queued_task());

        // Control: an administrator may.
        $this->assertNotNull(default_backfill::queue((int) get_admin()->id, self::CHOSEN));
    }

    /**
     * Queueing writes no course state; the cron run of the task does.
     *
     * @return void
     */
    public function test_the_queued_task_applies_the_default_when_the_cron_runs(): void {
        $this->resetAfterTest();
        $legacy = $this->course_created_at(self::CHOSEN - 1000);
        $this->choose_default(discoverability::STATE_UNLISTED);

        $this->assertNotNull(default_backfill::queue((int) get_admin()->id, self::CHOSEN));
        $this->assertFalse($this->row($legacy), 'Queueing the task wrote a state.');

        ob_start();
        $this->run_all_adhoc_tasks();
        ob_end_clean();

        $this->assertSame(discoverability::STATE_UNLISTED, (int) $this->row($legacy)->state);
        $this->assertNull(default_backfill::queued_task());
    }

    /**
     * A task whose default was changed back to listed before the cron ran writes nothing.
     *
     * The control is the same task against an unchanged default, in the previous test.
     *
     * @return void
     */
    public function test_a_task_whose_default_changed_writes_nothing(): void {
        $this->resetAfterTest();
        $legacy = $this->course_created_at(self::CHOSEN - 1000);
        $this->choose_default(discoverability::STATE_UNLISTED);
        $this->assertNotNull(default_backfill::queue((int) get_admin()->id, self::CHOSEN));
        $this->choose_default(discoverability::STATE_DEFAULT, self::CHOSEN + 100);

        ob_start();
        $this->run_all_adhoc_tasks();
        $output = ob_get_clean();

        $this->assertFalse($this->row($legacy));
        $this->assertStringContainsString(get_string('applydefault_changed', 'local_unlistedcourses'), $output);
        $this->assertNull(default_backfill::queued_task());
    }
}
