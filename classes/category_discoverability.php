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
 * Course category discoverability - The state of a category
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses;

use local_unlistedcourses\event\category_state_updated;

/**
 * The discoverability state of a course category: who may learn that it exists.
 *
 * Two states. LISTED, the default, is a category like any other. UNLISTED is
 * named only to people who are members of a cohort at that category, hold a
 * role in its context, or are staff - the predicate in category_access decides
 * that per viewer. There is no public state: nothing serves a category page to
 * a visitor who is not logged in, so the anonymous decision stays on the
 * course, in {@see discoverability::is_public()}. Only a category in a
 * non-default state has a row; absence means listed.
 *
 * THE STATE IS A PROPERTY OF THE PATH, AND THIS CLASS HOLDS THE ROWS ONLY. A
 * category is effectively unlisted when it or any ancestor carries the state;
 * that walk belongs to the predicate, which reads course_categories.path, so
 * one row unlists a whole subtree and clearing a child's own row cannot
 * un-hide it. Here a category's state is its own row and nothing else.
 *
 * THE CAPABILITY CHECK LIVES IN set_state() AND NOWHERE ELSE, mirroring the
 * rule the course state already follows. The editing page checks it too, as
 * a courtesy, so nobody is shown a form their save will refuse - but the page
 * is one writer and this is the boundary. Every real transition is gated, in
 * both directions: unlike the course side, where four writers already sit
 * behind moodle/course:update, this state has exactly one writer and the
 * gate costs nothing.
 *
 * A call that changes nothing needs no capability and fires no event.
 *
 * NOTHING HERE IS CACHED BEYOND THE REQUEST, for the reason the course state
 * gives: the answer feeds an access decision whose other half (cohort
 * membership, role assignments) is deliberately uncached.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class category_discoverability {
    /** @var int The default: the category is listed like any other. */
    public const STATE_DEFAULT = 0;

    /** @var int Named only to cohort members at the category, role holders in its context, or staff. */
    public const STATE_UNLISTED = 1;

    /** @var string The table holding the non-default states. */
    public const TABLE = 'local_unlistedcourses_catstate';

    /** @var string The capability that gates every change of a category's state. */
    public const CAPABILITY_MANAGE = 'local/unlistedcourses:managecategorystate';

    /** @var array Request cache of the state, keyed categoryid => int. */
    private static array $states = [];

    /** @var int[]|null Request cache of the ids of every unlisted category, null until asked. */
    private static ?array $unlistedids = null;

    /**
     * The valid states.
     *
     * @return array List of the state constants.
     */
    public static function states(): array {
        return [self::STATE_DEFAULT, self::STATE_UNLISTED];
    }

    /**
     * Forget everything cached for this request.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$states = [];
        self::$unlistedids = null;
    }

    /**
     * The state of each of these categories, in the order given.
     *
     * One query for every id not already known in this request. A category
     * without a row is listed. A row holding a value outside the known states
     * reads as listed too: for a category the safe failure is not blanking the
     * site's tree over a corrupt row.
     *
     * @param array $categoryids Category ids.
     * @return array Map of categoryid => state constant, in the order given.
     */
    public static function get_states(array $categoryids): array {
        global $DB;

        $categoryids = array_values(array_unique(array_map('intval', $categoryids)));

        $unknown = [];
        foreach ($categoryids as $categoryid) {
            if (!array_key_exists($categoryid, self::$states)) {
                $unknown[] = $categoryid;
            }
        }
        if ($unknown) {
            $rows = $DB->get_records_list(self::TABLE, 'categoryid', $unknown, '', 'categoryid, state');
            foreach ($unknown as $categoryid) {
                $state = isset($rows[$categoryid]) ? (int) $rows[$categoryid]->state : self::STATE_DEFAULT;
                $state = in_array($state, self::states(), true) ? $state : self::STATE_DEFAULT;
                self::$states[$categoryid] = $state;
            }
        }

        $states = [];
        foreach ($categoryids as $categoryid) {
            $states[$categoryid] = self::$states[$categoryid];
        }
        return $states;
    }

    /**
     * The state of one category: its own row, not its ancestors'.
     *
     * @param int $categoryid The category id.
     * @return int One of the state constants.
     */
    public static function get_state(int $categoryid): int {
        $states = self::get_states([$categoryid]);
        return $states[$categoryid];
    }

    /**
     * Whether the category's own row says unlisted.
     *
     * This is the row only: an ancestor's state is not consulted here, and
     * whether the CURRENT user may still discover an unlisted category is the
     * predicate's question, not this one.
     *
     * @param int $categoryid The category id.
     * @return bool True when the category itself is in the unlisted state.
     */
    public static function is_unlisted(int $categoryid): bool {
        return self::get_state($categoryid) === self::STATE_UNLISTED;
    }

    /**
     * The ids of every category whose own row says unlisted.
     *
     * One query per request, memoised. This is the empty-set fast path of the
     * whole feature: when it returns nothing, no category is unlisted and the
     * predicate answers "discoverable" with no further query. Sorted, so two
     * calls in one request and two requests agree on the order.
     *
     * @return int[] Category ids, ascending.
     */
    public static function unlisted_ids(): array {
        global $DB;

        if (self::$unlistedids === null) {
            $ids = $DB->get_fieldset_select(self::TABLE, 'categoryid', 'state = :state', ['state' => self::STATE_UNLISTED]);
            $ids = array_map('intval', $ids);
            sort($ids);
            self::$unlistedids = $ids;
        }
        return self::$unlistedids;
    }

    /**
     * Set the state of a category.
     *
     * The one place the manage capability is checked - see the class docblock
     * for why it is here and not on the page. A call that does not change the
     * state returns without checking anything and fires nothing. Every change
     * fires {@see category_state_updated}.
     *
     * @param int $categoryid The category id. Not the top level, which has no row to hold a state.
     * @param int $state One of the state constants.
     * @param int|null $userid The user performing the change, null for the current user.
     * @return void
     * @throws \coding_exception On an unknown state or the top level.
     * @throws \required_capability_exception When the user may not manage the state of this category.
     */
    public static function set_state(int $categoryid, int $state, ?int $userid = null): void {
        global $DB, $USER;

        if (!in_array($state, self::states(), true)) {
            throw new \coding_exception("Unknown category discoverability state '{$state}'.");
        }
        if ($categoryid <= 0) {
            throw new \coding_exception('The top level has no discoverability state.');
        }
        // MUST_EXIST: an unknown category is refused before anything is read or written.
        $context = \core\context\coursecat::instance($categoryid);
        $userid = $userid ?? (int) $USER->id;

        $row = $DB->get_record(self::TABLE, ['categoryid' => $categoryid]);
        $current = $row ? (int) $row->state : self::STATE_DEFAULT;
        $current = in_array($current, self::states(), true) ? $current : self::STATE_DEFAULT;
        if ($current === $state) {
            self::$states[$categoryid] = $state;
            return;
        }

        require_capability(self::CAPABILITY_MANAGE, $context, $userid);

        if ($state === self::STATE_DEFAULT) {
            $DB->delete_records(self::TABLE, ['categoryid' => $categoryid]);
        } else if ($row) {
            $row->state = $state;
            $row->usermodified = $userid;
            $row->timemodified = time();
            $DB->update_record(self::TABLE, $row);
        } else {
            $DB->insert_record(self::TABLE, (object) [
                'categoryid' => $categoryid,
                'state' => $state,
                'usermodified' => $userid,
                'timemodified' => time(),
            ]);
        }

        /* Every answer composed from this state is stale now: the category answers, and
           the course answers that take the category into account. */
        self::reset_caches();
        access::reset_caches();
        self::$states[$categoryid] = $state;

        category_state_updated::create([
            'context' => $context,
            'objectid' => $categoryid,
            'userid' => $userid,
            'other' => ['oldstate' => $current, 'newstate' => $state],
        ])->trigger();
    }

    /**
     * Drop the state of a category that is being deleted.
     *
     * Called from the pre_course_category_delete and
     * pre_course_category_delete_move plugin callbacks in lib.php, which core
     * invokes before the row disappears. No capability and no event: the
     * category is going away, and core's own deletion is the audited act.
     * A deleted category's descendants are deleted or moved by core one by
     * one, each through the same callback, so a subtree is cleaned by repeated
     * invocation and nothing here walks it.
     *
     * @param int $categoryid The category id.
     * @return void
     */
    public static function on_category_deleted(int $categoryid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['categoryid' => $categoryid]);
        self::reset_caches();
        access::reset_caches();
    }
}
