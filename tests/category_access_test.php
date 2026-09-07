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
 * Unlisted courses - Tests for the category discoverability predicate
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the category discoverability predicate.
 *
 * Every test that asserts a category is HIDDEN also asserts that some control
 * category, or some control user, is visible in the same run - without the
 * control a hidden-category assertion would pass just as happily against a
 * predicate that never ran at all.
 *
 * D1 (cohorts only at the unlisted category's own context, never an ancestor
 * or system), D2 (role assignments at the unlisted category or an ancestor
 * CATEGORY context, plus the moodle/category:viewhiddencategories escape) and
 * the AND rule over every unlisted category on a path are exercised here; the
 * course-level D12 split lives in {@see access_test} and the anonymous clamp
 * in {@see discoverability_test}.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(category_access::class)]
final class category_access_test extends \advanced_testcase {
    /**
     * Reset the plugin's request caches between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        access::reset_caches();
        category_access::reset_caches();
    }

    /**
     * Put a category in the unlisted state, or back in the default.
     *
     * Done as admin so that the test's own viewer is never the actor, and the
     * caches are reset so the new state is what the next assertion reads.
     *
     * @param int $categoryid The category id.
     * @param bool $unlisted Whether the category should be unlisted.
     * @return void
     */
    private function set_category_unlisted(int $categoryid, bool $unlisted): void {
        $current = $GLOBALS['USER'];
        $this->setAdminUser();
        category_discoverability::set_state(
            $categoryid,
            $unlisted ? category_discoverability::STATE_UNLISTED : category_discoverability::STATE_DEFAULT
        );
        $this->setUser($current);
        category_access::reset_caches();
    }

    /**
     * Assign a role, by shortname, to a user in a context.
     *
     * @param string $shortname The role's shortname.
     * @param int $userid The user id.
     * @param \context $context The context to assign the role in.
     * @return void
     */
    private function assign_role(string $shortname, int $userid, \context $context): void {
        global $DB;

        $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
        role_assign($roleid, $userid, $context->id);
    }

    /**
     * Create a cohort in the given context.
     *
     * @param \context $context The cohort's context.
     * @param bool $visible Whether the cohort itself is visible (D10: must not matter to the predicate).
     * @return \stdClass The cohort record.
     */
    private function create_cohort_at(\context $context, bool $visible = true): \stdClass {
        return $this->getDataGenerator()->create_cohort([
            'contextid' => $context->id,
            'visible' => $visible ? 1 : 0,
        ]);
    }

    /**
     * Add a user to a cohort.
     *
     * @param int $cohortid The cohort id.
     * @param int $userid The user id.
     * @return void
     */
    private function add_to_cohort(int $cohortid, int $userid): void {
        global $CFG;

        require_once($CFG->dirroot . '/cohort/lib.php');
        cohort_add_member($cohortid, $userid);
    }

    /**
     * A site admin discovers every category, marked or not.
     *
     * @return void
     */
    public function test_a_site_admin_sees_every_category(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $this->set_category_unlisted((int) $category->id, true);

        $this->setAdminUser();
        category_access::reset_caches();
        $this->assertTrue(category_access::is_category_discoverable((int) $category->id));

        /* The short circuit is what answers, and this is the only assertion that can
           tell it apart from the staff escape: an admin passes
           moodle/category:viewhiddencategories at every context, so the escape would
           reach the same "true" for a category that exists. An id with no category
           behind it has no context to hold a capability at, and is refused by every
           term - so an admin reading it as discoverable proves they were answered
           before any path was resolved. */
        $this->assertTrue(
            category_access::is_category_discoverable(999999),
            'A site admin is answered before any path is read, so even an unknown id reads as discoverable.'
        );

        // Control: an outsider in the same run must not discover it.
        $this->setUser($generator->create_user());
        category_access::reset_caches();
        $this->assertFalse(
            category_access::is_category_discoverable((int) $category->id),
            'Control: the gate must still be refusing an ordinary user.'
        );
    }

