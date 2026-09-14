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
 * Course discoverability - Tests for the state and its gate
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses;

use local_unlistedcourses\event\course_state_updated;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the discoverability state, its capability gate and the public predicate.
 *
 * Every refusal is paired with a control that the same call succeeds for
 * somebody who may make it, and every "still in state X" assertion follows a
 * refused attempt to leave it - so a gate that stopped gating would be seen.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(discoverability::class)]
#[CoversClass(course_state_updated::class)]
final class discoverability_test extends \advanced_testcase {
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
     * A manager over the course's category.
     *
     * @param \stdClass $course The course.
     * @return \stdClass The user.
     */
    private function create_manager(\stdClass $course): \stdClass {
        global $DB;

        $manager = $this->getDataGenerator()->create_user();
        role_assign(
            $DB->get_field('role', 'id', ['shortname' => 'manager']),
            $manager->id,
            \core\context\coursecat::instance($course->category)->id
        );
        return $manager;
    }

    /**
     * An editing teacher enrolled in the course.
     *
     * @param \stdClass $course The course.
     * @return \stdClass The user.
     */
    private function create_teacher(\stdClass $course): \stdClass {
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        return $teacher;
    }

    /**
     * A course without a row is listed, and get_states() answers in the order asked.
     *
     * @return void
     */
    public function test_a_course_without_a_row_is_listed_and_states_keep_the_order_given(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $listed = $generator->create_course();
        $unlisted = $generator->create_course();
        $public = $generator->create_course();
        $this->setAdminUser();
        discoverability::set_state((int) $unlisted->id, discoverability::STATE_UNLISTED);
        discoverability::set_state((int) $public->id, discoverability::STATE_PUBLIC);

        $states = discoverability::get_states([(int) $public->id, (int) $listed->id, (int) $unlisted->id, (int) $listed->id]);
        $this->assertSame([
            (int) $public->id => discoverability::STATE_PUBLIC,
            (int) $listed->id => discoverability::STATE_DEFAULT,
            (int) $unlisted->id => discoverability::STATE_UNLISTED,
        ], $states);

        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state(999999));
        $this->assertTrue(discoverability::is_unlisted((int) $unlisted->id));
        $this->assertFalse(discoverability::is_unlisted((int) $listed->id));
        $this->assertFalse(discoverability::is_unlisted((int) $public->id));
    }

    /**
     * A manager publishes and un-publishes, and each change is logged.
     *
     * @return void
     */
    public function test_a_manager_publishes_and_unpublishes_and_each_change_is_logged(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->create_manager($course);
        $this->setUser($manager);

        $sink = $this->redirectEvents();
        discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC);
        $this->assertTrue(discoverability::is_public((int) $course->id));
        $row = $DB->get_record(discoverability::TABLE, ['courseid' => $course->id], '*', MUST_EXIST);
        $this->assertSame(discoverability::STATE_PUBLIC, (int) $row->state);
        $this->assertSame((int) $manager->id, (int) $row->usermodified);
        $this->assertGreaterThan(0, (int) $row->timemodified);

        discoverability::set_state((int) $course->id, discoverability::STATE_DEFAULT);
        $this->assertFalse(discoverability::is_public((int) $course->id));
        $this->assertFalse(
            $DB->record_exists(discoverability::TABLE, ['courseid' => $course->id]),
            'Back to the default means no row at all.'
        );

        $events = array_values(array_filter($sink->get_events(), static function ($event) {
            return $event instanceof course_state_updated;
        }));
        $sink->close();
        $this->assertCount(2, $events);
        $this->assertSame((int) $course->id, (int) $events[0]->objectid);
        $this->assertSame((int) $manager->id, (int) $events[0]->userid);
        $this->assertEquals(\core\context\course::instance($course->id), $events[0]->get_context());
        $this->assertSame(['oldstate' => 0, 'newstate' => 2], $events[0]->other);
        $this->assertSame(['oldstate' => 2, 'newstate' => 0], $events[1]->other);
        $this->assertStringContainsString("'{$course->id}'", $events[0]->get_description());
        $this->assertStringContainsString('course/edit.php', $events[0]->get_url()->out(false));
    }

    /**
     * An editing teacher may unlist a course, and may not publish it.
     *
     * @return void
     */
    public function test_an_editing_teacher_may_unlist_but_not_publish(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->create_teacher($course);
        $manager = $this->create_manager($course);

        $this->setUser($teacher);
        discoverability::set_state((int) $course->id, discoverability::STATE_UNLISTED);
        $this->assertTrue(discoverability::is_unlisted((int) $course->id));

        try {
            discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC);
            $this->fail('An editing teacher must not publish a course.');
        } catch (\required_capability_exception $e) {
            // The exception carries the capability's display name, not its key.
            $this->assertSame(get_capability_string(discoverability::CAPABILITY_PUBLISH), $e->a);
        }
        $this->assertTrue(discoverability::is_unlisted((int) $course->id), 'The refused change must not land.');
        $this->assertFalse(discoverability::is_public((int) $course->id));

        // Control: the same call by a manager succeeds.
        $this->setUser($manager);
        discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC);
        $this->assertTrue(discoverability::is_public((int) $course->id));
    }

    /**
     * Leaving the public state is the publishing decision reversed: the same capability.
     *
     * @return void
     */
    public function test_an_editing_teacher_cannot_unpublish(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->create_teacher($course);
        $manager = $this->create_manager($course);

        $this->setUser($manager);
        discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC);

        $this->setUser($teacher);
        foreach ([discoverability::STATE_UNLISTED, discoverability::STATE_DEFAULT] as $target) {
            try {
                discoverability::set_state((int) $course->id, $target);
                $this->fail("An editing teacher must not move a public course to state {$target}.");
            } catch (\required_capability_exception $e) {
                $this->assertTrue(discoverability::is_public((int) $course->id));
            }
        }

        // Control: the manager may.
        $this->setUser($manager);
        discoverability::set_state((int) $course->id, discoverability::STATE_UNLISTED);
        $this->assertTrue(discoverability::is_unlisted((int) $course->id));
    }

    /**
     * A call that changes nothing needs no capability and fires no event.
     *
     * This is what lets the course form re-submit "public" from a frozen
     * control when an editor who may not publish saves an unrelated change.
     *
     * @return void
     */
    public function test_an_unchanged_state_needs_no_capability_and_fires_no_event(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $manager = $this->create_manager($course);
        $nobody = $this->getDataGenerator()->create_user();

        $this->setUser($manager);
        discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC);

        $this->setUser($nobody);
        $sink = $this->redirectEvents();
        discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC);
        $this->assertCount(0, $sink->get_events());
        $sink->close();
        $this->assertTrue(discoverability::is_public((int) $course->id));

        // Control: the same user CHANGING the state is refused.
        $this->expectException(\required_capability_exception::class);
        discoverability::set_state((int) $course->id, discoverability::STATE_UNLISTED);
    }

    /**
     * The user passed in is the one checked, not whoever is logged in.
     *
     * Restore passes the restoring user, which is not necessarily $USER.
     *
     * @return void
     */
    public function test_the_explicit_user_is_the_one_checked(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->create_teacher($course);
        $manager = $this->create_manager($course);
        $this->setAdminUser();

        try {
            discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC, (int) $teacher->id);
            $this->fail('The explicit user must be the one checked, even with an admin logged in.');
        } catch (\required_capability_exception $e) {
            $this->assertFalse(discoverability::is_public((int) $course->id));
        }

        // Control: an explicit user who may publish is accepted, and recorded.
        discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC, (int) $manager->id);
        $this->assertTrue(discoverability::is_public((int) $course->id));
        $this->assertSame(
            (int) $manager->id,
            (int) $DB->get_field(discoverability::TABLE, 'usermodified', ['courseid' => $course->id])
        );
    }

    /**
     * The site course and unknown states are programming errors.
     *
     * @return void
     */
    public function test_the_site_course_and_unknown_states_are_refused(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        try {
            discoverability::set_state(SITEID, discoverability::STATE_UNLISTED);
            $this->fail('The site course has no state.');
        } catch (\coding_exception $e) {
            $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state(SITEID));
        }

        try {
            discoverability::set_state((int) $course->id, 7);
            $this->fail('An unknown state must be refused.');
        } catch (\coding_exception $e) {
            $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state((int) $course->id));
        }
    }

    /**
     * is_public() requires the course and every category on its path to be visible.
     *
     * @return void
     */
    public function test_is_public_requires_the_course_and_every_category_on_its_path_to_be_visible(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category();
        $child = $generator->create_category(['parent' => $parent->id]);
        $course = $generator->create_course(['category' => $child->id]);
        $this->setAdminUser();
        discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC);
        $this->assertTrue(discoverability::is_public((int) $course->id), 'Precondition: visible everywhere.');

        /* The answer is memoised for the request, so a visibility flag written straight to
           the table - which is not a route this plugin owns - is followed only after the
           memo is dropped. Every pair below therefore resets before it re-reads. */
        $DB->set_field('course', 'visible', 0, ['id' => $course->id]);
        discoverability::reset_caches();
        $this->assertFalse(discoverability::is_public((int) $course->id), 'A hidden course is never public.');
        $DB->set_field('course', 'visible', 1, ['id' => $course->id]);
        discoverability::reset_caches();
        $this->assertTrue(discoverability::is_public((int) $course->id));

        $DB->set_field('course_categories', 'visible', 0, ['id' => $child->id]);
        discoverability::reset_caches();
        $this->assertFalse(discoverability::is_public((int) $course->id), 'A hidden category is never public.');
        $DB->set_field('course_categories', 'visible', 1, ['id' => $child->id]);
        discoverability::reset_caches();
        $this->assertTrue(discoverability::is_public((int) $course->id));

        $DB->set_field('course_categories', 'visible', 0, ['id' => $parent->id]);
        discoverability::reset_caches();
        $this->assertFalse(discoverability::is_public((int) $course->id), 'A hidden ancestor is never public.');
        $DB->set_field('course_categories', 'visible', 1, ['id' => $parent->id]);
        discoverability::reset_caches();
        $this->assertTrue(discoverability::is_public((int) $course->id));

        // Control: a listed and an unlisted course are never public, however visible.
        $other = $generator->create_course(['category' => $child->id]);
        $this->assertFalse(discoverability::is_public((int) $other->id));
        discoverability::set_state((int) $other->id, discoverability::STATE_UNLISTED);
        $this->assertFalse(discoverability::is_public((int) $other->id));
    }

    /**
     * A public course in an unlisted category is not public; re-listing the category restores it.
     *
     * An anonymous visitor can never satisfy a cohort or a role, so no course
     * with an unlisted category on its path may be served to one, however the
     * course's own state reads.
     *
     * @return void
     */
    public function test_a_public_course_in_an_unlisted_category_is_not_public(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $unlistedcategory = $generator->create_category();
        $courseinside = $generator->create_course(['category' => $unlistedcategory->id]);
        $listedcategory = $generator->create_category();
        $siblingcourse = $generator->create_course(['category' => $listedcategory->id]);

        $this->setAdminUser();
        category_discoverability::set_state((int) $unlistedcategory->id, category_discoverability::STATE_UNLISTED);
        discoverability::set_state((int) $courseinside->id, discoverability::STATE_PUBLIC);
        discoverability::set_state((int) $siblingcourse->id, discoverability::STATE_PUBLIC);

        $this->assertFalse(
            discoverability::is_public((int) $courseinside->id),
            'A public course in an unlisted category must not be public.'
        );
        $this->assertTrue(
            discoverability::is_public((int) $siblingcourse->id),
            'Control: a public course in a listed sibling category stays public.'
        );

        // Re-listing the category makes the first course public again.
        category_discoverability::set_state((int) $unlistedcategory->id, category_discoverability::STATE_DEFAULT);
        $this->assertTrue(discoverability::is_public((int) $courseinside->id));
    }

    /**
     * A value this version does not know reads as listed - never as public.
     *
     * @return void
     */
    public function test_an_unknown_stored_value_reads_as_listed_never_as_public(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record(discoverability::TABLE, (object) [
            'courseid' => $course->id,
            'state' => 9,
            'usermodified' => 0,
            'timemodified' => time(),
        ]);
        access::reset_caches();

        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state((int) $course->id));
        $this->assertFalse(discoverability::is_public((int) $course->id));
        $this->assertFalse(discoverability::is_unlisted((int) $course->id));
    }

    /**
     * Deleting a course drops its row, through the before_course_deleted hook.
     *
     * @return void
     */
    public function test_deleting_a_course_drops_its_row(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $doomed = $generator->create_course();
        $control = $generator->create_course();
        $this->setAdminUser();
        discoverability::set_state((int) $doomed->id, discoverability::STATE_UNLISTED);
        discoverability::set_state((int) $control->id, discoverability::STATE_UNLISTED);

        delete_course($doomed->id, false);

        $this->assertFalse($DB->record_exists(discoverability::TABLE, ['courseid' => $doomed->id]));
        $this->assertTrue(
            $DB->record_exists(discoverability::TABLE, ['courseid' => $control->id]),
            'Control: another course\'s row must survive.'
        );
    }

    /**
     * are_public() agrees with is_public() over every shape, and answers in the order asked.
     *
     * The six shapes are the whole composition: the state, the course's own
     * visibility, the category's visibility, an ancestor's visibility, an
     * unlisted category on the path, and a course that is not there at all.
     *
     * @return void
     */
    public function test_are_public_agrees_with_is_public_over_every_shape(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $parent = $generator->create_category();
        $child = $generator->create_category(['parent' => $parent->id]);
        $hiddencategory = $generator->create_category(['parent' => $parent->id, 'visible' => 0]);
        $unlistedcategory = $generator->create_category(['parent' => $parent->id]);

        $public = $generator->create_course(['category' => $child->id]);
        $hidden = $generator->create_course(['category' => $child->id, 'visible' => 0]);
        $inhidden = $generator->create_course(['category' => $hiddencategory->id]);
        $inunlisted = $generator->create_course(['category' => $unlistedcategory->id]);
        $unlisted = $generator->create_course(['category' => $child->id]);
        $listed = $generator->create_course(['category' => $child->id]);

        $this->setAdminUser();
        foreach ([$public, $hidden, $inhidden, $inunlisted] as $course) {
            discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC);
        }
        discoverability::set_state((int) $unlisted->id, discoverability::STATE_UNLISTED);
        category_discoverability::set_state((int) $unlistedcategory->id, category_discoverability::STATE_UNLISTED);

        $expected = [
            (int) $public->id => true,
            (int) $hidden->id => false,
            (int) $inhidden->id => false,
            (int) $inunlisted->id => false,
            (int) $unlisted->id => false,
            (int) $listed->id => false,
            999999 => false,
        ];

        $this->setUser(0);
        access::reset_caches();
        $batch = discoverability::are_public(array_keys($expected));
        $this->assertSame(array_keys($expected), array_keys($batch), 'The answers come back in the order asked.');
        $this->assertSame($expected, $batch);

        foreach ($expected as $courseid => $answer) {
            access::reset_caches();
            $this->assertSame(
                $answer,
                discoverability::is_public($courseid),
                "One at a time and in a batch must agree for course {$courseid}."
            );
        }

        // Control: a hidden ancestor withholds a course whose own category is visible.
        $DB->set_field('course_categories', 'visible', 0, ['id' => $parent->id]);
        access::reset_caches();
        $this->assertSame(
            [(int) $public->id => false],
            discoverability::are_public([(int) $public->id]),
            'A hidden ancestor is never public.'
        );
    }

    /**
     * are_public() over 200 courses costs what it costs over 2.
     *
     * THE LARGE SET IS SPREAD OVER TWENTY CATEGORIES, and the small one sits in
     * one, on purpose. All 202 courses in a single category would read alike
     * under an implementation that batched per distinct CATEGORY rather than
     * per request, because there would be one distinct category either way, and
     * the equality would then hold for a reason other than the one asserted.
     * Twenty categories against one separates the two: a per-category
     * implementation pays twenty path lookups on the large set and one on the
     * small, and the assertion fails as it should.
     *
     * @return void
     */
    public function test_are_public_reads_the_same_for_two_courses_and_for_two_hundred(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category();
        $category = $generator->create_category(['parent' => $parent->id]);

        $small = $this->seed_public_courses((int) $category->id, 2);
        $large = [];
        for ($i = 0; $i < 20; $i++) {
            $spread = $generator->create_category(['parent' => $parent->id]);
            $large = array_merge($large, $this->seed_public_courses((int) $spread->id, 10));
        }
        $this->assertCount(200, $large, 'Precondition: the large set really holds two hundred courses.');
        [$insql, $params] = $DB->get_in_or_equal($large, SQL_PARAMS_NAMED, 'crs');
        $this->assertCount(
            20,
            array_unique($DB->get_fieldset_select('course', 'category', "id $insql", $params)),
            'The large set must be spread over twenty distinct categories, not one.'
        );

        $this->setUser(0);
        access::reset_caches();
        $before = $DB->perf_get_reads();
        $answers = discoverability::are_public($small);
        $smallreads = $DB->perf_get_reads() - $before;
        $this->assertSame([true, true], array_values($answers), 'Precondition: the small set really is public.');

        access::reset_caches();
        $before = $DB->perf_get_reads();
        $answers = discoverability::are_public($large);
        $largereads = $DB->perf_get_reads() - $before;
        $this->assertSame(array_fill(0, 200, true), array_values($answers));

        $this->assertSame($smallreads, $largereads, 'The predicate must cost the same for 200 courses as for 2.');
    }

    /**
     * Public, visible course rows in a category, written straight to the tables.
     *
     * The generator builds a whole course per call - context, sections, enrolment
     * instances, gradebook - which costs minutes at this size and none of which
     * this predicate reads: it reads id, category and visible, and the state row.
     *
     * @param int $categoryid The category the courses sit in.
     * @param int $count How many to create.
     * @return array The course ids.
     */
    private function seed_public_courses(int $categoryid, int $count): array {
        global $DB;

        $ids = [];
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = (int) $DB->insert_record('course', (object) [
                'category' => $categoryid,
                'fullname' => 'Budget course ' . $categoryid . '-' . $i,
                'shortname' => 'bc' . $categoryid . '-' . $i,
                'visible' => 1,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
        foreach ($ids as $courseid) {
            $rows[] = (object) [
                'courseid' => $courseid,
                'state' => discoverability::STATE_PUBLIC,
                'usermodified' => 0,
                'timemodified' => time(),
            ];
        }
        $DB->insert_records(discoverability::TABLE, $rows);
        return $ids;
    }
}
