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
 * Course discoverability - Tests for the report builder data source of courses
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\reportbuilder\datasource;

use core_course\reportbuilder\datasource\courses as core_courses;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\tests\core_reportbuilder_testcase;
use core_reportbuilder_generator;
use local_unlistedcourses\access;
use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\discoverability;
use local_unlistedcourses\reportbuilder\local\entities\category_state;
use local_unlistedcourses\reportbuilder\local\entities\course_state;
use local_unlistedcourses\reportbuilder\local\helpers\public_effect;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the data source that lists every course with its discoverability.
 *
 * The public effect column is SQL that mirrors discoverability::are_public();
 * the tests hold it to that method over every way a public state is held back,
 * with a course it holds for and courses it does not in the same report.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(courses::class)]
#[CoversClass(course_state::class)]
#[CoversClass(category_state::class)]
#[CoversClass(public_effect::class)]
final class courses_test extends core_reportbuilder_testcase {
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
     * A report of this data source with the given columns, and its rows.
     *
     * @param array $columns Column identifiers.
     * @param array $filters Filter identifiers to add.
     * @param array $filtervalues Values for those filters.
     * @param string $source The data source class.
     * @return array The rows, each a list of values in column order.
     */
    private function report_rows(
        array $columns,
        array $filters = [],
        array $filtervalues = [],
        string $source = courses::class
    ): array {
        /** @var core_reportbuilder_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_reportbuilder');
        $report = $generator->create_report(['name' => 'Discoverability', 'source' => $source, 'default' => 0]);
        foreach ($columns as $column) {
            $generator->create_column(['reportid' => $report->get('id'), 'uniqueidentifier' => $column]);
        }
        foreach ($filters as $filter) {
            $generator->create_filter(['reportid' => $report->get('id'), 'uniqueidentifier' => $filter]);
        }
        return array_map('array_values', $this->get_custom_report_content($report->get('id'), 100, $filtervalues));
    }

    /**
     * Write a course's or category's visibility straight to its row, as a move or a hide would leave it.
     *
     * @param string $table course or course_categories.
     * @param int $id The row id.
     * @param int $visible The value.
     * @return void
     */
    private function set_visible(string $table, int $id, int $visible): void {
        global $DB;
        $DB->set_field($table, 'visible', $visible, ['id' => $id]);
    }

    /**
     * One public course for each thing that holds a public state back, and two nothing holds back.
     *
     * @return array Map of course fullname => [course id, expected effect constant].
     */
    private function create_public_courses(): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $plain = $generator->create_category();
        $belowplain = $generator->create_category(['parent' => $plain->id]);
        $hidden = $generator->create_category();
        $belowhidden = $generator->create_category(['parent' => $hidden->id]);
        $unlisted = $generator->create_category();
        $belowunlisted = $generator->create_category(['parent' => $unlisted->id]);
        $orphan = $generator->create_category();
        $pathless = $generator->create_category();

        $cases = [
            'Effective' => [$plain->id, public_effect::EFFECTIVE],
            'Effective below the top level' => [$belowplain->id, public_effect::EFFECTIVE],
            'Course hidden' => [$plain->id, public_effect::HIDDEN],
            'In hidden category' => [$hidden->id, public_effect::HIDDEN_CATEGORY],
            'Below hidden category' => [$belowhidden->id, public_effect::HIDDEN_CATEGORY],
            'In unlisted category' => [$unlisted->id, public_effect::UNLISTED_CATEGORY],
            'Below unlisted category' => [$belowunlisted->id, public_effect::UNLISTED_CATEGORY],
            'Missing ancestor' => [$orphan->id, public_effect::HIDDEN_CATEGORY],
            'Category without a path' => [$pathless->id, public_effect::HIDDEN_CATEGORY],
        ];
        $courses = [];
        foreach ($cases as $name => [$categoryid, $effect]) {
            $course = $generator->create_course(['fullname' => $name, 'category' => $categoryid]);
            discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC);
            $courses[$name] = [(int) $course->id, $effect];
        }

        // The facts that hold them back, written after the courses so core hides nothing on its own.
        $this->set_visible('course', $courses['Course hidden'][0], 0);
        $this->set_visible('course_categories', (int) $hidden->id, 0);
        category_discoverability::set_state((int) $unlisted->id, category_discoverability::STATE_UNLISTED);
        $DB->set_field('course_categories', 'path', '/999999/' . $orphan->id, ['id' => $orphan->id]);
        $DB->set_field('course_categories', 'depth', 2, ['id' => $orphan->id]);
        $DB->set_field('course_categories', 'path', '', ['id' => $pathless->id]);
        $DB->set_field('course_categories', 'depth', 0, ['id' => $pathless->id]);

