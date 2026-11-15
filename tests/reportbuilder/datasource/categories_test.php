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
 * Course discoverability - Tests for the report builder data source of course categories
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\reportbuilder\datasource;

use core_reportbuilder\local\filters\select;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use core_reportbuilder_generator;
use local_unlistedcourses\access;
use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\reportbuilder\local\entities\category_state;
use local_unlistedcourses\reportbuilder\local\helpers\public_effect;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the data source that lists every course category with its discoverability.
 *
 * The public effect column is held to category_discoverability::is_public()
 * over every way a public category is held back, beside one that is not.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(categories::class)]
#[CoversClass(category_state::class)]
#[CoversClass(public_effect::class)]
final class categories_test extends core_reportbuilder_testcase {
    /**
     * Reset the request caches between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        category_discoverability::reset_caches();
        access::reset_caches();
    }

    /**
     * A report of the data source with the given columns, and its rows.
     *
     * @param array $columns Column identifiers.
     * @param array $filters Filter identifiers to add.
     * @param array $filtervalues Values for those filters.
     * @return array The rows, each a list of values in column order.
     */
    private function report_rows(array $columns, array $filters = [], array $filtervalues = []): array {
        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Categories', 'source' => categories::class, 'default' => 0]);
        foreach ($columns as $column) {
            $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $column]);
        }
        foreach ($filters as $filter) {
            $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => $filter]);
        }
        return array_map('array_values', $this->get_custom_report_content($report->get('id'), 100, $filtervalues));
    }

    /**
     * One public category for each thing that holds a public state back, and two nothing holds back.
     *
     * @return array Map of category name => [category id, expected effect constant].
     */
    private function create_public_categories(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $hiddenparent = $generator->create_category(['name' => 'Hidden parent']);
        $unlistedparent = $generator->create_category(['name' => 'Unlisted parent']);
        $plainparent = $generator->create_category(['name' => 'Plain parent']);

        $cases = [
            'Effective' => [0, public_effect::EFFECTIVE],
            'Effective below the top level' => [(int) $plainparent->id, public_effect::EFFECTIVE],
            'Hidden itself' => [0, public_effect::HIDDEN],
            'Below hidden category' => [(int) $hiddenparent->id, public_effect::HIDDEN_CATEGORY],
            'Below unlisted category' => [(int) $unlistedparent->id, public_effect::UNLISTED_CATEGORY],
            'Missing ancestor' => [0, public_effect::HIDDEN_CATEGORY],
        ];
        $categories = [];
        foreach ($cases as $name => [$parent, $effect]) {
            $category = $generator->create_category(['name' => $name, 'parent' => $parent]);
            category_discoverability::set_state((int) $category->id, category_discoverability::STATE_PUBLIC);
            $categories[$name] = [(int) $category->id, $effect];
        }

        // The facts that hold them back, written after the states so core hides nothing on its own.
        $DB->set_field('course_categories', 'visible', 0, ['id' => $categories['Hidden itself'][0]]);
        $DB->set_field('course_categories', 'visible', 0, ['id' => $hiddenparent->id]);
        category_discoverability::set_state((int) $unlistedparent->id, category_discoverability::STATE_UNLISTED);
        $orphanid = $categories['Missing ancestor'][0];
        $DB->set_field('course_categories', 'path', '/999999/' . $orphanid, ['id' => $orphanid]);
        $DB->set_field('course_categories', 'depth', 2, ['id' => $orphanid]);

        category_discoverability::reset_caches();
        access::reset_caches();
        return $categories;
    }

    /**
     * The default report names the state, its effect, who changed it and when.
     *
     * @return void
     */
    public function test_the_default_report_lists_each_category_with_its_state_and_who_changed_it(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $unlisted = $generator->create_category(['name' => 'Zeta unlisted']);
        $public = $generator->create_category(['name' => 'Zeta public']);
        category_discoverability::set_state((int) $unlisted->id, category_discoverability::STATE_UNLISTED);
        category_discoverability::set_state((int) $public->id, category_discoverability::STATE_PUBLIC);

        /** @var core_reportbuilder_generator $rbgenerator */
        $rbgenerator = $generator->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Categories', 'source' => categories::class, 'default' => 1]);
        $rows = array_map('array_values', $this->get_custom_report_content($report->get('id')));
        $byname = array_column($rows, null, 0);

        // The category the test site starts with is a row too: listed, changed by nobody.
        $this->assertCount(3, $rows);
        $default = \core_course_category::get_default()->get_nested_name(false);
        $this->assertSame([$default, 'Listed', 'Not public', '', ''], $byname[$default]);

        $admin = fullname(get_admin());
        $this->assertSame(['Unlisted', 'Not public', $admin], array_slice($byname['Zeta unlisted'], 1, 3));
        $this->assertNotSame('', $byname['Zeta unlisted'][4]);
        $this->assertSame(
            ['Public', 'In effect: served to visitors', $admin],
            array_slice($byname['Zeta public'], 1, 3)
        );
        $this->assertNotSame('', $byname['Zeta public'][4]);
    }

    /**
     * The effect column agrees with category_discoverability::is_public() for every way a public state is held back.
     *
     * @return void
     */
    public function test_the_effect_column_agrees_with_the_public_predicate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $categories = $this->create_public_categories();
        $labels = public_effect::options(true);

        $rows = $this->report_rows(
            ['course_category:name', 'category_state:state', 'category_state:effect'],
            ['category_state:state'],
            [
                'category_state:state_operator' => select::EQUAL_TO,
                'category_state:state_value' => category_discoverability::STATE_PUBLIC,
            ]
        );
        $this->assertCount(count($categories), $rows);

        $effective = 0;
        foreach ($rows as [$name, $state, $effect]) {
            [$categoryid, $expected] = $categories[$name];
            $this->assertSame('Public', $state, $name);
            $this->assertSame($labels[$expected], $effect, $name);
            $this->assertSame(
                category_discoverability::is_public($categoryid),
                $effect === $labels[public_effect::EFFECTIVE],
                $name
            );
            $effective += (int) ($effect === $labels[public_effect::EFFECTIVE]);
        }
        // The control: the column says "in effect" for the two categories nothing holds back.
        $this->assertSame(2, $effective);
    }

    /**
     * An unknown stored value reads as listed, in the column and in the filter.
     *
     * @return void
     */
    public function test_an_unknown_value_reads_as_listed(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $unknown = $generator->create_category(['name' => 'Unknown']);
        $unlisted = $generator->create_category(['name' => 'Unlisted']);
        $DB->insert_record(category_discoverability::TABLE, ['categoryid' => $unknown->id, 'state' => 7]);
        category_discoverability::set_state((int) $unlisted->id, category_discoverability::STATE_UNLISTED);

        $rows = $this->report_rows(
            ['course_category:name', 'category_state:state', 'category_state:effect'],
            ['category_state:state'],
            [
                'category_state:state_operator' => select::NOT_EQUAL_TO,
                'category_state:state_value' => category_discoverability::STATE_UNLISTED,
            ]
        );
        $byname = array_column($rows, null, 0);

        $this->assertSame(['Unknown', 'Listed', 'Not public'], $byname['Unknown']);
        // The control: the filter has force, the unlisted category is left out.
        $this->assertArrayNotHasKey('Unlisted', $byname);
        $this->assertCount(2, $rows);
    }

    /**
     * Every column, aggregation and condition of the data source builds a statement the database runs.
     *
     * Core runs these helpers only in its long tests; here they take a few seconds and
     * are what runs the effect SQL through GROUP BY on both database families.
     *
     * @return void
     */
    public function test_stress_datasource(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $category = $this->getDataGenerator()->create_category();
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_PUBLIC);

        $this->datasource_stress_test_columns(categories::class);
        $this->datasource_stress_test_columns_aggregation(categories::class);
        $this->datasource_stress_test_conditions(categories::class, 'course_category:idnumber');
    }
}
