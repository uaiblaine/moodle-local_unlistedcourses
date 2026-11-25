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
 * Course discoverability - Tests for the category discoverability editing form
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\local\form;

use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\discoverability;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the category discoverability editing form's own markup.
 *
 * Every assertion is scoped to the one element tag it is about, never to the
 * whole rendered form, so a match elsewhere in the markup (such as the preview
 * panel the form embeds) cannot satisfy it by accident.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(categorystate::class)]
final class categorystate_test extends \advanced_testcase {
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
     * Render the editing form for a category.
     *
     * @param \core_course_category $category The category.
     * @return string The rendered HTML.
     */
    private function render(\core_course_category $category): string {
        $context = \core\context\coursecat::instance($category->id);
        $form = new categorystate(null, [
            'id' => (int) $category->id,
            'category' => $category,
            'context' => $context,
        ]);
        return $form->render();
    }

    /**
     * Extract the discoverability select's own markup from a rendered form.
     *
     * @param string $html The rendered form.
     * @return string The select element's markup.
     */
    private function select(string $html): string {
        $this->assertSame(
            1,
            preg_match('~<select[^>]*name="state"[^>]*>.*?</select>~s', $html, $matches),
            'The state select was not rendered at all.'
        );
        return $matches[0];
    }

    /**
     * Extract one option's own tag, by its value, from a select's markup.
     *
     * Scoped to the single tag so that checking for "selected" cannot be
     * satisfied by another option in the same select.
     *
     * @param string $select The select element's markup.
     * @param int $value The option's value attribute.
     * @return string The option tag's own markup.
     */
    private function option_tag(string $select, int $value): string {
        $this->assertSame(
            1,
            preg_match('~<option[^>]*value="' . $value . '"[^>]*>~', $select, $matches),
            "No option with value \"{$value}\" was rendered."
        );
        return $matches[0];
    }

    /**
     * Submit the form for a category, as the current user, and hand back what get_data() returns.
     *
     * The form is built after the submission, as on the page: its options come from the state
     * stored at that moment, which is what decides whether the posted value survives.
     *
     * @param \core_course_category $category The category.
     * @param array $post The posted values besides the category id.
     * @return \stdClass|null The validated data, null when the form refused the submission.
     */
    private function submit(\core_course_category $category, array $post): ?\stdClass {
        categorystate::mock_submit(['id' => (int) $category->id] + $post);
        $form = new categorystate(null, [
            'id' => (int) $category->id,
            'category' => $category,
            'context' => \core\context\coursecat::instance($category->id),
        ]);
        return $form->get_data();
    }

    /**
     * A user who may change the state but may not publish the category.
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
     * The select offers the three category states to somebody who may publish.
     *
     * @return void
     */
    public function test_the_select_offers_the_three_states_to_a_publisher(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $select = $this->select($this->render($category));

        $this->assertSame(3, substr_count($select, '<option'), 'The select must offer exactly three options.');
        $this->option_tag($select, category_discoverability::STATE_DEFAULT);
        $this->option_tag($select, category_discoverability::STATE_UNLISTED);
        $this->option_tag($select, category_discoverability::STATE_PUBLIC);
    }

    /**
     * A category that is not already public offers no public option without the publish capability.
     *
     * @return void
     */
    public function test_the_public_option_is_absent_without_the_publish_capability(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $statemanager = $this->create_statemanager($category);

        $this->setUser($statemanager);
        $select = $this->select($this->render($category));
        $this->assertSame(2, substr_count($select, '<option'), 'Only the two states this user may set.');
        $this->assertSame(
            0,
            preg_match('~<option[^>]*value="' . category_discoverability::STATE_PUBLIC . '"~', $select),
            'The public option must not be offered to somebody who may not publish.'
        );

        // Control: the same category, the same form, rendered for somebody who may publish.
        $this->setAdminUser();
        $select = $this->select($this->render($category));
        $this->option_tag($select, category_discoverability::STATE_PUBLIC);
    }

    /**
     * A public category freezes the select for an editor who may not publish, value and all.
     *
     * @return void
     */
    public function test_a_public_category_freezes_the_select_for_an_editor_who_may_not_publish(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $statemanager = $this->create_statemanager($category);
        $this->setAdminUser();
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_PUBLIC);

        $this->setUser($statemanager);
        $html = $this->render($category);

        $this->assertSame(
            0,
            preg_match('~<select[^>]*name="state"~', $html),
            'A frozen control is not a select any more.'
        );
        $this->assertSame(
            1,
            preg_match('~<input[^>]*name="state"[^>]*>~', $html, $matches),
            'The frozen value must still be submitted through a hidden input.'
        );
        $this->assertStringContainsString('type="hidden"', $matches[0]);
        $this->assertStringContainsString('value="' . category_discoverability::STATE_PUBLIC . '"', $matches[0]);

