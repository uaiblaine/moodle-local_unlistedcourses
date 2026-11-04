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

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Course discoverability - Tests for the guards of the "apply the default" page
 *
 * The page is a script, so its wiring is read from its source, as category_access_test reads
 * category.php; who may reach it is asked of the admin tree it registers in.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversNothing]
final class applydefault_page_test extends \advanced_testcase {
    /**
     * The page's source.
     *
     * @return string
     */
    private function source(): string {
        global $CFG;

        $source = file_get_contents($CFG->dirroot . '/local/unlistedcourses/applydefault.php');
        $this->assertNotFalse($source);
        return $source;
    }

    /**
     * Only a holder of moodle/site:config reaches the page: a manager does not, an administrator does.
     *
     * The tree is built as an administrator, because settings.php adds nothing to a tree built for a
     * user without moodle/site:config; the page's own check then reads the current user.
     *
     * @return void
     */
    public function test_only_a_site_administrator_may_reach_the_page(): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $managerrole = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($managerrole, $manager->id, \core\context\system::instance());

        $this->setAdminUser();
        $page = admin_get_root(true, false)->locate('local_unlistedcourses_applydefault');
        $this->assertInstanceOf(\admin_externalpage::class, $page);
        $this->assertSame(['moodle/site:config'], $page->req_capability);
        $this->assertTrue($page->check_access());

        $this->setUser($manager);
        $this->assertFalse($page->check_access(), 'A manager may queue the backfill.');
    }

    /**
     * The page checks access first, then queues only from a POST that carries the session key.
     *
     * @return void
     */
    public function test_the_page_queues_only_after_its_guards(): void {
        $source = $this->source();
        $setup = strpos($source, "admin_externalpage_setup('local_unlistedcourses_applydefault');");
        $posted = strpos($source, "if (optional_param('confirm', 0, PARAM_BOOL) && data_submitted()) {");
        $sesskey = strpos($source, 'require_sesskey();');
        $queue = strpos($source, 'default_backfill::queue(');

        $this->assertNotFalse($setup, 'The page does not set itself up as the admin external page.');
        $this->assertNotFalse($posted, 'The page queues outside a confirmed POST.');
        $this->assertNotFalse($sesskey, 'The page does not check the session key.');
        $this->assertNotFalse($queue);
        $this->assertLessThan($posted, $setup);
        $this->assertLessThan($sesskey, $posted);
        $this->assertLessThan($queue, $sesskey);
        $this->assertSame(1, substr_count($source, 'default_backfill::queue('), 'The page queues from a second place.');
        $this->assertSame(0, substr_count($source, 'default_backfill::run('), 'The page writes course states itself.');
    }

    /**
     * The settings page links to the action page, which is hidden from the tree itself.
     *
     * @return void
     */
    public function test_the_settings_page_links_to_the_action(): void {
        global $CFG;

        require_once($CFG->libdir . '/adminlib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $root = admin_get_root(true, true);
        $this->assertTrue($root->locate('local_unlistedcourses_applydefault')->is_hidden());
        $settings = $root->locate('local_unlistedcourses');
        $link = $settings->settings->local_unlistedcoursesapplydefault ?? null;
        $this->assertInstanceOf(\admin_setting_description::class, $link);
        $this->assertStringContainsString('/local/unlistedcourses/applydefault.php', (string) $link->description);
    }
}
