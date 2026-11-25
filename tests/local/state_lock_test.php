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

use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\discoverability;
use local_unlistedcourses\event\course_state_updated;
use local_unlistedcourses\fixtures\first_writer_discoverability;
use local_unlistedcourses\fixtures\state_lock_factory;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Course discoverability - Tests for the lock around a state write
 *
 * A true interleaving of two requests cannot be run under PHPUnit, so the lock factory is a
 * fixture ({@see state_lock_factory}) that records what set_state() asks of it, can play
 * another holder, and can play a writer that finished between the lock-free read and the lock.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(state_lock::class)]
#[CoversClass(discoverability::class)]
#[CoversClass(category_discoverability::class)]
final class state_lock_test extends \advanced_testcase {
    /**
     * Install the recording lock factory and reset the plugin's request caches.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        require_once($CFG->dirroot . '/local/unlistedcourses/tests/fixtures/state_lock_factory.php');
        require_once($CFG->dirroot . '/local/unlistedcourses/tests/fixtures/first_writer_discoverability.php');
        $this->resetAfterTest();
        $CFG->lock_factory = '\\' . state_lock_factory::class;
        state_lock_factory::reset();
        discoverability::reset_caches();
        category_discoverability::reset_caches();
    }

    /**
     * Forget the fixture's state.
     *
     * @return void
     */
    protected function tearDown(): void {
        state_lock_factory::reset();
        first_writer_discoverability::$next = null;
        parent::tearDown();
    }

    /**
     * The state stored for a course, read past every memo.
     *
     * @param int $courseid The course id.
     * @return int|null The stored state, null without a row.
     */
    private function stored_course_state(int $courseid): ?int {
        global $DB;

        $state = $DB->get_field(discoverability::TABLE, 'state', ['courseid' => $courseid]);
        return $state === false ? null : (int) $state;
    }

    /**
     * The state stored for a category, read past every memo.
     *
     * @param int $categoryid The category id.
     * @return int|null The stored state, null without a row.
     */
    private function stored_category_state(int $categoryid): ?int {
        global $DB;

        $state = $DB->get_field(category_discoverability::TABLE, 'state', ['categoryid' => $categoryid]);
        return $state === false ? null : (int) $state;
    }

    /**
     * Write a state row as somebody else would have, bypassing set_state().
     *
     * @param string $table The table.
     * @param string $field The id field.
     * @param int $id The course or category id.
     * @param int $state The state.
     * @return void
     */
    private function write_behind(string $table, string $field, int $id, int $state): void {
        global $DB;

        $DB->insert_record($table, (object) [
            $field => $id,
            'state' => $state,
            'usermodified' => get_admin()->id,
            'timemodified' => time(),
        ]);
    }

    /**
     * A user holding the category manage capability at the category and nothing else.
     *
     * @param int $categoryid The category id.
     * @return \stdClass The user.
     */
    private function create_statemanager(int $categoryid): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $context = \core\context\coursecat::instance($categoryid);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(category_discoverability::CAPABILITY_MANAGE, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        return $user;
    }

    /**
     * A course change is made under the course's lock, released after it; a change of nothing takes none.
     *
     * @return void
     */
    public function test_a_course_change_takes_and_releases_its_lock(): void {
        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $this->setAdminUser();

        discoverability::set_state($courseid, discoverability::STATE_UNLISTED);
        $this->assertSame(['get:course_' . $courseid, 'release:course_' . $courseid], state_lock_factory::$log);
        $this->assertSame(discoverability::STATE_UNLISTED, $this->stored_course_state($courseid));

        // Control: the same state again changes nothing and asks for no lock.
        discoverability::set_state($courseid, discoverability::STATE_UNLISTED);
        $this->assertCount(2, state_lock_factory::$log, 'A call that changes nothing must take no lock.');
    }

