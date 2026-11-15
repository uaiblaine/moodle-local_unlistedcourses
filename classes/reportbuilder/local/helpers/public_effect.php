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
 * Course discoverability - SQL of the report columns that read a state and what a public state amounts to
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\reportbuilder\local\helpers;

use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\discoverability;

/**
 * The SQL behind the report builder columns of this plugin.
 *
 * A custom report filters, sorts and aggregates in the database, so the two
 * answers a manager monitors are expressions here and not PHP: the state a row
 * holds, and whether a public state takes effect or is held back by the course
 * or category row or by a category above it.
 *
 * The second one mirrors {@see discoverability::are_public()} for a course and
 * {@see category_discoverability::are_public()} for a category, edge case for
 * edge case: the row itself must be visible, no category on the path may be
 * unlisted, and every category on the path must exist and be visible. "Every
 * one exists" is read as "as many visible rows match the path as the category
 * is deep", so a path naming a missing category, and a path that is empty,
 * fail closed as they do there. The reports' tests hold the expression to those
 * two methods; bring it in step with them before touching either.
 *
 * Only integer constants of this plugin and aliases the report builder
 * generated are interpolated, and no parameter is used, because a column's SQL
 * is repeated in the GROUP BY and ORDER BY of an aggregated report.
 */
final class public_effect {
    /** @var int The state is not public, so there is nothing to take effect. */
    public const NONE = 0;

    /** @var int The public state takes effect: the page is served to visitors. */
    public const EFFECTIVE = 1;

    /** @var int Held back by the row itself: the course, or the category, is hidden. */
    public const HIDDEN = 2;

    /** @var int Held back by an unlisted category on the path. */
    public const UNLISTED_CATEGORY = 3;

    /** @var int Held back by a hidden, or missing, category on the path. */
    public const HIDDEN_CATEGORY = 4;

    /**
     * The state a row of one of the two state tables holds, read as the plugin reads it.
     *
     * No row is the default, and a value that is not a known state reads as
     * listed and never as public, the rule of {@see discoverability::get_states()}.
     *
     * @param string $statealias The alias of the LEFT JOINed state table.
     * @return string The SQL expression.
     */
    public static function state_sql(string $statealias): string {
        $known = discoverability::STATE_UNLISTED . ', ' . discoverability::STATE_PUBLIC;
        return "CASE WHEN {$statealias}.state IN ({$known}) THEN {$statealias}.state ELSE "
            . discoverability::STATE_DEFAULT . " END";
    }

    /**
     * What a course's public state amounts to, as one of the constants of this class.
     *
     * @param string $statealias The alias of the LEFT JOINed {local_unlistedcourses_state}.
     * @param string $coursealias The alias of {course}.
     * @param string $categoryalias The alias of the course's own row of {course_categories}.
     * @return string The SQL expression.
     */
    public static function course_sql(string $statealias, string $coursealias, string $categoryalias): string {
        return self::effect_sql(self::state_sql($statealias), "{$coursealias}.visible", $categoryalias);
    }

    /**
     * What a category's public state amounts to, as one of the constants of this class.
     *
     * The category's own row is on its path, so one expression answers for it
     * and for the categories above it. Its own state cannot be public and
     * unlisted at once, which is why the unlisted test need not leave it out.
     *
     * @param string $statealias The alias of the LEFT JOINed {local_unlistedcourses_catstate}.
     * @param string $categoryalias The alias of {course_categories}.
     * @return string The SQL expression.
     */
    public static function category_sql(string $statealias, string $categoryalias): string {
        return self::effect_sql(self::state_sql($statealias), "{$categoryalias}.visible", $categoryalias);
    }

    /**
     * The labels of the effect constants.
     *
     * @param bool $category True for the wording of a category, false for a course.
     * @return array Map of constant => label.
     */
    public static function options(bool $category): array {
        return [
            self::NONE => get_string('publiceffect_none', 'local_unlistedcourses'),
            self::EFFECTIVE => get_string('publiceffect_effective', 'local_unlistedcourses'),
            self::HIDDEN => $category
                ? get_string('publiceffect_hiddenitself_category', 'local_unlistedcourses')
                : get_string('publiceffect_hiddenitself_course', 'local_unlistedcourses'),
            self::UNLISTED_CATEGORY => get_string('publiceffect_unlistedcategory', 'local_unlistedcourses'),
            self::HIDDEN_CATEGORY => get_string('publiceffect_hiddencategory', 'local_unlistedcourses'),
        ];
    }

    /**
     * The labels of the three states.
     *
     * @return array Map of state constant => label.
     */
    public static function state_options(): array {
        return [
            discoverability::STATE_DEFAULT => get_string('state_default', 'local_unlistedcourses'),
            discoverability::STATE_UNLISTED => get_string('state_unlisted', 'local_unlistedcourses'),
            discoverability::STATE_PUBLIC => get_string('state_public', 'local_unlistedcourses'),
        ];
    }

    /**
     * The shared composition: the state, the row's own visibility, then the category path.
     *
     * The order of the branches decides only which reason a row held back twice
     * reports; EFFECTIVE is reached when none of them refuses.
     *
     * @param string $statesql The normalised state expression.
     * @param string $visiblesql The visibility column of the row the state belongs to.
     * @param string $categoryalias The alias of the {course_categories} row whose path is read.
     * @return string The SQL expression.
     */
    private static function effect_sql(string $statesql, string $visiblesql, string $categoryalias): string {
        global $DB;

        // Aliases of the two subqueries, unique per expression: a report may carry both columns.
        static $count = 0;
        $count++;
        $unlisted = "lucu{$count}";
        $ancestor = "luca{$count}";

        // The stored path is "/1/2/3": closed with a slash, every id on it is found as "/id/".
        $path = $DB->sql_concat("{$categoryalias}.path", "'/'");
        // A plain LIKE between two expressions: sql_like() takes a bound pattern, and digits need no escape.
        $onpath = fn(string $idsql): string => $path . ' LIKE '
            . $DB->sql_concat("'%/'", $DB->sql_cast_to_char($idsql), "'/%'");

        $unlistedonpath = "EXISTS (
            SELECT 1
              FROM {" . category_discoverability::TABLE . "} {$unlisted}
             WHERE {$unlisted}.state = " . category_discoverability::STATE_UNLISTED . "
               AND " . $onpath("{$unlisted}.categoryid") . ")";

        $pathvisible = "({$categoryalias}.depth >= 1 AND {$categoryalias}.depth = (
            SELECT COUNT(1)
              FROM {course_categories} {$ancestor}
             WHERE {$ancestor}.visible = 1
               AND " . $onpath("{$ancestor}.id") . "))";

        return "CASE
            WHEN {$statesql} <> " . discoverability::STATE_PUBLIC . " THEN " . self::NONE . "
            WHEN {$visiblesql} = 0 THEN " . self::HIDDEN . "
            WHEN {$unlistedonpath} THEN " . self::UNLISTED_CATEGORY . "
            WHEN NOT {$pathvisible} THEN " . self::HIDDEN_CATEGORY . "
            ELSE " . self::EFFECTIVE . "
        END";
    }
}
