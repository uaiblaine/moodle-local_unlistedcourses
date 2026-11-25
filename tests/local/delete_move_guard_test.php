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

use local_unlistedcourses\access;
use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\discoverability;
use local_unlistedcourses\event\category_state_updated;
use local_unlistedcourses\event\course_state_updated;
use local_unlistedcourses\event\public_state_kept_after_move;
use local_unlistedcourses\fixtures\state_lock_factory;
use local_unlistedcourses\observer;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests that deleting a category with its contents moved publishes nothing.
 *
 * Every deletion goes through core's own core_course_category::delete_move(), so what these
 * prove is the wiring of the pre_course_category_delete_move callback and of the
 * course_category_deleted observer, not the guard's methods on their own. The deleting user
 * holds moodle/category:manage and none of the plugin's capabilities: the demotion is a
 * restriction and must not need them. Each "left alone" case is beside the case that demotes,
 * so a guard that stopped demoting would be seen.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(delete_move_guard::class)]
#[CoversClass(observer::class)]
#[CoversClass(discoverability::class)]
#[CoversClass(category_discoverability::class)]
final class delete_move_guard_test extends \advanced_testcase {
    /**
     * Reset the request caches between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        access::reset_caches();
    }

    /**
     * Put a course in a state as the site administrator, with the caches reset behind it.
     *
     * @param int $courseid The course id.
     * @param int $state One of the state constants.
     * @return void
     */
    private function set_course_state(int $courseid, int $state): void {
        $current = $GLOBALS['USER'];
        $this->setAdminUser();
        discoverability::set_state($courseid, $state);
        $this->setUser($current);
        access::reset_caches();
    }

    /**
     * Put a category in a state as the site administrator, with the caches reset behind it.
     *
     * @param int $categoryid The category id.
     * @param int $state One of the state constants.
     * @return void
     */
    private function set_category_state(int $categoryid, int $state): void {
        $current = $GLOBALS['USER'];
        $this->setAdminUser();
        category_discoverability::set_state($categoryid, $state);
        $this->setUser($current);
        access::reset_caches();
    }

    /**
     * A user who may manage categories and holds none of the plugin's capabilities.
     *
     * @return \stdClass The user.
     */
    private function create_category_manager(): \stdClass {
        $generator = $this->getDataGenerator();
        $system = \core\context\system::instance();
        $roleid = $generator->create_role();
        assign_capability('moodle/category:manage', CAP_ALLOW, $roleid, $system->id, true);
        $user = $generator->create_user();
        role_assign($roleid, $user->id, $system->id);
        foreach (
            [
                discoverability::CAPABILITY_PUBLISH,
                category_discoverability::CAPABILITY_MANAGE,
                category_discoverability::CAPABILITY_PUBLISH,
            ] as $capability
        ) {
            $this->assertFalse(has_capability($capability, $system, $user), "Precondition: the deleter lacks {$capability}.");
        }
        return $user;
    }

    /**
     * The state of a course as stored now, the caches reset first.
     *
     * @param int $courseid The course id.
     * @return int The state constant.
     */
    private function course_state(int $courseid): int {
        access::reset_caches();
        return discoverability::get_state($courseid);
    }

    /**
     * The state of a category as stored now, the caches reset first.
     *
     * @param int $categoryid The category id.
     * @return int The state constant.
     */
    private function category_state(int $categoryid): int {
        access::reset_caches();
        return category_discoverability::get_state($categoryid);
    }

    /**
     * Log every event from now on to the standard log store.
     *
     * A sink would not do: events redirected to a sink are never dispatched, so the
     * course_category_deleted observer under test would not run at all.
     *
     * @return void
     */
    private function start_log(): void {
        $this->preventResetByRollback();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
    }

    /**
     * The logged events of one class, by object id.
     *
     * @param string $class The event class.
     * @return array Map of objectid => the event's other data plus the user who fired it.
     */
    private static function logged(string $class): array {
        global $DB;

        $found = [];
        foreach ($DB->get_records('logstore_standard_log', ['eventname' => '\\' . $class], 'id') as $row) {
            $found[(int) $row->objectid] = json_decode($row->other, true) + ['userid' => (int) $row->userid];
        }
        return $found;
    }

