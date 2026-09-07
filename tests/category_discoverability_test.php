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

use local_unlistedcourses\event\category_state_updated;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the category discoverability state, its capability gate and its cleanup.
 *
 * Every refusal is paired with a control that the same call succeeds for
 * somebody who may make it, and every "still in state X" assertion follows a
 * refused attempt to leave it - so a gate that stopped gating would be seen.
 * The deletion tests go through core's own delete_full() and delete_move(),
 * so what they prove is the wiring of the lib.php callbacks, not the cleanup
 * method on its own.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(category_discoverability::class)]
#[CoversClass(category_state_updated::class)]
final class category_discoverability_test extends \advanced_testcase {
    /**
     * Reset the request caches between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        category_discoverability::reset_caches();
        access::reset_caches();
    }

    /**
     * A manager at the category.
     *
     * @param \core_course_category $category The category, as the generator returns it.
     * @return \stdClass The user.
     */
    private function create_manager(\core_course_category $category): \stdClass {
        global $DB;

        $manager = $this->getDataGenerator()->create_user();
        role_assign(
            $DB->get_field('role', 'id', ['shortname' => 'manager']),
            $manager->id,
            \core\context\coursecat::instance($category->id)->id
        );
        return $manager;
    }

    /**
     * A category without a row is listed, and get_states() answers in the order asked.
     *
     * @return void
     */
    public function test_a_category_without_a_row_is_listed_and_states_keep_the_order_given(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $listed = $generator->create_category();
        $unlisted = $generator->create_category();
        $this->setAdminUser();
        category_discoverability::set_state((int) $unlisted->id, category_discoverability::STATE_UNLISTED);

        $states = category_discoverability::get_states([(int) $unlisted->id, (int) $listed->id, (int) $unlisted->id]);
        $this->assertSame([
            (int) $unlisted->id => category_discoverability::STATE_UNLISTED,
            (int) $listed->id => category_discoverability::STATE_DEFAULT,
        ], $states);

        $this->assertSame(category_discoverability::STATE_DEFAULT, category_discoverability::get_state(999999));
        $this->assertTrue(category_discoverability::is_unlisted((int) $unlisted->id));
        $this->assertFalse(category_discoverability::is_unlisted((int) $listed->id));
    }

    /**
     * unlisted_ids() names every unlisted category once, ascending, and follows a change within the request.
     *
     * @return void
     */
    public function test_unlisted_ids_lists_every_unlisted_category_once_and_follows_a_change(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->setAdminUser();

        $first = $generator->create_category();
        $second = $generator->create_category();
        $third = $generator->create_category();
        $this->assertSame([], category_discoverability::unlisted_ids(), 'Control: nothing is unlisted yet.');

        category_discoverability::set_state((int) $third->id, category_discoverability::STATE_UNLISTED);
        category_discoverability::set_state((int) $first->id, category_discoverability::STATE_UNLISTED);
        $this->assertSame([(int) $first->id, (int) $third->id], category_discoverability::unlisted_ids());
        $this->assertNotContains((int) $second->id, category_discoverability::unlisted_ids());

        // The write resets the memo: the same request sees the new answer.
        category_discoverability::set_state((int) $first->id, category_discoverability::STATE_DEFAULT);
        $this->assertSame([(int) $third->id], category_discoverability::unlisted_ids());
    }

    /**
     * Changing the state requires the manage capability; a manager at the category has it.
     *
     * @return void
     */
    public function test_setting_a_state_requires_the_manage_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $outsider = $generator->create_user();
        $manager = $this->create_manager($category);

        // Refused, and the state is still the default afterwards.
        $this->setUser($outsider);
        $refused = false;
        try {
            category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        } catch (\required_capability_exception $e) {
            $refused = true;
        }
        $this->assertTrue($refused, 'An outsider must not unlist a category.');
        $this->assertSame(category_discoverability::STATE_DEFAULT, category_discoverability::get_state((int) $category->id));

        // Control: the same call succeeds for the manager, in both directions.
        $this->setUser($manager);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $this->assertSame(category_discoverability::STATE_UNLISTED, category_discoverability::get_state((int) $category->id));

