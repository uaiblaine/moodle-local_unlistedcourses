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
 * Course discoverability - Tests for the category discoverability preview
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\output;

use local_unlistedcourses\category_discoverability;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the "who sees this category" preview shown on the editing page.
 *
 * Every export goes through a FRESH \moodle_page built for the category being
 * previewed, never the shared global $PAGE: moodle_page::set_context() warns
 * once a non-system, non-course context is switched to a DIFFERENT context of
 * the same level, and several tests here export two or more categories in one
 * run.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(category_preview::class)]
final class category_preview_test extends \advanced_testcase {
    /**
     * Reset the plugin's request caches between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        category_discoverability::reset_caches();
    }

    /**
     * A manager at a category.
     *
     * @param \core_course_category $category The category.
     * @return \stdClass The user.
     */
    private function create_manager(\core_course_category $category): \stdClass {
        return $this->assign_role_user('manager', $category->id);
    }

    /**
     * A new user, assigned a role by shortname at a category's context.
     *
     * @param string $shortname The role's shortname.
     * @param int $categoryid The category id.
     * @return \stdClass The user.
     */
    private function assign_role_user(string $shortname, int $categoryid): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->assign_role($shortname, (int) $user->id, \core\context\coursecat::instance($categoryid));
        return $user;
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
     * Create a cohort at the given context.
     *
     * @param \context $context The cohort's context.
     * @param string $name The cohort's name.
     * @return \stdClass The cohort record.
     */
    private function create_cohort_at(\context $context, string $name = 'Cohort'): \stdClass {
        return $this->getDataGenerator()->create_cohort([
            'contextid' => $context->id,
            'name' => $name,
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
     * Export the preview for a category, against its own fresh page.
     *
     * @param \core_course_category $category The category.
     * @param \core\context\coursecat $context The category's context.
     * @return array The exported template context.
     */
    private function export(\core_course_category $category, \core\context\coursecat $context): array {
        $page = new \moodle_page();
        $page->set_url('/local/unlistedcourses/category.php', ['id' => $category->id]);
        $page->set_context($context);

        return (new category_preview($category, $context))->export_for_template($page->get_renderer('core'));
    }

    /**
     * 'state' and 'unlisted' follow the category's own row.
     *
     * @return void
     */
    public function test_state_and_unlisted_reflect_the_categorys_own_row(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $context = \core\context\coursecat::instance($category->id);

        $data = $this->export($category, $context);
        $this->assertSame(category_discoverability::STATE_DEFAULT, $data['state']);
        $this->assertFalse($data['unlisted']);

        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $data = $this->export($category, $context);
        $this->assertSame(category_discoverability::STATE_UNLISTED, $data['state']);
        $this->assertTrue($data['unlisted']);
    }

    /**
     * 'ancestorunlisted' follows an unlisted ANCESTOR, and excludes the category's own row.
     *
     * @return void
     */
    public function test_ancestorunlisted_follows_an_unlisted_ancestor_and_excludes_self(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $parent = $generator->create_category();
        $child = $generator->create_category(['parent' => $parent->id]);
        $childcontext = \core\context\coursecat::instance($child->id);
        category_discoverability::set_state((int) $parent->id, category_discoverability::STATE_UNLISTED);

        $data = $this->export($child, $childcontext);
        $this->assertTrue($data['ancestorunlisted']);
        $this->assertFalse($data['unlisted'], 'The child itself must not read as unlisted through its own row.');

        // Control: the child's OWN row is unlisted, but it no longer has an unlisted ancestor.
        category_discoverability::set_state((int) $parent->id, category_discoverability::STATE_DEFAULT);
        category_discoverability::set_state((int) $child->id, category_discoverability::STATE_UNLISTED);
        $data = $this->export($child, $childcontext);
        $this->assertFalse($data['ancestorunlisted'], 'A category is not its own ancestor.');
        $this->assertTrue($data['unlisted']);
    }

    /**
     * Only cohorts whose context IS the category's own are exported; a system cohort and a
     * parent-category cohort are both excluded.
     *
     * @return void
     */
    public function test_only_cohorts_at_this_categorys_own_context_are_exported(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $parent = $generator->create_category();
        $category = $generator->create_category(['parent' => $parent->id]);
        $context = \core\context\coursecat::instance($category->id);

        $this->create_cohort_at(\core\context\system::instance(), 'System cohort');
        $this->create_cohort_at(\core\context\coursecat::instance($parent->id), 'Parent cohort');
        $this->create_cohort_at($context, 'Own cohort');

        $data = $this->export($category, $context);

        $this->assertSame(['Own cohort'], array_column($data['cohorts'], 'name'));
        $this->assertTrue($data['hascohorts']);
        $this->assertSame(
            (new \moodle_url('/cohort/index.php', ['contextid' => $context->id]))->out(false),
            $data['cohortsurl']
        );
    }

    /**
     * With no cohort defined at the category, the preview says so plainly.
     *
     * @return void
     */
    public function test_hascohorts_is_false_with_no_cohort_defined(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $context = \core\context\coursecat::instance($category->id);

        $data = $this->export($category, $context);
        $this->assertFalse($data['hascohorts']);
        $this->assertSame([], $data['cohorts']);
    }

    /**
     * Each cohort's member count is right, an empty cohort included.
     *
     * @return void
     */
    public function test_member_counts_are_right(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $context = \core\context\coursecat::instance($category->id);
        $populated = $this->create_cohort_at($context, 'Members here');
        $this->add_to_cohort((int) $populated->id, (int) $generator->create_user()->id);
        $this->add_to_cohort((int) $populated->id, (int) $generator->create_user()->id);
        $this->create_cohort_at($context, 'Empty cohort');

        $data = $this->export($category, $context);
        $byname = array_combine(array_column($data['cohorts'], 'name'), array_column($data['cohorts'], 'members'));

        $this->assertSame(2, $byname['Members here']);
        $this->assertSame(0, $byname['Empty cohort']);
    }

    /**
     * More cohorts than fit on one page are truncated, and 'cohortsmore' says how many were left out.
     *
     * @return void
     */
    public function test_more_than_a_page_of_cohorts_yields_cohortsmore(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $context = \core\context\coursecat::instance($category->id);

        // Assumption: the class's own page size is 100, per the implementation brief.
        for ($i = 1; $i <= 101; $i++) {
            $this->create_cohort_at($context, sprintf('Cohort %03d', $i));
        }

        $data = $this->export($category, $context);
        $this->assertCount(100, $data['cohorts'], 'The listing must stop at the page size.');
        $this->assertSame(1, $data['cohortsmore']);
    }

    /**
     * Without moodle/cohort:view, no cohort is named at all - not even that one exists.
     * A manager, who holds it by default, gets the same category's cohorts back.
     *
     * @return void
     */
    public function test_cohorts_are_hidden_without_the_cohort_view_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $this->setAdminUser();
        $category = $generator->create_category();
        $context = \core\context\coursecat::instance($category->id);
        $this->create_cohort_at($context, 'Hidden from the viewer');

        // A bespoke role holding the manage capability, but never moodle/cohort:view.
        $roleid = $generator->create_role();
        assign_capability(
            category_discoverability::CAPABILITY_MANAGE,
            CAP_ALLOW,
            $roleid,
            \core\context\system::instance()->id
        );
        $limited = $generator->create_user();
        role_assign($roleid, $limited->id, $context->id);
        $this->assertFalse(
            has_capability('moodle/cohort:view', $context, $limited),
            'Precondition: the bespoke role must not carry moodle/cohort:view.'
        );

        $this->setUser($limited);
        $data = $this->export($category, $context);
        $this->assertFalse($data['cohortsviewable']);
        $this->assertSame([], $data['cohorts'], 'No cohort may be named to a viewer without moodle/cohort:view.');

        // Control: a manager, who holds moodle/cohort:view by default, sees the same category's cohorts.
        $this->setUser($this->create_manager($category));
        $data = $this->export($category, $context);
        $this->assertTrue($data['cohortsviewable']);
        $this->assertNotEmpty($data['cohorts']);
    }

    /**
     * A cohort named with a bare ampersand exports the PLAIN spelling: the template
     * double-stashes the name.
     *
     * @return void
     */
    public function test_a_cohort_named_with_an_ampersand_exports_the_plain_spelling(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $context = \core\context\coursecat::instance($category->id);
        $this->create_cohort_at($context, 'A & B');

        $data = $this->export($category, $context);
        $names = array_column($data['cohorts'], 'name');

        $this->assertContains('A & B', $names);
        $this->assertNotContains('A &amp; B', $names);
    }

    /**
     * 'roleholders' counts a role at the category ITSELF and at an ancestor CATEGORY, but
     * never a role at a course inside it.
     *
     * @return void
     */
    public function test_roleholders_counts_the_path_but_not_a_course_inside(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $parent = $generator->create_category();
        $category = $generator->create_category(['parent' => $parent->id]);
        $context = \core\context\coursecat::instance($category->id);
        $course = $generator->create_course(['category' => $category->id]);

        $this->assign_role_user('manager', (int) $parent->id);
        $this->assign_role('teacher', (int) $generator->create_user()->id, $context);
        $this->assign_role('editingteacher', (int) $generator->create_user()->id, \core\context\course::instance($course->id));

        $data = $this->export($category, $context);
        $this->assertSame(
            2,
            $data['roleholders'],
            'A role at an ancestor category and at the category itself must both count; a course role must not.'
        );
    }

    /**
     * 'visiblecount' is the DISTINCT union of cohort members and role holders: a user who is
     * both counts once.
     *
     * @return void
     */
    public function test_visiblecount_is_the_distinct_union_of_members_and_role_holders(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $context = \core\context\coursecat::instance($category->id);
        $cohort = $this->create_cohort_at($context, 'Members');

        $both = $generator->create_user();
        $this->add_to_cohort((int) $cohort->id, (int) $both->id);
        $this->assign_role('manager', (int) $both->id, $context);

        $onlymember = $generator->create_user();
        $this->add_to_cohort((int) $cohort->id, (int) $onlymember->id);

        $onlyrole = $generator->create_user();
        $this->assign_role('editingteacher', (int) $onlyrole->id, $context);

        $data = $this->export($category, $context);

        /* Summing the two sources instead of taking their union would read 4 here (2
           members + 2 role holders): the assertion below is the one that tells them apart. */
        $this->assertSame(3, $data['visiblecount']);
    }

    /**
     * With a category ABOVE this one unlisted too, 'visiblecount' is the INTERSECTION of
     * the two eligible sets - the quantifier the access predicate applies - and the
     * "visible to nobody" warning follows that number rather than this category's own.
     *
     * @return void
     */
    public function test_visiblecount_intersects_an_unlisted_ancestors_own_term(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $parent = $generator->create_category();
        $child = $generator->create_category(['parent' => $parent->id]);
        $parentcontext = \core\context\coursecat::instance($parent->id);
        $childcontext = \core\context\coursecat::instance($child->id);

        category_discoverability::set_state((int) $parent->id, category_discoverability::STATE_UNLISTED);
        category_discoverability::set_state((int) $child->id, category_discoverability::STATE_UNLISTED);

        $parentcohort = $this->create_cohort_at($parentcontext, 'Above');
        $childcohort = $this->create_cohort_at($childcontext, 'Here');

        // In the child's cohort only: the parent still withholds the whole subtree from them.
        $onlyhere = $generator->create_user();
        $this->add_to_cohort((int) $childcohort->id, (int) $onlyhere->id);

        // In the parent's cohort only: the child names nothing to them either.
        $onlyabove = $generator->create_user();
        $this->add_to_cohort((int) $parentcohort->id, (int) $onlyabove->id);

        $data = $this->export($child, $childcontext);
        $this->assertTrue($data['ancestorunlisted'], 'Precondition: the compound arrangement, not a lone unlisted category.');
        $this->assertSame(
            0,
            $data['visiblecount'],
            'Satisfying one unlisted category on the path is not satisfying the path.'
        );
        $this->assertTrue($data['nobodywarning'], 'Nobody satisfies both categories, so the warning must fire.');

        // Control: one person in BOTH cohorts is counted, and clears the warning.
        $both = $generator->create_user();
        $this->add_to_cohort((int) $parentcohort->id, (int) $both->id);
        $this->add_to_cohort((int) $childcohort->id, (int) $both->id);

        $data = $this->export($child, $childcontext);
        $this->assertSame(1, $data['visiblecount']);
        $this->assertFalse($data['nobodywarning'], 'A person eligible for both categories clears the warning.');

        /* Control: a role at the unlisted ancestor satisfies BOTH terms at once - the
           parent's own, and the child's, whose path includes the parent - so this raises
           the count by one person rather than by none. */
        $this->assign_role('manager', (int) $generator->create_user()->id, $parentcontext);
        $this->assertSame(2, $this->export($child, $childcontext)['visiblecount']);
    }

    /**
     * 'nobodywarning' is true only while the category is UNLISTED and nobody besides staff
     * would see it; a member clears it, and a listed category never shows it at all.
     *
     * @return void
     */
    public function test_nobodywarning_follows_unlisted_state_and_membership(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $context = \core\context\coursecat::instance($category->id);

        // Listed, with nobody eligible: never a warning, whatever the membership would be.
        $this->assertFalse($this->export($category, $context)['nobodywarning'], 'A listed category never warns.');

        // Unlisted, with nobody eligible: warns.
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $this->assertTrue(
            $this->export($category, $context)['nobodywarning'],
            'Unlisted and visible to nobody besides staff must warn.'
        );

        // Control: a single cohort member is enough to clear the warning.
        $cohort = $this->create_cohort_at($context, 'One member');
        $this->add_to_cohort((int) $cohort->id, (int) $generator->create_user()->id);
        $this->assertFalse($this->export($category, $context)['nobodywarning'], 'A member must clear the warning.');
    }

    /**
     * 'themewarning' follows the site's allowcategorythemes setting.
     *
     * @return void
     */
    public function test_themewarning_follows_allowcategorythemes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $context = \core\context\coursecat::instance($category->id);

        set_config('allowcategorythemes', 0);
        $this->assertFalse($this->export($category, $context)['themewarning']);

        set_config('allowcategorythemes', 1);
        $this->assertTrue($this->export($category, $context)['themewarning']);
    }
}