    /**
     * A visitor and a guest never discover an unlisted category, but keep seeing listed ones.
     *
     * The guest is a member of the gating cohort here, so the refusal is the
     * fail-closed guard's and not an absence of membership.
     *
     * @return void
     */
    public function test_a_visitor_and_a_guest_see_no_unlisted_category_but_see_listed_ones(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $unlisted = $generator->create_category();
        $listed = $generator->create_category();
        $this->set_category_unlisted((int) $unlisted->id, true);

        $cohort = $this->create_cohort_at(\core\context\coursecat::instance($unlisted->id));
        $member = $generator->create_user();
        $this->add_to_cohort((int) $cohort->id, (int) $member->id);

        /* The guest is put in the gating cohort on purpose. Without it the refusal
           below would be reached by the cohort term finding nothing, which is the
           same answer the fail-closed guard gives and proves neither - the guard is
           only observable when a guest DOES hold a membership and is refused anyway. */
        $guest = guest_user();
        $this->add_to_cohort((int) $cohort->id, (int) $guest->id);

        $this->setUser(0);
        category_access::reset_caches();
        $this->assertFalse(category_access::is_category_discoverable((int) $unlisted->id));
        $this->assertTrue(
            category_access::is_category_discoverable((int) $listed->id),
            'A visitor still discovers a category that is not unlisted.'
        );

        $this->setGuestUser();
        category_access::reset_caches();
        $this->assertFalse(category_access::is_category_discoverable((int) $unlisted->id));
        $this->assertTrue(
            category_access::is_category_discoverable((int) $listed->id),
            'A guest still discovers a category that is not unlisted.'
        );

        // Control: a logged-in member of the gating cohort sees it.
        $this->setUser($member);
        category_access::reset_caches();
        $this->assertTrue(category_access::is_category_discoverable((int) $unlisted->id));
    }

    /**
     * A cohort at the unlisted category's own context grants access.
     *
     * @return void
     */
    public function test_a_cohort_at_the_category_grants_access(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $this->set_category_unlisted((int) $category->id, true);

        $cohort = $this->create_cohort_at(\core\context\coursecat::instance($category->id));
        $member = $generator->create_user();
        $outsider = $generator->create_user();
        $this->add_to_cohort((int) $cohort->id, (int) $member->id);

        $this->setUser($outsider);
        category_access::reset_caches();
        $this->assertFalse(
            category_access::is_category_discoverable((int) $category->id),
            'Control: a non-member of the gating cohort must not discover the category.'
        );

        $this->setUser($member);
        category_access::reset_caches();
        $this->assertTrue(category_access::is_category_discoverable((int) $category->id));
    }

    /**
     * A cohort at the system context grants nothing (D1).
     *
     * @return void
     */
    public function test_a_cohort_at_the_system_context_grants_nothing(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $this->set_category_unlisted((int) $category->id, true);

        // The generator's default contextid for a cohort is the system context.
        $systemcohort = $this->create_cohort_at(\core\context\system::instance());
        $user = $generator->create_user();
        $this->add_to_cohort((int) $systemcohort->id, (int) $user->id);

        $this->setUser($user);
        category_access::reset_caches();
        $this->assertFalse(
            category_access::is_category_discoverable((int) $category->id),
            'D1: a cohort at the system context must not count.'
        );

        // Control: the same user, added to a cohort at the category's own context, is eligible.
        $categorycohort = $this->create_cohort_at(\core\context\coursecat::instance($category->id));
        $this->add_to_cohort((int) $categorycohort->id, (int) $user->id);
        category_access::reset_caches();
        $this->assertTrue(category_access::is_category_discoverable((int) $category->id));
    }

