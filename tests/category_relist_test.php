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
 * Tests for the gate on taking a category out of the unlisted state.
 *
 * A course or subcategory stored public below an unlisted category is retained: public in its
 * own row, inert while the category holds it back. Leaving the unlisted state makes it public in
 * effect, so set_state() asks the capability that publishes each such row, at that row's own
 * context. Every refusal here goes through set_state() as the acting user, asserts which
 * capability was missing, that the category's row and every descendant's public answer are
 * unchanged, and sits beside a control that the same call passes for somebody who may make it.
 * Every "asks nothing" case first shows the same fixture refused while the row is activated, so
 * the force was on.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(category_discoverability::class)]
#[CoversClass(discoverability::class)]
final class category_relist_test extends \advanced_testcase {
    /**
     * Reset the request caches between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->reset();
    }

    /**
     * Forget every request cache the public answers are composed from.
     *
     * @return void
     */
    private function reset(): void {
        category_discoverability::reset_caches();
        discoverability::reset_caches();
        access::reset_caches();
    }

    /**
     * Give a user a capability in a context, through a role of its own assigned there.
     *
     * @param \stdClass $user The user.
     * @param string $capability The capability.
     * @param \context $context The context.
     * @return void
     */
    private function grant(\stdClass $user, string $capability, \context $context): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
    }

    /**
     * A user holding only the manage capability, at a category and so at every category below it.
     *
     * @param \core_course_category $category The category.
     * @return \stdClass The user.
     */
    private function create_delegate(\core_course_category $category): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->grant($user, category_discoverability::CAPABILITY_MANAGE, \core\context\coursecat::instance($category->id));
        return $user;
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
        $this->reset();
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
        $this->reset();
    }

    /**
     * Hide a category or a course by writing its row, with the caches reset behind it.
     *
     * @param string $table Either course_categories or course.
     * @param int $id The row id.
     * @return void
     */
    private function hide(string $table, int $id): void {
        global $DB;

        $DB->set_field($table, 'visible', 0, ['id' => $id]);
        $this->reset();
    }

    /**
     * Assert that the current user's change of a category's state is refused for want of a capability.
     *
     * @param int $categoryid The category.
     * @param int $state The state asked for.
     * @param string $capability The capability the refusal must name.
     * @return void
     */
    private function assert_refused(int $categoryid, int $state, string $capability): void {
        $refusal = null;
        try {
            category_discoverability::set_state($categoryid, $state);
        } catch (\required_capability_exception $e) {
            $refusal = $e;
        }
        $this->assertNotNull($refusal, 'The change must be refused.');
        $this->assertSame(get_capability_string($capability), $refusal->a, 'The refusal names the missing capability.');
    }

    /**
     * Assert that the current user's listing of a category again is refused for want of a capability.
     *
     * @param int $categoryid The category.
     * @param string $capability The capability the refusal must name.
     * @return void
     */
    private function assert_relist_refused(int $categoryid, string $capability): void {
        $this->assert_refused($categoryid, category_discoverability::STATE_DEFAULT, $capability);
    }

    /**
     * Assert that a category is still unlisted and that none of these rows below it became public.
     *
     * @param int $categoryid The category.
     * @param array $courseids Course ids that must still not be public.
     * @param array $categoryids Category ids that must still not be public.
     * @return void
     */
    private function assert_unchanged(int $categoryid, array $courseids, array $categoryids): void {
        global $DB;

        $this->reset();
        $this->assertSame(
            category_discoverability::STATE_UNLISTED,
            (int) $DB->get_field(category_discoverability::TABLE, 'state', ['categoryid' => $categoryid]),
            'The refused change must not land.'
        );
        foreach ($courseids as $courseid) {
            $this->assertFalse(discoverability::is_public((int) $courseid), "Course {$courseid} must still not be public.");
        }
        foreach ($categoryids as $id) {
            $this->assertFalse(category_discoverability::is_public((int) $id), "Category {$id} must still not be public.");
        }
    }

    /**
     * Assert that the current user lists a category again, and that it is listed afterwards.
     *
     * @param int $categoryid The category.
     * @return void
     */
    private function assert_relists(int $categoryid): void {
        category_discoverability::set_state($categoryid, category_discoverability::STATE_DEFAULT);
        $this->reset();
        $this->assertSame(category_discoverability::STATE_DEFAULT, category_discoverability::get_state($categoryid));
    }

    /**
     * A root with one unlisted category under it holding a public course, and a delegate at the root.
     *
     * @return array The root, the unlisted category, the course and the delegate.
     */
    private function retained_course_fixture(): array {
        $generator = $this->getDataGenerator();
        $root = $generator->create_category();
        $category = $generator->create_category(['parent' => $root->id]);
        $course = $generator->create_course(['category' => $category->id]);
        $this->set_course_state((int) $course->id, discoverability::STATE_PUBLIC);
        $this->set_category_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $this->assertFalse(discoverability::is_public((int) $course->id), 'Precondition: the public course is retained.');
        return [$root, $category, $course, $this->create_delegate($root)];
    }

    /**
     * A root with one unlisted category under it holding a public subcategory, and a delegate at the root.
     *
     * @return array The root, the unlisted category, the subcategory and the delegate.
     */
    private function retained_category_fixture(): array {
        $generator = $this->getDataGenerator();
        $root = $generator->create_category();
        $category = $generator->create_category(['parent' => $root->id]);
        $sub = $generator->create_category(['parent' => $category->id]);
        $this->set_category_state((int) $sub->id, category_discoverability::STATE_PUBLIC);
        $this->set_category_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $this->assertFalse(
            category_discoverability::is_public((int) $sub->id),
            'Precondition: the public subcategory is retained.'
        );
        return [$root, $category, $sub, $this->create_delegate($root)];
    }

    /**
     * A delegate may not list again a category holding a retained public course; a publisher of that course may.
     *
     * @return void
     */
    public function test_a_delegate_may_not_relist_a_category_holding_a_retained_public_course(): void {
        $this->resetAfterTest();
        [$root, $category, $course, $delegate] = $this->retained_course_fixture();
        $empty = $this->getDataGenerator()->create_category(['parent' => $root->id]);
        $this->getDataGenerator()->create_course(['category' => $empty->id]);
        $this->set_category_state((int) $empty->id, category_discoverability::STATE_UNLISTED);
        $publisher = $this->create_delegate($root);
        $this->grant($publisher, discoverability::CAPABILITY_PUBLISH, \core\context\course::instance($course->id));

        $this->setUser($delegate);
        $this->assert_relist_refused((int) $category->id, discoverability::CAPABILITY_PUBLISH);
        $this->assert_unchanged((int) $category->id, [$course->id], []);

        // Control: the same delegate lists again an unlisted category that retains nothing public.
        $this->assert_relists((int) $empty->id);

        // Control: the same call by somebody who may also publish the course passes, and the course is public.
        $this->setUser($publisher);
        $this->assert_relists((int) $category->id);
        $this->assertTrue(discoverability::is_public((int) $course->id));
    }

    /**
     * A delegate may not list again a category holding a retained public subcategory; its publisher may.
     *
     * @return void
     */
    public function test_a_delegate_may_not_relist_a_category_holding_a_retained_public_subcategory(): void {
        $this->resetAfterTest();
        [$root, $category, $sub, $delegate] = $this->retained_category_fixture();
        $empty = $this->getDataGenerator()->create_category(['parent' => $root->id]);
        $this->getDataGenerator()->create_category(['parent' => $empty->id]);
        $this->set_category_state((int) $empty->id, category_discoverability::STATE_UNLISTED);
        $publisher = $this->create_delegate($root);
        $this->grant($publisher, category_discoverability::CAPABILITY_PUBLISH, \core\context\coursecat::instance($sub->id));

        $this->setUser($delegate);
        $this->assert_refused(
            (int) $category->id,
            category_discoverability::STATE_DEFAULT,
            category_discoverability::CAPABILITY_PUBLISH
        );
        $this->assert_unchanged((int) $category->id, [], [$sub->id]);

        // Control: the same delegate lists again an unlisted category that retains nothing public.
        $this->assert_relists((int) $empty->id);

        // Control: somebody who may also publish the subcategory lists it again, and the subcategory is public.
        $this->setUser($publisher);
        $this->assert_relists((int) $category->id);
        $this->assertTrue(category_discoverability::is_public((int) $sub->id));
    }

    /**
     * The publish capability is asked at the retained course's own context, not at a sibling's or at the category.
     *
     * @return void
     */
    public function test_the_course_capability_is_asked_at_the_course_itself(): void {
        $this->resetAfterTest();
        [$root, $category, $course] = $this->retained_course_fixture();
        $sibling = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $coursecontext = \core\context\course::instance($course->id);
        $categorycontext = \core\context\coursecat::instance($category->id);

        // Publish at a sibling course in the same category.
        $wrongrow = $this->create_delegate($root);
        $this->grant($wrongrow, discoverability::CAPABILITY_PUBLISH, \core\context\course::instance($sibling->id));

        // Publish at the category itself, prevented at the course: allowed at the category, refused at the row.
        $overridden = $this->create_delegate($root);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(discoverability::CAPABILITY_PUBLISH, CAP_ALLOW, $roleid, $categorycontext->id, true);
        assign_capability(discoverability::CAPABILITY_PUBLISH, CAP_PREVENT, $roleid, $coursecontext->id, true);
        role_assign($roleid, $overridden->id, $categorycontext->id);
        $this->assertTrue(has_capability(discoverability::CAPABILITY_PUBLISH, $categorycontext, $overridden));

        // Publish only in the course's own context.
        $rightrow = $this->create_delegate($root);
        $this->grant($rightrow, discoverability::CAPABILITY_PUBLISH, $coursecontext);

        foreach ([$wrongrow, $overridden] as $user) {
            $this->setUser($user);
            $this->assert_relist_refused((int) $category->id, discoverability::CAPABILITY_PUBLISH);
            $this->assert_unchanged((int) $category->id, [$course->id], []);
        }

        // Control: publish in the course's own context and nowhere else is enough.
        $this->setUser($rightrow);
        $this->assert_relists((int) $category->id);
        $this->assertTrue(discoverability::is_public((int) $course->id));
    }

    /**
     * The category capability is asked at the retained subcategory's own context, not at a sibling's or at the category.
     *
     * @return void
     */
    public function test_the_category_capability_is_asked_at_the_subcategory_itself(): void {
        $this->resetAfterTest();
        [$root, $category, $sub] = $this->retained_category_fixture();
        $sibling = $this->getDataGenerator()->create_category(['parent' => $category->id]);
        $subcontext = \core\context\coursecat::instance($sub->id);
        $categorycontext = \core\context\coursecat::instance($category->id);

        $wrongrow = $this->create_delegate($root);
        $this->grant($wrongrow, category_discoverability::CAPABILITY_PUBLISH, \core\context\coursecat::instance($sibling->id));

        $overridden = $this->create_delegate($root);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(category_discoverability::CAPABILITY_PUBLISH, CAP_ALLOW, $roleid, $categorycontext->id, true);
        assign_capability(category_discoverability::CAPABILITY_PUBLISH, CAP_PREVENT, $roleid, $subcontext->id, true);
        role_assign($roleid, $overridden->id, $categorycontext->id);
        $this->assertTrue(has_capability(category_discoverability::CAPABILITY_PUBLISH, $categorycontext, $overridden));

        $rightrow = $this->create_delegate($root);
        $this->grant($rightrow, category_discoverability::CAPABILITY_PUBLISH, $subcontext);

        foreach ([$wrongrow, $overridden] as $user) {
            $this->setUser($user);
            $this->assert_refused(
                (int) $category->id,
                category_discoverability::STATE_DEFAULT,
                category_discoverability::CAPABILITY_PUBLISH
            );
            $this->assert_unchanged((int) $category->id, [], [$sub->id]);
        }

        // Control: publishcategory in the subcategory's own context and nowhere else is enough.
        $this->setUser($rightrow);
        $this->assert_relists((int) $category->id);
        $this->assertTrue(category_discoverability::is_public((int) $sub->id));
    }

    /**
     * A public course that is hidden is not activated: the delegate lists the category again and it stays not public.
     *
     * @return void
     */
    public function test_a_hidden_public_course_asks_nothing(): void {
        $this->resetAfterTest();
        [, $category, $course, $delegate] = $this->retained_course_fixture();

        $this->setUser($delegate);
        $this->assert_relist_refused((int) $category->id, discoverability::CAPABILITY_PUBLISH);

        $this->hide('course', (int) $course->id);
        $this->assert_relists((int) $category->id);
        $this->assertFalse(discoverability::is_public((int) $course->id));
    }

    /**
     * A public course under a hidden category below the unlisted one is not activated.
     *
     * @return void
     */
    public function test_a_public_course_under_a_hidden_category_asks_nothing(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $root = $generator->create_category();
        $category = $generator->create_category(['parent' => $root->id]);
        $inner = $generator->create_category(['parent' => $category->id]);
        $course = $generator->create_course(['category' => $inner->id]);
        $this->set_course_state((int) $course->id, discoverability::STATE_PUBLIC);
        $this->set_category_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $delegate = $this->create_delegate($root);

        $this->setUser($delegate);
        $this->assert_relist_refused((int) $category->id, discoverability::CAPABILITY_PUBLISH);

        $this->hide('course_categories', (int) $inner->id);
        $this->assert_relists((int) $category->id);
        $this->assertFalse(discoverability::is_public((int) $course->id));
    }

    /**
     * A public course under a second unlisted category, below or above the one listed again, is not activated.
     *
     * @return void
     */
    public function test_a_public_course_under_a_second_unlisted_category_asks_nothing(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $root = $generator->create_category();
        $category = $generator->create_category(['parent' => $root->id]);
        $inner = $generator->create_category(['parent' => $category->id]);
        $course = $generator->create_course(['category' => $inner->id]);
        $this->set_course_state((int) $course->id, discoverability::STATE_PUBLIC);
        $this->set_category_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $delegate = $this->create_delegate($root);

        $this->setUser($delegate);
        $this->assert_relist_refused((int) $category->id, discoverability::CAPABILITY_PUBLISH);

        // Below: a second unlisted category between the one listed again and the course.
        $this->set_category_state((int) $inner->id, category_discoverability::STATE_UNLISTED);
        $this->assert_relists((int) $category->id);
        $this->assertFalse(discoverability::is_public((int) $course->id));

        // Above: the root unlisted holds the course back whatever happens below it.
        $this->set_category_state((int) $inner->id, category_discoverability::STATE_DEFAULT);
        $this->set_category_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $this->assert_relist_refused((int) $category->id, discoverability::CAPABILITY_PUBLISH);
        $this->set_category_state((int) $root->id, category_discoverability::STATE_UNLISTED);
        $this->assert_relists((int) $category->id);
        $this->assertFalse(discoverability::is_public((int) $course->id));
    }

    /**
     * An unlisted course below the category is not activated.
     *
     * @return void
     */
    public function test_an_unlisted_course_asks_nothing(): void {
        $this->resetAfterTest();
        [, $category, $course, $delegate] = $this->retained_course_fixture();

        $this->setUser($delegate);
        $this->assert_relist_refused((int) $category->id, discoverability::CAPABILITY_PUBLISH);

        $this->set_course_state((int) $course->id, discoverability::STATE_UNLISTED);
        $this->assert_relists((int) $category->id);
        $this->assertFalse(discoverability::is_public((int) $course->id));
    }

    /**
     * A public subcategory that is hidden, or sits under a hidden category, is not activated.
     *
     * @return void
     */
    public function test_a_hidden_public_subcategory_or_one_under_a_hidden_category_asks_nothing(): void {
        $this->resetAfterTest();
        [$root, $category, $sub, $delegate] = $this->retained_category_fixture();

        $this->setUser($delegate);
        $this->assert_relist_refused((int) $category->id, category_discoverability::CAPABILITY_PUBLISH);

        // Hidden itself.
        $this->hide('course_categories', (int) $sub->id);
        $this->assert_relists((int) $category->id);
        $this->assertFalse(category_discoverability::is_public((int) $sub->id));

        // Under a hidden category between it and the one listed again.
        $generator = $this->getDataGenerator();
        $other = $generator->create_category(['parent' => $root->id]);
        $inner = $generator->create_category(['parent' => $other->id]);
        $deep = $generator->create_category(['parent' => $inner->id]);
        $this->set_category_state((int) $deep->id, category_discoverability::STATE_PUBLIC);
        $this->set_category_state((int) $other->id, category_discoverability::STATE_UNLISTED);
        $this->assert_relist_refused((int) $other->id, category_discoverability::CAPABILITY_PUBLISH);
        $this->hide('course_categories', (int) $inner->id);
        $this->assert_relists((int) $other->id);
        $this->assertFalse(category_discoverability::is_public((int) $deep->id));
    }

    /**
     * A public subcategory under a second unlisted category, and an unlisted subcategory, are not activated.
     *
     * @return void
     */
    public function test_a_subcategory_under_a_second_unlisted_category_or_unlisted_itself_asks_nothing(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $root = $generator->create_category();
        $category = $generator->create_category(['parent' => $root->id]);
        $inner = $generator->create_category(['parent' => $category->id]);
        $deep = $generator->create_category(['parent' => $inner->id]);
        $this->set_category_state((int) $deep->id, category_discoverability::STATE_PUBLIC);
        $this->set_category_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $delegate = $this->create_delegate($root);

        $this->setUser($delegate);
        $this->assert_relist_refused((int) $category->id, category_discoverability::CAPABILITY_PUBLISH);

        // A second unlisted category between them.
        $this->set_category_state((int) $inner->id, category_discoverability::STATE_UNLISTED);
        $this->assert_relists((int) $category->id);
        $this->assertFalse(category_discoverability::is_public((int) $deep->id));

        // The subcategory unlisted itself, nothing else in the way.
        $this->set_category_state((int) $inner->id, category_discoverability::STATE_DEFAULT);
        $this->set_category_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $this->assert_relist_refused((int) $category->id, category_discoverability::CAPABILITY_PUBLISH);
        $this->set_category_state((int) $deep->id, category_discoverability::STATE_UNLISTED);
        $this->assert_relists((int) $category->id);
        $this->assertFalse(category_discoverability::is_public((int) $deep->id));
    }

    /**
     * Unlisted to public is gated the same way: publishing the category does not publish what it holds back.
     *
     * @return void
     */
    public function test_unlisted_to_public_needs_publish_on_every_retained_course(): void {
        $this->resetAfterTest();
        [$root, $category, $course] = $this->retained_course_fixture();
        $categorycontext = \core\context\coursecat::instance($category->id);

        $catpublisher = $this->create_delegate($root);
        $this->grant($catpublisher, category_discoverability::CAPABILITY_PUBLISH, $categorycontext);
        $both = $this->create_delegate($root);
        $this->grant($both, category_discoverability::CAPABILITY_PUBLISH, $categorycontext);
        $this->grant($both, discoverability::CAPABILITY_PUBLISH, \core\context\course::instance($course->id));

        $this->setUser($catpublisher);
        $this->assert_refused((int) $category->id, category_discoverability::STATE_PUBLIC, discoverability::CAPABILITY_PUBLISH);
        $this->assert_unchanged((int) $category->id, [$course->id], [$category->id]);

        // Control: holding both capabilities, the same call passes and both are public.
        $this->setUser($both);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_PUBLIC);
        $this->reset();
        $this->assertSame(category_discoverability::STATE_PUBLIC, category_discoverability::get_state((int) $category->id));
        $this->assertTrue(category_discoverability::is_public((int) $category->id));
        $this->assertTrue(discoverability::is_public((int) $course->id));
    }

    /**
     * The prediction is read again under the lock, not taken from a memo of the same request.
     *
     * Two rows change behind the request's back, as another process would change them: a course
     * made public after this request memoised its state, and an unlisted category between the one
     * listed again and a public course that stopped being unlisted after this request memoised
     * the unlisted ids. Both are activated, and both refusals depend on reading them again.
     *
     * @return void
     */
    public function test_the_prediction_reads_the_rows_again_rather_than_the_request_memos(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $root = $generator->create_category();
        $delegate = $this->create_delegate($root);

        $first = $generator->create_category(['parent' => $root->id]);
        $course = $generator->create_course(['category' => $first->id]);
        $this->set_category_state((int) $first->id, category_discoverability::STATE_UNLISTED);

        $second = $generator->create_category(['parent' => $root->id]);
        $inner = $generator->create_category(['parent' => $second->id]);
        $innercourse = $generator->create_course(['category' => $inner->id]);
        $this->set_course_state((int) $innercourse->id, discoverability::STATE_PUBLIC);
        $this->set_category_state((int) $inner->id, category_discoverability::STATE_UNLISTED);
        $this->set_category_state((int) $second->id, category_discoverability::STATE_UNLISTED);

        /* The memos are warmed after every fixture write, which resets them, and the rows change
           behind them afterwards. The first refusal below resets nothing the second one reads
           unless the code resets it, because a refused change writes nothing. */
        $this->assertSame(
            [(int) $course->id => discoverability::STATE_DEFAULT],
            discoverability::get_states([(int) $course->id])
        );
        $this->assertContains((int) $inner->id, category_discoverability::unlisted_ids());
        $DB->insert_record(discoverability::TABLE, (object) [
            'courseid' => $course->id,
            'state' => discoverability::STATE_PUBLIC,
            'usermodified' => 0,
            'timemodified' => time(),
        ]);
        $DB->delete_records(category_discoverability::TABLE, ['categoryid' => $inner->id]);

        $this->setUser($delegate);
        $this->assert_relist_refused((int) $first->id, discoverability::CAPABILITY_PUBLISH);
        $this->assert_relist_refused((int) $second->id, discoverability::CAPABILITY_PUBLISH);
        $this->assert_unchanged((int) $first->id, [$course->id], []);
        $this->assert_unchanged((int) $second->id, [$innercourse->id], []);
    }

    /**
     * The predicted set is exactly what the transition makes public, over a tree carrying every shape.
     *
     * @return void
     */
    public function test_the_prediction_is_what_the_transition_makes_public(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $cat = fn(int $parent): int => (int) $generator->create_category(['parent' => $parent])->id;
        $course = fn(int $category): int => (int) $generator->create_course(['category' => $category])->id;

        $root = $cat(0);
        $category = $cat($root);
        $c1 = $course($category);
        $c2 = $course($category);
        $c3 = $course($category);
        $c4 = $course($category);
        $hidden = $cat($category);
        $c5 = $course($hidden);
        $s3 = $cat($hidden);
        $unlisted = $cat($category);
        $c6 = $course($unlisted);
        $s4 = $cat($unlisted);
        $s = $cat($category);
        $c7 = $course($s);
        $s2 = $cat($category);
        $listed = $cat($category);
        $s5 = $cat($listed);
        $c8 = $course($listed);
        $other = $cat($root);
        $c9 = $course($other);
        $above = $cat($root);
        $second = $cat($above);
        $c10 = $course($second);
        $s6 = $cat($second);

        foreach ([$c1, $c2, $c5, $c6, $c7, $c8, $c9, $c10] as $id) {
            discoverability::set_state($id, discoverability::STATE_PUBLIC);
        }
        discoverability::set_state($c3, discoverability::STATE_UNLISTED);
        foreach ([$s, $s2, $s3, $s4, $s5, $s6, $other] as $id) {
            category_discoverability::set_state($id, category_discoverability::STATE_PUBLIC);
        }
        foreach ([$category, $unlisted, $above, $second] as $id) {
            category_discoverability::set_state($id, category_discoverability::STATE_UNLISTED);
        }
        $DB->set_field('course', 'visible', 0, ['id' => $c2]);
        $DB->set_field('course_categories', 'visible', 0, ['id' => $hidden]);
        $DB->set_field('course_categories', 'visible', 0, ['id' => $s2]);
        $courses = [$c1, $c2, $c3, $c4, $c5, $c6, $c7, $c8, $c9, $c10];
        $categories = $DB->get_fieldset_select('course_categories', 'id', 'id > 0');

        $predictions = [];
        foreach ([$category, $second] as $relisted) {
            $this->reset();
            $predicted = category_discoverability::relist_activations($relisted);
            $predictions[$relisted] = $predicted;
            $before = [discoverability::are_public($courses), category_discoverability::are_public($categories)];
            category_discoverability::set_state($relisted, category_discoverability::STATE_DEFAULT);
            $this->reset();
            $after = [discoverability::are_public($courses), category_discoverability::are_public($categories)];

            $flipped = [];
            foreach ([0 => 'courses', 1 => 'categories'] as $index => $kind) {
                $ids = array_keys(array_filter(
                    $after[$index],
                    fn(bool $public, int $id): bool => $public && !$before[$index][$id],
                    ARRAY_FILTER_USE_BOTH
                ));
                sort($ids);
                $flipped[$kind] = $ids;
            }
            $this->assertSame($flipped, $predicted, "The prediction for category {$relisted} is what flipped.");
        }

        // Not vacuous: the first tree activates exactly these, and the one under an unlisted ancestor nothing.
        $expectedcourses = [$c1, $c7, $c8];
        $expectedcategories = [$s, $s5];
        sort($expectedcourses);
        sort($expectedcategories);
        $this->assertSame(['courses' => $expectedcourses, 'categories' => $expectedcategories], $predictions[$category]);
        $this->assertSame(['courses' => [], 'categories' => []], $predictions[$second]);
    }

    /**
     * Asked of a category that is not unlisted, the prediction is empty.
     *
     * @return void
     */
    public function test_a_category_that_is_not_unlisted_activates_nothing(): void {
        $this->resetAfterTest();
        [, $category, $course] = $this->retained_course_fixture();

        $this->assertSame(
            ['courses' => [(int) $course->id], 'categories' => []],
            category_discoverability::relist_activations((int) $category->id),
            'Control: while the category is unlisted, its public course is activated.'
        );

        $this->set_category_state((int) $category->id, category_discoverability::STATE_DEFAULT);
        $this->assertSame(
            ['courses' => [], 'categories' => []],
            category_discoverability::relist_activations((int) $category->id)
        );
    }
}
