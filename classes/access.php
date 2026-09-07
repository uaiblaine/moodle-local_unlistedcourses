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
 * Unlisted courses - Whether the current user may discover a course
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses;

/**
 * Decides whether the current user may discover a course.
 *
 * A course in the UNLISTED state ({@see discoverability}) is discoverable
 * only by someone who is actively enrolled, has an application pending, or
 * could enrol right now. Everyone else must not learn that it exists - so the
 * answer feeds course listings, the enrolment page and anything else that
 * would otherwise print its name. The other two states (listed, public) are
 * discoverable by anybody; whether a PUBLIC course may be served to a visitor
 * who is not logged in is {@see discoverability::is_public()}, not this.
 *
 * CURRENT USER ONLY, and not by choice. The two predicates this class
 * delegates to both read the $USER global rather than accepting a user id:
 * enrol_self_plugin::can_self_enrol() resolves cohort membership through
 * $USER->id, and the fleet's enrol_apply fork does the same in allow_apply().
 * Passing a user id would mean reimplementing both, and a reimplementation
 * that drifts from the plugin it mirrors fails open. Tests switch users with
 * setUser() instead.
 *
 * NOTHING HERE IS CACHED BEYOND THE REQUEST, deliberately. The answer depends
 * on cohort membership, and the fleet's tool_dynamic_cohorts writes
 * cohort_members in bulk WITHOUT firing cohort_member_added/removed - by its
 * own comment, "the bulk path deliberately skips" them. A cache invalidated
 * by those events would keep showing a course to someone who has just been
 * removed from the cohort that gated it, which is the failure this plugin
 * exists to prevent. The answer also depends on time(), through the
 * enrolment window each enrol plugin enforces.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access {
    /** @var array Request cache of the discoverability answer, keyed "userid:courseid" => bool. */
    private static array $discoverable = [];

    /** @var array Request cache of the course relationship, keyed "userid:courseid" => bool. */
    private static array $related = [];

    /**
     * Forget everything cached for this request, both state caches included.
     *
     * Called by tests, by {@see discoverability::set_state()}, and by anything
     * that changes the current user's enrolments inside one request. The
     * category side is reset from here as well, because a listing answer is
     * composed from both: {@see category_access::reset_caches()} clears its own
     * memos and the category state behind them.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$discoverable = [];
        self::$related = [];
        discoverability::reset_caches();
        category_access::reset_caches();
    }

    /**
     * Whether the current user may discover this course.
     *
     * THE COURSE'S OWN STATE AND NOTHING ELSE: an unlisted CATEGORY above it
     * does not enter this answer. The split is deliberate. The theme's
     * after_config guard ghosts /enrol/index.php, /course/info.php and the
     * hotsite off this method, so a category rule added here would stop being a
     * listing rule and become an enrolment block - somebody who was handed the
     * link to a course inside an unlisted category, and who may enrol in it,
     * must still be able to. The category term applies to listings only, in
     * {@see filter_courses()}.
     *
     * @param int $courseid The course id.
     * @return bool True when the course may be named to this user.
     */
    public static function is_course_discoverable(int $courseid): bool {
        $answers = self::are_courses_discoverable([$courseid]);
        return $answers[$courseid] ?? true;
    }

    /**
     * Whether the current user may discover each of these courses.
     *
     * Resolves the state for every id in ONE query and then evaluates
     * eligibility only for the courses that are actually unlisted - which on a
     * real site is a small minority of any listing, and is what keeps this
     * affordable. The expensive half (one enrol_get_instances() plus one
     * cohort_is_member() per instance, neither of which core caches anywhere)
     * therefore runs over the marked subset, never over the whole page.
     *
     * The course's own state alone, like {@see is_course_discoverable()} and
     * for the same reason: the category term belongs to {@see filter_courses()}.
     *
     * Memoised per viewer AND course. An unlisted course's answer is entirely a
     * property of the viewer - it ends in {@see eligible()}, which reads $USER
     * through is_enrolled(), has_capability() and can_self_enrol() - so a memo
     * keyed by the course alone would answer for whoever asked first, and
     * setUser() and "log in as" switch users inside one request.
     *
     * @param array $courseids Course ids.
     * @return array Map of courseid => bool, in the order given.
     */
    public static function are_courses_discoverable(array $courseids): array {
        global $USER;

        $courseids = array_values(array_unique(array_map('intval', $courseids)));
        $viewer = (int) $USER->id;
        $answers = [];

        $unknown = [];
        foreach ($courseids as $courseid) {
            $key = self::memo_key($viewer, $courseid);
            if (array_key_exists($key, self::$discoverable)) {
                $answers[$courseid] = self::$discoverable[$key];
            } else {
                $unknown[] = $courseid;
            }
        }
        if (!$unknown) {
            return $answers;
        }

        $states = discoverability::get_states($unknown);
        foreach ($unknown as $courseid) {
            $answer = ($states[$courseid] === discoverability::STATE_UNLISTED) ? self::eligible($courseid) : true;
            self::$discoverable[self::memo_key($viewer, $courseid)] = $answer;
            $answers[$courseid] = $answer;
        }

        return $answers;
    }

    /**
     * The memo key of one answer.
     *
     * @param int $viewer The user the answer belongs to.
     * @param int $courseid The course id.
     * @return string The key.
     */
    private static function memo_key(int $viewer, int $courseid): string {
        // The viewer is half the key: setUser() and "log in as" switch users inside one request.
        return $viewer . ':' . $courseid;
    }

    /**
     * Keep only the courses the current user may discover in a listing.
     *
     * Accepts anything with an ->id, which covers both the stdClass records
     * and the core_course_list_element objects the course renderer passes
     * around, and preserves the incoming keys so a caller can keep paginating
     * with them.
     *
     * THIS IS THE ONE PLACE THE CATEGORY TERM APPLIES. A course whose category
     * is effectively unlisted is withheld here even when the course itself is
     * listed - unlisting a category has to withhold what is inside it, or it
     * withholds nothing. The same course is NOT withheld from
     * {@see is_course_discoverable()}, which gates the enrolment page and the
     * public landing page: a listing rule must not become an enrolment block.
     *
     * Three relationships rescue a course from the category term - enrolled,
     * application pending, staff of the course - so the people who already work
     * in a course keep it on their own listings whatever happens to the
     * category above them. Being ABLE to enrol right now does not, and that is
     * the point: an open self-enrolment instance is the normal case inside an
     * unlisted category, so honouring it here would leave the category rule
     * withholding nothing at all.
     *
     * @param array $courses Course records or list elements, any keys.
     * @return array The same array minus the courses this user must not discover.
     */
    public static function filter_courses(array $courses): array {
        if (!$courses) {
            return $courses;
        }

        $ids = [];
        foreach ($courses as $course) {
            $ids[] = (int) $course->id;
        }
        $answers = self::are_courses_discoverable($ids);
        $categories = self::course_categories($courses);
        $catanswers = category_access::are_categories_discoverable(array_values($categories));

        $kept = [];
        foreach ($courses as $key => $course) {
            $courseid = (int) $course->id;
            if (!($answers[$courseid] ?? true)) {
                continue;
            }
            $categoryid = $categories[$courseid] ?? 0;
            if (!($catanswers[$categoryid] ?? true) && !self::has_course_relationship($courseid)) {
                continue;
            }
            $kept[$key] = $course;
        }
        return $kept;
    }

    /**
     * The category of each of these courses.
     *
     * Reads the category off the item when it carries one, which both a course
     * record and a core_course_list_element do, and falls back to ONE query for
     * the rest - so a caller handing over real records costs nothing here, and
     * an object carrying only an id is still safe to pass. A course that no
     * longer exists gets category 0, which no category owns, and is refused by
     * the predicate unless the viewer has a relationship with the course.
     *
     * @param array $courses Course records or list elements.
     * @return array Map of courseid => categoryid.
     */
    private static function course_categories(array $courses): array {
        global $DB;

        $categories = [];
        $missing = [];
        foreach ($courses as $course) {
            $courseid = (int) $course->id;
            if (isset($course->category)) {
                $categories[$courseid] = (int) $course->category;
            } else {
                $missing[] = $courseid;
            }
        }
        if ($missing) {
            $rows = $DB->get_records_list('course', 'id', $missing, '', 'id, category');
            foreach ($missing as $courseid) {
                $categories[$courseid] = isset($rows[$courseid]) ? (int) $rows[$courseid]->category : 0;
            }
        }
        return $categories;
    }

    /**
     * Whether the current user is enrolled in, has applied to, or could join this course.
     *
     * Every term but the last is a relationship the course already has with
     * this user, and they live in {@see has_course_relationship()} so the
     * listing clamp in {@see filter_courses()} can ask for them on their own.
     * "Could join right now" is the one term that clamp deliberately does not
     * honour, which is why it stays here and nowhere else.
     *
     * @param int $courseid The course id.
     * @return bool True when the course may be named to this user.
     */
    private static function eligible(int $courseid): bool {
        if (self::has_course_relationship($courseid)) {
            return true;
        }

        /* viewer_context() carries the visitor and guest guard for this last term
           as well, and it has to: can_self_enrol($instance, false) skips its own
           guest check, so a guest would otherwise pass on any instance that
           carries no cohort restriction. */
        return self::viewer_context($courseid) !== null && self::can_enrol($courseid);
    }

    /**
     * Whether the current user already has a relationship with this course.
     *
     * Enrolled, staff of it, or an application pending - the three things that
     * keep a course on somebody's own listings whatever the category above it
     * says. Deliberately NOT {@see can_enrol()}: being able to join a course is
     * not a relationship with it, and letting it rescue one would leave the
     * category rule withholding nothing, since an open self-enrolment instance
     * is the normal case inside an unlisted category.
     *
     * Memoised per viewer AND course: setUser() and "log in as" switch users
     * inside one request, and this answer is entirely a property of the viewer.
     *
     * @param int $courseid The course id.
     * @return bool True when this user is enrolled, is staff, or has applied.
     */
    private static function has_course_relationship(int $courseid): bool {
        global $USER;

        $key = self::memo_key((int) $USER->id, $courseid);
        if (!array_key_exists($key, self::$related)) {
            self::$related[$key] = self::compute_course_relationship($courseid);
        }
        return self::$related[$key];
    }

    /**
     * Work out the course relationship, with no memo in the way.
     *
     * @param int $courseid The course id.
     * @return bool True when this user is enrolled, is staff, or has applied.
     */
    private static function compute_course_relationship(int $courseid): bool {
        global $USER;

        if ($courseid == SITEID) {
            // Everybody participates on the frontpage.
            return true;
        }

        $context = self::viewer_context($courseid);
        if (!$context) {
            return false;
        }

        if (is_enrolled($context, $USER, '', true)) {
            return true;
        }

        /* Staff keep normal visibility. Without this, an unlisted course
           disappears from the category listing of the very people who
           administer it: a manager is not enrolled, is not in the gating
           cohort, and can_self_enrol() answers no for them like anybody else -
           so the course they are responsible for stops existing on screen, and
           the site admin loses it too.

           The two capabilities are core's own idioms for "may see this course
           without being enrolled": moodle/course:view is what is_viewing()
           tests and what managers hold, and moodle/course:viewhiddencourses is
           the one core itself consults to decide who still sees a course that
           has been hidden - held by teacher, editingteacher, coursecreator and
           manager. Site admins pass both. */
        if (is_viewing($context) || has_capability('moodle/course:viewhiddencourses', $context)) {
            return true;
        }

        return self::has_pending_enrolment($courseid);
    }

    /**
     * The course context, for a user entitled to be asked about one at all.
     *
     * One place for the visitor and guest guard, so that neither the
     * relationship half nor the enrolability half can be written without it.
     *
     * @param int $courseid The course id.
     * @return \core\context\course|null The context, or null for a visitor, a guest or a missing course.
     */
    private static function viewer_context(int $courseid): ?\core\context\course {
        /* Fail closed for visitors and guests before consulting any enrol plugin:
           can_self_enrol($instance, false) skips its own guest check, so a guest
           would otherwise pass on any instance that carries no cohort restriction. */
        if (!isloggedin() || isguestuser()) {
            return null;
        }

        $context = \core\context\course::instance($courseid, IGNORE_MISSING);
        return $context ?: null;
    }

    /**
     * Whether the current user holds an inactive enrolment row in this course.
     *
     * An enrol_apply application in the waiting state is a user_enrolments row
     * with a status other than ENROL_USER_ACTIVE, so is_enrolled() with
     * $onlyactive reports false for an applicant who is waiting for a decision.
     * Without this term, applying to an unlisted course would make it vanish
     * from the listing the moment the application was filed.
     *
     * @param int $courseid The course id.
     * @return bool True when an inactive enrolment row exists for this user.
     */
    private static function has_pending_enrolment(int $courseid): bool {
        global $DB, $USER;

        /* status <> ENROL_USER_ACTIVE, not "any row": an ACTIVE row is already
           is_enrolled()'s answer, and matching it here would make this term
           subsume that one - which is exactly how the first draft of this
           method passed its tests while the enrolment short circuit it was
           meant to complement went unheld by any of them. */
        $sql = "SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid
                   AND e.courseid = :courseid
                   AND ue.status <> :active";
        return $DB->record_exists_sql($sql, [
            'userid' => $USER->id,
            'courseid' => $courseid,
            'active' => ENROL_USER_ACTIVE,
        ]);
    }

    /**
     * Whether any enabled enrolment instance would accept the current user right now.
     *
     * Dispatches per plugin rather than calling one shared method, because
     * there is no shared method to call: enrol_plugin::can_self_enrol() is
     * `return false` in the base class and only enrol_self overrides it in
     * the whole of core, so asking every plugin through it would report "no"
     * for enrol_apply and hide the course from the very people it is open to.
     *
     * The enrol_apply branch mirrors theme_boost_union_fundaseg's own resolver:
     * allow_apply() is guarded with is_callable() because the fork may not be
     * installed, and the applicant cap lives OUTSIDE allow_apply() so it is
     * checked separately.
     *
     * Note that both predicates also enforce the enrolment window and the
     * places limit, so an unlisted course disappears from the listing while
     * its enrolment window is shut or once it is full. That is deliberate -
     * it is the same answer core's own enrolment icons give - but it does
     * make the listing time-dependent.
     *
     * @param int $courseid The course id.
     * @return bool True when at least one instance would accept this user.
     */
    private static function can_enrol(int $courseid): bool {
        global $CFG;

        require_once($CFG->dirroot . '/enrol/self/lib.php');

        foreach (enrol_get_instances($courseid, true) as $instance) {
            $plugin = enrol_get_plugin($instance->enrol);
            if (!$plugin) {
                continue;
            }

            if ($instance->enrol === 'self') {
                /* Strictly === true: can_self_enrol() returns true, an error string, or
                   null - the last when customint5 points at a deleted cohort, which
                   cohort_delete_cohort() never clears. Only === true fails closed. */
                if ($plugin->can_self_enrol($instance, false) === true) {
                    return true;
                }
                continue;
            }

            if ($instance->enrol === 'apply' && is_callable([$plugin, 'allow_apply'])) {
                if ($plugin->allow_apply($instance) !== true) {
                    continue;
                }
                if (self::apply_is_full($instance, $plugin)) {
                    continue;
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an enrol_apply instance has no place left.
     *
     * Asks the plugin, rather than re-deriving the answer, because that definition
     * changed and this adapter used to re-implement it. enrol_apply stopped counting EXPIRED
     * enrolments against the cap: it ships expiredaction = ENROL_EXT_REMOVED_KEEP, under which
     * core changes nothing when a period runs out, so a counted expired row made the cap a
     * ratchet that only ever tightened. While the count was duplicated here, a course whose
     * places had been freed by expiry still read as closed on this surface while enrol_apply's
     * own pages offered the button and accepted the application.
     *
     * It goes through the PLUGIN OBJECT and not \enrol_apply\local\capacity, and that is not
     * a style choice: enrol_apply is an optional dependency here, so naming a class in its
     * namespace is a reference the autoloader would have to resolve on a site without the
     * plugin. is_callable() on the object is the same guard every allow_apply() call in this
     * file already uses.
     *
     * The inline fallback is not dead code: it is what an enrol_apply build predating the
     * method means by "full", and on such a build the unfiltered count IS the plugin's rule.
     *
     * @param \stdClass $instance Enrol instance belonging to the apply plugin.
     * @param \enrol_plugin $plugin The apply plugin instance.
     * @return bool True when the instance has no place left.
     */
    private static function apply_is_full(\stdClass $instance, \enrol_plugin $plugin): bool {
        global $DB;

        if (is_callable([$plugin, 'is_full'])) {
            return (bool) $plugin->is_full($instance);
        }

        $cap = (int) $instance->customint3;

        return $cap > 0 && $DB->count_records('user_enrolments', ['enrolid' => $instance->id]) >= $cap;
    }
}
