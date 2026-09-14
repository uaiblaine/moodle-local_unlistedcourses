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
        /* The event points at the page that edits the state, not at the category listing:
           a reader following this from the log wants the control that produced the entry.
           Both halves are asserted, so a URL pointing anywhere else fails here. */
        $this->assertStringContainsString(
            '/local/unlistedcourses/category.php',
            $unlisted->get_url()->out(false)
        );
        $this->assertSame((int) $category->id, (int) $unlisted->get_url()->get_param('id'));
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

    /**
     * A user holding the manage capability at the category and nothing else.
     *
     * The control for every publish refusal: somebody who may change the state
     * but may not take it into or out of the public one.
     *
     * @param \core_course_category $category The category.
     * @return \stdClass The user.
     */
    private function create_statemanager(\core_course_category $category): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $context = \core\context\coursecat::instance($category->id);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(category_discoverability::CAPABILITY_MANAGE, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * Put a category in a state as the site administrator, with the caches reset behind it.
     *
     * @param int $categoryid The category id.
     * @param int $state One of the state constants.
     * @return void
     */
    private function set_state_as_admin(int $categoryid, int $state): void {
        $current = $GLOBALS['USER'];
        $this->setAdminUser();
        category_discoverability::set_state($categoryid, $state);
        $this->setUser($current);
        category_discoverability::reset_caches();
    }

    /**
     * There are three states, and public_ids() names the categories in the third one.
     *
     * @return void
     */
    public function test_states_has_three_values_and_public_ids_names_the_public_categories(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->setAdminUser();

        $this->assertSame(
            [
                category_discoverability::STATE_DEFAULT,
                category_discoverability::STATE_UNLISTED,
                category_discoverability::STATE_PUBLIC,
            ],
            category_discoverability::states()
        );

        $first = $generator->create_category();
        $second = $generator->create_category();
        $unlisted = $generator->create_category();
        $this->assertSame([], category_discoverability::public_ids(), 'Control: nothing is public yet.');

        category_discoverability::set_state((int) $second->id, category_discoverability::STATE_PUBLIC);
        category_discoverability::set_state((int) $first->id, category_discoverability::STATE_PUBLIC);
        category_discoverability::set_state((int) $unlisted->id, category_discoverability::STATE_UNLISTED);

        $this->assertSame([(int) $first->id, (int) $second->id], category_discoverability::public_ids());
        $this->assertNotContains((int) $unlisted->id, category_discoverability::public_ids());
        $this->assertSame([(int) $unlisted->id], category_discoverability::unlisted_ids());

        // The write resets the memo: the same request sees the new answer.
        category_discoverability::set_state((int) $first->id, category_discoverability::STATE_DEFAULT);
        $this->assertSame([(int) $second->id], category_discoverability::public_ids());
    }

    /**
     * Entering the public state needs the publish capability, over and above the manage one.
     *
     * @return void
     */
    public function test_entering_the_public_state_needs_the_publish_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $statemanager = $this->create_statemanager($category);
        $manager = $this->create_manager($category);

        // The manage capability alone unlists the category: the control that the gate is reached at all.
        $this->setUser($statemanager);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $this->assertSame(
            category_discoverability::STATE_UNLISTED,
            category_discoverability::get_state((int) $category->id)
        );

        $refused = false;
        try {
            category_discoverability::set_state((int) $category->id, category_discoverability::STATE_PUBLIC);
        } catch (\required_capability_exception $e) {
            $refused = true;
            $this->assertSame(get_capability_string(category_discoverability::CAPABILITY_PUBLISH), $e->a);
        }
        $this->assertTrue($refused, 'The manage capability alone must not publish a category.');
        $this->assertSame(
            category_discoverability::STATE_UNLISTED,
            category_discoverability::get_state((int) $category->id),
            'The refused change must not land.'
        );

        // Control: a manager, who holds both capabilities, makes the very same call.
        $this->setUser($manager);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_PUBLIC);
        $this->assertSame(
            category_discoverability::STATE_PUBLIC,
            category_discoverability::get_state((int) $category->id)
        );
    }

    /**
     * Leaving the public state needs the publish capability too: publishing reversed.
     *
     * @return void
     */
    public function test_leaving_the_public_state_needs_the_publish_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $statemanager = $this->create_statemanager($category);
        $manager = $this->create_manager($category);

        $this->setUser($manager);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_PUBLIC);

        $this->setUser($statemanager);
        foreach ([category_discoverability::STATE_UNLISTED, category_discoverability::STATE_DEFAULT] as $target) {
            $refused = false;
            try {
                category_discoverability::set_state((int) $category->id, $target);
            } catch (\required_capability_exception $e) {
                $refused = true;
            }
            $this->assertTrue($refused, "The manage capability alone must not move a public category to {$target}.");
            $this->assertSame(
                category_discoverability::STATE_PUBLIC,
                category_discoverability::get_state((int) $category->id)
            );
        }

        // Control: the manager may un-publish it.
        $this->setUser($manager);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $this->assertSame(
            category_discoverability::STATE_UNLISTED,
            category_discoverability::get_state((int) $category->id)
        );
    }

    /**
     * Re-submitting "public" changes nothing, needs no capability and fires no event.
     *
     * This is what lets an editor who may not publish save the form of a public
     * category without un-publishing it and without being refused.
     *
     * @return void
     */
    public function test_a_public_to_public_save_needs_no_capability_and_fires_no_event(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $statemanager = $this->create_statemanager($category);
        $manager = $this->create_manager($category);
        $ours = static fn(\core\event\base $event): bool => $event instanceof category_state_updated;

        $this->setUser($manager);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_PUBLIC);

        $this->setUser($statemanager);
        $sink = $this->redirectEvents();
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_PUBLIC);
        $this->assertCount(0, array_filter($sink->get_events(), $ours));
        $this->assertSame(
            category_discoverability::STATE_PUBLIC,
            category_discoverability::get_state((int) $category->id)
        );

        // Control: the same user CHANGING the state is refused, and the state stays put.
        $refused = false;
        try {
            category_discoverability::set_state((int) $category->id, category_discoverability::STATE_DEFAULT);
        } catch (\required_capability_exception $e) {
            $refused = true;
        }
        $sink->close();
        $this->assertTrue($refused);
        $this->assertSame(
            category_discoverability::STATE_PUBLIC,
            category_discoverability::get_state((int) $category->id)
        );
    }

    /**
     * A public category under a listed, visible parent is public; the six ways it stops being.
     *
     * Viewer-independent throughout: the assertions run with nobody logged in,
     * which is the surface the predicate exists for.
     *
     * @return void
     */
    public function test_is_public_composes_own_state_own_visibility_and_the_whole_path(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $parent = $generator->create_category();
        $leaf = $generator->create_category(['parent' => $parent->id]);
        $this->set_state_as_admin((int) $leaf->id, category_discoverability::STATE_PUBLIC);

        $this->setUser(0);
        $this->assertTrue(
            category_discoverability::is_public((int) $leaf->id),
            'Precondition: a public leaf under a listed visible parent is public.'
        );

        // An UNLISTED ancestor refuses it: an anonymous visitor can satisfy no term of that rule.
        $this->set_state_as_admin((int) $parent->id, category_discoverability::STATE_UNLISTED);
        $this->assertFalse(category_discoverability::is_public((int) $leaf->id));
        $this->set_state_as_admin((int) $parent->id, category_discoverability::STATE_DEFAULT);
        $this->assertTrue(category_discoverability::is_public((int) $leaf->id));

        // A HIDDEN ancestor refuses it.
        $DB->set_field('course_categories', 'visible', 0, ['id' => $parent->id]);
        category_discoverability::reset_caches();
        $this->assertFalse(category_discoverability::is_public((int) $leaf->id));
        $DB->set_field('course_categories', 'visible', 1, ['id' => $parent->id]);
        category_discoverability::reset_caches();
        $this->assertTrue(category_discoverability::is_public((int) $leaf->id));

        // Its own row hidden refuses it.
        $DB->set_field('course_categories', 'visible', 0, ['id' => $leaf->id]);
        category_discoverability::reset_caches();
        $this->assertFalse(category_discoverability::is_public((int) $leaf->id));
        $DB->set_field('course_categories', 'visible', 1, ['id' => $leaf->id]);
        category_discoverability::reset_caches();
        $this->assertTrue(category_discoverability::is_public((int) $leaf->id));

        // Its own state, unlisted and then listed, refuses it in both cases.
        $this->set_state_as_admin((int) $leaf->id, category_discoverability::STATE_UNLISTED);
        $this->assertFalse(category_discoverability::is_public((int) $leaf->id));
        $this->set_state_as_admin((int) $leaf->id, category_discoverability::STATE_DEFAULT);
        $this->assertFalse(category_discoverability::is_public((int) $leaf->id));
    }

    /**
     * A category id that does not exist answers false, and throws nothing.
     *
     * Two shapes: an id with no row at all, and a state row whose category has
     * vanished - the second is the one a MUST_EXIST read would throw on.
     *
     * @return void
     */
    public function test_a_missing_category_answers_false_without_throwing(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $control = $generator->create_category();
        $this->set_state_as_admin((int) $control->id, category_discoverability::STATE_PUBLIC);

        $DB->insert_record(category_discoverability::TABLE, (object) [
            'categoryid' => 999999,
            'state' => category_discoverability::STATE_PUBLIC,
            'usermodified' => 0,
            'timemodified' => time(),
        ]);
        category_discoverability::reset_caches();

        $this->setUser(0);
        $this->assertFalse(category_discoverability::is_public(999999));
        $this->assertFalse(category_discoverability::is_public(888888));
        $this->assertSame(
            [999999 => false, 888888 => false, (int) $control->id => true],
            category_discoverability::are_public([999999, 888888, (int) $control->id]),
            'Control: a real public category answers the other way in the very same call.'
        );
    }

    /**
     * are_public() agrees with is_public() and answers in the order asked.
     *
     * @return void
     */
    public function test_are_public_agrees_with_is_public_and_keeps_the_order_given(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $parent = $generator->create_category();
        $public = $generator->create_category(['parent' => $parent->id]);
        $unlisted = $generator->create_category(['parent' => $parent->id]);
        $listed = $generator->create_category(['parent' => $parent->id]);
        $inside = $generator->create_category(['parent' => $unlisted->id]);
        $this->set_state_as_admin((int) $public->id, category_discoverability::STATE_PUBLIC);
        $this->set_state_as_admin((int) $unlisted->id, category_discoverability::STATE_UNLISTED);
        $this->set_state_as_admin((int) $inside->id, category_discoverability::STATE_PUBLIC);

        $ids = [(int) $inside->id, (int) $public->id, (int) $listed->id, (int) $unlisted->id];
        $batch = category_discoverability::are_public($ids);
        $this->assertSame($ids, array_keys($batch), 'The answers must come back in the order asked.');
        $this->assertSame(
            [(int) $inside->id => false, (int) $public->id => true, (int) $listed->id => false, (int) $unlisted->id => false],
            $batch
        );

        foreach ($ids as $categoryid) {
            category_discoverability::reset_caches();
            $this->assertSame(
                $batch[$categoryid],
                category_discoverability::is_public($categoryid),
                "One at a time and in a batch must agree for category {$categoryid}."
            );
        }
    }

    /**
     * are_public() over 200 categories costs what it costs over 2.
     *
     * The two calls run the same code over the same shape of data and differ
     * only in how many ids are asked about, so anything per category - a row
     * read, a path walk, a visibility lookup - is the only thing that could
     * make the larger set read more than the smaller one.
     *
     * THE LARGE SET IS SPREAD OVER TWENTY PARENTS, and the small one sits under
     * a single parent, on purpose. With every candidate under one shared parent
     * the two calls would also read alike under an implementation that batched
     * per DISTINCT ANCESTOR rather than per request, because there would be one
     * distinct ancestor either way - the equality would hold for a reason that
     * is not the one being asserted. Twenty parents against one makes the two
     * explanations disagree: a per-ancestor implementation reads twenty path
     * lookups here and one there, and the assertion fails as it should.
     *
     * @return void
     */
    public function test_are_public_reads_the_same_for_two_categories_and_for_two_hundred(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category();

        $small = [];
        for ($i = 0; $i < 2; $i++) {
            $small[] = (int) $generator->create_category(['parent' => $parent->id])->id;
        }
        $parents = [];
        for ($i = 0; $i < 20; $i++) {
            $parents[] = (int) $generator->create_category()->id;
        }
        $large = [];
        for ($i = 0; $i < 200; $i++) {
            $large[] = (int) $generator->create_category(['parent' => $parents[$i % 20]])->id;
        }

        /* The state rows are written straight to the table rather than through set_state():
           the budget is what is being measured, and 202 gated writes with their events cost
           minutes and prove nothing about the read path. */
        $rows = [];
        foreach (array_merge($small, $large) as $categoryid) {
            $rows[] = (object) [
                'categoryid' => $categoryid,
                'state' => category_discoverability::STATE_PUBLIC,
                'usermodified' => 0,
                'timemodified' => time(),
            ];
        }
        $DB->insert_records(category_discoverability::TABLE, $rows);

        /* Precondition: the two sets really do differ in how many distinct ancestors they
           carry, which is the whole reason the equality below means what it says. */
        [$insql, $params] = $DB->get_in_or_equal($large, SQL_PARAMS_NAMED, 'cat');
        $this->assertCount(
            20,
            array_unique($DB->get_fieldset_select('course_categories', 'parent', "id $insql", $params)),
            'The large set must sit under twenty distinct parents, not one.'
        );

        $this->setUser(0);
        category_discoverability::reset_caches();
        $before = $DB->perf_get_reads();
        $answers = category_discoverability::are_public($small);
        $smallreads = $DB->perf_get_reads() - $before;
        $this->assertSame([true, true], array_values($answers), 'Precondition: the small set really is public.');

        category_discoverability::reset_caches();
        $before = $DB->perf_get_reads();
        $answers = category_discoverability::are_public($large);
        $largereads = $DB->perf_get_reads() - $before;
        $this->assertSame(array_fill(0, 200, true), array_values($answers));

        $this->assertSame(
            $smallreads,
            $largereads,
            'The predicate must cost the same for 200 categories as for 2.'
        );
    }
}
