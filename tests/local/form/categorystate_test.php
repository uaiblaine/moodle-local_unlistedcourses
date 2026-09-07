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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the category discoverability editing form's own markup.
 *
 * The form is rendered with render(), the repo's own idiom for a plain
 * \moodleform (see local_groupdist\form\options_form_test, the fleet
 * precedent this follows), and every assertion is scoped to the ONE element
 * tag it is about - never to the whole page - so a match further down the
 * markup (the preview panel this form also embeds) cannot satisfy it by
 * accident.
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
     * satisfied by a DIFFERENT option later in the same select.
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
     * The select offers exactly the two category states, and nothing else - never the
     * course-side "Public" option, which this form has no third state to hold.
     *
     * @return void
     */
    public function test_the_select_has_exactly_the_two_states(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();

        $select = $this->select($this->render($category));

        $this->assertSame(2, substr_count($select, '<option'), 'The select must offer exactly two options.');
        $this->option_tag($select, category_discoverability::STATE_DEFAULT);
        $this->option_tag($select, category_discoverability::STATE_UNLISTED);
    }

    /**
     * The default option is the category's CURRENT state, not always "Listed".
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
     * The hidden 'id' element carries the category id, so the submit knows which category to
     * change - not attribute order, which the pear renderer does not promise.
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
}
