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
use local_unlistedcourses\local\id_chunks;
use local_unlistedcourses\local\state_lock;

/**
 * The discoverability state of a course category: who may learn that it exists.
 *
 * Three states. LISTED, the default, is a category like any other. UNLISTED is
 * named only to people who are members of a cohort at that category, hold a
 * role in its context, or are staff - the predicate in category_access decides
 * that per viewer. PUBLIC is the opposite direction: the category's page may be
 * served to visitors who are not logged in, and inside it only the courses whose
 * own state is public are served to them. Only a category in a non-default state
 * has a row; absence means listed.
 *
 * The public predicate lives here, not in {@see category_access}, as on the
 * course side. Everything in category_access is an answer about a viewer - a
 * cohort membership, a role assignment, a capability - and a category that is
 * public is public for nobody in particular: its answer is composed from the
 * stored state and from rows of the category tree, with no capability involved,
 * exactly as {@see discoverability::is_public()} composes the course one.
 * Putting it beside the per-viewer predicate would invite a viewer term into an
 * answer that must not have one.
 *
 * The composition rule is the course side's. A category is effectively public
 * when its own row says public, its own row is visible, every category on its
 * path exists and is visible, and no category on that path is unlisted.
 * Ancestors need not be public: a strict chain would force a site to publish its
 * whole root to publish one programme area. An unlisted ancestor refuses it
 * because an anonymous visitor can satisfy none of the three terms that open an
 * unlisted category, so there is nobody the page could be served to.
 *
 * This class holds the rows only. A category is effectively unlisted when it
 * or any ancestor carries the state; that walk belongs to the predicate, which
 * reads course_categories.path, so one row unlists a whole subtree and clearing
 * a child's own row cannot un-hide it. Here a category's state is its own row
 * and nothing else.
 *
 * set_state() is the capability boundary, as on the course side. category.php
 * checks the manage capability too, only so that nobody is shown a form their
 * save will refuse. Every real transition is gated, in both directions: unlike
 * the course side, where four writers already sit behind moodle/course:update,
 * this state has one writer and the gate costs nothing. One exception:
 * unpublish_after_move() takes a public row back to listed with no capability asked, a
 * restriction reachable only from {@see local\delete_move_guard}.
 *
 * A call that changes nothing needs no capability and fires no event.
 *
 * Nothing here is cached beyond the request, for the reason the course state
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

    /** @var int The category's page may be served to visitors who are not logged in. */
    public const STATE_PUBLIC = 2;

    /** @var string The table holding the non-default states. */
    public const TABLE = 'local_unlistedcourses_catstate';

    /** @var string The capability that gates every change of a category's state. */
    public const CAPABILITY_MANAGE = 'local/unlistedcourses:managecategorystate';

    /** @var string The capability that gates entering and leaving the public state. */
    public const CAPABILITY_PUBLISH = 'local/unlistedcourses:publishcategory';

    /** @var array Request cache of the state, keyed categoryid => int. */
    private static array $states = [];

    /** @var int[]|null Request cache of the ids of every unlisted category, null until asked. */
    private static ?array $unlistedids = null;

    /** @var int[]|null Request cache of the ids of every public category, null until asked. */
    private static ?array $publicids = null;

    /** @var array Request cache of the effective public answer, keyed categoryid => bool. */
    private static array $public = [];

    /**
     * The valid states.
     *
     * @return array List of the state constants.
     */
    public static function states(): array {
        return [self::STATE_DEFAULT, self::STATE_UNLISTED, self::STATE_PUBLIC];
    }

    /**
     * Forget everything cached for this request.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$states = [];
        self::$unlistedids = null;
        self::$publicids = null;
        self::$public = [];
    }

    /**
     * The state of each of these categories, in the order given.
     *
     * One query for every id not already known in this request, and one per
     * {@see id_chunks::SIZE} of them beyond that. A category without a row is
     * listed. A row holding a value outside the known states
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
            $rows = id_chunks::read(
                $unknown,
                fn(array $chunk): array => $DB->get_records_list(self::TABLE, 'categoryid', $chunk, '', 'categoryid, state')
            );
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
     * whether the current user may still discover an unlisted category is
     * {@see category_access::is_category_discoverable()}.
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
     * category predicate: when it returns nothing, no category is unlisted and
     * the predicate answers "discoverable" with no further query. Sorted, so
     * two calls in one request and two requests agree on the order.
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
     * The ids of every category whose own row says public.
     *
     * One query per request, memoised and sorted, the twin of
     * {@see unlisted_ids()}. This is the own state of each category and nothing
     * else: whether one of them is effectively public is {@see is_public()}.
     *
     * @return int[] Category ids, ascending.
     */
    public static function public_ids(): array {
        global $DB;

        if (self::$publicids === null) {
            $ids = $DB->get_fieldset_select(self::TABLE, 'categoryid', 'state = :state', ['state' => self::STATE_PUBLIC]);
            $ids = array_map('intval', $ids);
            sort($ids);
            self::$publicids = $ids;
        }
        return self::$publicids;
    }

    /**
     * Whether the category's page may be served to a visitor who is not logged in.
     *
     * Independent of the viewer, and fail-closed: see the class docblock for
     * the composition rule and for why the answer lives here.
     *
     * @param int $categoryid The category id.
     * @return bool True when the category may be served anonymously.
     */
    public static function is_public(int $categoryid): bool {
        $answers = self::are_public([$categoryid]);
        return $answers[$categoryid] ?? false;
    }

    /**
     * Whether each of these categories may be served to a visitor who is not logged in.
     *
     * Four statements for a list of up to {@see id_chunks::SIZE} ids, and every
     * one of them bounded by the ids the caller asked about or by the paths those
     * ids carry: the state of the ids, the own row of the candidates, the
     * visibility of their ancestors, and the memoised unlisted ids. Nothing is
     * read per category, which is what lets a listing ask about a whole page at
     * once; a longer list pays each of the first three once per chunk of that
     * size rather than failing on the driver's parameter limit.
     *
     * Non-throwing for an id that is not there. The callers are the anonymous
     * surface, where the id came from a visitor, so a missing category is an
     * answer - false - and never an exception: every read is a plain one, no
     * MUST_EXIST anywhere.
     *
     * Memoised per category for the request, with no viewer in the key, because
     * the answer has no viewer in it.
     *
     * @param array $categoryids Category ids.
     * @return array Map of categoryid => bool, deduplicated, in the order given.
     */
    public static function are_public(array $categoryids): array {
        global $DB;

        $categoryids = array_values(array_unique(array_map('intval', $categoryids)));
        $answers = [];
        $unknown = [];
        foreach ($categoryids as $categoryid) {
            if (array_key_exists($categoryid, self::$public)) {
                $answers[$categoryid] = self::$public[$categoryid];
            } else {
                $unknown[] = $categoryid;
            }
        }

        if ($unknown) {
            // One statement for the state of every id not already known in this request.
            $states = self::get_states($unknown);
            $candidates = [];
            foreach ($unknown as $categoryid) {
                if ($states[$categoryid] !== self::STATE_PUBLIC) {
                    $answers[$categoryid] = self::remember($categoryid, false);
                } else {
                    $candidates[] = $categoryid;
                }
            }

            /* One statement for the candidates' own rows, and one for the visibility of
               every ancestor they name between them. */
            $rows = $candidates
                ? id_chunks::read(
                    $candidates,
                    fn(array $chunk): array => $DB->get_records_list('course_categories', 'id', $chunk, '', 'id, path, visible')
                )
                : [];
            $visible = self::ancestor_visibility($candidates, $rows);

            foreach ($candidates as $categoryid) {
                $row = $rows[$categoryid] ?? null;
                /* Three terms, kept apart because each is a rule of its own: the category
                   is really there, its own row is visible, and the path above it admits it. */
                $answer = $row !== null && (bool) $row->visible
                    && self::path_admits($categoryid, (string) $row->path, $visible);
                $answers[$categoryid] = self::remember($categoryid, $answer);
            }
        }

        $ordered = [];
        foreach ($categoryids as $categoryid) {
            $ordered[$categoryid] = $answers[$categoryid];
        }
        return $ordered;
    }

    /**
     * Memoise one public answer for the rest of the request, and hand it back.
     *
     * @param int $categoryid The category id.
     * @param bool $answer The answer.
     * @return bool The same answer.
     */
    private static function remember(int $categoryid, bool $answer): bool {
        self::$public[$categoryid] = $answer;
        return $answer;
    }

    /**
     * The visibility of every ancestor named by these categories' paths, in one statement.
     *
     * The IN list is bounded by the paths of the ids the caller asked about: a
     * category tree is shallow, so it is about as long as the list of ids, and
     * chunked like it past {@see id_chunks::SIZE}.
     *
     * @param array $categoryids The categories being asked about.
     * @param array $rows Their own rows, keyed by id, as read from course_categories.
     * @return array Map of categoryid => record carrying visible, missing for an id with no row.
     */
    private static function ancestor_visibility(array $categoryids, array $rows): array {
        global $DB;

        $ancestors = [];
        foreach ($categoryids as $categoryid) {
            if (!isset($rows[$categoryid])) {
                continue;
            }
            foreach (self::path_ids((string) $rows[$categoryid]->path) as $pathid) {
                if ($pathid !== $categoryid) {
                    $ancestors[$pathid] = true;
                }
            }
        }
        if (!$ancestors) {
            return [];
        }
        return id_chunks::read(
            array_keys($ancestors),
            fn(array $chunk): array => $DB->get_records_list('course_categories', 'id', $chunk, '', 'id, visible')
        );
    }

    /**
     * Whether the categories above this one let it be public.
     *
     * Every ancestor must exist and be visible, and none of them may be
     * unlisted. The category's own row is not read here - its visibility is a
     * term of its own in {@see are_public()}, and its own state cannot be
     * unlisted and public at once.
     *
     * @param int $categoryid The category the path belongs to.
     * @param string $path Its stored path.
     * @param array $visible Map of categoryid => record carrying visible, as read for the ancestors.
     * @return bool True when nothing above the category refuses it.
     */
    private static function path_admits(int $categoryid, string $path, array $visible): bool {
        $ancestors = array_values(array_diff(self::path_ids($path), [$categoryid]));

        if (array_intersect($ancestors, self::unlisted_ids())) {
            return false;
        }
        foreach ($ancestors as $ancestorid) {
            if (empty($visible[$ancestorid]) || !$visible[$ancestorid]->visible) {
                return false;
            }
        }
        return true;
    }

    /**
     * The category ids on a stored path, ancestor first.
     *
     * @param string $path The stored path, in its "/1/2/3" spelling.
     * @return int[] The ids as ints.
     */
    private static function path_ids(string $path): array {
        return array_values(array_map('intval', array_filter(explode('/', $path))));
    }

    /**
     * Set the state of a category.
     *
     * The capability boundary: the manage capability on every change, and the
     * publish capability as well on a change that enters or leaves the public
     * state - see the class docblock. A call that does not change the state
     * returns without checking anything, without a lock, and fires nothing. A
     * change is read again, decided and written under the category's lock
     * ({@see local\state_lock}), so two writers never decide on the same stale
     * row. Every change fires {@see category_state_updated}.
     *
     * @param int $categoryid The category id. Not the top level, which has no row to hold a state.
     * @param int $state One of the state constants.
     * @param int|null $userid The user performing the change, null for the current user.
     * @return void
     * @throws \coding_exception On an unknown state or the top level.
     * @throws \required_capability_exception When the user may not manage the state of this category, or when the
     *   change enters or leaves the public state and the user may not publish categories here.
     * @throws \local_unlistedcourses\local\state_busy_exception When another writer of this category holds its lock past
     *   the timeout; nothing is written.
     */
    public static function set_state(int $categoryid, int $state, ?int $userid = null): void {
        global $USER;

        if (!in_array($state, self::states(), true)) {
            throw new \coding_exception("Unknown category discoverability state '{$state}'.");
        }
        if ($categoryid <= 0) {
            throw new \coding_exception('The top level has no discoverability state.');
        }
        // MUST_EXIST: an unknown category is refused before anything is read or written.
        $context = \core\context\coursecat::instance($categoryid);
        $userid = $userid ?? (int) $USER->id;

        // Lock-free first: a save that changes nothing takes no lock, as it checks no capability.
        [, $current] = self::read_row($categoryid);
        if ($current === $state) {
            self::$states[$categoryid] = $state;
            return;
        }

        $lock = state_lock::category($categoryid);
        try {
            // Read again under the lock: both capability decisions and the write are about this row.
            [$row, $current] = self::read_row($categoryid);
            if ($current === $state) {
                self::$states[$categoryid] = $state;
                return;
            }

            require_capability(self::CAPABILITY_MANAGE, $context, $userid);

            /* Entering or leaving the public state is a second decision on top of the first,
               and it carries its own capability: publishing a category to the internet is not
               an editing act, and un-publishing one is that same decision reversed. */
            if ($current === self::STATE_PUBLIC || $state === self::STATE_PUBLIC) {
                require_capability(self::CAPABILITY_PUBLISH, $context, $userid);
            }

            self::write($categoryid, $state, $current, $row, $userid, $context);
        } finally {
            $lock->release();
        }
    }

    /**
     * Take back to listed a public category that a category deletion has just made effective.
     *
     * For {@see local\delete_move_guard} alone, which calls it when core deleted a category with
     * its contents moved and the move, not a decision of somebody holding
     * local/unlistedcourses:publishcategory, is what lets this category be served anonymously:
     * it was public in its own row and inert before the deletion, and is effective after it. No
     * capability is asked because nothing is published here: the category goes from public to
     * listed, a restriction. Core's delete_move() checks no capability itself (the check is
     * can_move_content_to(), which the management page and the web service call before it): the
     * callers of core's deletion have passed core's own check, and this method asks nothing. It
     * cannot be used for any other transition.
     *
     * The row is read again under the category's lock, as {@see set_state()} reads it, and a
     * category that is no longer public there is left alone. The write is set_state()'s own, so
     * {@see category_state_updated} fires and every request cache is reset.
     *
     * @param int $categoryid The category id.
     * @param int $userid The user who deleted the category above it, named in the event.
     * @return void
     * @throws \local_unlistedcourses\local\state_busy_exception When another writer of this category holds its lock past
     *   the timeout; nothing is written.
     */
    public static function unpublish_after_move(int $categoryid, int $userid): void {
        $context = \core\context\coursecat::instance($categoryid, IGNORE_MISSING);
        if (!$context) {
            return;
        }

        $lock = state_lock::category($categoryid);
        try {
            [$row, $current] = self::read_row($categoryid);
            if ($current !== self::STATE_PUBLIC) {
                return;
            }
            self::write($categoryid, self::STATE_DEFAULT, $current, $row, $userid, $context);
        } finally {
            $lock->release();
        }
    }

    /**
     * Write a decided change of state, reset the caches and fire the event.
     *
     * The caller holds the category's lock and has decided the change on the row it read under it.
     *
     * @param int $categoryid The category id.
     * @param int $state The new state.
     * @param int $current The state the row held, read under the lock.
     * @param \stdClass|false $row The row read under the lock, false when there was none.
     * @param int $userid The user performing the change.
     * @param \core\context\coursecat $context The category context.
     * @return void
     */
    private static function write(
        int $categoryid,
        int $state,
        int $current,
        \stdClass|false $row,
        int $userid,
        \core\context\coursecat $context
    ): void {
        global $DB;

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
        category_access::reset_caches();
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
     * The stored row of a category and the state it holds, an unknown value read as listed.
     *
     * @param int $categoryid The category id.
     * @return array The row or false, then the state constant.
     */
    private static function read_row(int $categoryid): array {
        global $DB;

        $row = $DB->get_record(self::TABLE, ['categoryid' => $categoryid]);
        $current = $row ? (int) $row->state : self::STATE_DEFAULT;
        $current = in_array($current, self::states(), true) ? $current : self::STATE_DEFAULT;
        return [$row, $current];
    }

    /**
     * Drop the state of a category core has deleted.
     *
     * Called from the course_category_deleted observer,
     * {@see observer::course_category_deleted()}, which says why it is not the
     * pre-delete callbacks. No capability and no event: the category is gone,
     * and core's own deletion is the audited act. Nothing here walks the
     * subtree: core_course_category::delete_full() deletes each descendant
     * through its own delete_full() call, which fires the event again, and
     * delete_move() moves the children, which keep their state unless the move
     * made a public one effective ({@see local\delete_move_guard}).
     *
     * @param int $categoryid The category id.
     * @return void
     */
    public static function on_category_deleted(int $categoryid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['categoryid' => $categoryid]);
        self::reset_caches();
        category_access::reset_caches();
        access::reset_caches();
    }
}