    /**
     * A refused course change still releases the lock.
     *
     * @return void
     */
    public function test_a_refused_course_change_releases_its_lock(): void {
        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $user = $this->getDataGenerator()->create_user();

        try {
            discoverability::set_state($courseid, discoverability::STATE_PUBLIC, (int) $user->id);
            $this->fail('Precondition: a user who may not publish is refused.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(['get:course_' . $courseid, 'release:course_' . $courseid], state_lock_factory::$log);
        }
        $this->assertNull($this->stored_course_state($courseid));
    }

    /**
     * A course whose lock another writer holds is refused with the plugin's message, and nothing is written.
     *
     * @return void
     */
    public function test_a_course_held_by_another_writer_is_refused_cleanly(): void {
        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $this->setAdminUser();
        state_lock_factory::$held = ['course_' . $courseid];
        $sink = $this->redirectEvents();

        try {
            discoverability::set_state($courseid, discoverability::STATE_UNLISTED);
            $this->fail('A write must not go ahead without the lock.');
        } catch (\moodle_exception $e) {
            $this->assertSame('statebusy', $e->errorcode);
            $this->assertSame('local_unlistedcourses', $e->module);
        }
        $this->assertNull($this->stored_course_state($courseid));
        $this->assertCount(0, $sink->get_events());

        // Control: once the other writer lets go, the same call writes.
        state_lock_factory::$held = [];
        discoverability::set_state($courseid, discoverability::STATE_UNLISTED);
        $this->assertSame(discoverability::STATE_UNLISTED, $this->stored_course_state($courseid));
        $this->assertCount(1, $sink->get_events());
        $sink->close();
    }

    /**
     * The publish decision is made on the row read under the lock, not on the one read before it.
     *
     * A publisher's public row lands between the lock-free read (no row) and the lock. Deciding on
     * the first read would insert a second row and break the unique key; the row read under the
     * lock is public, and leaving public needs the publish capability, which this user lacks.
     *
     * @return void
     */
    public function test_a_course_change_is_decided_on_the_row_read_under_the_lock(): void {
        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $user = $this->getDataGenerator()->create_user();
        state_lock_factory::$ongranted = function () use ($courseid): void {
            $this->write_behind(discoverability::TABLE, 'courseid', $courseid, discoverability::STATE_PUBLIC);
        };

        try {
            discoverability::set_state($courseid, discoverability::STATE_UNLISTED, (int) $user->id);
            $this->fail('Leaving the public state written meanwhile needs the publish capability.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(discoverability::STATE_PUBLIC, $this->stored_course_state($courseid));
        }
        $this->assertSame('release:course_' . $courseid, end(state_lock_factory::$log));

        // Control: with nothing written meanwhile, the same user unlists the course.
        $otherid = (int) $this->getDataGenerator()->create_course()->id;
        discoverability::set_state($otherid, discoverability::STATE_UNLISTED, (int) $user->id);
        $this->assertSame(discoverability::STATE_UNLISTED, $this->stored_course_state($otherid));
    }

    /**
     * The course_state_updated events a sink holds.
     *
     * @param \phpunit_event_sink $sink The sink.
     * @return array The events.
     */
    private static function state_events(\phpunit_event_sink $sink): array {
        return array_values(array_filter($sink->get_events(), static fn($event) => $event instanceof course_state_updated));
    }

    /**
     * A first insert that meets a row another writer inserted without the lock is refused as busy.
     *
     * The other writer is the backfill, which inserts without the lock: the fixture
     * ({@see first_writer_discoverability}) inserts its unlisted row after set_state() read no row
     * under the lock and before set_state()'s own insert, so the duplicate key error is the
     * database's. The test runs outside the test's wrapping transaction, because inside one
     * write() rethrows by design; the database is still reset after the test, and the state rows
     * are cleared at once. The control is the same user's next call, which changes that row.
     *
     * @return void
     */
    public function test_a_first_insert_that_meets_another_writers_row_is_refused_as_busy(): void {
        global $DB;

        $this->preventResetByRollback();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $this->setAdminUser();
        $sink = $this->redirectEvents();
        try {
            first_writer_discoverability::$next = first_writer_discoverability::COLLIDE;
            try {
                first_writer_discoverability::set_state($courseid, discoverability::STATE_PUBLIC);
                $this->fail('A first insert that meets another writer\'s row must not go ahead.');
            } catch (state_busy_exception $e) {
                $this->assertSame('statebusy', $e->errorcode);
                $this->assertSame('local_unlistedcourses', $e->module);
            }
            $this->assertSame(1, $DB->count_records(discoverability::TABLE, ['courseid' => $courseid]));
            $row = $DB->get_record(discoverability::TABLE, ['courseid' => $courseid], '*', MUST_EXIST);
            $this->assertSame(discoverability::STATE_UNLISTED, (int) $row->state, 'The other writer\'s row is kept.');
            $this->assertSame(0, (int) $row->usermodified, 'And not overwritten.');
            $this->assertCount(0, self::state_events($sink), 'Nothing was written by the refused call.');
            $this->assertSame(['get:course_' . $courseid, 'release:course_' . $courseid], state_lock_factory::$log);

            // Control: the lock is free again, and the same user's next call changes the row it met.
            first_writer_discoverability::set_state($courseid, discoverability::STATE_PUBLIC);
            $this->assertSame(discoverability::STATE_PUBLIC, $this->stored_course_state($courseid));
            $this->assertSame(1, $DB->count_records(discoverability::TABLE, ['courseid' => $courseid]));
            $this->assertCount(1, self::state_events($sink));
        } finally {
            $sink->close();
            first_writer_discoverability::$next = null;
            $DB->delete_records(discoverability::TABLE, ['courseid' => $courseid]);
        }
    }

    /**
     * A first insert the database refuses for another reason, leaving no row, is rethrown as itself.
     *
     * The fixture's refusal is a real NOT NULL violation, the database's own error: the row is
     * inserted without its course id, so nothing for the course exists afterwards. Busy would tell
     * the caller to try again over a write that will fail the same way. The control is the same call
     * with nothing refused.
     *
     * @return void
     */
    public function test_a_first_insert_refused_without_a_row_is_rethrown_as_itself(): void {
        global $DB;

        $this->preventResetByRollback();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $this->setAdminUser();
        try {
            first_writer_discoverability::$next = first_writer_discoverability::REFUSE;
            try {
                first_writer_discoverability::set_state($courseid, discoverability::STATE_UNLISTED);
                $this->fail('The database refuses a row without its course id.');
            } catch (\dml_write_exception $e) {
                $this->assertSame(\dml_write_exception::class, get_class($e));
            }
            $this->assertSame(0, $DB->count_records(discoverability::TABLE, ['courseid' => $courseid]));
            $this->assertSame(['get:course_' . $courseid, 'release:course_' . $courseid], state_lock_factory::$log);

            // Control: the same call with nothing refused writes the row.
            first_writer_discoverability::set_state($courseid, discoverability::STATE_UNLISTED);
            $this->assertSame(discoverability::STATE_UNLISTED, $this->stored_course_state($courseid));
        } finally {
            first_writer_discoverability::$next = null;
            $DB->delete_records(discoverability::TABLE, ['courseid' => $courseid]);
        }
    }

    /**
     * The same collision inside a caller's transaction goes back to that caller as the database's error.
     *
     * On PostgreSQL the failed insert has aborted the transaction, so a re-read would fail too; on
     * MariaDB the re-read would find the row and answer busy. Either way the owner of the transaction
     * must see the original error and roll back. The control is the same collision outside a
     * transaction, which is busy.
     *
     * @return void
     */
    public function test_a_collision_inside_a_transaction_is_rethrown_to_its_owner(): void {
        global $DB;

        $this->preventResetByRollback();
        $courseid = (int) $this->getDataGenerator()->create_course()->id;
        $this->setAdminUser();
        try {
            $thrown = null;
            $transaction = $DB->start_delegated_transaction();
            first_writer_discoverability::$next = first_writer_discoverability::COLLIDE;
            try {
                first_writer_discoverability::set_state($courseid, discoverability::STATE_PUBLIC);
            } catch (\moodle_exception $e) {
                $thrown = $e;
            }
            $this->assertNotNull($thrown, 'The collision must not go unreported.');
            try {
                $transaction->rollback($thrown);
            } catch (\moodle_exception $rethrown) {
                $this->assertSame($thrown, $rethrown);
            }
            $this->assertSame(\dml_write_exception::class, get_class($thrown), 'The database\'s error, not busy.');
            $this->assertFalse($DB->is_transaction_started());
            $this->assertSame(0, $DB->count_records(discoverability::TABLE, ['courseid' => $courseid]), 'All rolled back.');

            // Control: outside a transaction the same collision is busy.
            first_writer_discoverability::$next = first_writer_discoverability::COLLIDE;
            try {
                first_writer_discoverability::set_state($courseid, discoverability::STATE_PUBLIC);
                $this->fail('Outside a transaction the collision is busy.');
            } catch (state_busy_exception $e) {
                $this->assertSame('statebusy', $e->errorcode);
            }
        } finally {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
            first_writer_discoverability::$next = null;
            $DB->delete_records(discoverability::TABLE, ['courseid' => $courseid]);
        }
    }

    /**
     * A category change is made under the category's lock, released after it; a change of nothing takes none.
     *
     * @return void
     */
    public function test_a_category_change_takes_and_releases_its_lock(): void {
        $categoryid = (int) $this->getDataGenerator()->create_category()->id;
        $this->setAdminUser();

        category_discoverability::set_state($categoryid, category_discoverability::STATE_UNLISTED);
        $this->assertSame(['get:category_' . $categoryid, 'release:category_' . $categoryid], state_lock_factory::$log);
        $this->assertSame(category_discoverability::STATE_UNLISTED, $this->stored_category_state($categoryid));

        // Control: the same state again changes nothing and asks for no lock.
        category_discoverability::set_state($categoryid, category_discoverability::STATE_UNLISTED);
        $this->assertCount(2, state_lock_factory::$log, 'A call that changes nothing must take no lock.');

        // A refused change releases both locks too, the row lock first.
        $user = $this->getDataGenerator()->create_user();
        try {
            category_discoverability::set_state($categoryid, category_discoverability::STATE_DEFAULT, (int) $user->id);
            $this->fail('Precondition: a user who may not manage the state is refused.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(
                [
                    'get:' . state_lock::RELIST_RESOURCE,
                    'get:category_' . $categoryid,
                    'release:category_' . $categoryid,
                    'release:' . state_lock::RELIST_RESOURCE,
                ],
                array_slice(state_lock_factory::$log, 2)
            );
        }
    }

    /**
     * Two relists of nested unlisted categories never interleave: the second waits for the first one's write.
     *
     * The fixture holds every lock it grants until it is released, as a real lock does for another
     * holder, and plays the second writer while the first one holds its locks.
     *
     * @return void
     */
    public function test_two_relists_of_nested_categories_never_interleave(): void {
        $generator = $this->getDataGenerator();
        $root = (int) $generator->create_category()->id;
        $outer = (int) $generator->create_category(['parent' => $root])->id;
        $inner = (int) $generator->create_category(['parent' => $outer])->id;
        $courseid = (int) $generator->create_course(['category' => $inner])->id;
        $this->setAdminUser();
        discoverability::set_state($courseid, discoverability::STATE_PUBLIC);
        category_discoverability::set_state($inner, category_discoverability::STATE_UNLISTED);
        category_discoverability::set_state($outer, category_discoverability::STATE_UNLISTED);
        $delegate = $this->create_statemanager($root);
        $publisher = $this->create_statemanager($root);
        $roleid = $generator->create_role();
        $coursecontext = \core\context\course::instance($courseid);
        assign_capability(discoverability::CAPABILITY_PUBLISH, CAP_ALLOW, $roleid, $coursecontext->id, true);
        role_assign($roleid, $publisher->id, $coursecontext->id);

        state_lock_factory::reset();
        state_lock_factory::$exclusive = true;
        $second = null;
        state_lock_factory::$ongranted = function () use ($inner, $delegate, &$second): void {
            try {
                category_discoverability::set_state($inner, category_discoverability::STATE_DEFAULT, (int) $delegate->id);
                $second = 'written';
            } catch (state_busy_exception $e) {
                $second = 'busy';
            }
        };

        // The first relist passes: the inner category still holds the course back.
        category_discoverability::set_state($outer, category_discoverability::STATE_DEFAULT, (int) $delegate->id);
        $this->assertSame('busy', $second, 'The second relist must meet the first one holding the shared lock.');
        $this->assertContains('timeout:' . state_lock::RELIST_RESOURCE, state_lock_factory::$log);
        $this->assertNull($this->stored_category_state($outer));
        $this->assertSame(category_discoverability::STATE_UNLISTED, $this->stored_category_state($inner));
        discoverability::reset_caches();
        category_discoverability::reset_caches();
        $this->assertFalse(discoverability::is_public($courseid), 'Nobody who may publish the course has listed it.');

        // Sequential control: the delegate's second relist now activates the course and is refused.
        try {
            category_discoverability::set_state($inner, category_discoverability::STATE_DEFAULT, (int) $delegate->id);
            $this->fail('Listing the inner category now publishes the course.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(category_discoverability::STATE_UNLISTED, $this->stored_category_state($inner));
        }

        // Control: somebody who may publish the course lists it, and the course is public.
        category_discoverability::set_state($inner, category_discoverability::STATE_DEFAULT, (int) $publisher->id);
        $this->assertNull($this->stored_category_state($inner));
        $this->assertSame(
            ['release:category_' . $inner, 'release:' . state_lock::RELIST_RESOURCE],
            array_slice(state_lock_factory::$log, -2)
        );
        discoverability::reset_caches();
        category_discoverability::reset_caches();
        $this->assertTrue(discoverability::is_public($courseid));
    }

    /**
     * A change to unlisted takes no shared lock: it goes ahead while another relist holds it; a relist does not.
     *
     * @return void
     */
    public function test_unlisting_does_not_wait_for_the_shared_lock(): void {
        $categoryid = (int) $this->getDataGenerator()->create_category()->id;
        $this->setAdminUser();
        state_lock_factory::$held = [state_lock::RELIST_RESOURCE];

        category_discoverability::set_state($categoryid, category_discoverability::STATE_UNLISTED);
        $this->assertSame(category_discoverability::STATE_UNLISTED, $this->stored_category_state($categoryid));
        $this->assertSame(['get:category_' . $categoryid, 'release:category_' . $categoryid], state_lock_factory::$log);

        // Control: leaving the unlisted state, to listed or to public, waits for it and is refused as busy.
        foreach ([category_discoverability::STATE_DEFAULT, category_discoverability::STATE_PUBLIC] as $target) {
            try {
                category_discoverability::set_state($categoryid, $target);
                $this->fail('A relist must not go ahead while another one holds the shared lock.');
            } catch (state_busy_exception $e) {
                $this->assertSame(category_discoverability::STATE_UNLISTED, $this->stored_category_state($categoryid));
            }
        }
        $this->assertNotContains('get:category_' . $categoryid, array_slice(state_lock_factory::$log, 2));
    }

    /**
     * A category whose lock another writer holds is refused with the plugin's message, and nothing is written.
     *
     * @return void
     */
    public function test_a_category_held_by_another_writer_is_refused_cleanly(): void {
        $categoryid = (int) $this->getDataGenerator()->create_category()->id;
        $this->setAdminUser();
        state_lock_factory::$held = ['category_' . $categoryid];

        try {
            category_discoverability::set_state($categoryid, category_discoverability::STATE_UNLISTED);
            $this->fail('A write must not go ahead without the lock.');
        } catch (\moodle_exception $e) {
            $this->assertSame('statebusy', $e->errorcode);
        }
        $this->assertNull($this->stored_category_state($categoryid));

        // Control: once the other writer lets go, the same call writes.
        state_lock_factory::$held = [];
        category_discoverability::set_state($categoryid, category_discoverability::STATE_UNLISTED);
        $this->assertSame(category_discoverability::STATE_UNLISTED, $this->stored_category_state($categoryid));
    }

    /**
     * The category publish decision is made on the row read under the lock.
     *
     * @return void
     */
    public function test_a_category_change_is_decided_on_the_row_read_under_the_lock(): void {
        $categoryid = (int) $this->getDataGenerator()->create_category()->id;
        $statemanager = $this->create_statemanager($categoryid);
        state_lock_factory::$ongranted = function () use ($categoryid): void {
            $this->write_behind(category_discoverability::TABLE, 'categoryid', $categoryid, category_discoverability::STATE_PUBLIC);
        };

        try {
            category_discoverability::set_state($categoryid, category_discoverability::STATE_UNLISTED, (int) $statemanager->id);
            $this->fail('Leaving the public state written meanwhile needs the publish capability.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(category_discoverability::STATE_PUBLIC, $this->stored_category_state($categoryid));
        }
        $this->assertSame('release:category_' . $categoryid, end(state_lock_factory::$log));

        // Control: with nothing written meanwhile, the same user unlists a category below it.
        $childid = (int) $this->getDataGenerator()->create_category(['parent' => $categoryid])->id;
        category_discoverability::set_state($childid, category_discoverability::STATE_UNLISTED, (int) $statemanager->id);
        $this->assertSame(category_discoverability::STATE_UNLISTED, $this->stored_category_state($childid));
    }
}
