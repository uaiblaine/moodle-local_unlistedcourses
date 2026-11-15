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
 * Course discoverability - Report builder data source of the course categories and their state
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\reportbuilder\datasource;

use core_course\reportbuilder\local\entities\course_category;
use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;
use lang_string;
use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\reportbuilder\local\entities\category_state;

/**
 * Every course category with its discoverability and who last changed it.
 *
 * Every row of {course_categories}, with no rule about the viewer: as in
 * {@see courses}, the report's audience decides who reads it.
 */
class categories extends datasource {
    /**
     * Return user friendly name of the data source.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:categories', 'local_unlistedcourses');
    }

    /**
     * Initialise the report.
     *
     * @return void
     */
    protected function initialise(): void {
        $categoryentity = new course_category();
        $categoryalias = $categoryentity->get_table_alias('course_categories');
        $this->set_main_table('course_categories', $categoryalias);
        $this->add_entity($categoryentity);

        $stateentity = (new category_state())
            ->set_table_alias('course_categories', $categoryalias);
        $statealias = $stateentity->get_table_alias(category_discoverability::TABLE);
        $statejoin = $stateentity->get_state_join();
        $this->add_entity($stateentity->add_join($statejoin));

        // The user who last changed it: nobody for a listed category, or once that user is detached.
        $userentity = (new user())
            ->set_entity_name('category_state_user')
            ->set_entity_title(new lang_string('entity:category_state_user', 'local_unlistedcourses'));
        $useralias = $userentity->get_table_alias('user');
        $this->add_entity($userentity->add_joins([
            $statejoin,
            "LEFT JOIN {user} {$useralias} ON {$useralias}.id = {$statealias}.usermodified",
        ]));

        $this->add_all_from_entities();
    }

    /**
     * Return the columns that will be added to the report once it is created.
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'course_category:path',
            'category_state:state',
            'category_state:effect',
            'category_state_user:fullname',
            'category_state:timemodified',
        ];
    }

    /**
     * Return the filters that will be added to the report once it is created.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'category_state:state',
            'category_state:effect',
            'course_category:text',
        ];
    }

    /**
     * Return the conditions that will be added to the report once it is created.
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [
            'category_state:state',
            'category_state:effect',
        ];
    }

    /**
     * Return the default sorting that will be added to the report once it is created.
     *
     * @return int[]
     */
    public function get_default_column_sorting(): array {
        return [
            'course_category:path' => SORT_ASC,
        ];
    }
}