    /**
     * The logged reports of a public row kept after a deletion with a move, in the order they were logged.
     *
     * @return array List of the context level and instance, the object id, the crud letter, the user and the other data.
     */
    private static function logged_kept(): array {
        global $DB;

        $found = [];
        $rows = $DB->get_records('logstore_standard_log', ['eventname' => '\\' . public_state_kept_after_move::class], 'id');
        foreach ($rows as $row) {
            $found[] = [
                'contextlevel' => (int) $row->contextlevel,
                'contextinstanceid' => (int) $row->contextinstanceid,
                'objectid' => $row->objectid,
                'crud' => $row->crud,
                'userid' => (int) $row->userid,
                'other' => json_decode($row->other, true),
            ];
        }
        return $found;
    }

    /**
     * A public course in an unlisted category moved under a listed, visible parent goes back to listed.
     *
     * @return void
     */
    public function test_a_public_course_the_move_would_publish_goes_back_to_listed(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $target = $generator->create_category();
        $doomed = $generator->create_category();
        $course = $generator->create_course(['category' => $doomed->id]);
        $this->set_category_state((int) $doomed->id, category_discoverability::STATE_UNLISTED);
        $this->set_course_state((int) $course->id, discoverability::STATE_PUBLIC);
        $this->assertFalse(discoverability::is_public((int) $course->id), 'Precondition: the unlisted category holds it back.');

        $deleter = $this->create_category_manager();
        $this->setUser($deleter);
        $this->start_log();
        $this->assertTrue(\core_course_category::get($doomed->id, MUST_EXIST, true)->delete_move($target->id));
        $events = self::logged(course_state_updated::class);

        $this->assertSame((int) $target->id, (int) get_course($course->id)->category, 'Precondition: core moved the course.');
        $this->assertSame(discoverability::STATE_DEFAULT, $this->course_state((int) $course->id));
        $this->assertFalse(discoverability::is_public((int) $course->id));
        $expected = [
            (int) $course->id => [
                'oldstate' => discoverability::STATE_PUBLIC,
                'newstate' => discoverability::STATE_DEFAULT,
                'userid' => (int) $deleter->id,
            ],
        ];
        $this->assertSame($expected, $events);
    }

    /**
     * A public course and subcategory moved under another unlisted category stay inert, and their rows are left alone.
     *
     * @return void
     */
    public function test_public_rows_the_move_leaves_inert_keep_their_state(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $target = $generator->create_category();
        $doomed = $generator->create_category();
        $child = $generator->create_category(['parent' => $doomed->id]);
        $course = $generator->create_course(['category' => $doomed->id]);
        $this->set_category_state((int) $target->id, category_discoverability::STATE_UNLISTED);
        $this->set_category_state((int) $doomed->id, category_discoverability::STATE_UNLISTED);
        $this->set_category_state((int) $child->id, category_discoverability::STATE_PUBLIC);
        $this->set_course_state((int) $course->id, discoverability::STATE_PUBLIC);

        $this->setUser($this->create_category_manager());
        $this->start_log();
        $this->assertTrue(\core_course_category::get($doomed->id, MUST_EXIST, true)->delete_move($target->id));

        $this->assertSame((int) $target->id, (int) get_course($course->id)->category, 'Precondition: core moved the course.');
        $this->assertFalse(discoverability::is_public((int) $course->id), 'Precondition: the new parent holds it back.');
        $this->assertFalse(category_discoverability::is_public((int) $child->id), 'Precondition: and the subcategory.');
        $this->assertSame(discoverability::STATE_PUBLIC, $this->course_state((int) $course->id));
        $this->assertSame(category_discoverability::STATE_PUBLIC, $this->category_state((int) $child->id));
        $this->assertSame([], self::logged(course_state_updated::class));
        $this->assertSame([], self::logged(category_state_updated::class));
    }