    /**
     * A cohort at a listed ancestor or a sibling grants nothing; one at the unlisted category unlocks its children.
     *
     * @return void
     */
    public function test_cohort_at_listed_parent_or_sibling_grants_nothing_but_unlisted_parent_unlocks_children(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $grandparent = $generator->create_category();
        $parent = $generator->create_category(['parent' => $grandparent->id]);
        $child = $generator->create_category(['parent' => $parent->id]);
        $sibling = $generator->create_category();
        $this->set_category_unlisted((int) $parent->id, true);

        $grandparentcohort = $this->create_cohort_at(\core\context\coursecat::instance($grandparent->id));
        $atgrandparent = $generator->create_user();
        $this->add_to_cohort((int) $grandparentcohort->id, (int) $atgrandparent->id);

        $siblingcohort = $this->create_cohort_at(\core\context\coursecat::instance($sibling->id));
        $atsibling = $generator->create_user();
        $this->add_to_cohort((int) $siblingcohort->id, (int) $atsibling->id);

        $parentcohort = $this->create_cohort_at(\core\context\coursecat::instance($parent->id));
        $atparent = $generator->create_user();
        $this->add_to_cohort((int) $parentcohort->id, (int) $atparent->id);

        $this->setUser($atgrandparent);
        category_access::reset_caches();
        $this->assertFalse(
            category_access::is_category_discoverable((int) $child->id),
            'A cohort at a listed ancestor above the unlisted category must not count (D1).'
        );

        $this->setUser($atsibling);
        category_access::reset_caches();
        $this->assertFalse(
            category_access::is_category_discoverable((int) $child->id),
            'A cohort at an unrelated sibling category must not count.'
        );

        // Control: a cohort at the unlisted category's OWN context unlocks its listed child too.
        $this->setUser($atparent);
        category_access::reset_caches();
        $this->assertTrue(category_access::is_category_discoverable((int) $child->id));
    }

    /**
     * An invisible cohort still grants access (D10).
     *
     * @return void
     */
    public function test_an_invisible_cohort_still_grants(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $this->set_category_unlisted((int) $category->id, true);

        $cohort = $this->create_cohort_at(\core\context\coursecat::instance($category->id), false);
        $this->assertSame(0, (int) $cohort->visible, 'Precondition: the cohort itself must be invisible.');

        $member = $generator->create_user();
        $this->add_to_cohort((int) $cohort->id, (int) $member->id);

        $this->setUser($member);
        category_access::reset_caches();
        $this->assertTrue(
            category_access::is_category_discoverable((int) $category->id),
            'D10: an invisible cohort must still grant access.'
        );
    }

    /**
     * A role at the unlisted category, or at an ancestor category, grants access.
     *
     * @return void
     */
    public function test_a_role_at_the_category_or_an_ancestor_category_grants_access(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $root = $generator->create_category();
        $unlisted = $generator->create_category(['parent' => $root->id]);
        $child = $generator->create_category(['parent' => $unlisted->id]);
        $sibling = $generator->create_category();
        $course = $generator->create_course(['category' => $unlisted->id]);
        $this->set_category_unlisted((int) $unlisted->id, true);

        $atcategory = $generator->create_user();
        $this->assign_role('teacher', (int) $atcategory->id, \core\context\coursecat::instance($unlisted->id));

        $atancestor = $generator->create_user();
        $this->assign_role('teacher', (int) $atancestor->id, \core\context\coursecat::instance($root->id));

        $atcourse = $generator->create_user();
        $this->assign_role('teacher', (int) $atcourse->id, \core\context\course::instance($course->id));

        $atsibling = $generator->create_user();
        $this->assign_role('teacher', (int) $atsibling->id, \core\context\coursecat::instance($sibling->id));

        $atchild = $generator->create_user();
        $this->assign_role('teacher', (int) $atchild->id, \core\context\coursecat::instance($child->id));

        $this->setUser($atcategory);
        category_access::reset_caches();
        $this->assertTrue(
            category_access::is_category_discoverable((int) $unlisted->id),
            'A role at the unlisted category itself must grant access.'
        );
        $this->assertTrue(
            category_access::is_category_discoverable((int) $child->id),
            'And its listed child with it: on that path the role sits at the unlisted category.'
        );

        $this->setUser($atancestor);
        category_access::reset_caches();
        $this->assertTrue(
            category_access::is_category_discoverable((int) $unlisted->id),
            'A role at an ancestor category must grant access.'
        );

        $this->setUser($atcourse);
        category_access::reset_caches();
        $this->assertFalse(
            category_access::is_category_discoverable((int) $unlisted->id),
            'Control: a role at a course inside the category must grant nothing.'
        );

        $this->setUser($atsibling);
        category_access::reset_caches();
        $this->assertFalse(
            category_access::is_category_discoverable((int) $unlisted->id),
            'Control: a role at a sibling category must grant nothing.'
        );

        $this->setUser($atchild);
        category_access::reset_caches();
        $this->assertFalse(
            category_access::is_category_discoverable((int) $unlisted->id),
            'Control: a role at a child category of the unlisted one must not unlock the parent.'
        );

        /* The same viewer must not reach the CHILD either, and this is the assertion
           that pins the rule rather than restating it. The role has to sit at the
           unlisted category or above IT - never merely somewhere on the path being
           answered - so a role below it satisfies nothing. The pairing is the proof:
           the role-at-the-category viewer above reads this very id as discoverable,
           and this one must not. */
        $this->assertFalse(
            category_access::is_category_discoverable((int) $child->id),
            'A role below the unlisted category satisfies it for nothing, its own category included.'
        );
    }

