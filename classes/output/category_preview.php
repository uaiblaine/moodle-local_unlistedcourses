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
 * Unlisting a category is the one act in this plugin whose consequence an
 * administrator cannot see from the form that performs it. The three terms that
 * open an unlisted category - a cohort at the category, a role here or above,
 * staff - are spread across cohort administration, role assignment and role
 * definitions, so the question "and who does that leave?" has no page of its own.
 * This is that page, rendered where the decision is made.
 *
 * IT COUNTS THE SAME THINGS {@see \local_unlistedcourses\category_access} READS,
 * and must keep agreeing with it: cohorts whose context IS this category's own
 * (never an ancestor's, never the system one), and role holders at this category
 * or at a CATEGORY above it. Staff are deliberately outside the count - every
 * manager and course creator of the site would otherwise swamp it - which is why
 * the string says "besides staff".
 *
 * AND THE QUANTIFIER IS THE PREDICATE'S. When a category above this one is
 * unlisted too, the viewer must satisfy that one as well, so the count is the
 * INTERSECTION of the eligible sets, not this category's own set. A cohort
 * defined here admits nobody the ancestor withholds the subtree from, and a
 * headline that ignored the ancestor would report people who cannot see the
 * category and would fall silent exactly when the answer is nobody.
 *
 * TWO GATES ARE INDEPENDENT HERE, and conflating them would lie in one direction
 * or the other. Whether the viewer may read cohort NAMES is
 * moodle/cohort:view; whether the category is visible to anybody is a fact about
 * the site. So a viewer without that capability sees the counts and no names -
 * and never the "visible to nobody" warning on the strength of a list they were
 * not allowed to see.
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

    /**
     * Build the preview of one category.
     *
     * @param \core_course_category $category The category being edited.
     * @param \core\context\coursecat $context Its own context.
     */
    public function __construct(\core_course_category $category, \core\context\coursecat $context) {
        $this->category = $category;
        $this->context = $context;
    }

    /**
     * Everything the template names.
     *
     * @param \renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(\renderer_base $output): array {
        global $CFG;

        require_once($CFG->dirroot . '/cohort/lib.php');

        $categoryid = (int) $this->category->id;
        $pathids = $this->path_ids();
        $ancestors = array_values(array_diff($pathids, [$categoryid]));
        $state = category_discoverability::get_state($categoryid);
        $unlisted = $state === category_discoverability::STATE_UNLISTED;

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

        /* Counted over EVERY cohort at this category, not over the page of them the
           template names: the count answers "is anybody left", which must not change
           with a listing limit or with who is reading it. */
        $cohortusers = self::cohort_members($this->context->id);
        $roleusers = self::role_holders($pathids);
        $eligible = array_unique(array_merge($cohortusers, $roleusers));

        /* AND over the unlisted categories ABOVE this one, never OR: that is the
           quantifier category_access::answers() applies, so a cohort defined here does
           not let anybody past an ancestor that withholds the whole subtree. Counting
           this category's own two terms alone would overstate the headline, and would
           keep the "visible to nobody" warning quiet on the one arrangement where
           nobody is the true answer. Two more statements per unlisted ancestor, which
           is a price only this admin page pays. */
        $unlistedancestors = array_values(array_intersect($ancestors, category_discoverability::unlisted_ids()));
        foreach ($unlistedancestors as $ancestorid) {
            $eligible = array_intersect($eligible, self::eligible_users($ancestorid, $pathids));
        }
        $visiblecount = count($eligible);

        return [
            'state' => $state,
            'unlisted' => $unlisted,
            'ancestorunlisted' => (bool) $unlistedancestors,
            'cohortsviewable' => $cohortsviewable,
            'cohorts' => $cohorts,
            'hascohorts' => (bool) $cohorts,
            'cohortsmore' => $cohortsmore,
            'cohortsurl' => (new \moodle_url('/cohort/index.php', ['contextid' => $this->context->id]))->out(false),
            'roleholders' => count($roleusers),
            'visiblecount' => $visiblecount,
            'nobodywarning' => $unlisted && $visiblecount === 0,
            'themewarning' => !empty($CFG->allowcategorythemes),
        ];
    }

    /**
     * The users one effectively-unlisted category on the path leaves it open to.
     *
     * Its own two terms, spelled the way category_access spells them: a cohort whose
     * context IS that category's, and a role at it or at a category above IT - which is
     * the prefix of this path, never the whole of it. Staff are outside the count here
     * for the reason the class docblock gives.
     *
     * @param int $categoryid An unlisted category on this category's path.
     * @param array $pathids The ids on this category's path, ancestor first.
     * @return array The user ids, deduplicated.
     */
    private static function eligible_users(int $categoryid, array $pathids): array {
        $context = \core\context\coursecat::instance($categoryid, IGNORE_MISSING);
        $cohortusers = $context ? self::cohort_members($context->id) : [];
        return array_unique(array_merge($cohortusers, self::role_holders(self::prefix_to($pathids, $categoryid))));
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
     * The users belonging to a cohort whose context IS this one.
     *
     * Read straight from the tables for the reason category_access gives: an
     * invisible cohort still grants, so cohort_get_user_cohorts() and anything
     * else filtering on visible would undercount.
     *
     * @param int $contextid The category's own context id.
     * @return array The user ids, deduplicated.
     */
    private static function cohort_members(int $contextid): array {
        global $DB;

        $sql = "SELECT DISTINCT cm.userid
                  FROM {cohort_members} cm
                  JOIN {cohort} c ON c.id = cm.cohortid
                 WHERE c.contextid = :contextid";
        return array_map('intval', $DB->get_fieldset_sql($sql, ['contextid' => $contextid]));
    }

    /**
     * The users holding any role at one of these categories.
     *
     * The whole path, because a role at a LISTED ancestor opens an unlisted
     * descendant - the rule the predicate applies, reproduced here so the two
     * cannot drift apart. Course and system assignments are not read: neither is
     * a relationship with this category.
     *
     * @param array $categoryids The category ids to read, this one and its ancestors.
     * @return array The user ids, deduplicated.
     */
    private static function role_holders(array $categoryids): array {
        global $DB;

        if (!$categoryids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'cat');
        $params['contextlevel'] = CONTEXT_COURSECAT;
        $sql = "SELECT DISTINCT ra.userid
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ctx.contextlevel = :contextlevel
                   AND ctx.instanceid $insql";
        return array_map('intval', $DB->get_fieldset_sql($sql, $params));
    }
}
