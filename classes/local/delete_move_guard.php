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
 * Course discoverability - A category deleted with its contents moved publishes nothing
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\local;

use local_unlistedcourses\access;
use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\discoverability;
use local_unlistedcourses\event\public_state_kept_after_move;

/**
 * Keeps core_course_category::delete_move() from making public what was not public before it.
 *
 * A course or category whose own row says public is not public while an unlisted (or hidden)
 * category is on its path: {@see discoverability::are_public()} and
 * {@see category_discoverability::are_public()} compose the row with the path. Deleting that
 * category with its contents moved under another parent changes the path, and a public row
 * that was inert starts to apply - a course reachable anonymously that nobody holding the
 * publish capability published at that moment. A deletion-with-move publishes nothing: what
 * the move made effective goes back to listed.
 *
 * Two steps, because core can still abort after the pre-delete callback (delete_move() returns
 * false when it cannot move the courses, and a later observer or statement can throw):
 *
 * 1. {@see record()}, from local_unlistedcourses_pre_course_category_delete_move(), only reads:
 *    which public rows in the deleted category's subtree are inert now. It writes nothing, so a
 *    deletion that does not finish leaves every state as it was.
 * 2. {@see settle()}, from the course_category_deleted observer once the category is gone and
 *    the plugin's request caches are reset, asks the same question of the recorded ids again. Each one that is
 *    public now was made public by the move, and goes back to listed through the state classes'
 *    own write, so the usual event fires; a row the move left inert, and a row that was
 *    effective before the deletion, is not touched.
 *
 * Only deletion with a move. A plain move of a course or a category, and any other change of a
 * path, is not observed: the plugin guards the state row, not the tree.
 *
 * The record is per-request static state and lives from the callback to the event, both inside
 * the request that deletes the category, so a fresh node or a fresh cron container has nothing
 * to rebuild and nothing node-local outlives the deletion. A record whose deletion core aborted
 * is never settled; an event for the same id without a move (delete_full()) drops it unread.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class delete_move_guard {
    /** @var array Map of deleted categoryid => ['courses' => int[], 'categories' => int[]], the inert public rows below it. */
    private static array $inert = [];

    /**
     * Record which public rows below a category about to be deleted with its contents moved are inert now.
     *
     * Two statements over the subtree, by {course_categories}.path, plus the public predicates'
     * own reads for what they return. The category's own row is not recorded: it goes with it.
     *
     * The predicates answer from request memos, which earlier work in the same request can have
     * left answering for a path that has changed since (one core_course_delete_categories call
     * deletes several categories, and a delete_move() that aborted after moving children resets
     * nothing). A row such a memo still calls public would be dropped here as effective and never
     * demoted, so the memos are reset first.
     *
     * @param \core_course_category $category The category core is about to delete.
     * @return void
     */
    public static function record(\core_course_category $category): void {
        global $DB;

        access::reset_caches();
        // Only core's pseudo-category top() has no path; its bound LIKE would be '/%', every category of the site.
        if ((string) $category->path === '') {
            return;
        }

        $categoryid = (int) $category->id;
        $below = $DB->sql_like('cc.path', ':below');
        $params = [
            'public' => discoverability::STATE_PUBLIC,
            'below' => $category->path . '/%',
        ];

        $courseids = $DB->get_fieldset_sql(
            "SELECT s.courseid
               FROM {" . discoverability::TABLE . "} s
               JOIN {course} c ON c.id = s.courseid
               JOIN {course_categories} cc ON cc.id = c.category
              WHERE s.state = :public
                AND (cc.id = :categoryid OR {$below})",
            $params + ['categoryid' => $categoryid]
        );
        $categoryids = $DB->get_fieldset_sql(
            "SELECT s.categoryid
               FROM {" . category_discoverability::TABLE . "} s
               JOIN {course_categories} cc ON cc.id = s.categoryid
              WHERE s.state = :public
                AND {$below}",
            ['public' => category_discoverability::STATE_PUBLIC, 'below' => $params['below']]
        );

        self::$inert[$categoryid] = [
            'courses' => self::inert(discoverability::are_public(array_map('intval', $courseids))),
            'categories' => self::inert(category_discoverability::are_public(array_map('intval', $categoryids))),
        ];
    }

    /**
     * Take back to listed every recorded row the deletion of this category made effective.
     *
     * Called after the category's own row is dropped and the plugin's request caches are reset, so the
     * public predicates answer for the paths as they are now. Both answers are read before the
     * first write: no write here changes another recorded answer (an ancestor's public row is no
     * term of a descendant's), so the order of the writes does not matter.
     *
     * A row whose lock another writer holds past the timeout is skipped: it stays public, and the
     * skip is logged on every site as {@see public_state_kept_after_move}, naming the row and the
     * deleted category, and reported through debugging() too where developer debugging is on.
     * Nothing guarantees that the lock holder is taking it out of the public state, and nothing
     * retries: the holder may be a publisher deciding that very row.
     *
     * @param int $categoryid The deleted category.
     * @param bool $moved Whether core moved its contents; a deletion without a move settles nothing.
     * @param int $userid The user who deleted it, named in the events.
     * @return void
     */
    public static function settle(int $categoryid, bool $moved, int $userid): void {
        $recorded = self::$inert[$categoryid] ?? null;
        unset(self::$inert[$categoryid]);
        if (!$moved || !$recorded) {
            return;
        }

        $courses = self::activated(discoverability::are_public($recorded['courses']));
        $categories = self::activated(category_discoverability::are_public($recorded['categories']));
        foreach ($courses as $courseid) {
            try {
                discoverability::unpublish_after_move($courseid, $userid);
            } catch (state_busy_exception) {
                debugging(
                    "local_unlistedcourses: course {$courseid} was skipped after category {$categoryid} was deleted with its "
                        . 'contents moved, because another writer held its lock; it kept its public state.',
                    DEBUG_NORMAL
                );
                self::report_kept(public_state_kept_after_move::KIND_COURSE, $courseid, $categoryid, $userid);
            }
        }
        foreach ($categories as $id) {
            try {
                category_discoverability::unpublish_after_move($id, $userid);
            } catch (state_busy_exception) {
                debugging(
                    "local_unlistedcourses: category {$id} was skipped after category {$categoryid} was deleted with its "
                        . 'contents moved, because another writer held its lock; it kept its public state.',
                    DEBUG_NORMAL
                );
                self::report_kept(public_state_kept_after_move::KIND_CATEGORY, $id, $categoryid, $userid);
            }
        }
    }

    /**
     * Log a public row a busy lock kept public, in the site log every site keeps.
     *
     * A course or category whose context is gone has been deleted since, and its state row with it:
     * nothing was kept, and nothing is logged.
     *
     * @param string $kind {@see public_state_kept_after_move::KIND_COURSE} or KIND_CATEGORY.
     * @param int $id The course or category that kept its public state.
     * @param int $categoryid The deleted category.
     * @param int $userid The user who deleted it.
     * @return void
     */
    private static function report_kept(string $kind, int $id, int $categoryid, int $userid): void {
        $context = $kind === public_state_kept_after_move::KIND_COURSE
            ? \core\context\course::instance($id, IGNORE_MISSING)
            : \core\context\coursecat::instance($id, IGNORE_MISSING);
        if (!$context) {
            return;
        }
        public_state_kept_after_move::create([
            'context' => $context,
            'userid' => $userid,
            'other' => ['deletedcategoryid' => $categoryid, 'kind' => $kind],
        ])->trigger();
    }

    /**
     * The ids a public predicate answered false for.
     *
     * @param array $answers Map of id => bool.
     * @return int[] The ids.
     */
    private static function inert(array $answers): array {
        return array_keys(array_filter($answers, fn(bool $public): bool => !$public));
    }

    /**
     * The ids a public predicate answered true for.
     *
     * @param array $answers Map of id => bool.
     * @return int[] The ids.
     */
    private static function activated(array $answers): array {
        return array_keys(array_filter($answers));
    }
}
