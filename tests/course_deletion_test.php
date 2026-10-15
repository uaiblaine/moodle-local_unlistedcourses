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

namespace local_unlistedcourses;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unlisted courses - Tests for the state row of a course that is deleted
 *
 * Moodle 5.3 can delete a course asynchronously: the request runs the pre_course_delete
 * callbacks and the before_course_deleted hook, hides the course and queues the deletion, and
 * the cron performs it later without running them again. These tests run that real path.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(observer::class)]
final class course_deletion_test extends \advanced_testcase {
    /**
     * Queue the deletion of a course the way the course management page does.
     *
     * @param int $courseid The course id.
     * @return void
     */
    private function delete_asynchronously(int $courseid): void {
        set_config('enablecourseasyncdeletion', 1, 'moodlecourse');
        delete_course($courseid, false);
    }

    /**
     * Run the adhoc tasks queued so far, as the cron would.
     *
     * @return void
     */
    private function run_the_cron(): void {
        ob_start();
        $this->run_all_adhoc_tasks();
        ob_end_clean();
    }

    /**
     * An asynchronous deletion keeps the state until the course is gone, and then drops it.
     *
     * Between the request and the cron the course still exists, hidden by core, and its state is
     * still what the owner chose: an unlisted course stays withheld from an outsider. Dropping the row at
     * request time, as the before_course_deleted hook did, would have listed it again for anyone
     * core lets see a hidden course, and lost the state for good if the deletion never finished.
     *
     * @return void
     */
    public function test_an_asynchronous_deletion_keeps_the_state_until_the_course_is_gone(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $doomed = $generator->create_course();
        $control = $generator->create_course();
        $this->setAdminUser();
        discoverability::set_state((int) $doomed->id, discoverability::STATE_UNLISTED);
        discoverability::set_state((int) $control->id, discoverability::STATE_UNLISTED);

        $outsider = $generator->create_user();
        $this->setUser($outsider);
        access::reset_caches();
        $this->assertFalse(access::is_course_discoverable((int) $doomed->id), 'Precondition: an unlisted course is withheld.');

        $this->setAdminUser();
        $this->delete_asynchronously((int) $doomed->id);

        $marked = $DB->get_record('course', ['id' => $doomed->id], 'id, visible, deletioninprogress', MUST_EXIST);
        $this->assertSame(1, (int) $marked->deletioninprogress, 'Precondition: the deletion is queued, not done.');
        $this->assertSame(0, (int) $marked->visible);
        $this->assertTrue(
            $DB->record_exists(discoverability::TABLE, ['courseid' => $doomed->id]),
            'The state row must survive the request: the course still exists.'
        );
        $this->setUser($outsider);
        access::reset_caches();
        $this->assertFalse(
            access::is_course_discoverable((int) $doomed->id),
            'The unlisted course must stay withheld while its deletion is pending.'
        );

        $this->run_the_cron();

        $this->assertFalse($DB->record_exists('course', ['id' => $doomed->id]), 'Precondition: the cron deleted the course.');
        $this->assertFalse(
            $DB->record_exists(discoverability::TABLE, ['courseid' => $doomed->id]),
            'The state row must go when the course does.'
        );
        $this->assertTrue(
            $DB->record_exists(discoverability::TABLE, ['courseid' => $control->id]),
            'Control: another course\'s row must survive.'
        );
    }

    /**
     * A public course is not served to a visitor while its deletion is pending, and loses its row at the end.
     *
     * @return void
     */
    public function test_a_public_course_is_not_served_while_its_deletion_is_pending(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $doomed = $generator->create_course();
        $control = $generator->create_course();
        $this->setAdminUser();
        discoverability::set_state((int) $doomed->id, discoverability::STATE_PUBLIC);
        discoverability::set_state((int) $control->id, discoverability::STATE_PUBLIC);
        $this->assertTrue(discoverability::is_public((int) $doomed->id), 'Precondition: the course is public.');

        $this->delete_asynchronously((int) $doomed->id);

        discoverability::reset_caches();
        $this->assertFalse(
            discoverability::is_public((int) $doomed->id),
            'A course core has hidden for deletion must not be served to a visitor.'
        );
        $this->assertTrue(discoverability::is_public((int) $control->id), 'Control: the other public course is still served.');
        $this->assertTrue(
            $DB->record_exists(discoverability::TABLE, ['courseid' => $doomed->id]),
            'The row stays until the course is gone.'
        );

        $this->run_the_cron();

        $this->assertFalse($DB->record_exists(discoverability::TABLE, ['courseid' => $doomed->id]));
        $this->assertTrue($DB->record_exists(discoverability::TABLE, ['courseid' => $control->id]), 'Control: its row survives.');
    }
}
