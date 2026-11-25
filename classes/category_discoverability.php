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
 * this state has one writer and the gate costs nothing. Leaving the unlisted
 * state asks more: every course and category below that is stored public and
 * held back only by this category goes public with that write, so each of them
 * needs the capability that publishes it, at its own context
 * ({@see require_relist_allowed()}); the manage capability alone lists and
 * unlists freely only what makes nothing public. One exception:
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
            foreach (self::compose($unknown, null) as $categoryid => $answer) {
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
     * The public answer for each of these categories, read from the database, memoising nothing.
     *
     * The composition {@see are_public()} documents, for ids it does not hold yet. With a category
     * named in $relisted, that one category is read as if it were not unlisted, and nothing else
     * changes: this is how {@see relist_activations()} predicts what leaving the unlisted state
     * makes public, with the very rule that will answer once the state is written, not a copy of it.
     *
     * @param array $categoryids Category ids, deduplicated ints.
     * @param int|null $relisted A category to read as not unlisted, null for the categories as they are.
     * @return array Map of categoryid => bool, for every id given.
     */
    private static function compose(array $categoryids, ?int $relisted): array {
        global $DB;

        // One statement for the state of every id not already known in this request.
        $states = self::get_states($categoryids);
        $answers = [];
        $candidates = [];
        foreach ($categoryids as $categoryid) {
            if ($states[$categoryid] !== self::STATE_PUBLIC) {
                $answers[$categoryid] = false;
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
                && self::path_admits($categoryid, (string) $row->path, $visible, $relisted);
            $answers[$categoryid] = $answer;
        }
        return $answers;
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
     * @param int|null $relisted An ancestor to read as not unlisted, null for none ({@see compose()}).
     * @return bool True when nothing above the category refuses it.
     */
    private static function path_admits(int $categoryid, string $path, array $visible, ?int $relisted): bool {
        $ancestors = array_values(array_diff(self::path_ids($path), [$categoryid]));

        // Category id 0 is on no path, so without a relisted category nothing is taken out.
        if (array_intersect(array_diff($ancestors, [$relisted ?? 0]), self::unlisted_ids())) {
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
     * The capability boundary: the manage capability on every change, the
     * publish capability as well on a change that enters or leaves the public
     * state - see the class docblock - and, on a change that leaves the unlisted
     * state, the capability that publishes each row it would make public
     * ({@see require_relist_allowed()}). A call that does not change the state
     * returns without checking anything, without a lock, and fires nothing. A
     * change is read again, decided and written under the category's lock
     * ({@see local\state_lock}), so two writers never decide on the same stale
     * row; a change to any state but unlisted is also made under the one site-wide
     * lock ({@see local\state_lock::category_relist()}), taken first, so two
     * predictions over nested categories never interleave. Every change fires
     * {@see category_state_updated}.
     *
     * @param int $categoryid The category id. Not the top level, which has no row to hold a state.
     * @param int $state One of the state constants.
     * @param int|null $userid The user performing the change, null for the current user.
     * @return void
     * @throws \coding_exception On an unknown state or the top level.
     * @throws \required_capability_exception When the user may not manage the state of this category, when the
     *   change enters or leaves the public state and the user may not publish categories here, or when the change
     *   leaves the unlisted state and the user may not publish a course or category it would make public.
     * @throws \local_unlistedcourses\local\state_busy_exception When another writer of this category holds its lock, or
     *   another change to a state other than unlisted holds the site-wide one, past the timeout; nothing is written.
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

        /* A change to anything but unlisted may leave the unlisted state, whose decision reads
           other categories' rows: every such change runs one at a time under the site-wide lock,
           taken first, so a second prediction reads the first one's write. A change to unlisted
           makes nothing public and takes only the row lock. */
        $sharedlock = $state === self::STATE_UNLISTED ? null : state_lock::category_relist();
        try {
            $lock = state_lock::category($categoryid);
        } catch (\Throwable $e) {
            $sharedlock?->release();
            throw $e;
        }
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

            /* Leaving the unlisted state is a third decision when it makes public what this
               category held back: each such row goes public by this write, so each asks the
               capability that publishes it, at its own context. The prediction reads the rows
               again rather than a memo taken earlier in the request, which may predate them. */
            if ($current === self::STATE_UNLISTED) {
                self::reset_caches();
                discoverability::reset_caches();
                self::require_relist_allowed($categoryid, $userid);
            }

            self::write($categoryid, $state, $current, $row, $userid, $context);
        } finally {
            // Reverse order of acquisition: the row lock, then the shared one.
            $lock->release();
            $sharedlock?->release();
        }
    }

    /**
     * The public courses and categories below this one that taking it out of the unlisted state would make public.
     *
     * Every course and category below it (a course in it too) whose own row is stored, asked of
     * the public composition with this one category read as not unlisted and everything else as
     * it is: {@see discoverability::would_be_public()} and {@see compose()}. A row still held back
     * by something else - hidden itself, under a hidden category, under another unlisted category
     * above or below this one, unlisted or listed itself - is not named. While this category is
     * unlisted nothing below it is public, because it is on every one of their paths, so the
     * rows named are exactly those the transition turns from not public into public; the state
     * the category leaves for, listed or public, makes no difference to them.
     *
     * The answer is about the rows, not about a viewer: the preview counts it for everybody, and
     * {@see may_relist()} and {@see require_relist_allowed()} decide it for one user. Asked of a
     * category whose own row is not unlisted, it names nothing.
     *
     * The same statements whatever the number of rows: the path, one statement per kind of row
     * with their contexts preloaded, so asking a capability of each of them reads no context, and
     * the two compositions' own reads.
     *
     * @param int $categoryid The category id.
     * @return array ['courses' => int[], 'categories' => int[]], each ascending.
     */
    public static function relist_activations(int $categoryid): array {
        global $DB;

        $none = ['courses' => [], 'categories' => []];
        if (self::get_state($categoryid) !== self::STATE_UNLISTED) {
            return $none;
        }
        $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);
        if ($path === false) {
            return $none;
        }

        /* Every stored row below, public or not: the composition decides which of them is public,
           so the prediction is its answer and not a second copy of its state term. LEFT JOIN on
           the context, so a row whose context is missing is still asked about. */
        $ctxfields = \core\context_helper::get_preload_record_columns_sql('ctx');
        $below = $DB->sql_like('cc.path', ':below');
        $courses = $DB->get_records_sql(
            "SELECT s.courseid, {$ctxfields}
               FROM {" . discoverability::TABLE . "} s
               JOIN {course} c ON c.id = s.courseid
               JOIN {course_categories} cc ON cc.id = c.category
          LEFT JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :courselevel
              WHERE cc.id = :categoryid OR {$below}",
            ['courselevel' => CONTEXT_COURSE, 'categoryid' => $categoryid, 'below' => $path . '/%']
        );
        $categories = $DB->get_records_sql(
            "SELECT s.categoryid, {$ctxfields}
               FROM {" . self::TABLE . "} s
               JOIN {course_categories} cc ON cc.id = s.categoryid
          LEFT JOIN {context} ctx ON ctx.instanceid = cc.id AND ctx.contextlevel = :categorylevel
              WHERE {$below}",
            ['categorylevel' => CONTEXT_COURSECAT, 'below' => $path . '/%']
        );
        foreach (array_merge(array_values($courses), array_values($categories)) as $record) {
            \core\context_helper::preload_from_record($record);
        }

        $courseids = array_map('intval', array_keys($courses));
        $categoryids = array_map('intval', array_keys($categories));
        return [
            'courses' => self::true_ids(discoverability::would_be_public($courseids, $categoryid)),
            'categories' => self::true_ids($categoryids ? self::compose($categoryids, $categoryid) : []),
        ];
    }

    /**
     * Whether a user may take this category out of the unlisted state, as far as what it would make public goes.
     *
     * The non-throwing twin of {@see require_relist_allowed()}, for the form and its preview, which
     * mirror the decision set_state() takes. Only the rows the transition makes public are asked
     * about here; the manage capability, and the publish one when the category itself enters the
     * public state, are set_state()'s own checks and are not repeated.
     *
     * @param int $categoryid The category id.
     * @param int $userid The user.
     * @param array|null $activations What {@see relist_activations()} returned for this category, when the caller
     *   holds it already; null to read it.
     * @return bool True when the user may publish every row that would become public, or there is none.
     */
    public static function may_relist(int $categoryid, int $userid, ?array $activations = null): bool {
        return self::relist_refusal($activations ?? self::relist_activations($categoryid), $userid) === null;
    }

    /**
     * Refuse a user who may not publish everything that taking this category out of the unlisted state makes public.
     *
     * The rule set_state() applies on every transition out of the unlisted state, before anything
     * is written: each course {@see relist_activations()} names needs
     * {@see discoverability::CAPABILITY_PUBLISH} in that course's own context, and each category
     * it names needs {@see CAPABILITY_PUBLISH} in that category's own context. The capability is
     * asked of the row that goes public, not of this category: a role here reaches the rows below
     * through the context tree as any role does, and an override at a row is respected.
     *
     * @param int $categoryid The category id.
     * @param int $userid The user.
     * @return void
     * @throws \required_capability_exception Naming the first row the user may not publish, and its capability.
     */
    public static function require_relist_allowed(int $categoryid, int $userid): void {
        $refusal = self::relist_refusal(self::relist_activations($categoryid), $userid);
        if ($refusal !== null) {
            [$capability, $context] = $refusal;
            throw new \required_capability_exception($context, $capability, 'nopermissions', '');
        }
    }

    /**
     * The first row of a relist prediction the user may not publish, with the capability it needs.
     *
     * @param array $activations What {@see relist_activations()} returned.
     * @param int $userid The user.
     * @return array|null [capability, context] of the first refused row, null when every row may be published.
     */
    private static function relist_refusal(array $activations, int $userid): ?array {
        foreach ($activations['courses'] as $courseid) {
            $context = \core\context\course::instance($courseid);
            if (!has_capability(discoverability::CAPABILITY_PUBLISH, $context, $userid)) {
                return [discoverability::CAPABILITY_PUBLISH, $context];
            }
        }
        foreach ($activations['categories'] as $id) {
            $context = \core\context\coursecat::instance($id);
            if (!has_capability(self::CAPABILITY_PUBLISH, $context, $userid)) {
                return [self::CAPABILITY_PUBLISH, $context];
            }
        }
        return null;
    }

    /**
     * The ids a public composition answered true for, ascending.
     *
     * @param array $answers Map of id => bool.
     * @return int[] The ids.
     */
    private static function true_ids(array $answers): array {
        $ids = array_map('intval', array_keys(array_filter($answers)));
        sort($ids);
        return $ids;
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
     * Internal: no other caller may use it, inside this plugin or out of it.
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
