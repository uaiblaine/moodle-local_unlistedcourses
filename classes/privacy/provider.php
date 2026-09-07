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
 * Privacy provider
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\discoverability;

/**
 * Privacy provider.
 *
 * The two state tables record WHO last changed a course's or a category's
 * state, which is the only personal data the plugin holds. The state itself
 * is course or category configuration, not the user's data, so a deletion
 * request detaches the user from the row (usermodified becomes 0) and leaves
 * the state in place - removing the row would un-hide or un-publish a course,
 * or un-hide a category, as a side effect of somebody leaving the site.
 *
 * Each table is keyed to one context level - courses to the course context,
 * categories to the category context - and every method resolves the table
 * from the context it is handed, so a request in one level never reaches the
 * other level's rows.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the data stored.
     *
     * @param collection $collection The collection to add to.
     * @return collection The same collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(discoverability::TABLE, [
            'courseid' => 'privacy:metadata:local_unlistedcourses_state:courseid',
            'state' => 'privacy:metadata:local_unlistedcourses_state:state',
            'usermodified' => 'privacy:metadata:local_unlistedcourses_state:usermodified',
            'timemodified' => 'privacy:metadata:local_unlistedcourses_state:timemodified',
        ], 'privacy:metadata:local_unlistedcourses_state');
        $collection->add_database_table(category_discoverability::TABLE, [
            'categoryid' => 'privacy:metadata:local_unlistedcourses_catstate:categoryid',
            'state' => 'privacy:metadata:local_unlistedcourses_catstate:state',
            'usermodified' => 'privacy:metadata:local_unlistedcourses_catstate:usermodified',
            'timemodified' => 'privacy:metadata:local_unlistedcourses_catstate:timemodified',
        ], 'privacy:metadata:local_unlistedcourses_catstate');
        return $collection;
    }

    /**
     * The course and category contexts whose state this user last changed.
     *
     * @param int $userid The user id.
     * @return contextlist The contexts.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_unlistedcourses_state} s ON s.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :courselevel
                   AND s.usermodified = :userid";
        $contextlist->add_from_sql($sql, ['courselevel' => CONTEXT_COURSE, 'userid' => $userid]);
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_unlistedcourses_catstate} s ON s.categoryid = ctx.instanceid
                 WHERE ctx.contextlevel = :categorylevel
                   AND s.usermodified = :userid";
        $contextlist->add_from_sql($sql, ['categorylevel' => CONTEXT_COURSECAT, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * The users who last changed the state in this context.
     *
     * @param userlist $userlist The userlist to add to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        $table = self::table_for($context);
        if (!$table) {
            return;
        }
        [$name, $column] = $table;
        $sql = "SELECT usermodified
                  FROM {{$name}}
                 WHERE {$column} = :instanceid
                   AND usermodified > 0";
        $userlist->add_from_sql('usermodified', $sql, ['instanceid' => $context->instanceid]);
    }

    /**
     * Export the state rows this user last changed.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $table = self::table_for($context);
            if (!$table) {
                continue;
            }
            [$name, $column] = $table;
            $row = $DB->get_record($name, [$column => $context->instanceid, 'usermodified' => $userid]);
            if (!$row) {
                continue;
            }
            $label = $context->contextlevel == CONTEXT_COURSECAT
                ? self::category_state_label((int) $row->state)
                : self::state_label((int) $row->state);
            writer::with_context($context)->export_data([get_string('pluginname', 'local_unlistedcourses')], (object) [
                'state' => $label,
                'timemodified' => transform::datetime($row->timemodified),
            ]);
        }
    }

    /**
     * Detach every user from the state of this context.
     *
     * @param \context $context The context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        $table = self::table_for($context);
        if (!$table) {
            return;
        }
        [$name, $column] = $table;
        $DB->set_field($name, 'usermodified', 0, [$column => $context->instanceid]);
    }

    /**
     * Detach this user from the state of the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $table = self::table_for($context);
            if (!$table) {
                continue;
            }
            [$name, $column] = $table;
            $DB->set_field($name, 'usermodified', 0, [
                $column => $context->instanceid,
                'usermodified' => $userid,
            ]);
        }
    }

    /**
     * Detach the approved users from the state of this context.
     *
     * @param approved_userlist $userlist The approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        $table = self::table_for($context);
        if (!$table || !$userids) {
            return;
        }
        [$name, $column] = $table;
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['instanceid'] = $context->instanceid;
        $DB->set_field_select($name, 'usermodified', 0, "{$column} = :instanceid AND usermodified $insql", $params);
    }

    /**
     * The table and instance column that hold the state for a context, or null when it holds none.
     *
     * @param \context $context The context.
     * @return array|null Two strings, table name and instance-id column, or null.
     */
    private static function table_for(\context $context): ?array {
        switch ($context->contextlevel) {
            case CONTEXT_COURSE:
                return [discoverability::TABLE, 'courseid'];
            case CONTEXT_COURSECAT:
                return [category_discoverability::TABLE, 'categoryid'];
            default:
                return null;
        }
    }

    /**
     * The human-readable name of a course state, for the export.
     *
     * @param int $state One of the course state constants.
     * @return string The localised label.
     */
    private static function state_label(int $state): string {
        switch ($state) {
            case discoverability::STATE_UNLISTED:
                return get_string('state_unlisted', 'local_unlistedcourses');
            case discoverability::STATE_PUBLIC:
                return get_string('state_public', 'local_unlistedcourses');
            default:
                return get_string('state_default', 'local_unlistedcourses');
        }
    }

    /**
     * The human-readable name of a category state, for the export.
     *
     * @param int $state One of the category state constants.
     * @return string The localised label.
     */
    private static function category_state_label(int $state): string {
        if ($state === category_discoverability::STATE_UNLISTED) {
            return get_string('state_unlisted', 'local_unlistedcourses');
        }
        return get_string('state_default', 'local_unlistedcourses');
    }
}
