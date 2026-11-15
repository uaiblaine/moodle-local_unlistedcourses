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
 * Course discoverability - Report builder entity of a course category's state
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\reportbuilder\local\entities;

use lang_string;
use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\reportbuilder\local\helpers\public_effect;

/**
 * The discoverability of a course category, for custom reports.
 *
 * The same three columns and filters as {@see course_state}, read from the
 * category's table. A report using it joins {course_categories} and, with
 * {@see get_state_join()}, the state table, and hands the first alias over
 * with set_table_alias().
 */
class category_state extends course_state {
    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            category_discoverability::TABLE,
            'course_categories',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity:category_state', 'local_unlistedcourses');
    }

    /**
     * The state table the time column and filter read.
     *
     * @return string
     */
    protected function get_state_table(): string {
        return category_discoverability::TABLE;
    }

    /**
     * The LEFT JOIN of the state table on the category, for the report using this entity.
     *
     * @return string
     */
    public function get_state_join(): string {
        $statealias = $this->get_table_alias(category_discoverability::TABLE);
        $categoryalias = $this->get_table_alias('course_categories');
        return "LEFT JOIN {" . category_discoverability::TABLE . "} {$statealias}
                       ON {$statealias}.categoryid = {$categoryalias}.id";
    }

    /**
     * The SQL of the state column.
     *
     * @return string
     */
    protected function get_state_sql(): string {
        return public_effect::state_sql($this->get_table_alias(category_discoverability::TABLE));
    }

    /**
     * The SQL of the public effect column.
     *
     * @return string
     */
    protected function get_effect_sql(): string {
        return public_effect::category_sql(
            $this->get_table_alias(category_discoverability::TABLE),
            $this->get_table_alias('course_categories')
        );
    }

    /**
     * The labels of the public effect column.
     *
     * @return array Map of {@see public_effect} constant => label.
     */
    protected function get_effect_options(): array {
        return public_effect::options(true);
    }
}