    /**
     * Public rows that were effective before the deletion stay public, and so does a hidden course the move left hidden.
     *
     * @return void
     */
    public function test_a_public_row_effective_before_or_inert_after_is_left_alone(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $target = $generator->create_category();
        $doomed = $generator->create_category();
        $child = $generator->create_category(['parent' => $doomed->id]);
        $effective = $generator->create_course(['category' => $doomed->id]);
        $hidden = $generator->create_course(['category' => $doomed->id, 'visible' => 0]);
        $this->set_category_state((int) $child->id, category_discoverability::STATE_PUBLIC);
        $this->set_course_state((int) $effective->id, discoverability::STATE_PUBLIC);
        $this->set_course_state((int) $hidden->id, discoverability::STATE_PUBLIC);
        $this->assertTrue(discoverability::is_public((int) $effective->id), 'Precondition: a listed category serves it.');
        $this->assertTrue(category_discoverability::is_public((int) $child->id), 'Precondition: and the subcategory.');
        $this->assertFalse(discoverability::is_public((int) $hidden->id), 'Precondition: a hidden course is not served.');

        $this->setUser($this->create_category_manager());
        $this->start_log();
        $this->assertTrue(\core_course_category::get($doomed->id, MUST_EXIST, true)->delete_move($target->id));

        $this->assertSame(discoverability::STATE_PUBLIC, $this->course_state((int) $effective->id));
        $this->assertTrue(discoverability::is_public((int) $effective->id));
        $this->assertSame(category_discoverability::STATE_PUBLIC, $this->category_state((int) $child->id));
        $this->assertTrue(category_discoverability::is_public((int) $child->id));
        $this->assertSame(discoverability::STATE_PUBLIC, $this->course_state((int) $hidden->id));
        $this->assertFalse(discoverability::is_public((int) $hidden->id));
        $this->assertSame([], self::logged(course_state_updated::class));
        $this->assertSame([], self::logged(category_state_updated::class));
    }

    /**
     * A public subcategory, and a public course inside it, both made effective by the move, go back to listed.
     *
     * @return void
     */
    public function test_a_public_subcategory_and_its_course_the_move_would_publish_go_back_to_listed(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $target = $generator->create_category();
        $doomed = $generator->create_category();
        $child = $generator->create_category(['parent' => $doomed->id]);
        $grandchild = $generator->create_category(['parent' => $child->id]);
        $course = $generator->create_course(['category' => $grandchild->id]);
        $this->set_category_state((int) $doomed->id, category_discoverability::STATE_UNLISTED);
        $this->set_category_state((int) $child->id, category_discoverability::STATE_PUBLIC);
        $this->set_category_state((int) $grandchild->id, category_discoverability::STATE_PUBLIC);
        $this->set_course_state((int) $course->id, discoverability::STATE_PUBLIC);
        $this->assertFalse(category_discoverability::is_public((int) $child->id), 'Precondition: held back by the parent.');

        $deleter = $this->create_category_manager();
        $this->setUser($deleter);
        $this->start_log();
        $this->assertTrue(\core_course_category::get($doomed->id, MUST_EXIST, true)->delete_move($target->id));

        $this->assertSame(
            (int) $target->id,
            (int) $DB->get_field('course_categories', 'parent', ['id' => $child->id]),
            'Precondition: core moved the subcategory.'
        );
        $this->assertSame(category_discoverability::STATE_DEFAULT, $this->category_state((int) $child->id));
        $this->assertSame(category_discoverability::STATE_DEFAULT, $this->category_state((int) $grandchild->id));
        $this->assertSame(discoverability::STATE_DEFAULT, $this->course_state((int) $course->id));
        $categoryevents = self::logged(category_state_updated::class);
        ksort($categoryevents);
        $this->assertSame([(int) $child->id, (int) $grandchild->id], array_keys($categoryevents));
        $this->assertSame((int) $deleter->id, $categoryevents[(int) $child->id]['userid']);
        $this->assertSame([(int) $course->id], array_keys(self::logged(course_state_updated::class)));
    }

    /**
     * A deletion with move core aborts writes nothing, and the same deletion completed does demote.
     *
     * move_courses() returns false when the new parent's row is gone; core_course_category::get()
     * still hands the category over from its record cache, which is how the test reaches that
     * branch after the callback has run.
     *
     * @return void
     */
    public function test_a_deletion_with_move_core_aborts_writes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $target = $generator->create_category();
        $doomed = $generator->create_category();
        $course = $generator->create_course(['category' => $doomed->id]);
        $this->set_category_state((int) $doomed->id, category_discoverability::STATE_UNLISTED);
        $this->set_course_state((int) $course->id, discoverability::STATE_PUBLIC);

        $this->setUser($this->create_category_manager());
        \core_course_category::get($target->id, MUST_EXIST, true);
        $targetrow = $DB->get_record('course_categories', ['id' => $target->id], '*', MUST_EXIST);
        $DB->delete_records('course_categories', ['id' => $target->id]);

        $this->start_log();
        $this->assertFalse(
            \core_course_category::get($doomed->id, MUST_EXIST, true)->delete_move($target->id),
            'Precondition: core cannot move the courses.'
        );
        $events = self::logged(course_state_updated::class);

