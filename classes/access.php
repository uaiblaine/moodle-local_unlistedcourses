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
 * A course in the unlisted state ({@see discoverability}) is discoverable
 * only by someone who is actively enrolled, has an application pending, is
 * staff of the course, or could enrol right now. Everyone else must not learn
 * that it exists, so the answer feeds course listings, the enrolment page and
 * anything else that would otherwise print its name. The other two states
 * (listed, public) are discoverable by anybody. Whether a public course may be
 * served to a visitor who is not logged in is {@see discoverability::is_public()},
 * not this; {@see filter_courses_public()} is the one method here that asks it,
 * and it reads no $USER.
 *
 * Current user only: enrol_self_plugin::can_self_enrol() reads $USER for its
 * cohort, existing-enrolment and capability checks and takes no user id, and a
 * reimplementation for another user would fail open as soon as it drifted from
 * the plugin. Tests switch users with setUser() instead.
 *
 * Nothing here is cached beyond the request. The answer depends on cohort
 * membership, which can change without an event: tool_dynamic_cohorts, for
 * one, writes cohort_members in bulk without firing cohort_member_added or
 * cohort_member_removed, so a cache invalidated by those events would keep
 * showing a course to someone just removed from the cohort that gated it. The
 * answer also depends on time(), through the enrolment window each enrol
 * plugin enforces.
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
     * The course's own state only: an unlisted category above it does not enter
     * this answer. Callers gate pages reached by link on it, such as
     * /enrol/index.php and /course/info.php, so a category rule here would stop
     * being a listing rule and become an enrolment block: somebody handed the
     * link to a course inside an unlisted category, who may enrol in it, must
     * still be able to. The category term applies to listings only, in
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
     * Resolves the state for every id in one query and then evaluates
     * eligibility only for the courses that are unlisted, normally a small
     * minority of a listing. Eligibility is the expensive half - relationship
     * queries, enrol_get_instances() and the per-instance enrol checks, none of
     * them cached by core - so it never runs over the whole page.
     *
     * The course's own state alone, like {@see is_course_discoverable()} and
     * for the same reason: the category term belongs to {@see filter_courses()}.
     *
     * Memoised per viewer and course. An unlisted course's answer is entirely a
     * property of the viewer - {@see eligible()} reads $USER through
     * is_enrolled(), has_capability() and can_self_enrol() - and setUser() and
     * "log in as" switch users inside one request.
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
     * This is the one place the category term applies. A course whose category
     * is effectively unlisted is withheld here even when the course itself is
     * listed: unlisting a category has to withhold what is inside it. The same
     * course is not withheld by {@see is_course_discoverable()}, which gates the
     * enrolment page: a listing rule must not become an enrolment block.
     *
     * Three relationships exempt a course from the category term - enrolled,
     * application pending, staff of the course - so the people who already work
     * in a course keep it on their own listings. Being able to enrol right now
     * does not: an open self-enrolment instance is the normal case inside an
     * unlisted category, so honouring it here would leave the category rule
     * withholding nothing.
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
     * Keep only the courses a visitor who is not logged in may be shown.
     *
     * The only predicate a page served to such visitors may use, and the one
     * method here that never consults the viewer: everything else in this class
     * is an answer about $USER. A course is kept when
     * {@see discoverability::are_public()} says so - its own state public, the
     * course visible, every category on its path existing and visible, and no
     * category on that path unlisted - so the answer is the same for a visitor,
     * an administrator and a crawler.
     *
     * A course whose own state is not public is dropped however public the
     * category around it is: a course reaches the internet only when somebody
     * holding the publish capability said so about that course. A listed course
     * inside a public category has no anonymous page to offer, and an unlisted
     * one is being withheld on purpose.
     *
     * Accepts anything with an ->id, which covers the course records and the
     * core_course_list_element objects a listing passes around, and preserves
     * the incoming keys. A ->category or ->visible carried by the item is not
     * read: those are the two fields a stale or hand-built list element gets
     * wrong, and trusting them would save one statement per request, not one
     * per course.
     *
     * @param array $courses Course records or list elements, any keys.
     * @return array The same array minus every course a visitor must not be shown.
     */
    public static function filter_courses_public(array $courses): array {
        if (!$courses) {
            return $courses;
        }

        $ids = [];
        foreach ($courses as $course) {
            $ids[] = (int) $course->id;
        }
        $answers = discoverability::are_public($ids);

        $kept = [];
        foreach ($courses as $key => $course) {
            // Fail closed: an id the predicate did not answer for is not served anonymously.
            if (!($answers[(int) $course->id] ?? false)) {
                continue;
            }
            $kept[$key] = $course;
        }
        return $kept;
    }

    /**
     * Vouch for the current user's relationship with these courses, for this request.
     *
     * A caller that has just read the {user_enrolments} table for a whole
     * subtree already knows which of its courses this user is enrolled in and
     * which ones hold an application of theirs. Handing that over fills the
     * relationship memo, so {@see filter_courses()} answers the category term
     * for those courses from memory instead of running is_enrolled() and the
     * pending-application query course by course - about one statement per
     * course, the one part of a listing that is not flat.
     *
     * Only true is ever written. The caller is vouching for a relationship it
     * read itself; it is not authoritative about the absence of one, because
     * being staff of a course is a relationship too and no enrolment table
     * carries it. An unprimed course is computed as usual.
     *
     * Keyed by the current viewer, like every other memo here: setUser() and
     * "log in as" switch users inside one request, and what was primed for one
     * of them must never answer for the next. {@see reset_caches()} drops it.
     *
     * @param array $enrolledcourseids Course ids this user holds an active enrolment in.
     * @param array $pendingcourseids Course ids this user has an application awaiting a decision in.
     * @return void
     */
    public static function prime_relationships(array $enrolledcourseids, array $pendingcourseids = []): void {
        global $USER;

        $viewer = (int) $USER->id;
        foreach (array_merge($enrolledcourseids, $pendingcourseids) as $courseid) {
            self::$related[self::memo_key($viewer, (int) $courseid)] = true;
        }
    }

    /**
     * The category of each of these courses.
     *
     * Reads the category off the item when it carries one, which both a course
     * record and a core_course_list_element do, and falls back to one query for
     * the rest - so a caller handing over real records costs nothing here, and
     * an object carrying only an id is still safe to pass. A course that no
     * longer exists gets category 0, which has no row: while any category is
     * unlisted, the category term then refuses it to everybody but a site admin.
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
     * Whether the current user is enrolled in, has applied to, is staff of, or could join this course.
     *
     * Every term but the last is a relationship the course already has with
     * this user, and they live in {@see has_course_relationship()} so the
     * category term in {@see filter_courses()} can ask for them on their own.
     * "Could join right now" is the one term that check deliberately does not
     * honour, which is why it stays here and nowhere else.
     *
     * @param int $courseid The course id.
     * @return bool True when the course may be named to this user.
     */
    private static function eligible(int $courseid): bool {
        if (self::has_course_relationship($courseid)) {
            return true;
        }

        // The visitor and guest guard in viewer_context() covers this last term as well.
        return self::viewer_context($courseid) !== null && self::can_enrol($courseid);
    }

    /**
     * Whether the current user already has a relationship with this course.
     *
     * Enrolled, staff of it, or an application pending - the three things that
     * keep a course on somebody's own listings whatever the category above it
     * says. Deliberately not {@see can_enrol()}: being able to join a course is
     * not a relationship with it ({@see filter_courses()} says why that matters).
     *
     * Memoised per viewer and course: setUser() and "log in as" switch users
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

        /* Staff keep the course. A manager is not enrolled and is usually not in
           the gating cohort, so without this term an unlisted course would vanish
           from the listings of the people who administer it.

           The two capabilities are core's own idioms for "may see this course
           without being enrolled": moodle/course:view is what is_viewing()
           tests and what managers hold, and moodle/course:viewhiddencourses is
           the one core consults to decide who still sees a hidden course - held
           by teacher, editingteacher, coursecreator and manager. Site admins
           pass both. */
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
        /* Fail closed for visitors and guests before consulting any enrol plugin.
           enrol_apply's allow_apply() checks neither guest status nor a capability, so
           a guest would otherwise pass any apply instance without a cohort restriction,
           and can_self_enrol($instance, false) skips its own guest check. */
        if (!isloggedin() || isguestuser()) {
            return null;
        }

        $context = \core\context\course::instance($courseid, IGNORE_MISSING);
        return $context ?: null;
    }

    /**
     * Whether the current user has an enrol_apply application awaiting a decision in this course.
     *
     * A waiting application is a user_enrolments row of an enrol_apply instance with a status
     * other than ENROL_USER_ACTIVE, so is_enrolled() with $onlyactive reports false for an
     * applicant who is waiting for a decision. Without this term, applying to an unlisted course
     * would make it vanish from the listing the moment the application was filed.
     *
     * Rows of other enrol plugins do not count: a suspended manual or self enrolment is a
     * decision already taken, not an application, and an enrolment whose start date is still
     * ahead is active, so this term does not match it either.
     *
     * @param int $courseid The course id.
     * @return bool True when an inactive enrol_apply row exists for this user.
     */
    private static function has_pending_enrolment(int $courseid): bool {
        global $DB, $USER;

        /* Inactive rows only, not any row: an active row is is_enrolled()'s to judge,
           and matching it here as well would make this term subsume that check. */
        $sql = "SELECT 1
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid
                   AND e.courseid = :courseid
                   AND e.enrol = :enrol
                   AND ue.status <> :active";
        return $DB->record_exists_sql($sql, [
            'userid' => $USER->id,
            'courseid' => $courseid,
            'enrol' => 'apply',
            'active' => ENROL_USER_ACTIVE,
        ]);
    }

    /**
     * Whether any enabled enrolment instance would accept the current user right now.
     *
     * Dispatches per plugin rather than calling one shared method, because
     * there is no shared method to call: enrol_plugin::can_self_enrol() is
     * `return false` in the base class and only enrol_self overrides it in
     * core, so asking every plugin through it would report "no" for
     * enrol_apply and hide the course from the people it is open to.
     *
     * The enrol_apply branch mirrors
     * {@see \theme_boost_union_fundaseg\local\hotsite\resolver}; keep the two in
     * step. allow_apply() is guarded with is_callable() because an enrol_apply
     * build without it may be installed, and the applicant limit is checked
     * separately because allow_apply() does not check it.
     *
     * Both branches also enforce the enrolment window and the places limit, so
     * an unlisted course disappears from the listing while its enrolment window
     * is shut or once it is full. That is deliberate - for enrol_self it is the
     * answer core's own enrolment icons give - but it does make the listing
     * time-dependent.
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
     * Asks the plugin rather than re-deriving the answer, so this stays in step with
     * enrol_apply's own definition of full, which leaves expired enrolments out of the count.
     * A copy here would drift, and a course whose places had been freed by expiry would read
     * as closed on this surface while enrol_apply's own pages accepted the application.
     *
     * It goes through the plugin object and not \enrol_apply\local\capacity: enrol_apply is an
     * optional dependency here, so naming a class in its namespace is a reference the
     * autoloader would have to resolve on a site without the plugin. is_callable() on the
     * object is the same guard the allow_apply() call in {@see can_enrol()} uses.
     *
     * The inline fallback is what an enrol_apply build without is_full() means by "full": on
     * such a build the unfiltered count is the plugin's own rule.
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