    /**
     * A bespoke system role with no category capability grants nothing (D2).
     *
     * @return void
     */
    public function test_a_bespoke_system_role_with_no_category_capability_grants_nothing(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $this->set_category_unlisted((int) $category->id, true);

        // A role with no archetype starts with no capabilities at all.
        $bespoke = $generator->create_role();
        $outsider = $generator->create_user();
        role_assign($bespoke, $outsider->id, \core\context\system::instance()->id);

        $this->setUser($outsider);
        category_access::reset_caches();
        $this->assertFalse(
            category_access::is_category_discoverable((int) $category->id),
            'A bespoke role with no category capability must grant nothing, even assigned at system level.'
        );

        // Control: a manager at system level sees it through the viewhiddencategories escape.
        $manager = $generator->create_user();
        $this->assign_role('manager', (int) $manager->id, \core\context\system::instance());
        $this->setUser($manager);
        category_access::reset_caches();
        $this->assertTrue(category_access::is_category_discoverable((int) $category->id));
    }

    /**
     * A manager and a course creator see every unlisted category, through the staff escape.
     *
     * @return void
     */
    public function test_a_manager_and_a_course_creator_see_every_unlisted_category(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $first = $generator->create_category();
        $second = $generator->create_category();
        $course = $generator->create_course(['category' => $first->id]);
        $this->set_category_unlisted((int) $first->id, true);
        $this->set_category_unlisted((int) $second->id, true);

        $manager = $generator->create_user();
        $this->assign_role('manager', (int) $manager->id, \core\context\system::instance());

        $creator = $generator->create_user();
        $this->assign_role('coursecreator', (int) $creator->id, \core\context\system::instance());

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        foreach ([$manager, $creator] as $staff) {
            $this->setUser($staff);
            category_access::reset_caches();
            $this->assertTrue(category_access::is_category_discoverable((int) $first->id));
            $this->assertTrue(category_access::is_category_discoverable((int) $second->id));
        }

        // Control: an editing teacher of a course inside, with no category role, does not.
        $this->setUser($teacher);
        category_access::reset_caches();
        $this->assertFalse(category_access::is_category_discoverable((int) $first->id));
    }

    /**
     * Nested unlisted categories require eligibility for every one of them (the AND rule).
     *
     * @return void
     */
    public function test_nested_unlisted_categories_require_eligibility_for_both(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $grandparent = $generator->create_category();
        $parent = $generator->create_category(['parent' => $grandparent->id]);
        $this->set_category_unlisted((int) $grandparent->id, true);
        $this->set_category_unlisted((int) $parent->id, true);

        $gpcohort = $this->create_cohort_at(\core\context\coursecat::instance($grandparent->id));
        $pcohort = $this->create_cohort_at(\core\context\coursecat::instance($parent->id));

        $eligibleforboth = $generator->create_user();
        $this->add_to_cohort((int) $gpcohort->id, (int) $eligibleforboth->id);
        $this->add_to_cohort((int) $pcohort->id, (int) $eligibleforboth->id);

        $eligibleforonlyparent = $generator->create_user();
        $this->add_to_cohort((int) $pcohort->id, (int) $eligibleforonlyparent->id);

        $this->setUser($eligibleforboth);
        category_access::reset_caches();
        $this->assertTrue(
            category_access::is_category_discoverable((int) $parent->id),
            'Eligible for every unlisted ancestor: the AND rule is satisfied.'
        );

        $this->setUser($eligibleforonlyparent);
        category_access::reset_caches();
        $this->assertFalse(
            category_access::is_category_discoverable((int) $parent->id),
            'Eligible for only one of two unlisted ancestors must not be enough.'
        );

        // Control: once only one level is unlisted, eligibility for it alone suffices.
        $this->set_category_unlisted((int) $grandparent->id, false);
        $this->setUser($eligibleforonlyparent);
        category_access::reset_caches();
        $this->assertTrue(category_access::is_category_discoverable((int) $parent->id));
    }