        // Control: a manager who may publish gets the same category as a live select.
        $this->setAdminUser();
        $select = $this->select($this->render($category));
        $this->assertStringContainsString(
            'selected',
            $this->option_tag($select, category_discoverability::STATE_PUBLIC)
        );
    }

    /**
     * The default option is the category's current state, not always "Listed".
     *
     * @return void
     */
    public function test_the_default_is_the_categorys_current_state(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $select = $this->select($this->render($category));
        $this->assertStringContainsString(
            'selected',
            $this->option_tag($select, category_discoverability::STATE_DEFAULT),
            'A listed category must default to "Listed".'
        );
        $this->assertStringNotContainsString(
            'selected',
            $this->option_tag($select, category_discoverability::STATE_UNLISTED)
        );

        // Control: once the category is unlisted, the very same form defaults to "Unlisted" instead.
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $select = $this->select($this->render($category));
        $this->assertStringContainsString(
            'selected',
            $this->option_tag($select, category_discoverability::STATE_UNLISTED)
        );
        $this->assertStringNotContainsString(
            'selected',
            $this->option_tag($select, category_discoverability::STATE_DEFAULT)
        );
    }

    /**
     * A stale public value from a frozen control saves nothing, where the cast read it as listed.
     *
     * The editor opened the form on a public category, a publisher unlisted it since, and the
     * frozen control still posts public, which the rebuilt select no longer offers. Core drops
     * the value; the save must then write nothing rather than read the absence as listed.
     *
     * @return void
     */
    public function test_a_submission_whose_state_the_form_dropped_saves_nothing(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $statemanager = $this->create_statemanager($category);
        $this->setAdminUser();
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);

        $this->setUser($statemanager);
        $data = $this->submit($category, ['state' => category_discoverability::STATE_PUBLIC]);
        $this->assertNotNull($data, 'Precondition: the form accepts the submission.');
        $this->assertFalse(isset($data->state), 'Precondition: the select dropped a value it no longer offers.');

        $sink = $this->redirectEvents();
        $this->assertFalse(categorystate::save((int) $category->id, $data));
        $this->assertSame(category_discoverability::STATE_UNLISTED, category_discoverability::get_state((int) $category->id));
        $this->assertCount(0, $sink->get_events(), 'A refused save fires nothing.');

        // Control: the same user, the same category, a value the form offers - it is written.
        $data = $this->submit($category, ['state' => category_discoverability::STATE_DEFAULT]);
        $this->assertTrue(categorystate::save((int) $category->id, $data));
        category_discoverability::reset_caches();
        $this->assertSame(category_discoverability::STATE_DEFAULT, category_discoverability::get_state((int) $category->id));
        $this->assertCount(1, $sink->get_events());
        $sink->close();
    }

    /**
     * A publisher's public value survives the rebuilt select and is written.
     *
     * The other direction of the refusal above: the guard reads an absent state, not a public one.
     *
     * @return void
     */
    public function test_a_publishers_public_submission_is_saved(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $this->setAdminUser();
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);

        $data = $this->submit($category, ['state' => category_discoverability::STATE_PUBLIC]);
        $this->assertTrue(categorystate::save((int) $category->id, $data));
        category_discoverability::reset_caches();
        $this->assertSame(category_discoverability::STATE_PUBLIC, category_discoverability::get_state((int) $category->id));
    }

    /**
     * The page writes through the form's save, and answers a refused save with an error before the success notice.
     *
     * A page cannot be required under PHPUnit, so this reads its source, as category_access_test does.
     *
     * @return void
     */
    public function test_the_page_refuses_a_save_the_form_refuses(): void {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/local/unlistedcourses/category.php');
        $save = strpos($source, 'if (!categorystate::save($id, $data)) {');
        $stale = strpos($source, "get_string('categorystate_stale', 'local_unlistedcourses')");
        $saved = strpos($source, "get_string('categorystate_saved', 'local_unlistedcourses')");

        $this->assertNotFalse($saved, 'Control: the page confirms a save.');
        $this->assertNotFalse($save, 'The page must write through categorystate::save() and read its answer.');
        $this->assertNotFalse($stale, 'The page must tell the editor that nothing was saved.');
        $this->assertLessThan($stale, $save);
        $this->assertLessThan($saved, $stale);
        // The page's comments name set_state(); a call passes it the category id.
        $this->assertSame(0, substr_count($source, 'category_discoverability::set_state($'), 'The page writes around the form.');
    }

    /**
     * The hidden 'id' element carries the category id, so the submission knows which category
     * to change. Type and value are checked separately: the QuickForm renderer does not promise
     * an attribute order.
     *
     * @return void
     */
    public function test_the_hidden_id_carries_the_category_id(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $html = $this->render($category);

        $this->assertSame(
            1,
            preg_match('~<input[^>]*name="id"[^>]*>~', $html, $matches),
            'The hidden id element was not rendered.'
        );
        $tag = $matches[0];
        $this->assertStringContainsString('type="hidden"', $tag);
        $this->assertStringContainsString('value="' . $category->id . '"', $tag);
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
     * An unlisted category under a root holding a public course, a delegate at the root, and a publisher of the course.
     *
     * @return array The root, the unlisted category, the course, the delegate and the publisher.
     */
    private function retained_course_fixture(): array {
        $generator = $this->getDataGenerator();
        $root = $generator->create_category();
        $category = $generator->create_category(['parent' => $root->id]);
        $course = $generator->create_course(['category' => $category->id]);
        $this->setAdminUser();
        discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_UNLISTED);
        $delegate = $this->create_statemanager($root);
        $publisher = $this->create_statemanager($root);
        $this->grant($publisher, discoverability::CAPABILITY_PUBLISH, \core\context\course::instance($course->id));
        category_discoverability::reset_caches();
        discoverability::reset_caches();
        return [$root, $category, $course, $delegate, $publisher];
    }

    /**
     * The preview panel's own markup, from its opening tag to the end of the form.
     *
     * @param string $html The rendered form.
     * @return string The markup from the preview on.
     */
    private function preview(string $html): string {
        $start = strpos($html, 'local-unlistedcourses-preview');
        $this->assertNotFalse($start, 'The preview was not rendered at all.');
        return substr($html, $start);
    }

    /**
     * An unlisted category whose listing would publish a course the delegate may not publish freezes the select.
     *
     * @return void
     */
    public function test_an_unlisted_category_freezes_the_select_for_a_delegate_who_may_not_publish_what_it_holds_back(): void {
        $this->resetAfterTest();
        [$root, $category, , $delegate, $publisher] = $this->retained_course_fixture();
        $blocked = get_string('preview_relistblocked', 'local_unlistedcourses');

        $this->setUser($delegate);
        $html = $this->render($category);
        $this->assertSame(0, preg_match('~<select[^>]*name="state"~', $html), 'A frozen control is not a select any more.');
        $this->assertSame(
            1,
            preg_match('~<input[^>]*name="state"[^>]*>~', $html, $matches),
            'The frozen value must still be submitted through a hidden input.'
        );
        $this->assertStringContainsString('type="hidden"', $matches[0]);
        $this->assertStringContainsString('value="' . category_discoverability::STATE_UNLISTED . '"', $matches[0]);
        $this->assertStringContainsString($blocked, $this->preview($html), 'The preview says who has to list it.');

        // Control: somebody who may publish the course gets the same category as a live select, and no such sentence.
        $this->setUser($publisher);
        $html = $this->render($category);
        $this->assertStringContainsString(
            'selected',
            $this->option_tag($this->select($html), category_discoverability::STATE_UNLISTED)
        );
        $this->assertStringNotContainsString($blocked, $this->preview($html));

        // Control: the delegate on an unlisted category that holds back nothing public gets a live select.
        $empty = $this->getDataGenerator()->create_category(['parent' => $root->id]);
        $this->setAdminUser();
        category_discoverability::set_state((int) $empty->id, category_discoverability::STATE_UNLISTED);
        $this->setUser($delegate);
        $this->option_tag($this->select($this->render($empty)), category_discoverability::STATE_DEFAULT);
    }

    /**
     * A delegate posting "listed" past the frozen control is refused by the save, and nothing changes.
     *
     * The form only mirrors the rule: the persistent freeze still lets a crafted post carry any
     * value the select offers, and the save hands it to set_state(), which decides.
     *
     * @return void
     */
    public function test_a_delegate_relisting_through_the_form_is_refused_and_a_publisher_is_not(): void {
        $this->resetAfterTest();
        [, $category, $course, $delegate, $publisher] = $this->retained_course_fixture();

        $this->setUser($delegate);
        $data = $this->submit($category, ['state' => category_discoverability::STATE_DEFAULT]);
        $this->assertNotNull($data, 'Precondition: the form accepts the submission.');
        $this->assertSame(category_discoverability::STATE_DEFAULT, (int) $data->state, 'Precondition: the posted value survives.');

        $sink = $this->redirectEvents();
        $refused = false;
        try {
            categorystate::save((int) $category->id, $data);
        } catch (\required_capability_exception $e) {
            $refused = true;
            $this->assertSame(get_capability_string(discoverability::CAPABILITY_PUBLISH), $e->a);
        }
        $this->assertTrue($refused, 'The save must refuse to list the category again.');
        $this->assertCount(0, $sink->get_events(), 'A refused save fires nothing.');
        category_discoverability::reset_caches();
        discoverability::reset_caches();
        $this->assertSame(category_discoverability::STATE_UNLISTED, category_discoverability::get_state((int) $category->id));
        $this->assertFalse(discoverability::is_public((int) $course->id));

        // Control: the publisher posts the same value through the same form, and it is written.
        $this->setUser($publisher);
        $data = $this->submit($category, ['state' => category_discoverability::STATE_DEFAULT]);
        $this->assertTrue(categorystate::save((int) $category->id, $data));
        $sink->close();
        category_discoverability::reset_caches();
        discoverability::reset_caches();
        $this->assertSame(category_discoverability::STATE_DEFAULT, category_discoverability::get_state((int) $category->id));
        $this->assertTrue(discoverability::is_public((int) $course->id));
    }
}
