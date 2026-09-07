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
 * Course discoverability - The state of a course and the gate for its public page
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses;

use local_unlistedcourses\event\course_state_updated;

/**
 * The discoverability state of a course: who may learn that it exists.
 *
 * Three states. LISTED, the default, is a course like any other. UNLISTED is
 * named only to people who are enrolled, have applied, could enrol right now
 * or are staff - the predicate in {@see access} decides that per viewer.
 * PUBLIC is the opposite direction: the course's landing page may be served
 * to visitors who are not logged in, on a site that forces login. Only a
 * course in a non-default state has a row; absence means listed.
 *
 * THE CAPABILITY CHECK LIVES IN set_state() AND NOWHERE ELSE. The course form
 * is one of four writers - the web service, tool_uploadcourse and course
 * restore all dispatch the same hook or call this class directly - so a check
 * placed in the form would be a check three of them route around. Only the
 * transitions that enter or leave PUBLIC are gated here, on
 * local/unlistedcourses:publish: publishing a course to the internet is not
 * an editing act, and un-publishing one is that same decision reversed.
 * Moving between listed and unlisted carries no gate of its own, on purpose -
 * every caller is already behind moodle/course:update or the restore
 * capabilities, and core authorises the visible flag through those same gates
 * (restore applies it without asking moodle/course:visibility). A second gate
 * here would only ever refuse a restoring teacher, and refuse them in the
 * un-hiding direction.
 *
 * A call that changes nothing needs no capability. That is what lets the
 * course form re-submit "public" from a frozen control when an editor who may
 * not publish saves an unrelated change: the save must never un-publish
 * silently, and it must never fail over a value the editor did not touch.
 *
 * NOTHING HERE IS CACHED BEYOND THE REQUEST. The state feeds an access
 * decision, and the rest of the decision (enrolments, cohort membership,
 * enrolment windows) is deliberately uncached in {@see access} - a cross
 * request cache of the state alone would buy nothing and would add a second
 * place to keep coherent.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discoverability {
    /** @var int The default: the course is listed like any other. */
    public const STATE_DEFAULT = 0;

    /** @var int Named only to people who are enrolled, have applied, could enrol, or are staff. */
    public const STATE_UNLISTED = 1;

    /** @var int The course's landing page may be served to visitors who are not logged in. */
    public const STATE_PUBLIC = 2;

    /** @var string The table holding the non-default states. */
    public const TABLE = 'local_unlistedcourses_state';

    /** @var string The capability that gates entering and leaving the public state. */
    public const CAPABILITY_PUBLISH = 'local/unlistedcourses:publish';

    /** @var array Request cache of the state, keyed courseid => int. */
    private static array $states = [];

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
    }

    /**
     * The state of each of these courses, in the order given.
     *
     * One query for every id not already known in this request, which is the
     * shape {@see access::are_courses_discoverable()} needs for a listing.
     * A course without a row is listed. A row holding a value outside the
     * known states reads as listed too, never as public: the table is the
     * gate for an anonymous page, so an unknown value fails closed.
     *
     * @param array $courseids Course ids.
     * @return array Map of courseid => state constant, in the order given.
     */
    public static function get_states(array $courseids): array {
        global $DB;

        $courseids = array_values(array_unique(array_map('intval', $courseids)));

        $unknown = [];
        foreach ($courseids as $courseid) {
            if (!array_key_exists($courseid, self::$states)) {
                $unknown[] = $courseid;
            }
        }
        if ($unknown) {
            $rows = $DB->get_records_list(self::TABLE, 'courseid', $unknown, '', 'courseid, state');
            foreach ($unknown as $courseid) {
                $state = isset($rows[$courseid]) ? (int) $rows[$courseid]->state : self::STATE_DEFAULT;
                $state = in_array($state, self::states(), true) ? $state : self::STATE_DEFAULT;
                self::$states[$courseid] = $state;
            }
        }

        $states = [];
        foreach ($courseids as $courseid) {
            $states[$courseid] = self::$states[$courseid];
        }
        return $states;
    }

    /**
     * The state of one course.
     *
     * @param int $courseid The course id.
     * @return int One of the state constants.
     */
    public static function get_state(int $courseid): int {
        $states = self::get_states([$courseid]);
        return $states[$courseid];
    }

    /**
     * Whether the course is unlisted.
     *
     * This is the state only. Whether the CURRENT user may still discover an
     * unlisted course is {@see access::is_course_discoverable()}.
     *
     * @param int $courseid The course id.
     * @return bool True when the course is in the unlisted state.
     */
    public static function is_unlisted(int $courseid): bool {
        return self::get_state($courseid) === self::STATE_UNLISTED;
    }

    /**
     * Whether the course's landing page may be served to a visitor who is not logged in.
     *
     * This is the gate for an anonymous page, so it cannot delegate: a course
     * that is public in this table but hidden, or that sits inside a hidden
     * category, is NOT public. Core's own answer to "may this visitor see the
     * course" (core_course_category::can_view_course_info()) ends in a
     * capability check, and accesslib hard-denies every capability for user
     * id 0 while forcelogin is on - so the visibility half of that answer is
     * reproduced here, from the course row and the category path, with no
     * capability involved. Every category on the path must exist and be
     * visible; a missing ancestor fails closed.
     *
     * An unlisted category on the path refuses it too. That is not a second
     * rule but the same one: {@see category_access} admits a viewer through a
     * cohort membership, a role assignment or a capability, and an anonymous
     * visitor can hold none of the three, so a course inside an unlisted
     * category has nobody it could be served to anonymously. Answering
     * otherwise would let "public" quietly outrank the category above it.
     *
     * Independent of the viewer, unlike everything in {@see access}.
     *
     * @param int $courseid The course id.
     * @return bool True when the course may be served anonymously.
     */
    public static function is_public(int $courseid): bool {
        global $DB;

        if (self::get_state($courseid) !== self::STATE_PUBLIC) {
            return false;
        }

        $course = $DB->get_record('course', ['id' => $courseid], 'id, category, visible');
        if (!$course || !$course->visible) {
            return false;
        }

        $category = $DB->get_record('course_categories', ['id' => $course->category], 'id, path');
        if (!$category) {
            return false;
        }
        $ids = array_map('intval', array_filter(explode('/', (string) $category->path)));
        if (!$ids) {
            return false;
        }
        if (array_intersect($ids, category_discoverability::unlisted_ids())) {
            return false;
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $visiblecount = $DB->count_records_select('course_categories', "id $insql AND visible = 1", $params);
        return $visiblecount === count($ids);
    }

    /**
     * Set the state of a course.
     *
     * The one place the publish capability is checked - see the class
     * docblock for why it is here and not in the form. A call that does not
     * change the state returns without checking anything. Every change fires
     * {@see course_state_updated}.
     *
     * @param int $courseid The course id. Not the site course.
     * @param int $state One of the state constants.
     * @param int|null $userid The user performing the change, null for the current user. Restore passes the
     *   restoring user, which is not necessarily $USER.
     * @return void
     * @throws \coding_exception On an unknown state or the site course.
     * @throws \required_capability_exception When the change enters or leaves the public state and the user may not
     *   publish courses here.
     */
    public static function set_state(int $courseid, int $state, ?int $userid = null): void {
        global $DB, $USER;

        if (!in_array($state, self::states(), true)) {
            throw new \coding_exception("Unknown discoverability state '{$state}'.");
        }
        if ($courseid == SITEID) {
            throw new \coding_exception('The site course has no discoverability state.');
        }
        $context = \core\context\course::instance($courseid);
        $userid = $userid ?? (int) $USER->id;

        $row = $DB->get_record(self::TABLE, ['courseid' => $courseid]);
        $current = $row ? (int) $row->state : self::STATE_DEFAULT;
        $current = in_array($current, self::states(), true) ? $current : self::STATE_DEFAULT;
        if ($current === $state) {
            self::$states[$courseid] = $state;
            return;
        }

        if ($current === self::STATE_PUBLIC || $state === self::STATE_PUBLIC) {
            require_capability(self::CAPABILITY_PUBLISH, $context, $userid);
        }

        if ($state === self::STATE_DEFAULT) {
            $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
        } else if ($row) {
            $row->state = $state;
            $row->usermodified = $userid;
            $row->timemodified = time();
            $DB->update_record(self::TABLE, $row);
        } else {
            $DB->insert_record(self::TABLE, (object) [
                'courseid' => $courseid,
                'state' => $state,
                'usermodified' => $userid,
                'timemodified' => time(),
            ]);
        }

        // The discoverability answer for this course is a function of the state: forget it.
        access::reset_caches();
        self::$states[$courseid] = $state;

        course_state_updated::create([
            'context' => $context,
            'objectid' => $courseid,
            'userid' => $userid,
            'other' => ['oldstate' => $current, 'newstate' => $state],
        ])->trigger();
    }

    /**
     * Drop the state of a course that is being deleted.
     *
     * Called from the before_course_deleted hook. No capability and no event:
     * the course is going away, and core's own deletion is the audited act.
     *
     * @param int $courseid The course id.
     * @return void
     */
    public static function on_course_deleted(int $courseid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
        access::reset_caches();
    }
}