    /**
     * An unlisted ancestor hides the whole subtree, but an unrelated sibling subtree stays visible.
     *
     * @return void
     */
    public function test_an_unlisted_ancestor_hides_the_whole_subtree(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $parent = $generator->create_category();
        $child = $generator->create_category(['parent' => $parent->id]);
        $siblingparent = $generator->create_category();
        $siblingchild = $generator->create_category(['parent' => $siblingparent->id]);
        $this->set_category_unlisted((int) $parent->id, true);

        $outsider = $generator->create_user();
        $this->setUser($outsider);
        category_access::reset_caches();

        $this->assertFalse(
            category_access::is_category_discoverable((int) $child->id),
            'A listed child of an unlisted parent must be withheld too.'
        );
        $this->assertTrue(
            category_access::is_category_discoverable((int) $siblingchild->id),
            'Control: an unrelated sibling subtree must stay visible.'
        );
    }

    /**
     * filter_categories() keeps keys and order, and accepts objects, stdClass records and bare ids.
     *
     * @return void
     */
    public function test_filter_categories_preserves_keys_and_order_and_accepts_objects_and_ids(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $asobject = $generator->create_category();
        $hidden = $generator->create_category();
        $this->set_category_unlisted((int) $hidden->id, true);
        $asidonly = $generator->create_category();
        $aswithpath = $generator->create_category();
        $asint = $generator->create_category();

        $outsider = $generator->create_user();
        $this->setUser($outsider);
        category_access::reset_caches();

        $categories = [
            'k1' => $asobject,
            'k2' => (object) ['id' => (int) $hidden->id],
            'k3' => (object) ['id' => (int) $asidonly->id],
            'k4' => (object) ['id' => (int) $aswithpath->id, 'path' => $aswithpath->path],
            'k5' => (int) $asint->id,
        ];

        $kept = category_access::filter_categories($categories);

        $this->assertSame(['k1', 'k3', 'k4', 'k5'], array_keys($kept), 'Keys and order must survive the filter.');
        $this->assertSame($asobject, $kept['k1']);
        $this->assertSame((int) $asidonly->id, $kept['k3']->id);
        $this->assertSame((int) $aswithpath->id, $kept['k4']->id);
        $this->assertSame((int) $asint->id, $kept['k5']);
    }

    /**
     * An unknown category id is not discoverable, while a real listed category resolves normally.
     *
     * @return void
     */
    public function test_an_unknown_category_is_not_discoverable(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        /* Something must be unlisted somewhere, or the empty-set fast path would answer
           "discoverable" for every id without ever resolving a path at all. */
        $elsewhere = $generator->create_category();
        $this->set_category_unlisted((int) $elsewhere->id, true);
        $real = $generator->create_category();

        $this->setUser($generator->create_user());
        category_access::reset_caches();

        $this->assertFalse(category_access::is_category_discoverable(999999));
        $this->assertTrue(
            category_access::is_category_discoverable((int) $real->id),
            'Control: a real, listed category must still resolve normally.'
        );
    }

