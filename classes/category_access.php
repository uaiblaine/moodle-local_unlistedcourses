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
 * Course category discoverability - Whether the current user may discover a category
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses;

/**
 * Decides whether the current user may discover a course category.
 *
 * A category in the UNLISTED state ({@see category_discoverability}) is named
 * only to somebody who has a relationship with that category. Everyone else
 * must not learn that it exists.
 *
 * THE STATE IS A PROPERTY OF THE PATH. A category is effectively unlisted when
 * it or any ancestor carries the state, so one row withholds a whole subtree
 * and clearing a child's own row cannot un-hide it. The quantifier over those
 * ancestors is AND, not OR: for EVERY effectively-unlisted category on the
 * path the viewer must satisfy one of the three terms below. Two nested
 * unlisted categories therefore need eligibility for both - the inner one is
 * not a way out of the outer one.
 *
 * The three terms, evaluated per unlisted category U on the path:
 *
 * - COHORT. The viewer belongs to a cohort whose context IS U's own context.
 *   Not an ancestor's, not the system one: a cohort at the system context is
 *   how most sites hold their entire student body, and treating it as a
 *   relationship with one category would admit everybody. Read straight from
 *   {cohort_members}, never through cohort_get_user_cohorts(), which filters
 *   visible = 1 - an invisible cohort is a normal way to run an automatic
 *   membership rule, and it still grants.
 * - ROLE. The viewer holds any role assignment in U's context or in an
 *   ancestor CATEGORY context of U. A role at a category BELOW U does not
 *   count, and neither does one at a course inside it: both are relationships
 *   with something U contains, not with U. System-context assignments are not
 *   read at all - a role held site-wide says nothing about one category, and
 *   the site-wide people it is meant to admit arrive through the escape below.
 * - STAFF. has_capability('moodle/category:viewhiddencategories', U). Core's
 *   own idiom for "may see a category that is hidden", held by manager and
 *   coursecreator by archetype - which is what admits a manager assigned at
 *   the system context, and site admins with it.
 *
 * ROLE SWITCHING DOES NOT CHANGE A CATEGORY ANSWER, and that is a choice
 * rather than an accident. The role term is a raw read of {role_assignments},
 * which no switch touches, and has_capability() at a category context ignores
 * a switch made at a course context because the switch applies only within the
 * path it was made in. So a manager who switches to student inside a course
 * keeps seeing the category tree they administer, which is what the switch is
 * for: it previews a COURSE, not the site.
 *
 * CURRENT USER ONLY, like {@see access}, and NOTHING IS CACHED BEYOND THE
 * REQUEST for the reason that class gives: tool_dynamic_cohorts writes
 * cohort_members in bulk without firing cohort_member_added/removed, so a
 * cache invalidated by those events would keep showing a category to somebody
 * who has just been removed from the cohort that gated it. The request memos
 * are keyed by the viewer as well as the category, because setUser() and
 * "log in as" change the answer inside one request.
 *
 * THE BUDGET IS FOUR STATEMENTS PER REQUEST, whatever the size of the listing:
 * one for the unlisted ids, one for the paths the caller did not already
 * carry, one for the viewer's cohorts and one for the viewer's category roles.
 * When nothing is unlisted it is one, and the same one for a site admin and
 * for a visitor. Context instances are resolved on top of that, from
 * accesslib's own cache.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class category_access {
    /** @var array Request cache of the answer, keyed "userid:categoryid" => bool. */
    private static array $discoverable = [];

    /** @var array Request cache of the unlisted categories the viewer holds a cohort at, keyed userid. */
    private static array $cohortcats = [];

    /** @var array Request cache of the categories the viewer holds any role in, keyed userid. */
    private static array $rolecats = [];

    /**
     * Forget everything cached for this request, the category state included.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$discoverable = [];
        self::$cohortcats = [];
        self::$rolecats = [];
        category_discoverability::reset_caches();
    }

    /**
     * Whether the current user may discover this category.
     *
     * @param int $categoryid The category id.
     * @return bool True when the category may be named to this user.
     */
    public static function is_category_discoverable(int $categoryid): bool {
        $answers = self::are_categories_discoverable([$categoryid]);
        return $answers[$categoryid] ?? true;
    }

    /**
     * Whether the current user may discover each of these categories.
     *
     * @param array $categoryids Category ids.
     * @return array Map of categoryid => bool, deduplicated, in the order given.
     */
    public static function are_categories_discoverable(array $categoryids): array {
        $categoryids = array_values(array_unique(array_map('intval', $categoryids)));

        $paths = [];
        foreach ($categoryids as $categoryid) {
            $paths[$categoryid] = null;
        }
        return self::answers($paths);
    }

    /**
     * Keep only the categories the current user may discover.
     *
     * Accepts core_course_category objects, course_categories records and bare
     * ids in the same array, and preserves the incoming keys so a caller can
     * keep paginating with them. An item that carries its own path costs
     * nothing to resolve, which is why the two shapes core hands around - the
     * category object and the record - are read for it before the fallback
     * query runs.
     *
     * @param array $categories Category objects, records or ids, any keys.
     * @return array The same array minus the categories this user must not discover.
     */
    public static function filter_categories(array $categories): array {
        if (!$categories) {
            return $categories;
        }

        $paths = [];
        foreach ($categories as $category) {
            $categoryid = self::item_id($category);
            $path = self::item_path($category);
            if (!array_key_exists($categoryid, $paths) || $paths[$categoryid] === null) {
                $paths[$categoryid] = $path;
            }
        }
        $answers = self::answers($paths);

        $kept = [];
        foreach ($categories as $key => $category) {
            if ($answers[self::item_id($category)] ?? true) {
                $kept[$key] = $category;
            }
        }
        return $kept;
    }

    /**
     * The answer for each category, given whatever paths the caller already holds.
     *
     * @param array $paths Map of categoryid => stored path, the value null wherever the caller did not have it.
     * @return array Map of categoryid => bool, in the order given.
     */
    private static function answers(array $paths): array {
        global $USER;

        if (!$paths) {
            return [];
        }

        /* The fast path of the whole feature, and the only reason it is affordable
           to ask this on every listing: with no category unlisted there is nothing
           to withhold, and no path, cohort or role is read at all. */
        $unlisted = category_discoverability::unlisted_ids();
        if (!$unlisted) {
            return array_fill_keys(array_keys($paths), true);
        }

        // A site admin discovers everything, and asking accesslib costs no query.
        if (is_siteadmin()) {
            return array_fill_keys(array_keys($paths), true);
        }

        $viewer = (int) $USER->id;
        $answers = [];
        $unresolved = [];
        foreach ($paths as $categoryid => $path) {
            $key = self::memo_key($viewer, $categoryid);
            if (array_key_exists($key, self::$discoverable)) {
                $answers[$categoryid] = self::$discoverable[$key];
            } else {
                $answers[$categoryid] = true;
                $unresolved[$categoryid] = $path;
            }
        }
        if (!$unresolved) {
            return $answers;
        }

        /* Fail closed for visitors and guests, and do it before any membership is
           read: neither can hold a cohort membership or a role that means anything
           here, so the two queries below would be spent to reach the same answer -
           and on the odd site where a guest HAS been swept into a cohort, the
           refusal is still the right one. */
        $visitor = !isloggedin() || isguestuser();

        $unresolved = self::fill_paths($unresolved);
        $cohortcats = $visitor ? [] : self::cohort_categories($unlisted);
        $rolecats = $visitor ? [] : self::role_categories();

        foreach ($unresolved as $categoryid => $path) {
            $pathids = self::path_ids($path);
            $unlistedonpath = array_values(array_intersect($pathids, $unlisted));
            if (!$pathids) {
                // A category that does not exist is not discoverable.
                $answer = false;
            } else if (!$unlistedonpath) {
                $answer = true;
            } else if ($visitor) {
                $answer = false;
            } else {
                $terms = [];
                foreach ($unlistedonpath as $unlistedid) {
                    $terms[] = self::eligible_for($unlistedid, $pathids, $cohortcats, $rolecats);
                }
                // AND over every effectively-unlisted ancestor, not "any of them".
                $answer = !in_array(false, $terms, true);
            }
            self::$discoverable[self::memo_key($viewer, $categoryid)] = $answer;
            $answers[$categoryid] = $answer;
        }

        return $answers;
    }

    /**
     * The memo key of one answer.
     *
     * @param int $viewer The user the answer belongs to.
     * @param int $categoryid The category id.
     * @return string The key.
     */
    private static function memo_key(int $viewer, int $categoryid): string {
        // The viewer is half the key: setUser() and "log in as" switch users inside one request.
        return $viewer . ':' . $categoryid;
    }

    /**
     * Read the stored path of every category whose path the caller did not carry.
     *
     * One query for all of them. A category with no row keeps an empty path,
     * which {@see path_ids()} turns into no ids and the caller into a refusal.
     *
     * @param array $paths Map of categoryid => stored path or null.
     * @return array The same map with every null replaced by a path, possibly the empty one.
     */
    private static function fill_paths(array $paths): array {
        global $DB;

        $missing = [];
        foreach ($paths as $categoryid => $path) {
            if ($path === null) {
                $missing[] = $categoryid;
            }
        }
        if ($missing) {
            $rows = $DB->get_records_list('course_categories', 'id', $missing, '', 'id, path');
            foreach ($missing as $categoryid) {
                $paths[$categoryid] = isset($rows[$categoryid]) ? (string) $rows[$categoryid]->path : '';
            }
        }
        return $paths;
    }

    /**
     * The category ids on a stored path, ancestor first and the category itself last.
     *
     * @param string $path A course_categories.path value, e.g. "/3/7".
     * @return array The ids as ints, empty when the path is empty.
     */
    private static function path_ids(string $path): array {
        return array_values(array_map('intval', array_filter(explode('/', $path))));
    }

    /**
     * Whether the viewer satisfies one effectively-unlisted category on a path.
     *
     * @param int $unlistedid The unlisted category on the path.
     * @param array $pathids The ids on the whole path being answered, ancestor first.
     * @param array $cohortcats The unlisted categories the viewer holds a cohort at.
     * @param array $rolecats The categories the viewer holds any role in.
     * @return bool True when one of the three terms holds.
     */
    private static function eligible_for(int $unlistedid, array $pathids, array $cohortcats, array $rolecats): bool {
        return self::cohort_term($unlistedid, $cohortcats)
            || self::role_term($unlistedid, $pathids, $rolecats)
            || self::staff_escape($unlistedid);
    }

    /**
     * Whether the viewer belongs to a cohort at this category's own context.
     *
     * @param int $unlistedid The unlisted category.
     * @param array $cohortcats The unlisted categories the viewer holds a cohort at.
     * @return bool True when the viewer is a member of a cohort defined at this category.
     */
    private static function cohort_term(int $unlistedid, array $cohortcats): bool {
        return in_array($unlistedid, $cohortcats, true);
    }

    /**
     * Whether the viewer holds a role at this category or above it.
     *
     * "Above it" is this category's OWN ancestors, which is the prefix of the
     * path being answered up to and including it - never the whole path. A role
     * at a category further down grants access to that category, and to nothing
     * that contains it.
     *
     * @param int $unlistedid The unlisted category.
     * @param array $pathids The ids on the whole path being answered, ancestor first.
     * @param array $rolecats The categories the viewer holds any role in.
     * @return bool True when the viewer holds a role at this category or an ancestor of it.
     */
    private static function role_term(int $unlistedid, array $pathids, array $rolecats): bool {
        $ownpath = self::prefix_to($pathids, $unlistedid);
        return (bool) array_intersect($ownpath, $rolecats);
    }

    /**
     * The prefix of a path up to and including one of its ids.
     *
     * @param array $pathids The ids on a path, ancestor first.
     * @param int $unlistedid The id to stop at.
     * @return array The ids up to and including it, empty when it is not on the path.
     */
    private static function prefix_to(array $pathids, int $unlistedid): array {
        $position = array_search($unlistedid, $pathids, true);
        return $position === false ? [] : array_slice($pathids, 0, $position + 1);
    }

    /**
     * Whether the viewer is staff of this category in core's own sense.
     *
     * @param int $categoryid The category id.
     * @return bool True when the viewer may see hidden categories here.
     */
    private static function staff_escape(int $categoryid): bool {
        $context = \core\context\coursecat::instance($categoryid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        return has_capability('moodle/category:viewhiddencategories', $context);
    }

    /**
     * The unlisted categories the viewer holds a cohort membership at.
     *
     * One query over every unlisted id, memoised for the viewer. The cohort
     * context must BE the category's own: an ancestor's cohort is a
     * relationship with the ancestor, and the system one is where a site keeps
     * its whole population.
     *
     * @param array $unlisted The ids of every unlisted category.
     * @return array The subset of them the viewer holds a cohort membership at.
     */
    private static function cohort_categories(array $unlisted): array {
        global $DB, $USER;

        $viewer = (int) $USER->id;
        if (isset(self::$cohortcats[$viewer])) {
            return self::$cohortcats[$viewer];
        }

        [$insql, $params] = $DB->get_in_or_equal($unlisted, SQL_PARAMS_NAMED, 'cat');
        /* {cohort_members} directly, never cohort_get_user_cohorts(): that helper
           filters c.visible = 1, and an invisible cohort still grants. */
        $sql = "SELECT DISTINCT ctx.instanceid
                  FROM {cohort_members} cm
                  JOIN {cohort} c ON c.id = cm.cohortid
                  JOIN {context} ctx ON ctx.id = c.contextid
                 WHERE cm.userid = :userid
                   AND ctx.contextlevel = :contextlevel
                   AND ctx.instanceid $insql";
        $params['userid'] = $viewer;
        $params['contextlevel'] = CONTEXT_COURSECAT;

        self::$cohortcats[$viewer] = array_map('intval', $DB->get_fieldset_sql($sql, $params));
        return self::$cohortcats[$viewer];
    }

    /**
     * Every category the viewer holds any role assignment in.
     *
     * One query, memoised for the viewer, and deliberately not narrowed to the
     * unlisted ids the way the cohort one is: a role at a LISTED ancestor of an
     * unlisted category grants it, so the ancestors have to come back too.
     * Only category contexts are read - a system assignment is not a
     * relationship with a category.
     *
     * @return array The category ids, unordered.
     */
    private static function role_categories(): array {
        global $DB, $USER;

        $viewer = (int) $USER->id;
        if (isset(self::$rolecats[$viewer])) {
            return self::$rolecats[$viewer];
        }

        $sql = "SELECT DISTINCT ctx.instanceid
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ra.userid = :userid
                   AND ctx.contextlevel = :contextlevel";
        $params = ['userid' => $viewer, 'contextlevel' => CONTEXT_COURSECAT];

        self::$rolecats[$viewer] = array_map('intval', $DB->get_fieldset_sql($sql, $params));
        return self::$rolecats[$viewer];
    }

    /**
     * The category id of one item handed to {@see filter_categories()}.
     *
     * @param mixed $category A category object, a record with an id, or an id.
     * @return int The category id.
     */
    private static function item_id(mixed $category): int {
        return is_object($category) ? (int) $category->id : (int) $category;
    }

    /**
     * The stored path of one item handed to {@see filter_categories()}, when it carries one.
     *
     * @param mixed $category A category object, a record with an id, or an id.
     * @return string|null The path, or null when the item does not carry one.
     */
    private static function item_path(mixed $category): ?string {
        if (!is_object($category) || !isset($category->path)) {
            return null;
        }
        $path = (string) $category->path;
        return $path === '' ? null : $path;
    }
}
