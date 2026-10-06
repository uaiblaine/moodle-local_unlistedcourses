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
 * Decides whether the current user may discover a course, and what they may do to join it.
 *
 * A course in the unlisted state ({@see discoverability}) is discoverable
 * only by someone who is actively enrolled, has an enrolment that starts later,
 * has an application pending or on its waiting list, is staff of the course,
 * could enrol right now, or will be enrolled on completing another course.
 * Everyone else must not learn that it exists, so the answer feeds course
 * listings, the enrolment page and anything else that would otherwise print its
 * name. The other two states (listed, public) are discoverable by anybody.
 * Whether a public course may be served to a visitor who is not logged in is
 * {@see discoverability::is_public()}, not this; {@see filter_courses_public()}
 * is the one method here that asks it, and it reads no $USER.
 *
 * Two questions about a viewer and a course are answered here for the themes
 * and blocks that print them: the relationship their enrolment rows give them
 * and the next action the course's enrolment methods offer them. The two are
 * independent: a viewer may hold a relationship and still be offered a route in.
 *
 * The supported API for other plugins is those answers and the constants that
 * name their values (RELATIONSHIP_*, NEXT_*, BLOCKED_*, GUEST_*):
 * {@see get_enrolment_state()} for one course, {@see get_next_action()} for one
 * course, {@see get_next_actions()} for a page of courses, and
 * {@see classify_enrolment()}, the pure per-row rule behind the first, for a
 * caller that already holds the rows. A plugin that depends on this one reads
 * enrolment state through them and never re-derives it; everything private here
 * may change. The discoverability predicates ({@see is_course_discoverable()},
 * {@see filter_courses()} and their kin) are the listing layer's.
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
    /** Enrolment relationship: an active enrolment, inside its window. */
    public const RELATIONSHIP_ENROLLED = 'enrolled';

    /** Enrolment relationship: an active enrolment on an enabled instance whose start date is still ahead. */
    public const RELATIONSHIP_SCHEDULED = 'scheduled';

    /** Enrolment relationship: an enrol_apply row that is not active and has not ended, an application awaiting a decision. */
    public const RELATIONSHIP_PENDING = 'pending';

    /** Enrolment relationship: an enrol_apply row on its waiting list (status 2) that has not ended. */
    public const RELATIONSHIP_WAITLISTED = 'waitlisted';

    /** Enrolment relationship: a suspended row on an enabled instance whose end date has not passed. */
    public const RELATIONSHIP_SUSPENDED = 'suspended';

    /** Enrolment relationship: a row on an enabled instance whose end date has passed. */
    public const RELATIONSHIP_EXPIRED = 'expired';

    /** Enrolment relationship: nothing that ties the user to the course. */
    public const RELATIONSHIP_NONE = 'none';

    /** Next action: at least one enrolment method would take the viewer now; the routes list them. */
    public const NEXT_OPEN = 'open';

    /** Next action: no route, but guest access is enabled, free or behind a key. */
    public const NEXT_GUEST = 'guest';

    /** Next action: no route and no guest access, but the viewer will be enrolled on completing another course. */
    public const NEXT_CONDITIONAL = 'conditional';

    /** Next action: an enrolment method exists for the viewer and refuses them now; the reason says why. */
    public const NEXT_BLOCKED = 'blocked';

    /** Next action: nothing on offer to this viewer. */
    public const NEXT_NONE = 'none';

    /** Why a method refuses: its enrolment window is not open yet, or has closed. */
    public const BLOCKED_WINDOW = 'window';

    /** Why a method refuses: it has no place left. */
    public const BLOCKED_FULL = 'full';

    /** Why a method refuses: it is restricted to a cohort the viewer is not a member of. */
    public const BLOCKED_COHORT = 'cohort';

    /** Why a method refuses: the viewer's own enrolment stands in the way, a row on that instance or one in the course. */
    public const BLOCKED_OWN_ROW = 'own_row';

    /** Why a method refuses: it takes no new enrolments, has no price, or refuses for a reason it does not tell. */
    public const BLOCKED_OFF = 'off';

    /** Guest access with no password. */
    public const GUEST_FREE = 'free';

    /** Guest access behind a key, asked for on entry. */
    public const GUEST_KEY = 'key';

    /** The user_enrolments status of an enrol_apply waiting-list row; that plugin's own constant may be absent. */
    private const APPLY_WAITLIST = 2;

    /** The rank of each relationship: when several rows exist the highest wins. */
    private const RELATIONSHIP_RANK = [
        self::RELATIONSHIP_ENROLLED => 6,
        self::RELATIONSHIP_SCHEDULED => 5,
        self::RELATIONSHIP_PENDING => 4,
        self::RELATIONSHIP_WAITLISTED => 3,
        self::RELATIONSHIP_SUSPENDED => 2,
        self::RELATIONSHIP_EXPIRED => 1,
        self::RELATIONSHIP_NONE => 0,
    ];

    /** The relationships that keep an unlisted course discoverable; suspended and expired do not. */
    private const DISCOVERABLE_RELATIONSHIPS = [
        self::RELATIONSHIP_ENROLLED,
        self::RELATIONSHIP_SCHEDULED,
        self::RELATIONSHIP_PENDING,
        self::RELATIONSHIP_WAITLISTED,
    ];

    /** The next actions that let the viewer into the course, and so keep an unlisted course discoverable. */
    private const ENROLABLE_ACTIONS = [self::NEXT_OPEN, self::NEXT_GUEST, self::NEXT_CONDITIONAL];

    /** The refusal reasons, the most useful to a learner first: when several methods refuse, the first wins. */
    private const BLOCKED_PRIORITY = [
        self::BLOCKED_WINDOW,
        self::BLOCKED_FULL,
        self::BLOCKED_COHORT,
        self::BLOCKED_OWN_ROW,
        self::BLOCKED_OFF,
    ];

    /** The enrol plugins the next action has a rule for; any other method offers a learner nothing to act on. */
    private const ROUTE_PLUGINS = ['self', 'apply', 'fee', 'paypal', 'guest', 'autoenrol', 'coursecompleted'];

    /** The next action of a viewer who is offered nothing. */
    private const NO_NEXT_ACTION = [
        'type' => self::NEXT_NONE,
        'routes' => [],
        'guest' => null,
        'conditional' => null,
        'blocked' => null,
    ];

    /** @var array Request cache of the enrolment relationship, keyed "userid:courseid" => classification. */
    private static array $enrolmentstate = [];

    /** @var array Request cache of the discoverability answer, keyed "userid:courseid" => bool. */
    private static array $discoverable = [];

    /** @var array Request cache of the course relationship, keyed "userid:courseid" => bool. */
    private static array $related = [];

    /** @var array Request cache of the next action, keyed "userid:courseid" => the answer of get_next_action(). */
    private static array $nextaction = [];

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
        self::$enrolmentstate = [];
        self::$nextaction = [];
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
     * @param array $pendingcourseids Course ids this user has an application awaiting a decision in, on its
     *        waiting list included.
     * @param array $scheduledcourseids Course ids this user holds an active enrolment in that starts later.
     * @return void
     */
    public static function prime_relationships(
        array $enrolledcourseids,
        array $pendingcourseids = [],
        array $scheduledcourseids = []
    ): void {
        global $USER;

        $viewer = (int) $USER->id;
        foreach (array_merge($enrolledcourseids, $pendingcourseids, $scheduledcourseids) as $courseid) {
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
     * Enrolled, enrolled from a later date, staff of it, or an application pending or on its
     * waiting list - the things that keep a course on somebody's own listings whatever the
     * category above it says. Deliberately not {@see can_enrol()}: being able to join a course
     * is not a relationship with it ({@see filter_courses()} says why that matters).
     *
     * Memoised per viewer and course: setUser() and "log in as" switch users
     * inside one request, and this answer is entirely a property of the viewer.
     *
     * @param int $courseid The course id.
     * @return bool True when this user is enrolled or scheduled, is staff, or has applied.
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
     * @return bool True when this user is enrolled or scheduled, is staff, or has applied.
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

        // An allow-list, not "anything but none": a suspended or ended enrolment ties nobody to an unlisted course.
        return in_array(self::get_enrolment_state($courseid)['type'], self::DISCOVERABLE_RELATIONSHIPS, true);
    }

    /**
     * Whether the current user may not be asked about any course: a visitor who is not logged in, or the guest account.
     *
     * One place for the visitor and guest guard of the per-course path ({@see viewer_context()})
     * and of the batch ({@see get_next_actions()}), so that neither can be written without it.
     *
     * @return bool True for a visitor or the guest account.
     */
    private static function refuses_viewer(): bool {
        /* Fail closed for visitors and guests before consulting any enrol plugin.
           enrol_apply's allow_apply() checks neither guest status nor a capability, so
           a guest would otherwise pass any apply instance without a cohort restriction,
           and can_self_enrol($instance, false) skips its own guest check. */
        return !isloggedin() || isguestuser();
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
        if (self::refuses_viewer()) {
            return null;
        }

        $context = \core\context\course::instance($courseid, IGNORE_MISSING);
        return $context ?: null;
    }

    /**
     * Classify one user_enrolments row against a point in time.
     *
     * The single owner of the rule that turns an enrolment row into a relationship with the
     * course, so that this class and a caller that already reads the rows (a listing, a card)
     * cannot drift apart. Pure: no database, no $USER. The branches, in the order they are
     * tried:
     *
     * - pending and waitlisted: a row of an enrol_apply instance that is not active and whose end
     *   date is unset or still ahead, an application awaiting a decision; waitlisted when the row
     *   is on the waiting list (status 2). That is enrol_apply's own queue rule, the authority on
     *   what awaits a decision: it reads neither the start date nor the instance status, so it is
     *   tried before either. An approved enrolment re-suspended by the expiry sweep after its end
     *   date is not awaiting anything, and falls through to expired.
     * - none: a row on a disabled instance (a staff setting, which tells the learner nothing),
     *   and a row whose end date precedes its start date, which core skips as well.
     * - expired: any row whose end date has passed, whatever its status - an ended enrolment, an
     *   approved application the expiry sweep suspended, a waiting-list row the queue no longer
     *   counts.
     * - suspended: a row that is not active and has not ended - a suspended manual enrolment, or
     *   a self enrolment a teacher suspended.
     * - scheduled: an active row whose start date is still ahead. Core does not call this user
     *   enrolled yet, but an administrator decided they take part, so the course is theirs.
     * - enrolled: an active row, now inside its window.
     *
     * @param \stdClass $row A user_enrolments row joined with its instance: status, timestart,
     *        timeend, enrol (the plugin name) and instancestatus.
     * @param int $now The time to judge against.
     * @return array {type: one of the RELATIONSHIP_* constants, startsat: int, endsat: int}, the
     *         dates being the row's own timestart and timeend, 0 when unset and for none.
     */
    public static function classify_enrolment(\stdClass $row, int $now): array {
        $status = (int) $row->status;
        $timestart = (int) $row->timestart;
        $timeend = (int) $row->timeend;
        $none = ['type' => self::RELATIONSHIP_NONE, 'startsat' => 0, 'endsat' => 0];

        // Must agree with \enrol_apply\local\queue::is_awaiting_decision(), which is the same test.
        if ($status !== ENROL_USER_ACTIVE && $row->enrol === 'apply' && ($timeend === 0 || $timeend > $now)) {
            $type = ($status === self::APPLY_WAITLIST) ? self::RELATIONSHIP_WAITLISTED : self::RELATIONSHIP_PENDING;
            return ['type' => $type, 'startsat' => $timestart, 'endsat' => $timeend];
        }

        if ((int) $row->instancestatus !== ENROL_INSTANCE_ENABLED) {
            return $none;
        }
        if ($timeend !== 0 && $timeend < $timestart) {
            return $none;
        }

        if ($status === self::APPLY_WAITLIST && $row->enrol === 'apply') {
            /* A waiting-list row whose end date has passed: the queue no longer counts it, so it
               is expired, the answer the end-date test below would give. It stays a branch of its
               own so the waiting list's whole rule reads in one place, next to its awaiting case. */
            return ['type' => self::RELATIONSHIP_EXPIRED, 'startsat' => $timestart, 'endsat' => $timeend];
        }
        if ($timeend !== 0 && $timeend <= $now) {
            return ['type' => self::RELATIONSHIP_EXPIRED, 'startsat' => $timestart, 'endsat' => $timeend];
        }
        if ($status !== ENROL_USER_ACTIVE) {
            return ['type' => self::RELATIONSHIP_SUSPENDED, 'startsat' => $timestart, 'endsat' => $timeend];
        }
        $type = $timestart > $now ? self::RELATIONSHIP_SCHEDULED : self::RELATIONSHIP_ENROLLED;
        return ['type' => $type, 'startsat' => $timestart, 'endsat' => $timeend];
    }

    /**
     * How the current user's enrolments tie them to a course, with the dates.
     *
     * One statement for the course, memoised per viewer. When several rows exist the highest rank
     * wins: enrolled, scheduled, pending, waitlisted, suspended, expired, none. The dates are the
     * winning row's own: between two scheduled rows the earlier start wins, which is the date the
     * user waits for; between two expired rows the later end wins, which is when their access
     * ended. It is the payload a surface needs to say "access opens on DATE" or "your enrolment
     * ended on DATE"; rendering is the caller's. A visitor or guest, and the site course, which has no rows, answer none
     * without a statement. Staff and "could enrol" are not enrolments and are not reported here.
     *
     * @param int $courseid The course id.
     * @return array {type: RELATIONSHIP_*, startsat: int, endsat: int}, as {@see classify_enrolment()}.
     */
    public static function get_enrolment_state(int $courseid): array {
        global $DB, $USER;

        $none = ['type' => self::RELATIONSHIP_NONE, 'startsat' => 0, 'endsat' => 0];
        if ($courseid == SITEID || !isloggedin() || isguestuser()) {
            return $none;
        }

        $key = self::memo_key((int) $USER->id, $courseid);
        if (isset(self::$enrolmentstate[$key])) {
            return self::$enrolmentstate[$key];
        }

        $rows = $DB->get_records_sql(
            "SELECT ue.id, ue.status, ue.timestart, ue.timeend, e.enrol, e.status AS instancestatus
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :userid
                AND e.courseid = :courseid",
            ['userid' => $USER->id, 'courseid' => $courseid]
        );

        $now = time();
        $best = $none;
        foreach ($rows as $row) {
            $state = self::classify_enrolment($row, $now);
            $stronger = self::RELATIONSHIP_RANK[$state['type']] > self::RELATIONSHIP_RANK[$best['type']];
            $sametype = $state['type'] === $best['type'];
            $earlier = $sametype && $state['type'] === self::RELATIONSHIP_SCHEDULED && $state['startsat'] < $best['startsat'];
            $later = $sametype && $state['type'] === self::RELATIONSHIP_EXPIRED && $state['endsat'] > $best['endsat'];
            if ($stronger || $earlier || $later) {
                $best = $state;
            }
        }

        self::$enrolmentstate[$key] = $best;
        return $best;
    }

    /**
     * What the current user may do next to join this course, asking each enrolment method its own question.
     *
     * Independent of the relationship: a viewer who is enrolled from a later date, has applied,
     * or is suspended may still be offered another route in, which is core's default and the
     * teacher's choice through each method's own settings. The summary, best first:
     *
     * - open: at least one route would take the viewer now; `routes` lists every one, in the
     *   course's own order of methods.
     * - guest: no route, but an enabled guest instance; `guest` is free when one asks for no key.
     * - conditional: no route and no guest access, but an enrol_coursecompleted instance inside
     *   its window, for a viewer actively enrolled in the course it names; the viewer will be
     *   enrolled on completing that course.
     * - blocked: no offer, but a method that exists for the viewer refuses them now; `blocked`
     *   carries the most useful reason ({@see BLOCKED_PRIORITY}).
     * - none: nothing on offer. A visitor who is not logged in, the guest account, a missing
     *   course and the site course answer none without asking any plugin.
     *
     * `guest` and `conditional` describe offers and are filled whenever one exists, whatever the
     * summary; `blocked` is filled only when the summary is blocked.
     *
     * The methods and what each is asked - the question its own enrolment page asks:
     *
     * - self (route): can_self_enrol(), strictly true.
     * - apply (route): allow_apply() and the places limit, on an instance where the viewer holds
     *   no row. The branch mirrors {@see \theme_boost_union_fundaseg\local\hotsite\resolver}; keep
     *   the two in step.
     * - fee and paypal (route): the conditions their enrol_page_hook() tests before it offers the
     *   payment button - no row of the viewer's on the instance, the enrolment window open, and a
     *   cost - which the same theme resolver mirrors for fee.
     * - guest: any enabled instance. A key, when set, is asked for on entry.
     * - autoenrol (route): the plugin's own enrol_allowed(), the check behind its enrolment page
     *   and its enrol-me link: its rule, its window, its limit and the viewer's enrolments.
     * - coursecompleted: an instance inside its window on which the viewer holds no row, for a
     *   viewer actively enrolled in the course it names. Anyone else has no tie to the
     *   prerequisite and is offered nothing by it, not even a refusal: naming the course to them
     *   would defeat the unlisting.
     *
     * The reason of a refusal is read from the instance in the plugin's own order of checks,
     * after the plugin said no; a refusal those fields do not explain (the self enrolment
     * capability, autoenrol's rule) is off.
     *
     * This answers what may be done, not whether the course may be named: a caller that prints
     * it for a course in a listing must already have filtered the listing with
     * {@see filter_courses()}. Statements per instance; for a page of courses use
     * {@see get_next_actions()}. Memoised per viewer and course.
     *
     * @param int $courseid The course id.
     * @return array {type: NEXT_*, routes: array of {kind: the enrol plugin name, instanceid: int},
     *         guest: GUEST_*|null, conditional: {prerequisiteid: int, instanceid: int}|null,
     *         blocked: BLOCKED_*|null}.
     */
    public static function get_next_action(int $courseid): array {
        global $USER;

        $key = self::memo_key((int) $USER->id, $courseid);
        if (!array_key_exists($key, self::$nextaction)) {
            self::$nextaction[$key] = self::evaluate_next_action($courseid, true);
        }
        return self::$nextaction[$key];
    }

    /**
     * Whether the course's next action for the current user lets them in: open, guest or conditional.
     *
     * The same evaluation as {@see get_next_action()}, without working out why a refusing method
     * refuses: those are the statements a listing probing unrelated unlisted courses would pay
     * for an answer it never reads.
     *
     * @param int $courseid The course id.
     * @return bool True when at least one instance offers this user a way in.
     */
    private static function can_enrol(int $courseid): bool {
        global $USER;

        $key = self::memo_key((int) $USER->id, $courseid);
        $answer = self::$nextaction[$key] ?? self::evaluate_next_action($courseid, false);
        return in_array($answer['type'], self::ENROLABLE_ACTIONS, true);
    }

    /**
     * Ask every enabled instance of the course what it offers the current user, and summarise.
     *
     * @param int $courseid The course id.
     * @param bool $withreasons Whether to work out why a refusing method refuses.
     * @return array The answer, shaped as {@see get_next_action()}'s; blocked is null when reasons were skipped.
     */
    private static function evaluate_next_action(int $courseid, bool $withreasons): array {
        global $CFG;

        if ($courseid == SITEID || self::viewer_context($courseid) === null) {
            return self::NO_NEXT_ACTION;
        }

        require_once($CFG->dirroot . '/enrol/self/lib.php');

        $now = time();
        $outcomes = [];
        foreach (enrol_get_instances($courseid, true) as $instance) {
            $plugin = enrol_get_plugin($instance->enrol);
            if (!$plugin) {
                continue;
            }
            $outcome = self::instance_outcome($instance, $plugin, $now, $withreasons);
            if ($outcome !== null) {
                $outcomes[] = $outcome;
            }
        }
        return self::summarise($outcomes);
    }

    /**
     * What one enabled instance offers the current user, asking the plugin's own methods.
     *
     * The methods outside core are optional here. allow_apply() and enrol_allowed() are called
     * through the plugin object behind is_callable(), because a build without them may be
     * installed and naming a class of theirs would need the autoloader to find it;
     * coursecompleted is read from its instance alone. The applicant limit is checked separately
     * because allow_apply() does not check it.
     *
     * Every method but guest also enforces an enrolment window, and some a places limit, so an
     * unlisted course disappears from the listing while its window is shut or once it is full.
     * That is deliberate - for enrol_self it is the answer core's own enrolment icons give - but
     * it does make the listing time-dependent.
     *
     * @param \stdClass $instance Enrol instance, enabled, of an enabled plugin.
     * @param \enrol_plugin $plugin The instance's plugin.
     * @param int $now The time windows are judged against.
     * @param bool $withreasons Whether to work out why a refusing method refuses.
     * @return array|null The outcome ({@see outcome()}), or null when the instance offers this viewer nothing to act on.
     */
    private static function instance_outcome(
        \stdClass $instance,
        \enrol_plugin $plugin,
        int $now,
        bool $withreasons
    ): ?array {
        global $USER;

        if ($instance->enrol === 'self') {
            /* Strictly === true: can_self_enrol() returns true, an error string, or
               null - the last when customint5 points at a deleted cohort, which
               cohort_delete_cohort() never clears. Only === true fails closed. */
            if ($plugin->can_self_enrol($instance, false) === true) {
                return self::outcome($instance, self::NEXT_OPEN);
            }
            if (!$withreasons) {
                return self::outcome($instance, self::NEXT_BLOCKED);
            }
            $reason = self::self_refusal(
                $instance,
                $now,
                self::holds_enrolment($instance),
                self::places_taken($instance, (int) $instance->customint3),
                self::passes_cohort((int) $instance->customint5)
            );
            return self::outcome($instance, self::NEXT_BLOCKED, $reason ?? self::BLOCKED_OFF);
        }

        if ($instance->enrol === 'apply' && is_callable([$plugin, 'allow_apply'])) {
            // An application already made, decided or not, is no route: allow_apply() does not look for one.
            if (self::holds_enrolment($instance)) {
                return self::outcome($instance, self::NEXT_BLOCKED, self::BLOCKED_OWN_ROW);
            }
            if ($plugin->allow_apply($instance) !== true) {
                if (!$withreasons) {
                    return self::outcome($instance, self::NEXT_BLOCKED);
                }
                $reason = self::apply_refusal($instance, $now, self::passes_cohort((int) $instance->customint5));
                return self::outcome($instance, self::NEXT_BLOCKED, $reason ?? self::BLOCKED_OFF);
            }
            if (self::apply_is_full($instance, $plugin)) {
                return self::outcome($instance, self::NEXT_BLOCKED, self::BLOCKED_FULL);
            }
            return self::outcome($instance, self::NEXT_OPEN);
        }

        if ($instance->enrol === 'fee' || $instance->enrol === 'paypal') {
            return self::payment_outcome($instance, $plugin, $now, self::holds_enrolment($instance));
        }

        if ($instance->enrol === 'guest') {
            return self::guest_outcome($instance);
        }

        if ($instance->enrol === 'autoenrol' && is_callable([$plugin, 'enrol_allowed'])) {
            if ($plugin->enrol_allowed($instance, $USER) === true) {
                return self::outcome($instance, self::NEXT_OPEN);
            }
            if (!$withreasons) {
                return self::outcome($instance, self::NEXT_BLOCKED);
            }
            $context = \core\context\course::instance((int) $instance->courseid);
            $reason = self::autoenrol_refusal(
                $instance,
                $now,
                self::holds_enrolment($instance),
                is_enrolled($context, $USER),
                self::places_taken($instance, (int) $instance->customint5)
            );
            return self::outcome($instance, self::NEXT_BLOCKED, $reason ?? self::BLOCKED_OFF);
        }

        if ($instance->enrol === 'coursecompleted') {
            // The prerequisite is the course the instance names (customint1); only a viewer
            // working towards it is promised this one.
            $prerequisite = \core\context\course::instance((int) $instance->customint1, IGNORE_MISSING);
            if (!$prerequisite || !is_enrolled($prerequisite, $USER, '', true)) {
                return null;
            }
            return self::completed_outcome($instance, $now, self::holds_enrolment($instance));
        }

        return null;
    }

    /**
     * What the current user may do next to join each of these courses, in a fixed number of statements.
     *
     * The batch form of {@see get_next_action()} for a page of courses, ported from the
     * classifier of the theme's category showcase: it never asks a plugin per course, it reads
     * what each plugin's rule reads from the instance and from at most four bulk statements, all
     * array-returning, so the count is the same on PostgreSQL and MariaDB:
     *
     * 1. the enabled instances of the enabled plugins this class has a rule for, in these courses;
     * 2. every row the viewer holds, driven by their user id alone (the own-row tests, autoenrol's
     *    "already enrolled" test and the course completed prerequisite all read from it);
     * 3. the viewer's cohorts, only when an instance restricts by one;
     * 4. one grouped count of the rows on every capped instance, only when an instance is capped.
     *
     * What cannot be read that way is dropped, which can only make a refusing method read open:
     * enrol_self's enrol/self:enrolself capability and enrol_autoenrol's rule (customtext2, judged
     * per user by availability conditions). Everything else is the per-course rule. So this may
     * answer open where {@see get_next_action()} answers blocked, never the reverse, and it must
     * therefore never decide whether a course may be named: discoverability always goes through
     * {@see filter_courses()}, which asks the plugins.
     *
     * The course ids go into one IN list, and get_in_or_equal() does not chunk: pass a page of
     * courses, never a whole population. A visitor, the guest account, the site course and an id
     * with no instance answer none; a visitor and the guest account cost no statement.
     *
     * @param array $courseids Course ids.
     * @return array Map of courseid => the answer of {@see get_next_action()}, in the order given.
     */
    public static function get_next_actions(array $courseids): array {
        global $DB, $USER;

        $courseids = array_values(array_unique(array_map('intval', $courseids)));
        $answers = array_fill_keys($courseids, self::NO_NEXT_ACTION);
        $asked = array_values(array_diff($courseids, [(int) SITEID]));
        if (!$asked || self::refuses_viewer()) {
            return $answers;
        }

        $plugins = [];
        foreach (enrol_get_plugins(true) as $name => $plugin) {
            if (!in_array($name, self::ROUTE_PLUGINS, true)) {
                continue;
            }
            // The same is_callable() guards as the per-course path: a build without the method offers nothing.
            if (
                ($name === 'apply' && !is_callable([$plugin, 'allow_apply']))
                || ($name === 'autoenrol' && !is_callable([$plugin, 'enrol_allowed']))
            ) {
                continue;
            }
            $plugins[$name] = $plugin;
        }
        if (!$plugins) {
            return $answers;
        }

        // 1. The enabled instances, in each course's own order of methods.
        [$coursesql, $courseparams] = $DB->get_in_or_equal($asked, SQL_PARAMS_NAMED, 'crs');
        [$pluginsql, $pluginparams] = $DB->get_in_or_equal(array_keys($plugins), SQL_PARAMS_NAMED, 'plg');
        $instances = $DB->get_records_sql(
            "SELECT e.id, e.courseid, e.enrol, e.enrolstartdate, e.enrolenddate, e.password, e.cost,
                    e.customint1, e.customint3, e.customint4, e.customint5, e.customint6, e.customint8
               FROM {enrol} e
              WHERE e.courseid {$coursesql} AND e.status = :enabled AND e.enrol {$pluginsql}
           ORDER BY e.sortorder, e.id",
            $courseparams + $pluginparams + ['enabled' => ENROL_INSTANCE_ENABLED]
        );
        if (!$instances) {
            return $answers;
        }

        // 2. Every row the viewer holds; rides the userid foreign key of {user_enrolments}.
        $rows = $DB->get_records_sql(
            "SELECT ue.id, ue.enrolid, ue.status, ue.timestart, ue.timeend,
                    e.courseid, e.enrol, e.status AS instancestatus
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :userid",
            ['userid' => $USER->id]
        );
        $now = time();
        $held = [];
        $incourse = [];
        $active = [];
        foreach ($rows as $row) {
            $held[(int) $row->enrolid] = true;
            $incourse[(int) $row->courseid] = true;
            // An active enrolment as is_enrolled() reads it: the row active, the instance enabled, now inside the window.
            if (self::classify_enrolment($row, $now)['type'] === self::RELATIONSHIP_ENROLLED) {
                $active[(int) $row->courseid] = true;
            }
        }

        // Enrol_self and enrol_apply: a cohort in customint5, a limit in customint3. Enrol_autoenrol: a limit in customint5.
        $restricted = false;
        $capped = [];
        foreach ($instances as $instance) {
            $cohorted = $instance->enrol === 'self' || $instance->enrol === 'apply';
            if ($cohorted && (int) $instance->customint5 > 0) {
                $restricted = true;
            }
            $cap = match ($instance->enrol) {
                'self', 'apply' => (int) $instance->customint3,
                'autoenrol' => (int) $instance->customint5,
                default => 0,
            };
            if ($cap > 0) {
                $capped[] = (int) $instance->id;
            }
        }

        // 3. The viewer's cohorts; rides the userid foreign key of {cohort_members}.
        $cohorts = [];
        if ($restricted) {
            $cohorts = array_flip(array_map('intval', $DB->get_fieldset_select(
                'cohort_members',
                'cohortid',
                'userid = :userid',
                ['userid' => $USER->id]
            )));
        }

        // 4. The rows on every capped instance, all of them and the ones that have not ended (enrol_apply's count).
        $taken = [];
        if ($capped) {
            [$capsql, $capparams] = $DB->get_in_or_equal($capped, SQL_PARAMS_NAMED, 'cap');
            $taken = $DB->get_records_sql(
                "SELECT ue.enrolid, COUNT(ue.id) AS total,
                        SUM(CASE WHEN ue.timeend = 0 OR ue.timeend > :now THEN 1 ELSE 0 END) AS live
                   FROM {user_enrolments} ue
                  WHERE ue.enrolid {$capsql}
               GROUP BY ue.enrolid",
                $capparams + ['now' => $now]
            );
        }

        $outcomes = [];
        foreach ($instances as $instance) {
            $id = (int) $instance->id;
            $outcome = self::batch_outcome(
                $instance,
                $plugins[$instance->enrol],
                $now,
                isset($held[$id]),
                (int) ($taken[$id]->total ?? 0),
                (int) ($taken[$id]->live ?? 0),
                (int) $instance->customint5 === 0 || isset($cohorts[(int) $instance->customint5]),
                isset($incourse[(int) $instance->courseid]),
                isset($active[(int) $instance->customint1])
            );
            if ($outcome !== null) {
                $outcomes[(int) $instance->courseid][] = $outcome;
            }
        }
        foreach ($outcomes as $courseid => $courseoutcomes) {
            $answers[$courseid] = self::summarise($courseoutcomes);
        }
        return $answers;
    }

    /**
     * What one enabled instance offers the current user, from facts read in bulk: the batch's rule.
     *
     * @param \stdClass $instance Enrol instance, enabled, of an enabled plugin.
     * @param \enrol_plugin $plugin The instance's plugin.
     * @param int $now The time windows are judged against.
     * @param bool $holdsrow Whether the viewer holds a row on the instance.
     * @param int $taken The rows on the instance, 0 when it is not capped.
     * @param int $live The rows on the instance whose end date is unset or ahead, 0 when it is not capped.
     * @param bool $incohort Whether the instance admits the viewer by cohort: unrestricted, or a member.
     * @param bool $incourse Whether the viewer holds any row in the instance's course.
     * @param bool $prerequisite Whether the viewer is actively enrolled in the course customint1 names.
     * @return array|null The outcome ({@see outcome()}), or null when the instance offers this viewer nothing to act on.
     */
    private static function batch_outcome(
        \stdClass $instance,
        \enrol_plugin $plugin,
        int $now,
        bool $holdsrow,
        int $taken,
        int $live,
        bool $incohort,
        bool $incourse,
        bool $prerequisite
    ): ?array {
        switch ($instance->enrol) {
            case 'self':
                // Dropped: the enrol/self:enrolself capability, which can only make a refusal read open.
                $reason = self::self_refusal($instance, $now, $holdsrow, $taken, $incohort);
                return self::outcome($instance, $reason === null ? self::NEXT_OPEN : self::NEXT_BLOCKED, $reason);

            case 'apply':
                $reason = $holdsrow ? self::BLOCKED_OWN_ROW : self::apply_refusal($instance, $now, $incohort);
                $cap = (int) $instance->customint3;
                // The count apply_is_full() reads: the rows not ended when the plugin has is_full(), else every row.
                $count = is_callable([$plugin, 'is_full']) ? $live : $taken;
                if ($reason === null && $cap > 0 && $count >= $cap) {
                    $reason = self::BLOCKED_FULL;
                }
                return self::outcome($instance, $reason === null ? self::NEXT_OPEN : self::NEXT_BLOCKED, $reason);

            case 'fee':
            case 'paypal':
                return self::payment_outcome($instance, $plugin, $now, $holdsrow);

            case 'guest':
                return self::guest_outcome($instance);

            case 'autoenrol':
                // Dropped: the rule in customtext2, judged per user, which can only make a refusal read open.
                $reason = self::autoenrol_refusal($instance, $now, $holdsrow, $incourse, $taken);
                return self::outcome($instance, $reason === null ? self::NEXT_OPEN : self::NEXT_BLOCKED, $reason);

            case 'coursecompleted':
                return $prerequisite ? self::completed_outcome($instance, $now, $holdsrow) : null;

            default:
                return null;
        }
    }

    /**
     * Why enrol_self refuses, from the instance, in the order its is_self_enrol_available() tests.
     *
     * @param \stdClass $instance Enrol instance of enrol_self.
     * @param int $now The time the window is judged against.
     * @param bool $holdsrow Whether the viewer holds a row on the instance, in any status.
     * @param int $taken The rows on the instance, every status and date, as enrol_self counts them.
     * @param bool $incohort Whether the instance admits the viewer by cohort: unrestricted, or a member.
     * @return string|null A BLOCKED_* reason, or null when none of these refuses.
     */
    private static function self_refusal(\stdClass $instance, int $now, bool $holdsrow, int $taken, bool $incohort): ?string {
        if (!self::inside_window($instance, $now)) {
            return self::BLOCKED_WINDOW;
        }
        if ((int) $instance->customint6 === 0) {
            return self::BLOCKED_OFF;
        }
        if ($holdsrow) {
            return self::BLOCKED_OWN_ROW;
        }
        $cap = (int) $instance->customint3;
        if ($cap > 0 && $taken >= $cap) {
            return self::BLOCKED_FULL;
        }
        if (!$incohort) {
            return self::BLOCKED_COHORT;
        }
        return null;
    }

    /**
     * Why enrol_apply's allow_apply() refuses, from the instance, in the order it tests.
     *
     * The viewer's own row and the places limit are not allow_apply()'s and are tested around it.
     *
     * @param \stdClass $instance Enrol instance of enrol_apply.
     * @param int $now The time the window is judged against.
     * @param bool $incohort Whether the instance admits the viewer by cohort: unrestricted, or a member;
     *        false for the -1 restore sentinel, which enrol_apply refuses.
     * @return string|null A BLOCKED_* reason, or null when none of these refuses.
     */
    private static function apply_refusal(\stdClass $instance, int $now, bool $incohort): ?string {
        if ((int) $instance->customint6 === 0) {
            return self::BLOCKED_OFF;
        }
        if (!self::inside_window($instance, $now)) {
            return self::BLOCKED_WINDOW;
        }
        if (!$incohort) {
            return self::BLOCKED_COHORT;
        }
        return null;
    }

    /**
     * Why enrol_autoenrol's enrol_allowed() refuses, from the instance, in the order it tests.
     *
     * Its last check, the rule in customtext2, is judged per user by availability conditions and
     * is not read here: a refusal that comes from it is explained by none of these.
     *
     * @param \stdClass $instance Enrol instance of enrol_autoenrol.
     * @param int $now The time the window is judged against.
     * @param bool $holdsrow Whether the viewer holds a row on the instance.
     * @param bool $incourse Whether the viewer holds any enrolment in the course, in any status.
     * @param int $taken The rows on the instance, as the plugin counts them against customint5.
     * @return string|null A BLOCKED_* reason, or null when none of these refuses.
     */
    private static function autoenrol_refusal(
        \stdClass $instance,
        int $now,
        bool $holdsrow,
        bool $incourse,
        int $taken
    ): ?string {
        if ($holdsrow) {
            return self::BLOCKED_OWN_ROW;
        }
        // Customint8 off: the plugin does not enrol somebody already enrolled by another method.
        if ((int) $instance->customint8 === 0 && $incourse) {
            return self::BLOCKED_OWN_ROW;
        }
        if ((int) $instance->customint4 === 0) {
            return self::BLOCKED_OFF;
        }
        if (!self::inside_window($instance, $now)) {
            return self::BLOCKED_WINDOW;
        }
        $cap = (int) $instance->customint5;
        if ($cap > 0 && $taken >= $cap) {
            return self::BLOCKED_FULL;
        }
        return null;
    }

    /**
     * What an enrol_fee or enrol_paypal instance offers: the conditions their enrol_page_hook() tests.
     *
     * Shared by both paths, because every condition is read from the instance: no row of the
     * viewer's on it, the enrolment window open, and a cost.
     *
     * @param \stdClass $instance Enrol instance of enrol_fee or enrol_paypal.
     * @param \enrol_plugin $plugin The instance's plugin.
     * @param int $now The time the window is judged against.
     * @param bool $holdsrow Whether the viewer holds a row on the instance.
     * @return array The outcome ({@see outcome()}).
     */
    private static function payment_outcome(\stdClass $instance, \enrol_plugin $plugin, int $now, bool $holdsrow): array {
        if ($holdsrow) {
            return self::outcome($instance, self::NEXT_BLOCKED, self::BLOCKED_OWN_ROW);
        }
        if (!self::inside_window($instance, $now)) {
            return self::outcome($instance, self::NEXT_BLOCKED, self::BLOCKED_WINDOW);
        }
        if (abs(self::payment_cost($instance, $plugin)) < 0.01) {
            return self::outcome($instance, self::NEXT_BLOCKED, self::BLOCKED_OFF);
        }
        return self::outcome($instance, self::NEXT_OPEN);
    }

    /**
     * What an enabled guest instance offers: guest access, free or behind its key.
     *
     * @param \stdClass $instance Enrol instance of enrol_guest.
     * @return array The outcome ({@see outcome()}).
     */
    private static function guest_outcome(\stdClass $instance): array {
        $outcome = self::outcome($instance, self::NEXT_GUEST);
        $outcome['guest'] = ((string) $instance->password === '') ? self::GUEST_FREE : self::GUEST_KEY;
        return $outcome;
    }

    /**
     * What an enrol_coursecompleted instance offers a viewer working towards its prerequisite.
     *
     * It enrols nobody now; that viewer will be enrolled on completing the course it names, which
     * is reason enough to let them find this one. A row of theirs on the instance, or a shut
     * window, ends that.
     *
     * @param \stdClass $instance Enrol instance of enrol_coursecompleted.
     * @param int $now The time the window is judged against.
     * @param bool $holdsrow Whether the viewer holds a row on the instance.
     * @return array The outcome ({@see outcome()}).
     */
    private static function completed_outcome(\stdClass $instance, int $now, bool $holdsrow): array {
        if ($holdsrow) {
            return self::outcome($instance, self::NEXT_BLOCKED, self::BLOCKED_OWN_ROW);
        }
        if (!self::inside_window($instance, $now)) {
            return self::outcome($instance, self::NEXT_BLOCKED, self::BLOCKED_WINDOW);
        }
        $outcome = self::outcome($instance, self::NEXT_CONDITIONAL);
        $outcome['prerequisiteid'] = (int) $instance->customint1;
        return $outcome;
    }

    /**
     * One instance's outcome: what it offers, and the facts the summary reads.
     *
     * @param \stdClass $instance Enrol instance.
     * @param string $type NEXT_OPEN, NEXT_GUEST, NEXT_CONDITIONAL or NEXT_BLOCKED.
     * @param string|null $blocked The BLOCKED_* reason of a refusal, when known.
     * @return array {type, kind: the plugin name, instanceid, guest: GUEST_*|null, prerequisiteid: int, blocked}.
     */
    private static function outcome(\stdClass $instance, string $type, ?string $blocked = null): array {
        return [
            'type' => $type,
            'kind' => (string) $instance->enrol,
            'instanceid' => (int) $instance->id,
            'guest' => null,
            'prerequisiteid' => 0,
            'blocked' => $blocked,
        ];
    }

    /**
     * Sum the outcomes of a course's instances up into its next action.
     *
     * Open beats guest, guest beats conditional, conditional beats blocked; free guest access
     * beats guest access behind a key; the first conditional instance is the one reported; the
     * reason of a blocked summary is the most useful one any refusing instance gave.
     *
     * @param array $outcomes The outcomes ({@see outcome()}), in the course's order of methods.
     * @return array The answer, shaped as {@see get_next_action()}'s.
     */
    private static function summarise(array $outcomes): array {
        $routes = [];
        $guest = null;
        $conditional = null;
        $blocked = null;
        $refused = false;
        foreach ($outcomes as $outcome) {
            if ($outcome['type'] === self::NEXT_OPEN) {
                $routes[] = ['kind' => $outcome['kind'], 'instanceid' => $outcome['instanceid']];
            } else if ($outcome['type'] === self::NEXT_GUEST) {
                if ($guest !== self::GUEST_FREE) {
                    $guest = $outcome['guest'];
                }
            } else if ($outcome['type'] === self::NEXT_CONDITIONAL) {
                if ($conditional === null) {
                    $conditional = ['prerequisiteid' => $outcome['prerequisiteid'], 'instanceid' => $outcome['instanceid']];
                }
            } else {
                $refused = true;
                $blocked = self::more_useful($blocked, $outcome['blocked']);
            }
        }

        if ($routes) {
            $type = self::NEXT_OPEN;
        } else if ($guest !== null) {
            $type = self::NEXT_GUEST;
        } else if ($conditional !== null) {
            $type = self::NEXT_CONDITIONAL;
        } else if ($refused) {
            $type = self::NEXT_BLOCKED;
        } else {
            $type = self::NEXT_NONE;
        }

        return [
            'type' => $type,
            'routes' => $routes,
            'guest' => $guest,
            'conditional' => $conditional,
            'blocked' => $type === self::NEXT_BLOCKED ? $blocked : null,
        ];
    }

    /**
     * The more useful of two refusal reasons, by {@see BLOCKED_PRIORITY}.
     *
     * @param string|null $current The reason so far, or null.
     * @param string|null $candidate The next refusal's reason, or null when it was not worked out.
     * @return string|null The more useful one.
     */
    private static function more_useful(?string $current, ?string $candidate): ?string {
        if ($candidate === null) {
            return $current;
        }
        if ($current === null) {
            return $candidate;
        }
        $order = array_flip(self::BLOCKED_PRIORITY);
        return $order[$candidate] < $order[$current] ? $candidate : $current;
    }

    /**
     * Whether the current user holds a user_enrolments row on this instance, in any status.
     *
     * @param \stdClass $instance Enrol instance.
     * @return bool True when a row exists.
     */
    private static function holds_enrolment(\stdClass $instance): bool {
        global $DB, $USER;

        return $DB->record_exists('user_enrolments', ['userid' => $USER->id, 'enrolid' => $instance->id]);
    }

    /**
     * How many rows an instance holds, every status and date, with no statement when it has no limit.
     *
     * The count enrol_self and enrol_autoenrol test their limit against.
     *
     * @param \stdClass $instance Enrol instance.
     * @param int $cap The instance's limit, from whichever field its plugin keeps it in; 0 or less is none.
     * @return int The rows, or 0 for an instance with no limit.
     */
    private static function places_taken(\stdClass $instance, int $cap): int {
        global $DB;

        if ($cap <= 0) {
            return 0;
        }
        return $DB->count_records('user_enrolments', ['enrolid' => $instance->id]);
    }

    /**
     * Whether an instance's cohort restriction admits the current user.
     *
     * @param int $cohortid The instance's cohort (customint5): 0 for none; a negative value is
     *        enrol_apply's restore sentinel, a restriction this site cannot honour.
     * @return bool True when unrestricted or a member.
     */
    private static function passes_cohort(int $cohortid): bool {
        global $CFG, $USER;

        if ($cohortid === 0) {
            return true;
        }
        if ($cohortid < 0) {
            return false;
        }
        require_once($CFG->dirroot . '/cohort/lib.php');
        return cohort_is_member($cohortid, $USER->id);
    }

    /**
     * Whether now is inside an instance's enrolment window, a bound of 0 meaning none.
     *
     * The window as enrol_self, enrol_apply, enrol_fee, enrol_paypal, enrol_autoenrol and
     * enrol_coursecompleted test it: the start date itself and the end date itself are both inside.
     *
     * @param \stdClass $instance Enrol instance carrying enrolstartdate and enrolenddate.
     * @param int $now The time to judge against.
     * @return bool True when the window is open.
     */
    private static function inside_window(\stdClass $instance, int $now): bool {
        $start = (int) $instance->enrolstartdate;
        $end = (int) $instance->enrolenddate;

        return ($start === 0 || $start <= $now) && ($end === 0 || $end >= $now);
    }

    /**
     * The price of a fee or paypal instance, falling back to the plugin's default.
     *
     * The same rule as both plugins' enrol_page_hook(): an instance cost of zero or less means
     * "use the site default", and a result under 0.01 means no price, on which the hook offers
     * no payment button.
     *
     * @param \stdClass $instance Enrol instance of enrol_fee or enrol_paypal.
     * @param \enrol_plugin $plugin The instance's plugin.
     * @return float The cost.
     */
    private static function payment_cost(\stdClass $instance, \enrol_plugin $plugin): float {
        if ((float) $instance->cost <= 0) {
            return (float) $plugin->get_config('cost');
        }
        return (float) $instance->cost;
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
     * object is the same guard the allow_apply() call in {@see instance_outcome()} uses.
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