        $this->assertTrue($DB->record_exists('course_categories', ['id' => $doomed->id]), 'Precondition: nothing was deleted.');
        $this->assertSame(discoverability::STATE_PUBLIC, $this->course_state((int) $course->id));
        $this->assertSame(category_discoverability::STATE_UNLISTED, $this->category_state((int) $doomed->id));
        $this->assertSame([], $events);

        // Control: with the new parent back, the same deletion completes and takes the course back to listed.
        $DB->import_record('course_categories', $targetrow);
        $this->assertTrue(\core_course_category::get($doomed->id, MUST_EXIST, true)->delete_move($target->id));
        $this->assertSame(discoverability::STATE_DEFAULT, $this->course_state((int) $course->id));
    }

    /**
     * A record no move settles is dropped by a deletion without a move, unread.
     *
     * What a delete_move() core aborted leaves behind: the callback recorded, nothing was deleted.
     * When the category is later deleted with its contents, the event carries no new parent, and
     * a course moved out of it in the meantime by an ordinary move keeps its state.
     *
     * @return void
     */
    public function test_a_deletion_without_a_move_settles_nothing(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $target = $generator->create_category();
        $doomed = $generator->create_category();
        $course = $generator->create_course(['category' => $doomed->id]);
        $this->set_category_state((int) $doomed->id, category_discoverability::STATE_UNLISTED);
        $this->set_course_state((int) $course->id, discoverability::STATE_PUBLIC);

        $this->setAdminUser();
        delete_move_guard::record(\core_course_category::get($doomed->id, MUST_EXIST, true));
        $this->assertTrue(move_courses([$course->id], $target->id), 'Precondition: an ordinary move.');
        access::reset_caches();
        $this->assertTrue(discoverability::is_public((int) $course->id), 'Precondition: the ordinary move made it effective.');

        \core_course_category::get($doomed->id, MUST_EXIST, true)->delete_full(false);

        $this->assertSame(discoverability::STATE_PUBLIC, $this->course_state((int) $course->id));
    }

    /**
     * A public row whose lock another writer holds is skipped, stays public and is reported; a free one is demoted.
     *
     * The held lock is the fixture factory's ({@see state_lock_factory}): another holder that
     * never lets go, which is what a timeout looks like. The report is the site log's event, which
     * every site keeps, and debugging(), which only a site with developer debugging shows. The control
     * is a second deletion in the same test with every lock free, where the same kind of row goes back
     * to listed and nothing is reported.
     *
     * @return void
     */
    public function test_a_row_whose_lock_is_held_stays_public_and_is_reported(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/unlistedcourses/tests/fixtures/state_lock_factory.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $target = $generator->create_category();
        $doomed = $generator->create_category();
        $child = $generator->create_category(['parent' => $doomed->id]);
        $course = $generator->create_course(['category' => $doomed->id]);
        $this->set_category_state((int) $doomed->id, category_discoverability::STATE_UNLISTED);
        $this->set_category_state((int) $child->id, category_discoverability::STATE_PUBLIC);
        $this->set_course_state((int) $course->id, discoverability::STATE_PUBLIC);

        $CFG->lock_factory = '\\' . state_lock_factory::class;
        state_lock_factory::reset();
        state_lock_factory::$held = ['course_' . $course->id, 'category_' . $child->id];
        $deleter = $this->create_category_manager();
        $this->setUser($deleter);
        $this->start_log();
        $this->assertTrue(\core_course_category::get($doomed->id, MUST_EXIST, true)->delete_move($target->id));

        $this->assertSame((int) $target->id, (int) get_course($course->id)->category, 'Precondition: core moved the course.');
        $this->assertTrue(discoverability::is_public((int) $course->id), 'Precondition: the move made it effective.');
        $this->assertSame(discoverability::STATE_PUBLIC, $this->course_state((int) $course->id));
        $this->assertSame(category_discoverability::STATE_PUBLIC, $this->category_state((int) $child->id));
        $this->assertSame([], self::logged(course_state_updated::class));
        $this->assertSame([], self::logged(category_state_updated::class));
        $reports = [
            [
                'contextlevel' => CONTEXT_COURSE,
                'contextinstanceid' => (int) $course->id,
                'objectid' => null,
                'crud' => 'r',
                'userid' => (int) $deleter->id,
                'other' => ['deletedcategoryid' => (int) $doomed->id, 'kind' => 'course'],
            ],
            [
                'contextlevel' => CONTEXT_COURSECAT,
                'contextinstanceid' => (int) $child->id,
                'objectid' => null,
                'crud' => 'r',
                'userid' => (int) $deleter->id,
                'other' => ['deletedcategoryid' => (int) $doomed->id, 'kind' => 'category'],
            ],
        ];
        $this->assertSame($reports, self::logged_kept(), 'Every site logs what was kept, whatever its debugging level.');
        $this->assertDebuggingCalledCount(2, [
            "local_unlistedcourses: course {$course->id} was skipped after category {$doomed->id} was deleted with its "
                . 'contents moved, because another writer held its lock; it kept its public state.',
            "local_unlistedcourses: category {$child->id} was skipped after category {$doomed->id} was deleted with its "
                . 'contents moved, because another writer held its lock; it kept its public state.',
        ]);

        // Control: the same kind of deletion with every lock free demotes the row and reports nothing.
        state_lock_factory::reset();
        $doomed2 = $generator->create_category();
        $course2 = $generator->create_course(['category' => $doomed2->id]);
        $this->set_category_state((int) $doomed2->id, category_discoverability::STATE_UNLISTED);
        $this->set_course_state((int) $course2->id, discoverability::STATE_PUBLIC);
        $this->start_log();
        $this->assertTrue(\core_course_category::get($doomed2->id, MUST_EXIST, true)->delete_move($target->id));
        $this->assertSame(discoverability::STATE_DEFAULT, $this->course_state((int) $course2->id));
        $events = self::logged(course_state_updated::class);
        $this->assertSame(discoverability::STATE_DEFAULT, $events[(int) $course2->id]['newstate']);
        $this->assertDebuggingNotCalled();
        $this->assertSame($reports, self::logged_kept(), 'A row that was demoted is not reported as kept.');
        state_lock_factory::reset();
    }

    /**
     * A row the request's memo still calls public, though a category now holds it back, is recorded and demoted.
     *
     * Earlier work in the same request can leave the public memos answering for a path that has
     * changed since: one core_course_delete_categories call deletes several categories, and a
     * delete_move() that aborted after moving children leaves the caches as they were. Here the memos
     * are warmed while the parent is listed, and the parent is then unlisted by a direct write, which
     * resets nothing. The control is the same sequence with the memos reset before the deletion.
     *
     * @return void
     */
    public function test_a_stale_public_memo_does_not_hide_a_row_the_move_publishes(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $target = $generator->create_category();
        $deleter = $this->create_category_manager();

        foreach (['stale' => false, 'control' => true] as $case => $reset) {
            $doomed = $generator->create_category();
            $child = $generator->create_category(['parent' => $doomed->id]);
            $course = $generator->create_course(['category' => $doomed->id]);
            $this->set_category_state((int) $child->id, category_discoverability::STATE_PUBLIC);
            $this->set_course_state((int) $course->id, discoverability::STATE_PUBLIC);

            $this->setUser($deleter);
            $this->start_log();
            $this->assertTrue(discoverability::is_public((int) $course->id), "{$case}: precondition, effective under its parent.");
            $this->assertTrue(category_discoverability::is_public((int) $child->id), "{$case}: precondition, the subcategory too.");
            $this->unlist_behind_the_memos((int) $doomed->id);
            if ($reset) {
                access::reset_caches();
                $this->assertFalse(discoverability::is_public((int) $course->id), "{$case}: precondition, held back now.");
                $this->assertFalse(category_discoverability::is_public((int) $child->id), "{$case}: precondition, both.");
            } else {
                $this->assertTrue(discoverability::is_public((int) $course->id), "{$case}: precondition, the memo is stale.");
                $this->assertTrue(category_discoverability::is_public((int) $child->id), "{$case}: precondition, both memos.");
            }

            $this->assertTrue(\core_course_category::get($doomed->id, MUST_EXIST, true)->delete_move($target->id));

            $this->assertSame(
                discoverability::STATE_DEFAULT,
                $this->course_state((int) $course->id),
                "{$case}: the move made the course public, so it goes back to listed."
            );
            $this->assertSame(
                category_discoverability::STATE_DEFAULT,
                $this->category_state((int) $child->id),
                "{$case}: and the subcategory."
            );
            $this->assertArrayHasKey((int) $course->id, self::logged(course_state_updated::class));
            $this->assertArrayHasKey((int) $child->id, self::logged(category_state_updated::class));
        }
    }

    /**
     * Unlist a category by writing its row directly, which resets none of the plugin's request memos.
     *
     * @param int $categoryid The category id.
     * @return void
     */
    private function unlist_behind_the_memos(int $categoryid): void {
        global $DB;

        $DB->insert_record(category_discoverability::TABLE, (object) [
            'categoryid' => $categoryid,
            'state' => category_discoverability::STATE_UNLISTED,
            'usermodified' => 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * A category object without a path records nothing; a real category records the public rows below it.
     *
     * core_course_category::top() is the category object core builds with an empty path: a bound
     * LIKE on its path would be '/%', every category of the site, and the course of an unrelated
     * category would be recorded under it. Recording is observed through settling: the course is
     * made effective between the two, so a recorded course is demoted. The control records the same
     * course through its own category, whose path is real, and settling that demotes it.
     *
     * @return void
     */
    public function test_a_category_without_a_path_records_nothing(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $this->set_category_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $this->set_course_state((int) $course->id, discoverability::STATE_PUBLIC);
        $this->setAdminUser();
        $adminid = (int) get_admin()->id;
        $top = \core_course_category::top();
        $this->assertSame('', (string) $top->path, 'Precondition: the top category has no path.');
        $this->assertFalse(discoverability::is_public((int) $course->id), 'Precondition: inert under the unlisted category.');

        delete_move_guard::record($top);
        $this->set_category_state((int) $category->id, category_discoverability::STATE_DEFAULT);
        $this->assertTrue(discoverability::is_public((int) $course->id), 'Precondition: effective once the category is listed.');
        delete_move_guard::settle((int) $top->id, true, $adminid);
        $this->assertSame(discoverability::STATE_PUBLIC, $this->course_state((int) $course->id), 'Nothing was recorded under it.');

        // Control: the course's own category records it, and settling that record demotes it.
        $this->set_category_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        delete_move_guard::record(\core_course_category::get($category->id, MUST_EXIST, true));
        $this->set_category_state((int) $category->id, category_discoverability::STATE_DEFAULT);
        delete_move_guard::settle((int) $category->id, true, $adminid);
        $this->assertSame(discoverability::STATE_DEFAULT, $this->course_state((int) $course->id));
    }

    /**
     * The path that skips the capability check takes a public row to listed and touches no other state.
     *
     * @return void
     */
    public function test_unpublishing_after_a_move_changes_only_a_public_row(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $unlisted = $generator->create_course(['category' => $category->id]);
        $listed = $generator->create_course(['category' => $category->id]);
        $public = $generator->create_course(['category' => $category->id]);
        $unlistedcat = $generator->create_category();
        $publiccat = $generator->create_category();
        $this->set_course_state((int) $unlisted->id, discoverability::STATE_UNLISTED);
        $this->set_course_state((int) $public->id, discoverability::STATE_PUBLIC);
        $this->set_category_state((int) $unlistedcat->id, category_discoverability::STATE_UNLISTED);
        $this->set_category_state((int) $publiccat->id, category_discoverability::STATE_PUBLIC);
        $user = $this->create_category_manager();

        $sink = $this->redirectEvents();
        discoverability::unpublish_after_move((int) $unlisted->id, (int) $user->id);
        discoverability::unpublish_after_move((int) $listed->id, (int) $user->id);
        category_discoverability::unpublish_after_move((int) $unlistedcat->id, (int) $user->id);
        $this->assertSame([], $sink->get_events(), 'Nothing but a public row is written.');
        $this->assertSame(discoverability::STATE_UNLISTED, $this->course_state((int) $unlisted->id));
        $this->assertSame(discoverability::STATE_DEFAULT, $this->course_state((int) $listed->id));
        $this->assertSame(category_discoverability::STATE_UNLISTED, $this->category_state((int) $unlistedcat->id));

        // Control: the public ones go to listed, with no capability asked of the user named.
        discoverability::unpublish_after_move((int) $public->id, (int) $user->id);
        category_discoverability::unpublish_after_move((int) $publiccat->id, (int) $user->id);
        $this->assertCount(2, $sink->get_events());
        $sink->close();
        $this->assertSame(discoverability::STATE_DEFAULT, $this->course_state((int) $public->id));
        $this->assertSame(category_discoverability::STATE_DEFAULT, $this->category_state((int) $publiccat->id));
    }
}