        category_discoverability::reset_caches();
        access::reset_caches();
        return $courses;
    }

    /**
     * The default report names the state, its effect, who changed it and when.
     *
     * @return void
     */
    public function test_the_default_report_lists_each_course_with_its_state_and_who_changed_it(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $category = $generator->create_category(['name' => 'Catalogue']);
        $listed = $generator->create_course(['fullname' => 'A listed', 'category' => $category->id]);
        $unlisted = $generator->create_course(['fullname' => 'B unlisted', 'category' => $category->id]);
        $public = $generator->create_course(['fullname' => 'C public', 'category' => $category->id]);
        discoverability::set_state((int) $unlisted->id, discoverability::STATE_UNLISTED);
        discoverability::set_state((int) $public->id, discoverability::STATE_PUBLIC);

        /** @var core_reportbuilder_generator $rbgenerator */
        $rbgenerator = $generator->get_plugin_generator('core_reportbuilder');
        $report = $rbgenerator->create_report(['name' => 'Discoverability', 'source' => courses::class, 'default' => 1]);
        $rows = array_map('array_values', $this->get_custom_report_content($report->get('id')));

        $this->assertCount(3, $rows);
        $admin = fullname(get_admin());
        // Category, course with link, state, effect, changed by, changed on; sorted by category then course.
        $this->assertSame('Catalogue', $rows[0][0]);
        $this->assertStringContainsString('A listed', $rows[0][1]);
        $this->assertSame(['Listed', 'Not public', '', ''], array_slice($rows[0], 2));
        $this->assertStringContainsString('B unlisted', $rows[1][1]);
        $this->assertSame(['Unlisted', 'Not public', $admin], array_slice($rows[1], 2, 3));
        $this->assertNotSame('', $rows[1][5]);
        $this->assertStringContainsString('C public', $rows[2][1]);
        $this->assertSame(['Public', 'In effect: served to visitors', $admin], array_slice($rows[2], 2, 3));
        $this->assertNotSame('', $rows[2][5]);
    }

    /**
     * The report lists the courses core's own Courses data source lists, whatever their state.
     *
     * @return void
     */
    public function test_the_population_is_the_one_of_cores_courses_data_source(): void {
        global $SITE;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $generator->create_course(['fullname' => 'Listed']);
        $unlisted = $generator->create_course(['fullname' => 'Unlisted']);
        discoverability::set_state((int) $unlisted->id, discoverability::STATE_UNLISTED);
        $generator->create_course(['fullname' => 'Hidden', 'visible' => 0]);

        $names = fn(array $rows): array => array_column($rows, 0);
        $own = $names($this->report_rows(['course:fullname']));
        $core = $names($this->report_rows(['course:fullname'], [], [], core_courses::class));
        sort($own);
        sort($core);

        $this->assertSame(['Hidden', 'Listed', 'Unlisted'], $own);
        $this->assertSame($core, $own);
        // The site course has no state: it is not a row, as it is not one in core's data source.
        $this->assertNotContains($SITE->fullname, $own);
    }

    /**
     * The effect column agrees with discoverability::is_public() for every way a public state is held back.
     *
     * @return void
     */
    public function test_the_effect_column_agrees_with_the_public_predicate(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $courses = $this->create_public_courses();
        $labels = public_effect::options(false);

        $rows = $this->report_rows(['course:fullname', 'course_state:state', 'course_state:effect']);
        $this->assertCount(count($courses), $rows);

        $effective = 0;
        foreach ($rows as [$name, $state, $effect]) {
            [$courseid, $expected] = $courses[$name];
            $this->assertSame('Public', $state, $name);
            $this->assertSame($labels[$expected], $effect, $name);
            $this->assertSame(
                discoverability::is_public($courseid),
                $effect === $labels[public_effect::EFFECTIVE],
                $name
            );
            $effective += (int) ($effect === $labels[public_effect::EFFECTIVE]);
        }
        // The control: the column says "in effect" for the two courses nothing holds back.
        $this->assertSame(2, $effective);
    }

    /**
     * A course whose state is not public has no effect to report, wherever it sits.
     *
     * @return void
     */
    public function test_a_state_that_is_not_public_has_no_effect_and_an_unknown_value_reads_as_listed(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $unlisted = $generator->create_course(['fullname' => 'Unlisted']);
        $unknown = $generator->create_course(['fullname' => 'Unknown']);
        $public = $generator->create_course(['fullname' => 'Public']);
        discoverability::set_state((int) $unlisted->id, discoverability::STATE_UNLISTED);
        discoverability::set_state((int) $public->id, discoverability::STATE_PUBLIC);
        $DB->insert_record(discoverability::TABLE, ['courseid' => $unknown->id, 'state' => 7]);

        $rows = $this->report_rows(['course:fullname', 'course_state:state', 'course_state:effect']);
        $byname = array_column($rows, null, 0);

        $this->assertSame(['Unlisted', 'Unlisted', 'Not public'], $byname['Unlisted']);
        $this->assertSame(['Unknown', 'Listed', 'Not public'], $byname['Unknown']);
        // The control: the public course of the same report reads as public and in effect.
        $this->assertSame(['Public', 'Public', 'In effect: served to visitors'], $byname['Public']);

        // The filter reads the same normalised value: the unknown row is found under listed.
        $listed = $this->report_rows(
            ['course:fullname'],
            ['course_state:state'],
            [
                'course_state:state_operator' => select::EQUAL_TO,
                'course_state:state_value' => discoverability::STATE_DEFAULT,
            ]
        );
        $this->assertSame(['Unknown'], array_column($listed, 0));
    }

    /**
     * The filters select by state and by what holds a public state back.
     *
     * @return void
     */
    public function test_the_filters_select_by_state_and_by_effect(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Created first: creating a course makes core repair the paths the next call breaks on purpose.
        $listed = $this->getDataGenerator()->create_course(['fullname' => 'Listed']);
        $this->create_public_courses();

        $filtered = function (string $filter, int $value): array {
            $names = array_column($this->report_rows(
                ['course:fullname'],
                ["course_state:{$filter}"],
                [
                    "course_state:{$filter}_operator" => select::EQUAL_TO,
                    "course_state:{$filter}_value" => $value,
                ]
            ), 0);
            sort($names);
            return $names;
        };

        $this->assertSame(['Listed'], $filtered('state', discoverability::STATE_DEFAULT));
        $this->assertCount(9, $filtered('state', discoverability::STATE_PUBLIC));
        $this->assertSame(
            ['Effective', 'Effective below the top level'],
            $filtered('effect', public_effect::EFFECTIVE)
        );
        $this->assertSame(['Course hidden'], $filtered('effect', public_effect::HIDDEN));
        $this->assertSame(
            ['Below unlisted category', 'In unlisted category'],
            $filtered('effect', public_effect::UNLISTED_CATEGORY)
        );
        $this->assertSame(
            ['Below hidden category', 'Category without a path', 'In hidden category', 'Missing ancestor'],
            $filtered('effect', public_effect::HIDDEN_CATEGORY)
        );
        $this->assertSame(['Listed'], $filtered('effect', public_effect::NONE));
        $this->assertNotEmpty($listed->id);
    }

    /**
     * The state of the course's category rides in the same report, and a detached user leaves the state.
     *
     * @return void
     */
    public function test_the_category_state_and_a_detached_user(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['fullname' => 'Inside', 'category' => $category->id]);
        $other = $generator->create_course(['fullname' => 'Outside']);
        discoverability::set_state((int) $course->id, discoverability::STATE_UNLISTED);
        category_discoverability::set_state((int) $category->id, category_discoverability::STATE_PUBLIC);

        $columns = [
            'course:fullname',
            'course_state:state',
            'course_state_user:fullname',
            'category_state:state',
            'category_state:effect',
        ];
        $byname = array_column($this->report_rows($columns), null, 0);
        $this->assertSame(
            ['Inside', 'Unlisted', fullname(get_admin()), 'Public', 'In effect: served to visitors'],
            $byname['Inside']
        );
        $this->assertSame(['Outside', 'Listed', '', 'Listed', 'Not public'], $byname['Outside']);

        // What a privacy deletion leaves: the state stays, nobody is named.
        $DB->set_field(discoverability::TABLE, 'usermodified', 0, ['courseid' => $course->id]);
        $byname = array_column($this->report_rows($columns), null, 0);
        $this->assertSame(['Inside', 'Unlisted', ''], array_slice($byname['Inside'], 0, 3));
        $this->assertNotEmpty($other->id);
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

        $course = $this->getDataGenerator()->create_course();
        discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC);

        $this->datasource_stress_test_columns(courses::class);
        $this->datasource_stress_test_columns_aggregation(courses::class);
        $this->datasource_stress_test_conditions(courses::class, 'course:idnumber');
    }
}
