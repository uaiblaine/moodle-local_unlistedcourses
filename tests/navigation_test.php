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
 * Course discoverability - Tests for the category settings navigation node
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses;

use PHPUnit\Framework\Attributes\CoversFunction;

/**
 * Tests for local_unlistedcourses_extend_settings_navigation().
 *
 * The callback runs on EVERY page of the site, so every test builds its own
 * fresh \moodle_page rather than reusing the global $PAGE - that is also what
 * lets one test build both a category page and a course page and compare
 * them, which a shared, already-initialised $PAGE could not do twice over.
 * Each settings_navigation is built and initialised once per assertion: the
 * class memoises "initialised" on itself, so a second find() on the same
 * instance would prove nothing about a second run of the callback.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversFunction('local_unlistedcourses_extend_settings_navigation')]
final class navigation_test extends \advanced_testcase {
    /**
     * Reset the plugin's request caches between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        category_discoverability::reset_caches();
        category_access::reset_caches();
    }

    /**
     * A manager at a category.
     *
     * @param int $categoryid The category id.
     * @return \stdClass The user.
     */
    private function create_manager(int $categoryid): \stdClass {
        global $DB;

        $manager = $this->getDataGenerator()->create_user();
        role_assign(
            $DB->get_field('role', 'id', ['shortname' => 'manager']),
            $manager->id,
            \core\context\coursecat::instance($categoryid)->id
        );
        return $manager;
    }

    /**
     * Build and initialise the settings navigation for one page under test.
     *
     * THE GLOBAL $PAGE IS GIVEN A URL FIRST, and it is not the page under test.
     * initialise() builds the user-settings branch on its way through, and two core
     * plugins read the global page there rather than the one they were handed:
     * tool_mfa_extend_navigation_user_settings() (admin/tool/mfa/lib.php:71) and
     * tool_usertours\helper::bootstrap() (admin/tool/usertours/classes/helper.php:524).
     * $FULLME is null in a CLI process, so an unset url makes moodle_page::magic_get_url()
     * debug in every test and out_as_local_url() throw in the first one to reach the
     * tours cache - neither about this plugin. Core's own settings_navigation_test does
     * the same thing for the same reason (lib/tests/navigation/settings_navigation_test.php).
     *
     * @param \moodle_page $page The page whose settings navigation is wanted.
     * @return \settings_navigation The initialised tree.
     */
    private function build_nav(\moodle_page $page): \settings_navigation {
        global $PAGE;

        $PAGE->set_url('/');

        $settingsnav = new \settings_navigation($page);
        $settingsnav->initialise();
        return $settingsnav;
    }

    /**
     * The settings navigation, initialised for a category page.
     *
     * @param int $categoryid The category id.
     * @return \settings_navigation The initialised tree.
     */
    private function category_settings_nav(int $categoryid): \settings_navigation {
        $page = new \moodle_page();
        $page->set_url('/course/index.php', ['categoryid' => $categoryid]);
        $page->set_category_by_id($categoryid);

        return $this->build_nav($page);
    }

    /**
     * The settings navigation, initialised for a course page.
     *
     * @param \stdClass $course The course.
     * @return \settings_navigation The initialised tree.
     */
    private function course_settings_nav(\stdClass $course): \settings_navigation {
        $page = new \moodle_page();
        $page->set_url('/course/view.php', ['id' => $course->id]);
        $page->set_course($course);

        return $this->build_nav($page);
    }

    /**
     * The node the callback adds, or false when it is absent.
     *
     * @param \settings_navigation $settingsnav The initialised tree.
     * @return \navigation_node|false The node.
     */
    private function find_node(\settings_navigation $settingsnav) {
        return $settingsnav->find('unlistedcoursescatstate', \navigation_node::TYPE_SETTING);
    }

    /**
     * The node exists for a manager, sits in the category settings container, and carries the right URL and text.
     *
     * @return void
     */
    public function test_the_node_exists_for_a_manager_with_the_right_url_and_text(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $manager = $this->create_manager((int) $category->id);

        $this->setUser($manager);
        $settingsnav = $this->category_settings_nav((int) $category->id);
        $node = $this->find_node($settingsnav);

        $this->assertNotFalse($node, 'The category settings container has no such child.');
        $this->assertSame(get_string('categorystate', 'local_unlistedcourses'), $node->text);
        $this->assertInstanceOf(\moodle_url::class, $node->action);
        $this->assertStringContainsString('/local/unlistedcourses/category.php', $node->action->out(false));
        $this->assertSame((int) $category->id, (int) $node->action->get_param('id'));

        /* The node has to be a child of the container the callback looks up, not merely
           present somewhere in the tree - find() would still succeed against a node added
           to the root by mistake, so the parent is checked explicitly. */
        $this->assertSame('categorysettings', $node->parent->key);
    }

    /**
     * Without the manage capability the node is absent; the same user, once given it through
     * a role, sees it in the same run.
     *
     * @return void
     */
    public function test_the_node_is_absent_without_the_capability_and_present_once_granted(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $outsider = $this->getDataGenerator()->create_user();

        $this->setUser($outsider);
        $settingsnav = $this->category_settings_nav((int) $category->id);
        $this->assertFalse($this->find_node($settingsnav), 'A user without the capability must not see the node.');

        // Control: the very same user, once given the capability through a role, does.
        role_assign(
            $DB->get_field('role', 'id', ['shortname' => 'manager']),
            $outsider->id,
            \core\context\coursecat::instance($category->id)->id
        );
        $settingsnav = $this->category_settings_nav((int) $category->id);
        $this->assertNotFalse(
            $this->find_node($settingsnav),
            'The same user, once given the capability, must see the node.'
        );
    }

    /**
     * The node is a category-page control and must not leak onto a course page, even for a
     * manager of that course's own category.
     *
     * @return void
     */
    public function test_the_node_is_absent_on_a_course_page(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        $manager = $this->create_manager((int) $category->id);

        $this->setUser($manager);
        $settingsnav = $this->course_settings_nav($course);
        $this->assertFalse(
            $this->find_node($settingsnav),
            'A course page must not offer the category-level control, even to a category manager.'
        );

        // Control: the same manager, on the category page itself, still sees it.
        $settingsnav = $this->category_settings_nav((int) $category->id);
        $this->assertNotFalse($this->find_node($settingsnav));
    }
}
