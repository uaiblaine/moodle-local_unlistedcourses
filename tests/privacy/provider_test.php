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
 * Course discoverability - Privacy provider tests
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use local_unlistedcourses\access;
use local_unlistedcourses\discoverability;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Privacy provider tests.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends provider_testcase {
    /** @var string The component. */
    private const COMPONENT = 'local_unlistedcourses';

    /**
     * Two courses whose states were set by two different managers.
     *
     * @return array [$coursea, $courseb, $managera, $managerb]
     */
    private function seed(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $coursea = $generator->create_course();
        $courseb = $generator->create_course();
        $managerid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $managera = $generator->create_user();
        $managerb = $generator->create_user();
        role_assign($managerid, $managera->id, \core\context\system::instance()->id);
        role_assign($managerid, $managerb->id, \core\context\system::instance()->id);

        $this->setUser($managera);
        discoverability::set_state((int) $coursea->id, discoverability::STATE_PUBLIC);
        $this->setUser($managerb);
        discoverability::set_state((int) $courseb->id, discoverability::STATE_UNLISTED);
        access::reset_caches();

        return [$coursea, $courseb, $managera, $managerb];
    }

    /**
     * Who is recorded as having last changed a course's state.
     *
     * @param int $courseid The course id.
     * @return int The user id, 0 when detached.
     */
    private function usermodified(int $courseid): int {
        global $DB;

        return (int) $DB->get_field(discoverability::TABLE, 'usermodified', ['courseid' => $courseid]);
    }

    /**
     * The metadata names the table and its user column.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = new \core_privacy\local\metadata\collection(self::COMPONENT);
        $collection = provider::get_metadata($collection);
        $items = $collection->get_collection();
        $this->assertCount(1, $items);
        $this->assertSame(discoverability::TABLE, $items[0]->get_name());
        $this->assertArrayHasKey('usermodified', $items[0]->get_privacy_fields());
    }

    /**
     * Contexts and users are found through usermodified.
     *
     * @return void
     */
    public function test_contexts_and_users(): void {
        $this->resetAfterTest();
        [$coursea, $courseb, $managera, $managerb] = $this->seed();
        $contexta = \core\context\course::instance($coursea->id);
        $contextb = \core\context\course::instance($courseb->id);

        $contexts = provider::get_contexts_for_userid((int) $managera->id)->get_contextids();
        $this->assertSame([(int) $contexta->id], array_map('intval', $contexts));

        $userlist = new userlist($contexta, self::COMPONENT);
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $managera->id], array_map('intval', $userlist->get_userids()));

        $userlist = new userlist($contextb, self::COMPONENT);
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $managerb->id], array_map('intval', $userlist->get_userids()));

        // A non-course context yields nobody.
        $userlist = new userlist(\core\context\system::instance(), self::COMPONENT);
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());
    }

    /**
     * The export names the state the user set.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();
        [$coursea, $courseb, $managera] = $this->seed();
        $contexta = \core\context\course::instance($coursea->id);
        $contextb = \core\context\course::instance($courseb->id);

        $this->export_context_data_for_user((int) $managera->id, $contexta, self::COMPONENT);
        $data = writer::with_context($contexta)->get_data([get_string('pluginname', self::COMPONENT)]);
        $this->assertSame(get_string('state_public', self::COMPONENT), $data->state);
        $this->assertNotEmpty($data->timemodified);

        // Control: nothing is exported for a course somebody else changed.
        $this->export_context_data_for_user((int) $managera->id, $contextb, self::COMPONENT);
        $this->assertEmpty((array) writer::with_context($contextb)->get_data([get_string('pluginname', self::COMPONENT)]));
    }

    /**
     * Deleting for a context detaches the user and keeps the state.
     *
     * @return void
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $this->resetAfterTest();
        [$coursea, $courseb, $managera, $managerb] = $this->seed();

        provider::delete_data_for_all_users_in_context(\core\context\course::instance($coursea->id));

        $rowa = $DB->get_record(discoverability::TABLE, ['courseid' => $coursea->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $rowa->usermodified);
        $this->assertSame(discoverability::STATE_PUBLIC, (int) $rowa->state, 'The state is course configuration and stays.');
        // Control: the other course is untouched.
        $this->assertSame((int) $managerb->id, $this->usermodified((int) $courseb->id));
    }

    /**
     * Deleting for a user detaches them from the approved contexts only.
     *
     * @return void
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $this->resetAfterTest();
        [$coursea, $courseb, $managera] = $this->seed();
        $coursec = $this->getDataGenerator()->create_course();
        $this->setUser($managera);
        discoverability::set_state((int) $coursec->id, discoverability::STATE_UNLISTED);

        $contextlist = new approved_contextlist($managera, self::COMPONENT, [\core\context\course::instance($coursea->id)->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertSame(0, $this->usermodified((int) $coursea->id));
        $this->assertSame(
            discoverability::STATE_PUBLIC,
            (int) $DB->get_field(discoverability::TABLE, 'state', ['courseid' => $coursea->id])
        );
        // Control: the same user's other course, not in the list, keeps the attribution.
        $this->assertSame((int) $managera->id, $this->usermodified((int) $coursec->id));
    }

    /**
     * Deleting for a user list detaches those users in that context.
     *
     * @return void
     */
    public function test_delete_data_for_users(): void {
        $this->resetAfterTest();
        [$coursea, $courseb, $managera, $managerb] = $this->seed();
        $contexta = \core\context\course::instance($coursea->id);

        // Somebody not in the list, in a different course, is the control.
        $userlist = new approved_userlist($contexta, self::COMPONENT, [$managera->id, $managerb->id]);
        provider::delete_data_for_users($userlist);

        $this->assertSame(0, $this->usermodified((int) $coursea->id));
        $this->assertSame((int) $managerb->id, $this->usermodified((int) $courseb->id));
    }
}