        // The un-hiding direction is gated too: the outsider may not re-list it either.
        $this->setUser($outsider);
        $refused = false;
        try {
            category_discoverability::set_state((int) $category->id, category_discoverability::STATE_DEFAULT);
        } catch (\required_capability_exception $e) {
            $refused = true;
        }
        $this->assertTrue($refused, 'An outsider must not re-list a category.');
        $this->assertSame(category_discoverability::STATE_UNLISTED, category_discoverability::get_state((int) $category->id));

        $this->setUser($manager);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_DEFAULT);
        $this->assertSame(category_discoverability::STATE_DEFAULT, category_discoverability::get_state((int) $category->id));
    }

    /**
     * The acting user may be named explicitly, and is the one the gate is checked against.
     *
     * @return void
     */
    public function test_the_gate_is_checked_against_the_user_named_in_the_call(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $outsider = $generator->create_user();
        $manager = $this->create_manager($category);

        // Admin is the current user; the named outsider is still refused.
        $this->setAdminUser();
        $this->expectException(\required_capability_exception::class);
        try {
            category_discoverability::set_state(
                (int) $category->id,
                category_discoverability::STATE_UNLISTED,
                (int) $outsider->id
            );
        } finally {
            // Control: the named manager is accepted, and recorded as the one who changed it.
            category_discoverability::set_state(
                (int) $category->id,
                category_discoverability::STATE_UNLISTED,
                (int) $manager->id
            );
            $row = $DB->get_record(category_discoverability::TABLE, ['categoryid' => $category->id], '*', MUST_EXIST);
            $this->assertSame((int) $manager->id, (int) $row->usermodified);
        }
    }

    /**
     * A save that changes nothing writes nothing, needs no capability and fires no event.
     *
     * @return void
     */
    public function test_a_no_op_save_writes_nothing_and_fires_no_event(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $outsider = $generator->create_user();
        // Before the sink opens: role_assign() fires events of its own.
        $manager = $this->create_manager($category);
        $ours = static fn(\core\event\base $event): bool => $event instanceof category_state_updated;

        // An outsider re-submitting the default is not refused: nothing changes.
        $this->setUser($outsider);
        $sink = $this->redirectEvents();
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_DEFAULT);
        $this->assertCount(0, array_filter($sink->get_events(), $ours));
        $this->assertSame(0, $DB->count_records(category_discoverability::TABLE));

        // Control: a real change by a manager writes one row and fires exactly one event.
        $this->setUser($manager);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $this->assertCount(1, array_filter($sink->get_events(), $ours));
        $this->assertSame(1, $DB->count_records(category_discoverability::TABLE));

        // Re-submitting the value it already has: still no event, still one row.
        $timemodified = (int) $DB->get_field(category_discoverability::TABLE, 'timemodified', ['categoryid' => $category->id]);
        $this->setUser($outsider);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $this->assertCount(1, array_filter($sink->get_events(), $ours));
        $this->assertSame(
            $timemodified,
            (int) $DB->get_field(category_discoverability::TABLE, 'timemodified', ['categoryid' => $category->id])
        );
        $sink->close();
    }

    /**
     * The event carries the category context, both states, and the category as its object.
     *
     * @return void
     */
    public function test_the_event_carries_both_states_and_the_category_context(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $manager = $this->create_manager($category);
        $this->setUser($manager);

        $sink = $this->redirectEvents();
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_DEFAULT);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(2, $events);
        [$unlisted, $relisted] = $events;
        $this->assertInstanceOf(category_state_updated::class, $unlisted);
        $this->assertSame(\core\context\coursecat::instance($category->id)->id, $unlisted->contextid);
        $this->assertSame((int) $category->id, (int) $unlisted->objectid);
        $this->assertSame('course_categories', $unlisted->objecttable);
        $this->assertSame((int) $manager->id, (int) $unlisted->userid);
        $this->assertSame(category_discoverability::STATE_DEFAULT, $unlisted->other['oldstate']);
        $this->assertSame(category_discoverability::STATE_UNLISTED, $unlisted->other['newstate']);
        $this->assertSame(category_discoverability::STATE_UNLISTED, $relisted->other['oldstate']);
        $this->assertSame(category_discoverability::STATE_DEFAULT, $relisted->other['newstate']);
        $this->assertStringContainsString("'{$category->id}'", $unlisted->get_description());
        $this->assertSame((int) $category->id, (int) $unlisted->get_url()->get_param('categoryid'));
    }

    /**
     * An unknown stored value reads as listed, and leaving it is a change from listed.
     *
     * @return void
     */
    public function test_an_unknown_stored_value_reads_as_listed(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $DB->insert_record(category_discoverability::TABLE, (object) [
            'categoryid' => $category->id,
            'state' => 7,
            'usermodified' => 0,
            'timemodified' => time(),
        ]);

        $this->assertSame(category_discoverability::STATE_DEFAULT, category_discoverability::get_state((int) $category->id));
        $this->assertFalse(category_discoverability::is_unlisted((int) $category->id));
        $this->assertSame([], category_discoverability::unlisted_ids());

        // Setting the state it reads as is a no-op: no event, and the row is left alone.
        $this->setUser($this->create_manager($category));
        $sink = $this->redirectEvents();
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_DEFAULT);
        $this->assertCount(0, $sink->get_events());
        $this->assertSame(7, (int) $DB->get_field(category_discoverability::TABLE, 'state', ['categoryid' => $category->id]));

        // Control: unlisting it is a real change, logged as leaving the default.
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertSame(category_discoverability::STATE_DEFAULT, $events[0]->other['oldstate']);
        $this->assertSame([(int) $category->id], category_discoverability::unlisted_ids());
    }

    /**
     * An unknown state, the top level and a category that does not exist are refused.
     *
     * @return void
     */
    public function test_an_unknown_state_the_top_level_and_a_missing_category_are_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        try {
            category_discoverability::set_state((int) $category->id, 9);
            $this->fail('An unknown state must be refused.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('Unknown category discoverability state', $e->getMessage());
        }
        try {
            category_discoverability::set_state(0, category_discoverability::STATE_UNLISTED);
            $this->fail('The top level must be refused.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('top level', $e->getMessage());
        }
        $this->expectException(\moodle_exception::class);
        category_discoverability::set_state(999999, category_discoverability::STATE_UNLISTED);
    }

    /**
     * Deleting a category with its contents drops its row; an unrelated category keeps its own.
     *
     * @return void
     */
    public function test_deleting_a_category_with_its_contents_drops_its_row(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->setAdminUser();

        $doomed = $generator->create_category();
        $child = $generator->create_category(['parent' => $doomed->id]);
        $unrelated = $generator->create_category();
        category_discoverability::set_state((int) $doomed->id, category_discoverability::STATE_UNLISTED);
        category_discoverability::set_state((int) $child->id, category_discoverability::STATE_UNLISTED);
        category_discoverability::set_state((int) $unrelated->id, category_discoverability::STATE_UNLISTED);
        $this->assertSame(3, $DB->count_records(category_discoverability::TABLE));

        \core_course_category::get($doomed->id)->delete_full(false);

        // The parent and the child it took down with it are gone; the control survives.
        $this->assertFalse($DB->record_exists(category_discoverability::TABLE, ['categoryid' => $doomed->id]));
        $this->assertFalse($DB->record_exists(category_discoverability::TABLE, ['categoryid' => $child->id]));
        $this->assertTrue($DB->record_exists(category_discoverability::TABLE, ['categoryid' => $unrelated->id]));
        $this->assertSame([(int) $unrelated->id], category_discoverability::unlisted_ids());
    }

    /**
     * Deleting a category while moving its contents drops its row; the target keeps its own.
     *
     * @return void
     */
    public function test_deleting_a_category_while_moving_its_contents_drops_its_row(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->setAdminUser();

        $doomed = $generator->create_category();
        $target = $generator->create_category();
        $generator->create_course(['category' => $doomed->id]);
        category_discoverability::set_state((int) $doomed->id, category_discoverability::STATE_UNLISTED);
        category_discoverability::set_state((int) $target->id, category_discoverability::STATE_UNLISTED);

        \core_course_category::get($doomed->id)->delete_move($target->id, false);

        $this->assertFalse($DB->record_exists(category_discoverability::TABLE, ['categoryid' => $doomed->id]));
        $this->assertTrue($DB->record_exists(category_discoverability::TABLE, ['categoryid' => $target->id]));
        $this->assertSame([(int) $target->id], category_discoverability::unlisted_ids());
    }
}
