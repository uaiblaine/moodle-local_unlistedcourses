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

        // A refused change releases the lock too.
        $user = $this->getDataGenerator()->create_user();
        try {
            category_discoverability::set_state($categoryid, category_discoverability::STATE_DEFAULT, (int) $user->id);
            $this->fail('Precondition: a user who may not manage the state is refused.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('release:category_' . $categoryid, end(state_lock_factory::$log));
        }
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
