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
 * Course discoverability - Report builder entity of a course's state
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\reportbuilder\local\entities;

use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use lang_string;
use local_unlistedcourses\discoverability;
use local_unlistedcourses\reportbuilder\local\helpers\public_effect;

/**
 * The discoverability of a course, for custom reports.
 *
 * The state a course holds, what its public state amounts to and when the
 * state last changed. The entity reads; it applies no rule of {@see \local_unlistedcourses\access}:
 * a report lists the courses its data source selects to whoever is in its audience.
 *
 * A report using it joins {course}, the course's own {course_categories} row
 * and, with {@see get_state_join()}, the state table, and hands the first two
 * aliases over with set_table_alias(). The user who last changed the state is
 * the core user entity, joined on usermodified by the data source.
 */
class course_state extends base {
    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return [
            discoverability::TABLE,
            'course',
            'course_categories',
        ];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entity:course_state', 'local_unlistedcourses');
    }

    /**
     * Initialise the entity: every filter is a condition too.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_all_filters() as $filter) {
            $this
                ->add_filter($filter)
                ->add_condition($filter);
        }
        return $this;
    }

    /**
     * The state table the time column and filter read.
     *
     * @return string
     */
    protected function get_state_table(): string {
        return discoverability::TABLE;
    }

    /**
     * The LEFT JOIN of the state table on the course, for the report using this entity.
     *
     * @return string
     */
    public function get_state_join(): string {
        return discoverability::state_sql(
            $this->get_table_alias('course'),
            $this->get_table_alias(discoverability::TABLE)
        )['join'];
    }

    /**
     * The SQL of the state column.
     *
     * @return string
     */
    protected function get_state_sql(): string {
        return public_effect::state_sql($this->get_table_alias(discoverability::TABLE));
    }

    /**
     * The SQL of the public effect column.
     *
     * @return string
     */
    protected function get_effect_sql(): string {
        return public_effect::course_sql(
            $this->get_table_alias(discoverability::TABLE),
            $this->get_table_alias('course'),
            $this->get_table_alias('course_categories')
        );
    }

    /**
     * The labels of the public effect column.
     *
     * @return array Map of {@see public_effect} constant => label.
     */
    protected function get_effect_options(): array {
        return public_effect::options(false);
    }

    /**
     * The columns of this entity.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $statealias = $this->get_table_alias($this->get_state_table());
        $effectoptions = fn(): array => $this->get_effect_options();

        $columns[] = (new column(
            'state',
            new lang_string('state', 'local_unlistedcourses'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field($this->get_state_sql(), 'state')
            ->set_is_sortable(true)
            ->add_callback(static function ($state): string {
                $options = public_effect::state_options();
                // An aggregated column hands a count or a list over, not a state.
                return $options[$state] ?? (string) $state;
            });

        $columns[] = (new column(
            'effect',
            new lang_string('publiceffect', 'local_unlistedcourses'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field($this->get_effect_sql(), 'effect')
            ->set_is_sortable(true)
            ->add_callback(static function ($effect) use ($effectoptions): string {
                return $effectoptions()[$effect] ?? (string) $effect;
            });

        $columns[] = (new column(
            'timemodified',
            new lang_string('statetimemodified', 'local_unlistedcourses'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$statealias}.timemodified")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        return $columns;
    }

    /**
     * The filters of this entity, each of them offered as a condition as well.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $statealias = $this->get_table_alias($this->get_state_table());

        $filters[] = (new filter(
            select::class,
            'state',
            new lang_string('state', 'local_unlistedcourses'),
            $this->get_entity_name(),
            $this->get_state_sql()
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback([public_effect::class, 'state_options']);

        $filters[] = (new filter(
            select::class,
            'effect',
            new lang_string('publiceffect', 'local_unlistedcourses'),
            $this->get_entity_name(),
            $this->get_effect_sql()
        ))
            ->add_joins($this->get_joins())
            ->set_options_callback(fn(): array => $this->get_effect_options());

        $filters[] = (new filter(
            date::class,
            'timemodified',
            new lang_string('statetimemodified', 'local_unlistedcourses'),
            $this->get_entity_name(),
            "{$statealias}.timemodified"
        ))
            ->add_joins($this->get_joins());

        return $filters;
    }
}
