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
 * Course discoverability - Privacy provider
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
use local_unlistedcourses\discoverability;

/**
 * Privacy provider.
 *
 * The state table records WHO last changed a course's state, which is the
 * only personal data the plugin holds. The state itself is course
 * configuration, not the user's data, so a deletion request detaches the
 * user from the row (usermodified becomes 0) and leaves the state in place -
 * removing the row would un-hide or un-publish a course as a side effect of
 * somebody leaving the site.
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
        return $collection;
    }

    /**
     * The course contexts whose state this user last changed.
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
        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $sql = "SELECT usermodified
                  FROM {local_unlistedcourses_state}
                 WHERE courseid = :courseid
                   AND usermodified > 0";
        $userlist->add_from_sql('usermodified', $sql, ['courseid' => $context->instanceid]);
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
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $row = $DB->get_record(discoverability::TABLE, ['courseid' => $context->instanceid, 'usermodified' => $userid]);
            if (!$row) {
                continue;
            }
            writer::with_context($context)->export_data([get_string('pluginname', 'local_unlistedcourses')], (object) [
                'state' => self::state_label((int) $row->state),
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

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }
        $DB->set_field(discoverability::TABLE, 'usermodified', 0, ['courseid' => $context->instanceid]);
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
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }
            $DB->set_field(discoverability::TABLE, 'usermodified', 0, [
                'courseid' => $context->instanceid,
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
        if ($context->contextlevel != CONTEXT_COURSE || !$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['courseid'] = $context->instanceid;
        $DB->set_field_select(discoverability::TABLE, 'usermodified', 0, "courseid = :courseid AND usermodified $insql", $params);
    }

    /**
     * The human-readable name of a state, for the export.
     *
     * @param int $state One of the state constants.
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
}
