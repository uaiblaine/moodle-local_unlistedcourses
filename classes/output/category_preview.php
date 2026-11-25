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
 * Course category discoverability - Who still sees an unlisted category
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\output;

use local_unlistedcourses\category_discoverability;

/**
 * The preview beside the state control: who will still discover this category.
 *
 * The three terms that open an unlisted category - a cohort at the category, a
 * role here or above, staff - are spread across cohort administration, role
 * assignment and role definitions, so no core page answers "and who does
 * unlisting leave?". This panel answers it where the decision is made.
 *
 * It counts the same things {@see \local_unlistedcourses\category_access} reads,
 * and must keep agreeing with it: cohorts whose context is this category's own
 * (never an ancestor's, never the system one), and role holders at this category
 * or at a category above it. Staff are deliberately outside the count - every
 * manager and course creator of the site would otherwise swamp it - which is why
 * the strings say "besides staff".
 *
 * The quantifier is the predicate's. When a category above this one is unlisted
 * too, a user must satisfy that one as well, so the count is the intersection of
 * the eligible sets, not this category's own set. Every figure is counted in the
 * database, so the page issues the same statements for a cohort of ten people and
 * for one of a hundred thousand, and holds no list of user ids. A headline that ignored the
 * ancestor would report people who cannot see the category, and would miss the
 * "visible to nobody" warning exactly when nobody is the true answer.
 *
 * A public category gets a different panel and no accounting. The cohort and
 * role terms answer "who still sees this while it is unlisted", and a public
 * category is shown to everybody, visitors included, so the counts would answer
 * a question nobody asked, at two statements of their own. What an
 * administrator needs there instead is what a visitor will be served, and the
 * two arrangements that make the public state inert: an unlisted category above
 * it, or a hidden category anywhere on its path.
 *
 * Two gates are independent here. Whether the viewer may read cohort names is
 * moodle/cohort:view; whether the category is visible to anybody is a fact about
 * the site. So a viewer without that capability sees the counts but no names,
 * and the "visible to nobody" warning is computed from the counts, never from
 * the list of names they may not read.
 *
 * An unlisted category also says what listing it again would make public: the
 * public courses and subcategories below it that only it holds back, counted by
 * {@see category_discoverability::relist_activations()}, the rule set_state()
 * decides with. When the viewer may not publish all of them, it says that
 * somebody who may has to list it; the form freezes the control on the same answer.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class category_preview implements \renderable, \templatable {
    /** @var int Most cohorts named at once; past it the template says how many are left. */
    public const PERPAGE = 100;

    /** @var \core_course_category The category being previewed. */
    private \core_course_category $category;

    /** @var \core\context\coursecat The category's own context. */
    private \core\context\coursecat $context;

    /** @var array|null What listing the category again would make public, when the caller read it already. */
    private ?array $activations;

    /**
     * Build the preview of one category.
     *
     * @param \core_course_category $category The category being edited.
     * @param \core\context\coursecat $context Its own context.
     * @param array|null $activations What {@see category_discoverability::relist_activations()} returned for it, when the
     *   caller read it already; null to read it here.
     */
    public function __construct(\core_course_category $category, \core\context\coursecat $context, ?array $activations = null) {
        $this->category = $category;
        $this->context = $context;
        $this->activations = $activations;
    }

    /**
     * Everything the template names.
     *
     * @param \renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(\renderer_base $output): array {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/cohort/lib.php');

        $categoryid = (int) $this->category->id;
        $pathids = $this->path_ids();
        $ancestors = array_values(array_diff($pathids, [$categoryid]));
        $state = category_discoverability::get_state($categoryid);
        $unlisted = $state === category_discoverability::STATE_UNLISTED;
        $unlistedancestors = array_values(array_intersect($ancestors, category_discoverability::unlisted_ids()));

        if ($state === category_discoverability::STATE_PUBLIC) {
            return $this->public_context($state, $pathids, $unlistedancestors);
        }

        $cohortsviewable = has_capability('moodle/cohort:view', $this->context);
        $cohorts = [];
        $cohortsmore = 0;
        if ($cohortsviewable) {
            $listing = cohort_get_cohorts($this->context->id, 0, self::PERPAGE);
            $counts = self::member_counts(array_keys($listing['cohorts']));
            foreach ($listing['cohorts'] as $cohort) {
                $cohorts[] = [
                    // Plain spelling: the template puts the name in a double stash.
                    'name' => format_string($cohort->name, true, ['context' => $this->context, 'escape' => false]),
                    'members' => (int) ($counts[(int) $cohort->id] ?? 0),
                ];
            }
            $cohortsmore = max(0, (int) $listing['totalcohorts'] - count($cohorts));
        }

        /* Counted over every cohort at this category, not over the page of them the
           template names: the count answers "is anybody left", which must not change
           with a listing limit or with who is reading it. */
        $visiblecount = self::count_eligible($categoryid, $pathids, $unlistedancestors);

        /* What listing it again would make public is counted for everybody, like the figures
           above; whether this viewer may do it is the one answer here about the viewer, and it
           is set_state()'s own rule, read through its non-throwing twin. */
        $activations = $unlisted
            ? ($this->activations ?? category_discoverability::relist_activations($categoryid))
            : ['courses' => [], 'categories' => []];
        $relistcourses = count($activations['courses']);
        $relistcategories = count($activations['categories']);
        $relistwarning = $relistcourses + $relistcategories > 0;

        return [
            'state' => $state,
            'unlisted' => $unlisted,
            'public' => false,
            'publicancestorunlisted' => false,
            'publichidden' => false,
            'ancestorunlisted' => (bool) $unlistedancestors,
            'cohortsviewable' => $cohortsviewable,
            'cohorts' => $cohorts,
            'hascohorts' => (bool) $cohorts,
            'cohortsmore' => $cohortsmore,
            'cohortsurl' => (new \moodle_url('/cohort/index.php', ['contextid' => $this->context->id]))->out(false),
            'roleholders' => self::count_role_holders($pathids),
            'visiblecount' => $visiblecount,
            'nobodywarning' => $unlisted && $visiblecount === 0,
            'relistcourses' => $relistcourses,
            'relistcategories' => $relistcategories,
            'relistwarning' => $relistwarning,
            'relistblocked' => $relistwarning
                && !category_discoverability::may_relist($categoryid, (int) $USER->id, $activations),
            'themewarning' => self::theme_warning(),
        ];
    }

    /**
     * Whether this site lets a category carry a theme of its own.
     *
     * Read by both branches from this one place, so they cannot disagree about a
     * fact of the site: a theme set on a category switches its pages away from
     * the theme that applies this plugin's listing rules, which matters for
     * a public category as much as for an unlisted one.
     *
     * @return bool True when category themes are enabled.
     */
    private static function theme_warning(): bool {
        global $CFG;

        return !empty($CFG->allowcategorythemes);
    }

    /**
     * Everything the template names, for a category in the public state.
     *
     * The same keys as the unlisted branch, because one template reads both,
     * with the accounting empty: see the class docblock for why it is not
     * computed rather than computed and hidden.
     *
     * 'ancestorunlisted' keeps its true value here although the template renders
     * it only in the non-public branch (the public panel says the same thing
     * through 'publicancestorunlisted'), so the key never contradicts its name if
     * a later template reads it in both branches.
     *
     * @param int $state The stored state, which is the public one here.
     * @param array $pathids This category's own id and every ancestor's.
     * @param array $unlistedancestors The ids of the unlisted categories above this one.
     * @return array The template context.
     */
    private function public_context(int $state, array $pathids, array $unlistedancestors): array {
        return [
            'state' => $state,
            'unlisted' => false,
            'public' => true,
            'publicancestorunlisted' => (bool) $unlistedancestors,
            'publichidden' => self::path_has_hidden($pathids),
            'ancestorunlisted' => (bool) $unlistedancestors,
            'cohortsviewable' => false,
            'cohorts' => [],
            'hascohorts' => false,
            'cohortsmore' => 0,
            'cohortsurl' => (new \moodle_url('/cohort/index.php', ['contextid' => $this->context->id]))->out(false),
            'roleholders' => 0,
            'visiblecount' => 0,
            'nobodywarning' => false,
            'relistcourses' => 0,
            'relistcategories' => 0,
            'relistwarning' => false,
            'relistblocked' => false,
            'themewarning' => self::theme_warning(),
        ];
    }

    /**
     * Whether this category or any category above it is hidden.
     *
     * The visibility half of the public composition, read here so the panel can
     * name the reason rather than just saying "not public": a hidden category is
     * never public, and neither is one inside a hidden category.
     *
     * @param array $pathids This category's own id and every ancestor's.
     * @return bool True when at least one of them is hidden.
     */
    private static function path_has_hidden(array $pathids): bool {
        global $DB;

        if (!$pathids) {
            return true;
        }
        [$insql, $params] = $DB->get_in_or_equal($pathids, SQL_PARAMS_NAMED, 'cat');
        $visiblecount = $DB->count_records_select('course_categories', "id $insql AND visible = 1", $params);
        return $visiblecount !== count($pathids);
    }

    /**
     * How many people this category stays visible to besides staff, in one statement.
     *
     * The people its own two terms admit, kept only when every unlisted category above it
     * admits them too: the AND category_access::answers() applies (see the class
     * docblock). Counted in the database, whatever the size of the cohorts, as a
     * COUNT(DISTINCT) over the union of the terms - kept with duplicates, so the DISTINCT
     * is what counts a member of two cohorts, or a member who also holds a role, once -
     * with one membership test per unlisted ancestor.
     *
     * @param int $categoryid This category's id.
     * @param array $pathids The ids on its path, ancestor first.
     * @param array $unlistedancestors The unlisted categories above it.
     * @return int The number of distinct users.
     */
    private static function count_eligible(int $categoryid, array $pathids, array $unlistedancestors): int {
        global $DB;

        [$ownsql, $params] = self::term_sql($categoryid, $pathids, 'own');
        $conditions = [];
        foreach (array_values($unlistedancestors) as $index => $ancestorid) {
            [$termsql, $termparams] = self::term_sql((int) $ancestorid, $pathids, 'anc' . $index);
            $conditions[] = "e.userid IN ($termsql)";
            $params += $termparams;
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT COUNT(DISTINCT e.userid)
                  FROM ($ownsql) e
                $where";
        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * The user ids one category on the path admits, as SQL, duplicates kept.
     *
     * Its own two terms, spelled the way category_access spells them: a cohort whose
     * context is that category's, read with no visible filter because an invisible cohort
     * still grants, and a role at it or at a category above it - the prefix of this path up
     * to it, never the whole of it, so a role below an unlisted ancestor opens nothing
     * there. Staff are outside the count for the reason the class docblock gives. Every
     * placeholder carries the prefix, so several terms can share one statement.
     *
     * @param int $categoryid A category on this category's path, this one included.
     * @param array $pathids The ids on this category's path, ancestor first.
     * @param string $prefix A placeholder prefix unique within the statement.
     * @return array The SQL and its named parameters.
     */
    private static function term_sql(int $categoryid, array $pathids, string $prefix): array {
        global $DB;

        $parts = [];
        $params = [];
        $context = \core\context\coursecat::instance($categoryid, IGNORE_MISSING);
        if ($context) {
            $parts[] = "SELECT cm.userid
                          FROM {cohort_members} cm
                          JOIN {cohort} c ON c.id = cm.cohortid
                         WHERE c.contextid = :{$prefix}ctx";
            $params[$prefix . 'ctx'] = $context->id;
        }
        // Never empty: the category is on its own prefix.
        [$insql, $inparams] = $DB->get_in_or_equal(self::prefix_to($pathids, $categoryid), SQL_PARAMS_NAMED, $prefix . 'cat');
        $parts[] = "SELECT ra.userid
                      FROM {role_assignments} ra
                      JOIN {context} ctx ON ctx.id = ra.contextid
                     WHERE ctx.contextlevel = :{$prefix}lvl
                       AND ctx.instanceid $insql";
        $params[$prefix . 'lvl'] = CONTEXT_COURSECAT;
        return [implode(' UNION ALL ', $parts), $params + $inparams];
    }

    /**
     * The prefix of a path up to and including one of its ids.
     *
     * @param array $pathids The ids on a path, ancestor first.
     * @param int $categoryid The id to stop at.
     * @return array The ids up to and including it, empty when it is not on the path.
     */
    private static function prefix_to(array $pathids, int $categoryid): array {
        $position = array_search($categoryid, $pathids, true);
        return $position === false ? [] : array_slice($pathids, 0, $position + 1);
    }

    /**
     * This category's own id and every ancestor's, ancestor first.
     *
     * @return array The ids as ints.
     */
    private function path_ids(): array {
        $ids = array_map('intval', $this->category->get_parents());
        $ids[] = (int) $this->category->id;
        return array_values(array_unique($ids));
    }

    /**
     * How many members each of these cohorts has, in one query.
     *
     * @param array $cohortids The cohort ids being named.
     * @return array Map of cohortid => member count, missing for a cohort with no members.
     */
    private static function member_counts(array $cohortids): array {
        global $DB;

        if (!$cohortids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'coh');
        $sql = "SELECT cm.cohortid, COUNT(*) AS membercount
                  FROM {cohort_members} cm
                 WHERE cm.cohortid $insql
              GROUP BY cm.cohortid";

        $counts = [];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $counts[(int) $row->cohortid] = (int) $row->membercount;
        }
        return $counts;
    }

    /**
     * How many people hold any role at one of these categories, each counted once.
     *
     * The whole path, because a role at a listed ancestor opens an unlisted
     * descendant - the rule the predicate applies, reproduced here so the two
     * cannot drift apart. Course and system assignments are not read: neither is
     * a relationship with this category.
     *
     * @param array $categoryids The category ids to read, this one and its ancestors.
     * @return int The number of distinct users.
     */
    private static function count_role_holders(array $categoryids): int {
        global $DB;

        if (!$categoryids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');
        $params['contextlevel'] = CONTEXT_COURSECAT;
        $sql = "SELECT COUNT(DISTINCT ra.userid)
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ctx.contextlevel = :contextlevel
                   AND ctx.instanceid $insql";
        return (int) $DB->count_records_sql($sql, $params);
    }
}