    /**
     * The answer follows a bulk write to {cohort_members} that fires no event, once caches are reset.
     *
     * tool_dynamic_cohorts writes cohort_members in bulk without firing
     * cohort_member_added or cohort_member_removed, so the predicate must
     * never rely on those events - only on reading the table fresh.
     *
     * @return void
     */
    public function test_the_answer_follows_a_bulk_cohort_write_with_no_events(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $this->set_category_unlisted((int) $category->id, true);
        $cohort = $this->create_cohort_at(\core\context\coursecat::instance($category->id));
        $user = $generator->create_user();

        $this->setUser($user);
        category_access::reset_caches();
        $this->assertFalse(category_access::is_category_discoverable((int) $category->id));

        $sink = $this->redirectEvents();
        $DB->insert_record('cohort_members', (object) [
            'cohortid' => $cohort->id,
            'userid' => $user->id,
            'timeadded' => time(),
        ]);
        $this->assertCount(0, $sink->get_events(), 'Precondition: the bulk write must fire no event at all.');
        $sink->close();

        category_access::reset_caches();
        $this->assertTrue(
            category_access::is_category_discoverable((int) $category->id),
            'The next request must see the membership even though no event fired.'
        );

        $DB->delete_records('cohort_members', ['cohortid' => $cohort->id, 'userid' => $user->id]);
        category_access::reset_caches();
        $this->assertFalse(category_access::is_category_discoverable((int) $category->id));
    }

    /**
     * The memoised answer is keyed by the viewer, not held globally.
     *
     * @return void
     */
    public function test_the_memo_is_keyed_by_the_viewer(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $this->set_category_unlisted((int) $category->id, true);
        $cohort = $this->create_cohort_at(\core\context\coursecat::instance($category->id));

        $member = $generator->create_user();
        $this->add_to_cohort((int) $cohort->id, (int) $member->id);
        $outsider = $generator->create_user();

        $this->setUser($member);
        category_access::reset_caches();
        $this->assertTrue(category_access::is_category_discoverable((int) $category->id));

        // Deliberately no reset_caches() here: the memo must be keyed by the viewer, not global.
        $this->setUser($outsider);
        $this->assertFalse(
            category_access::is_category_discoverable((int) $category->id),
            'A different viewer, with no reset in between, must get their own answer.'
        );
    }

    /**
     * When nothing is unlisted, the predicate issues at most one statement and answers true for all.
     *
     * @return void
     */
    public function test_the_predicate_issues_at_most_one_statement_when_no_category_is_unlisted(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $this->setUser($generator->create_user());
        category_access::reset_caches();

        // Warm up: the first call resolves, and memoises, that nothing is unlisted.
        $warmup = $generator->create_category();
        category_access::is_category_discoverable((int) $warmup->id);

        $first = $generator->create_category();
        $second = $generator->create_category();
        $before = $DB->perf_get_reads();
        $answers = category_access::are_categories_discoverable([(int) $first->id, (int) $second->id]);
        $reads = $DB->perf_get_reads() - $before;

        $this->assertLessThanOrEqual(
            3,
            $reads,
            'A recordset can cost more than one statement on some drivers; 3 is the measured ceiling.'
        );
        $this->assertSame([(int) $first->id => true, (int) $second->id => true], $answers);
    }

    /**
     * A role switch inside a course does not change the category answer.
     *
     * The raw role read (role_term) never consults a switch, and has_capability()
     * at the category context only walks the category's OWN path upward - the
     * course the switch is registered against is a descendant, never one of the
     * paths checked, so the staff escape is unaffected too.
     *
     * @return void
     */
    public function test_a_role_switch_inside_a_course_does_not_change_the_category_answer(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $this->set_category_unlisted((int) $category->id, true);

        $manager = $generator->create_user();
        $this->assign_role('manager', (int) $manager->id, \core\context\system::instance());

        $this->setUser($manager);
        category_access::reset_caches();
        $this->assertTrue(
            category_access::is_category_discoverable((int) $category->id),
            'Precondition: the system manager sees the category through the staff escape.'
        );

        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        role_switch($studentroleid, \core\context\course::instance($course->id));
        $this->assertTrue(
            is_role_switched((int) $course->id),
            'Precondition: the switch must actually be armed, or this test proves nothing.'
        );

        category_access::reset_caches();
        $this->assertTrue(
            category_access::is_category_discoverable((int) $category->id),
            'A role switch inside a descendant course must not change the category answer.'
        );
    }
}
