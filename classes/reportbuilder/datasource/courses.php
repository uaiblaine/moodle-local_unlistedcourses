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
 * Course discoverability - Report builder data source of the courses and their state
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\reportbuilder\datasource;

use core_course\reportbuilder\local\entities\course_category;
use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\course;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\helpers\database;
use lang_string;
use local_unlistedcourses\discoverability;
use local_unlistedcourses\reportbuilder\local\entities\category_state;
use local_unlistedcourses\reportbuilder\local\entities\course_state;

/**
 * Every course with its discoverability, the state of its category and who last changed it.
 *
 * The population is the one of core's own Courses data source
 * ({@see \core_course\reportbuilder\datasource\courses}): every course but the
 * site course, joined to its category, with no rule about the viewer. Like
 * there, who reads a report is decided by its audience, so unlisted courses
 * are listed to that audience by design: the report is how a manager follows
 * them.
 */
class courses extends datasource {
    /**
     * Return user friendly name of the data source.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:courses', 'local_unlistedcourses');
    }

    /**
     * Initialise the report.
     *
     * @return void
     */
    protected function initialise(): void {
        $courseentity = new course();
        $coursealias = $courseentity->get_table_alias('course');

        // The site course has no state and is no course of a listing, as in core's data source.
        $paramsiteid = database::generate_param_name();
        $this->set_main_table('course', $coursealias);
        $this->add_base_condition_sql("{$coursealias}.id != :{$paramsiteid}", [$paramsiteid => SITEID]);
        $this->add_entity($courseentity);

        $categoryentity = new course_category();
        $categoryalias = $categoryentity->get_table_alias('course_categories');
        $categoryjoin = "JOIN {course_categories} {$categoryalias}
                           ON {$categoryalias}.id = {$coursealias}.category";
        $this->add_entity($categoryentity->add_join($categoryjoin));

        // The course's own state, read against its row and its category's path.
        $stateentity = (new course_state())
            ->set_table_alias('course', $coursealias)
            ->set_table_alias('course_categories', $categoryalias);
        $statealias = $stateentity->get_table_alias(discoverability::TABLE);
        $statejoin = $stateentity->get_state_join();
        $this->add_entity($stateentity->add_joins([$categoryjoin, $statejoin]));

        // The user who last changed it: nobody for a listed course, or once that user is detached.
        $userentity = (new user())
            ->set_entity_name('course_state_user')
            ->set_entity_title(new lang_string('entity:course_state_user', 'local_unlistedcourses'));
        $useralias = $userentity->get_table_alias('user');
        $this->add_entity($userentity->add_joins([
            $statejoin,
            "LEFT JOIN {user} {$useralias} ON {$useralias}.id = {$statealias}.usermodified",
        ]));

        // The state of the category the course sits in.
        $categorystateentity = (new category_state())
            ->set_table_alias('course_categories', $categoryalias);
        $this->add_entity($categorystateentity->add_joins([$categoryjoin, $categorystateentity->get_state_join()]));

        $this->add_all_from_entities();
    }

    /**
     * Return the columns that will be added to the report once it is created.
     *
     * @return string[]
     */
    public function get_default_columns(): array {
        return [
            'course_category:name',
            'course:coursefullnamewithlink',
            'course_state:state',
            'course_state:effect',
            'course_state_user:fullname',
            'course_state:timemodified',
        ];
    }

    /**
     * Return the filters that will be added to the report once it is created.
     *
     * @return string[]
     */
    public function get_default_filters(): array {
        return [
            'course_state:state',
            'course_state:effect',
            'course:fullname',
        ];
    }

    /**
     * Return the conditions that will be added to the report once it is created.
     *
     * @return string[]
     */
    public function get_default_conditions(): array {
        return [
            'course_state:state',
            'course_state:effect',
        ];
    }

    /**
     * Return the default sorting that will be added to the report once it is created.
     *
     * @return int[]
     */
    public function get_default_column_sorting(): array {
        return [
            'course_category:name' => SORT_ASC,
            'course:coursefullnamewithlink' => SORT_ASC,
        ];
    }
}
