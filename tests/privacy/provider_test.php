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
use local_unlistedcourses\category_discoverability;
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
     * Two categories whose states were set by two different managers.
     *
     * @return array [$categorya, $categoryb, $managera, $managerb]
     */
    private function seed_categories(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $categorya = $generator->create_category();
        $categoryb = $generator->create_category();
        $managerid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $managera = $generator->create_user();
        $managerb = $generator->create_user();
        role_assign($managerid, $managera->id, \core\context\system::instance()->id);
        role_assign($managerid, $managerb->id, \core\context\system::instance()->id);

        $this->setUser($managera);
        category_discoverability::set_state((int) $categorya->id, category_discoverability::STATE_UNLISTED);
        $this->setUser($managerb);
        category_discoverability::set_state((int) $categoryb->id, category_discoverability::STATE_UNLISTED);
        category_discoverability::reset_caches();

        return [$categorya, $categoryb, $managera, $managerb];
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
     * Who is recorded as having last changed a category's state.
     *
     * @param int $categoryid The category id.
     * @return int The user id, 0 when detached.
     */
    private function category_usermodified(int $categoryid): int {
        global $DB;

        return (int) $DB->get_field(category_discoverability::TABLE, 'usermodified', ['categoryid' => $categoryid]);
    }

    /**
     * The metadata names both tables and their user column.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = new \core_privacy\local\metadata\collection(self::COMPONENT);
        $collection = provider::get_metadata($collection);
        $items = $collection->get_collection();
        $this->assertCount(2, $items);
        $names = array_map(static fn($item) => $item->get_name(), $items);
        $this->assertContains(discoverability::TABLE, $names);
        $this->assertContains(category_discoverability::TABLE, $names);
        foreach ($items as $item) {
            $this->assertArrayHasKey('usermodified', $item->get_privacy_fields());
        }
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

    /**
     * Category contexts and their users are found through the category table's usermodified.
     *
     * @return void
     */
    public function test_category_contexts_and_users(): void {
        $this->resetAfterTest();
        [$categorya, $categoryb, $managera, $managerb] = $this->seed_categories();
        $contexta = \core\context\coursecat::instance($categorya->id);
        $contextb = \core\context\coursecat::instance($categoryb->id);

        // The same manager also changed a course state: both contexts come back, and only those.
        $coursea = $this->getDataGenerator()->create_course();
        $this->setUser($managera);
        discoverability::set_state((int) $coursea->id, discoverability::STATE_UNLISTED);
        $contexts = array_map('intval', provider::get_contexts_for_userid((int) $managera->id)->get_contextids());
        sort($contexts);
        $expected = [(int) $contexta->id, (int) \core\context\course::instance($coursea->id)->id];
        sort($expected);
        $this->assertSame($expected, $contexts);

        $userlist = new userlist($contexta, self::COMPONENT);
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $managera->id], array_map('intval', $userlist->get_userids()));

        $userlist = new userlist($contextb, self::COMPONENT);
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $managerb->id], array_map('intval', $userlist->get_userids()));
    }

    /**
     * The export of a category context names the category state.
     *
     * @return void
     */
    public function test_category_export_user_data(): void {
        $this->resetAfterTest();
        [$categorya, $categoryb, $managera] = $this->seed_categories();
        $contexta = \core\context\coursecat::instance($categorya->id);
        $contextb = \core\context\coursecat::instance($categoryb->id);

        $this->export_context_data_for_user((int) $managera->id, $contexta, self::COMPONENT);
        $data = writer::with_context($contexta)->get_data([get_string('pluginname', self::COMPONENT)]);
        $this->assertSame(get_string('state_unlisted', self::COMPONENT), $data->state);
        $this->assertNotEmpty($data->timemodified);

        // Control: nothing is exported for a category somebody else changed.
        $this->export_context_data_for_user((int) $managera->id, $contextb, self::COMPONENT);
        $this->assertEmpty((array) writer::with_context($contextb)->get_data([get_string('pluginname', self::COMPONENT)]));
    }

    /**
     * Each deletion detaches the user from the category row, keeps the state, and leaves the course rows alone.
     *
     * The course rows are the control in every case, and a category-context
     * deletion is the control for the course side: the two tables are keyed to
     * different context levels, so a request in one must never reach the other.
     *
     * @return void
     */
    public function test_category_deletions_detach_the_user_and_leave_course_rows_alone(): void {
        global $DB;

        $this->resetAfterTest();
        [$categorya, $categoryb, $managera, $managerb] = $this->seed_categories();
        $contexta = \core\context\coursecat::instance($categorya->id);
        $contextb = \core\context\coursecat::instance($categoryb->id);

        // Two course rows attributed to the same two managers, so a category deletion has something to spare.
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $this->setUser($managera);
        discoverability::set_state((int) $coursea->id, discoverability::STATE_UNLISTED);
        $this->setUser($managerb);
        discoverability::set_state((int) $courseb->id, discoverability::STATE_UNLISTED);

        // For all users in the category context.
        provider::delete_data_for_all_users_in_context($contexta);
        $rowa = $DB->get_record(category_discoverability::TABLE, ['categoryid' => $categorya->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $rowa->usermodified);
        $this->assertSame(category_discoverability::STATE_UNLISTED, (int) $rowa->state, 'The state is configuration and stays.');
        $this->assertSame((int) $managerb->id, $this->category_usermodified((int) $categoryb->id));
        $this->assertSame((int) $managera->id, $this->usermodified((int) $coursea->id), 'The course row is untouched.');

        // For one user, in the approved category context only.
        $contextlist = new approved_contextlist($managerb, self::COMPONENT, [$contextb->id]);
        provider::delete_data_for_user($contextlist);
        $this->assertSame(0, $this->category_usermodified((int) $categoryb->id));
        $this->assertSame(
            category_discoverability::STATE_UNLISTED,
            (int) $DB->get_field(category_discoverability::TABLE, 'state', ['categoryid' => $categoryb->id])
        );
        $this->assertSame((int) $managerb->id, $this->usermodified((int) $courseb->id), 'The course row is untouched.');

        // For a list of users in a category context, after re-attributing the row.
        $DB->set_field(category_discoverability::TABLE, 'usermodified', $managera->id, ['categoryid' => $categorya->id]);
        $userlist = new approved_userlist($contexta, self::COMPONENT, [$managera->id, $managerb->id]);
        provider::delete_data_for_users($userlist);
        $this->assertSame(0, $this->category_usermodified((int) $categorya->id));
        $this->assertSame((int) $managera->id, $this->usermodified((int) $coursea->id), 'The course row is untouched.');

        // And the other way round: a course-context deletion never reaches a category row.
        $DB->set_field(category_discoverability::TABLE, 'usermodified', $managera->id, ['categoryid' => $categorya->id]);
        provider::delete_data_for_all_users_in_context(\core\context\course::instance($coursea->id));
        $this->assertSame(0, $this->usermodified((int) $coursea->id));
        $this->assertSame(
            (int) $managera->id,
            $this->category_usermodified((int) $categorya->id),
            'The category row is untouched.'
        );
    }
}
