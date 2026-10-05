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
 * Unlisted courses - Tests for the discoverability predicate
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the discoverability predicate.
 *
 * Every test that asserts a course is hidden also asserts that some control
 * course or control user is visible in the same run. Without the control a
 * hidden-course assertion would also pass if the predicate never ran at all.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(access::class)]
final class access_test extends \advanced_testcase {
    /**
     * Reset the plugin's request caches between tests.
     *
     * They are static, so one test's answer would otherwise decide the next
     * test's assertion.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        access::reset_caches();
    }

    /**
     * Put a course in the unlisted state, or back in the default.
     *
     * Done as admin so that the test's own viewer is never the actor, and
     * the caches are reset so the new state is what the next assertion reads.
     *
     * @param int $courseid The course id.
     * @param bool $unlisted Whether the course should be unlisted.
     * @return void
     */
    private function set_unlisted(int $courseid, bool $unlisted): void {
        $current = $GLOBALS['USER'];
        $this->setAdminUser();
        discoverability::set_state(
            $courseid,
            $unlisted ? discoverability::STATE_UNLISTED : discoverability::STATE_DEFAULT
        );
        $this->setUser($current);
        access::reset_caches();
    }

    /**
     * Put a course category in the unlisted state, or back in the default.
     *
     * Done as admin so that the test's own viewer is never the actor, and the
     * caches are reset so the new state is what the next assertion reads.
     *
     * @param int $categoryid The category id.
     * @param bool $unlisted Whether the category should be unlisted.
     * @return void
     */
    private function set_category_unlisted(int $categoryid, bool $unlisted): void {
        $current = $GLOBALS['USER'];
        $this->setAdminUser();
        category_discoverability::set_state(
            $categoryid,
            $unlisted ? category_discoverability::STATE_UNLISTED : category_discoverability::STATE_DEFAULT
        );
        $this->setUser($current);
        access::reset_caches();
    }

    /**
     * Add a self enrolment instance to a course, optionally gated on a cohort.
     *
     * @param \stdClass $course The course.
     * @param int $cohortid Cohort id to restrict to, or 0 for no restriction.
     * @return \stdClass The enrol instance record.
     */
    private function add_self_enrol(\stdClass $course, int $cohortid = 0): \stdClass {
        global $DB;

        $plugin = enrol_get_plugin('self');
        $instanceid = $plugin->add_instance($course, [
            'status' => ENROL_INSTANCE_ENABLED,
            'customint6' => 1,
            'customint5' => $cohortid,
            'roleid' => $DB->get_field('role', 'id', ['shortname' => 'student']),
        ]);
        return $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }

    /**
     * A course with no stored state is discoverable by anyone.
     *
     * @return void
     */
    public function test_a_course_without_the_flag_is_discoverable(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $user = $generator->create_user();
        $this->setUser($user);

        $this->assertTrue(access::is_course_discoverable((int) $course->id));
    }

    /**
     * An unlisted course is hidden from a non-member and visible to a member.
     *
     * The two halves are deliberately one test: the member is the control that
     * proves the cohort gate was actually configured and consulted.
     *
     * @return void
     */
    public function test_an_unlisted_course_follows_cohort_membership(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $cohort = $generator->create_cohort();
        $this->add_self_enrol($course, (int) $cohort->id);
        $this->set_unlisted((int) $course->id, true);

        $member = $generator->create_user();
        $outsider = $generator->create_user();
        cohort_add_member($cohort->id, $member->id);

        $this->setUser($outsider);
        access::reset_caches();
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'A user outside the gating cohort must not discover the course.'
        );

        $this->setUser($member);
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'Control: a member of the gating cohort must still discover the course.'
        );
    }

    /**
     * An enrolled user keeps seeing the course after leaving the gating cohort.
     *
     * @return void
     */
    public function test_an_enrolled_user_sees_the_course_after_leaving_the_cohort(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $cohort = $generator->create_cohort();
        $this->add_self_enrol($course, (int) $cohort->id);
        $this->set_unlisted((int) $course->id, true);

        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);

        $this->setUser($user);
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'An actively enrolled user is not subject to the cohort gate.'
        );

        // Control: an identically placed user who is not enrolled must be refused.
        $stranger = $generator->create_user();
        $this->setUser($stranger);
        access::reset_caches();
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'Control: the gate must still be refusing someone, or the test proves nothing.'
        );
    }

    /**
     * An unlisted course with no enrolment instance at all is hidden.
     *
     * @return void
     */
    public function test_an_unlisted_course_with_no_enrolment_method_is_hidden(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $hidden = $generator->create_course();
        $control = $generator->create_course();
        $this->set_unlisted((int) $hidden->id, true);

        // Strip every enrolment instance the generator created.
        foreach (enrol_get_instances($hidden->id, false) as $instance) {
            enrol_get_plugin($instance->enrol)->delete_instance($instance);
        }

        $user = $generator->create_user();
        $this->setUser($user);
        access::reset_caches();

        $this->assertFalse(access::is_course_discoverable((int) $hidden->id));
        $this->assertTrue(
            access::is_course_discoverable((int) $control->id),
            'Control: an unmarked course must remain discoverable.'
        );
    }

    /**
     * A guest never discovers an unlisted course with an unrestricted self enrolment instance.
     *
     * This pins the outcome, not the guest guard in access::viewer_context():
     * can_self_enrol($instance, false) skips its own guest check, but it still
     * requires enrol/self:enrolself, a write capability that has_capability()
     * never grants a guest, so core refuses the guest here even without the
     * guard. The guard is held by
     * test_the_viewer_context_guard_refuses_a_guest_and_a_visitor() and, with enrol_apply installed,
     * by test_a_guest_is_refused_an_apply_only_course().
     *
     * @return void
     */
    public function test_a_guest_never_discovers_an_unlisted_course(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        // No cohort restriction: the instance admits any logged-in user, as the control below shows.
        $this->add_self_enrol($course, 0);
        $this->set_unlisted((int) $course->id, true);

        $this->setGuestUser();
        access::reset_caches();
        $this->assertFalse(access::is_course_discoverable((int) $course->id));

        // Control: an ordinary authenticated user is admitted by that same instance.
        $this->setUser($generator->create_user());
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'Control: the unrestricted self enrolment instance must admit a logged-in user.'
        );
    }

    /**
     * The site course is discoverable even for a guest, and even when marked.
     *
     * A guest is the only viewer that proves the frontpage exemption is load
     * bearing: for a logged-in user is_enrolled() already returns true
     * unconditionally on SITEID, so the exemption would be untestable through
     * one. The exemption runs before the visitor guard, and a guest that
     * reached the guard would be refused.
     *
     * @return void
     */
    public function test_the_site_course_is_discoverable_even_for_a_guest(): void {
        global $DB;

        $this->resetAfterTest();

        /* set_state() refuses the site course, so the row is written by hand: the
           exemption exists for exactly that case, a row that arrived by a route the
           API does not offer. */
        $DB->insert_record(discoverability::TABLE, (object) [
            'courseid' => SITEID,
            'state' => discoverability::STATE_UNLISTED,
            'usermodified' => 0,
            'timemodified' => time(),
        ]);
        $this->setGuestUser();
        access::reset_caches();

        $this->assertTrue(access::is_course_discoverable(SITEID));
    }

    /**
     * filter_courses() drops the right rows and keeps the incoming keys.
     *
     * @return void
     */
    public function test_filter_courses_drops_only_the_hidden_rows(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $open = $generator->create_course();
        $unlisted = $generator->create_course();
        $cohort = $generator->create_cohort();
        $this->add_self_enrol($unlisted, (int) $cohort->id);
        $this->set_unlisted((int) $unlisted->id, true);

        $this->setUser($generator->create_user());
        access::reset_caches();

        $courses = [
            'first' => (object) ['id' => (int) $open->id],
            'second' => (object) ['id' => (int) $unlisted->id],
        ];
        $kept = access::filter_courses($courses);

        $this->assertSame(['first'], array_keys($kept), 'Keys must survive the filter.');
        $this->assertSame((int) $open->id, (int) $kept['first']->id);
    }

    /**
     * An unlisted course whose enrolment window has not opened is hidden.
     *
     * Documents a deliberate consequence: the predicate mirrors the enrol
     * plugin's own answer, and that answer depends on time().
     *
     * @return void
     */
    public function test_an_unlisted_course_is_hidden_before_its_enrolment_window_opens(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $instance = $this->add_self_enrol($course, 0);
        $this->set_unlisted((int) $course->id, true);

        $user = $generator->create_user();
        $this->setUser($user);
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'Control: with the window open the course must be discoverable.'
        );

        $DB->set_field('enrol', 'enrolstartdate', time() + DAYSECS, ['id' => $instance->id]);
        access::reset_caches();
        $this->assertFalse(access::is_course_discoverable((int) $course->id));
    }

    /**
     * Add an enrol_apply instance to a course, optionally gated on a cohort.
     *
     * @param \stdClass $course The course.
     * @param int $cohortid Cohort id to restrict to, or 0 for no restriction.
     * @return \stdClass|null The enrol instance record, or null when enrol_apply (with allow_apply()) is not installed.
     */
    private function add_apply_enrol(\stdClass $course, int $cohortid = 0): ?\stdClass {
        global $DB;

        $plugin = enrol_get_plugin('apply');
        if (!$plugin || !is_callable([$plugin, 'allow_apply'])) {
            return null;
        }

        /* enrol_apply is a third-party plugin, so it is absent from the default
           enrol_plugins_enabled a fresh test site carries - and an instance of a
           disabled plugin is filtered out by enrol_get_instances($id, true), so the
           predicate would answer "cannot enrol" for a reason that has nothing to do
           with the applicant. */
        $enabled = array_keys(enrol_get_plugins(true));
        if (!in_array('apply', $enabled, true)) {
            $enabled[] = 'apply';
            set_config('enrol_plugins_enabled', implode(',', $enabled));
        }

        $instanceid = $plugin->add_instance($course, [
            'status' => ENROL_INSTANCE_ENABLED,
            'customint6' => 1,
            'customint5' => $cohortid,
            'roleid' => $DB->get_field('role', 'id', ['shortname' => 'student']),
        ]);
        return $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }

    /**
     * A guest is refused an apply-only course, which no capability would refuse.
     *
     * enrol_apply::allow_apply() checks the instance status, the enrolment
     * window and the cohort - it checks neither guest nor any capability. So
     * an unrestricted apply instance is the case where the visitor guard is
     * the only thing standing between a guest and the course name.
     *
     * @return void
     */
    public function test_a_guest_is_refused_an_apply_only_course(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        if (!$this->add_apply_enrol($course, 0)) {
            $this->markTestSkipped('enrol_apply is not installed.');
        }
        foreach (enrol_get_instances($course->id, false) as $instance) {
            if ($instance->enrol !== 'apply') {
                enrol_get_plugin($instance->enrol)->delete_instance($instance);
            }
        }
        $this->set_unlisted((int) $course->id, true);

        $this->setGuestUser();
        access::reset_caches();
        $this->assertFalse(access::is_course_discoverable((int) $course->id));

        // Control: the same instance admits an ordinary authenticated user.
        $this->setUser($generator->create_user());
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'Control: the apply instance must admit a logged-in user.'
        );
    }

    /**
     * An applicant awaiting a decision keeps seeing the course.
     *
     * A waiting application is a user_enrolments row that is not active, so
     * is_enrolled() with $onlyactive reports false for it. Without the pending
     * term the course would vanish from the listing the moment the applicant
     * filed - and the cohort gate is set here so that can_enrol() cannot be
     * what keeps it visible.
     *
     * @return void
     */
    public function test_an_applicant_awaiting_a_decision_still_sees_the_course(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $cohort = $generator->create_cohort();
        $instance = $this->add_apply_enrol($course, (int) $cohort->id);
        if (!$instance) {
            $this->markTestSkipped('enrol_apply is not installed.');
        }
        foreach (enrol_get_instances($course->id, false) as $other) {
            if ($other->enrol !== 'apply') {
                enrol_get_plugin($other->enrol)->delete_instance($other);
            }
        }
        $this->set_unlisted((int) $course->id, true);

        $applicant = $generator->create_user();
        $plugin = enrol_get_plugin('apply');
        $plugin->enrol_user(
            $instance,
            $applicant->id,
            $DB->get_field('role', 'id', ['shortname' => 'student']),
            0,
            0,
            ENROL_USER_SUSPENDED
        );

        $this->setUser($applicant);
        access::reset_caches();
        $this->assertFalse(
            is_enrolled(\core\context\course::instance($course->id), $applicant, '', true),
            'Precondition: a waiting application must not read as an active enrolment.'
        );
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'An applicant awaiting a decision must keep seeing the course they applied to.'
        );

        // Control: someone who never applied, and is outside the cohort, is refused.
        $this->setUser($generator->create_user());
        access::reset_caches();
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'Control: the cohort gate must still be refusing a non-applicant.'
        );
    }

    /**
     * Only an enrol_apply application counts as pending, not any inactive enrolment.
     *
     * A suspended manual enrolment is a decision already taken. The control is a waiting row of an
     * enrol_apply instance, written straight to the tables so the test does not need that plugin:
     * the term reads the instance's plugin name and nothing else from it.
     *
     * @return void
     */
    public function test_a_suspended_enrolment_of_another_method_is_not_a_pending_application(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        // A cohort gate nobody passes, so that can_enrol() cannot be what keeps the course visible.
        $this->add_self_enrol($course, (int) $generator->create_cohort()->id);
        $this->set_unlisted((int) $course->id, true);

        $suspended = $generator->create_user();
        $generator->enrol_user($suspended->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->setUser($suspended);
        access::reset_caches();
        $this->assertFalse(
            is_enrolled(\core\context\course::instance($course->id), $suspended, '', true),
            'Precondition: a suspended enrolment must not read as an active one.'
        );
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'A suspended manual enrolment is not an application awaiting a decision.'
        );

        // Control: the same row on an enrol_apply instance is a waiting application and keeps the course.
        $now = time();
        $applyid = $DB->insert_record('enrol', (object) [
            'enrol' => 'apply',
            'courseid' => $course->id,
            'status' => ENROL_INSTANCE_ENABLED,
            'sortorder' => 99,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $applicant = $generator->create_user();
        $DB->insert_record('user_enrolments', (object) [
            'enrolid' => $applyid,
            'userid' => $applicant->id,
            'status' => ENROL_USER_SUSPENDED,
            'timestart' => 0,
            'timeend' => 0,
            'modifierid' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $this->setUser($applicant);
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'Control: a waiting application on an enrol_apply instance must keep the course discoverable.'
        );
    }

    /**
     * Add an instance of any enrol plugin to a course, enabling the plugin site-wide first.
     *
     * A fresh test site enables manual, guest, self and cohort only, and enrol_get_instances()
     * drops the instances of a plugin that is not enabled, so the predicate would answer "no
     * route" for a reason that has nothing to do with the instance.
     *
     * @param \stdClass $course The course.
     * @param string $enrol The plugin name.
     * @param array $fields Instance fields; the instance is enabled whatever they say.
     * @return \stdClass|null The instance record, or null when the plugin is not installed.
     */
    private function add_enrol_instance(\stdClass $course, string $enrol, array $fields): ?\stdClass {
        global $DB;

        $plugin = enrol_get_plugin($enrol);
        if (!$plugin) {
            return null;
        }

        $enabled = array_keys(enrol_get_plugins(true));
        if (!in_array($enrol, $enabled, true)) {
            $enabled[] = $enrol;
            set_config('enrol_plugins_enabled', implode(',', $enabled));
        }

        $instanceid = $plugin->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED] + $fields);
        return $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
    }

    /**
     * Delete every enrolment instance of a course except one, so no other route can answer.
     *
     * @param \stdClass $course The course.
     * @param int $keepid The id of the instance to keep.
     * @return void
     */
    private function keep_only_instance(\stdClass $course, int $keepid): void {
        foreach (enrol_get_instances($course->id, false) as $instance) {
            if ((int) $instance->id !== $keepid) {
                enrol_get_plugin($instance->enrol)->delete_instance($instance);
            }
        }
    }

    /**
     * A new user holding one user_enrolments row on an instance, written straight to the table.
     *
     * Straight to the table so that no plugin's enrol_user() adds roles, groups or messages the
     * predicate does not read.
     *
     * @param int $enrolid The instance id.
     * @param int $status The row status.
     * @param int $timestart The start date.
     * @param int $timeend The end date.
     * @return \stdClass The user.
     */
    private function add_enrolment_row(int $enrolid, int $status, int $timestart, int $timeend): \stdClass {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('user_enrolments', (object) [
            'enrolid' => $enrolid,
            'userid' => $user->id,
            'status' => $status,
            'timestart' => $timestart,
            'timeend' => $timeend,
            'modifierid' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        return $user;
    }

    /**
     * An application whose end date has passed no longer keeps an unlisted course, and a waiting-list row does.
     *
     * The rule is enrol_apply's own queue: an approved enrolment that the expiry sweep suspended
     * after its end date has the status of a fresh application, and only the end date tells the
     * two apart. The instance is written straight to the tables, as in the test above, so the
     * test does not need that plugin. The controls run in the same test: an application with no
     * end date and a waiting-list row both keep the course.
     *
     * @return void
     */
    public function test_an_application_whose_end_date_has_passed_no_longer_keeps_an_unlisted_course(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        // A cohort gate nobody passes, so that can_enrol() cannot be what keeps the course visible.
        $this->add_self_enrol($course, (int) $generator->create_cohort()->id);
        $this->set_unlisted((int) $course->id, true);

        $now = time();
        $applyid = (int) $DB->insert_record('enrol', (object) [
            'enrol' => 'apply',
            'courseid' => $course->id,
            'status' => ENROL_INSTANCE_ENABLED,
            'sortorder' => 99,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $lapsed = $this->add_enrolment_row($applyid, ENROL_USER_SUSPENDED, $now - 10 * DAYSECS, $now - 5 * DAYSECS);
        $this->setUser($lapsed);
        access::reset_caches();
        $this->assertSame(access::RELATIONSHIP_NONE, access::get_enrolment_state((int) $course->id)['type']);
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'An enrolment suspended after its end date is not an application awaiting a decision.'
        );

        // Control: an application with no end date is awaiting a decision and keeps the course.
        $waiting = $this->add_enrolment_row($applyid, ENROL_USER_SUSPENDED, 0, 0);
        $this->setUser($waiting);
        access::reset_caches();
        $this->assertSame(access::RELATIONSHIP_PENDING, access::get_enrolment_state((int) $course->id)['type']);
        $this->assertTrue(access::is_course_discoverable((int) $course->id), 'Control: a waiting application keeps the course.');

        // Control: so does a row on enrol_apply's waiting list (status 2).
        $deferred = $this->add_enrolment_row($applyid, 2, 0, 0);
        $this->setUser($deferred);
        access::reset_caches();
        $this->assertSame(access::RELATIONSHIP_PENDING, access::get_enrolment_state((int) $course->id)['type']);
        $this->assertTrue(access::is_course_discoverable((int) $course->id), 'Control: a waiting-list row keeps the course.');
    }

    /**
     * An apply instance on which the viewer already holds a row is no route in for them.
     *
     * The viewer's approved enrolment ended under enrol_apply's default expiry action, which
     * leaves the row active: no relationship, and allow_apply() still says yes because it never
     * looks for the viewer's own row. enrol_apply takes no second application on that instance,
     * so the course must not stay discoverable for them on the strength of it. The control is a
     * user with no row on the same instance, in the same run.
     *
     * @return void
     */
    public function test_an_apply_instance_holding_the_viewers_own_row_is_no_route_in(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $instance = $this->add_apply_enrol($course, 0);
        if (!$instance) {
            $this->markTestSkipped('enrol_apply is not installed.');
        }
        $this->keep_only_instance($course, (int) $instance->id);
        $this->set_unlisted((int) $course->id, true);

        $now = time();
        $holder = $this->add_enrolment_row((int) $instance->id, ENROL_USER_ACTIVE, $now - 10 * DAYSECS, $now - 5 * DAYSECS);
        $this->setUser($holder);
        access::reset_caches();
        $this->assertSame(
            access::RELATIONSHIP_NONE,
            access::get_enrolment_state((int) $course->id)['type'],
            'Precondition: an enrolment whose end date has passed is no relationship.'
        );
        $this->assertTrue(
            enrol_get_plugin('apply')->allow_apply($instance) === true,
            'Precondition: allow_apply() on its own would let the holder apply.'
        );
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'An instance the viewer already holds a row on is no route in.'
        );

        // Control: the same instance is a route in for somebody with no row on it.
        $this->setUser($generator->create_user());
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'Control: the apply instance must admit a user with no row on it.'
        );
    }

    /**
     * The two payment methods, which share one branch of the predicate.
     *
     * @return array Name => [enrol plugin name].
     */
    public static function payment_method_provider(): array {
        return [
            'fee' => ['fee'],
            'paypal' => ['paypal'],
        ];
    }

    /**
     * A fee or paypal instance is a route in while its enrolment page would offer to take payment.
     *
     * Each condition of the page is switched off in turn and back on, and the route is asked for
     * after each: a row of the viewer's own on the instance, the window, and the price, with the
     * site default price standing in for an instance that has none.
     *
     * @param string $enrol The enrol plugin name.
     * @return void
     */
    #[DataProvider('payment_method_provider')]
    public function test_a_payment_instance_is_a_route_in_while_it_would_take_payment(string $enrol): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $instance = $this->add_enrol_instance($course, $enrol, ['cost' => 10, 'currency' => 'USD']);
        $this->keep_only_instance($course, (int) $instance->id);
        $this->set_unlisted((int) $course->id, true);
        $plugin = enrol_get_plugin($enrol);
        $plugin->set_config('cost', 0);

        $viewer = $generator->create_user();
        $this->setUser($viewer);
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'A priced instance with its window open is a route in.'
        );

        // A row of the viewer's own, ended so that it is no relationship: the page offers no payment.
        $now = time();
        $holder = $this->add_enrolment_row((int) $instance->id, ENROL_USER_ACTIVE, $now - 10 * DAYSECS, $now - 5 * DAYSECS);
        // Working towards the prerequisite, so that only the holder's own row withholds the course.
        $generator->enrol_user($holder->id, $prerequisite->id);
        $this->setUser($holder);
        access::reset_caches();
        $this->assertSame(
            access::RELATIONSHIP_NONE,
            access::get_enrolment_state((int) $course->id)['type'],
            'Precondition: an enrolment whose end date has passed is no relationship.'
        );
        $this->assertTrue(
            is_enrolled(\core\context\course::instance((int) $prerequisite->id), $holder, '', true),
            'Precondition: the holder is working towards the prerequisite, so the prerequisite term holds.'
        );
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'An instance the viewer already holds a row on is no route in.'
        );

        $this->setUser($viewer);
        $DB->set_field('enrol', 'enrolstartdate', $now + DAYSECS, ['id' => $instance->id]);
        access::reset_caches();
        $this->assertFalse(access::is_course_discoverable((int) $course->id), 'Before the window opens it is no route in.');

        $DB->set_field('enrol', 'enrolstartdate', 0, ['id' => $instance->id]);
        $DB->set_field('enrol', 'enrolenddate', $now - DAYSECS, ['id' => $instance->id]);
        access::reset_caches();
        $this->assertFalse(access::is_course_discoverable((int) $course->id), 'After the window closes it is no route in.');

        // No price of its own and none by default: the page shows an error instead of a button.
        $DB->set_field('enrol', 'enrolenddate', 0, ['id' => $instance->id]);
        $DB->set_field('enrol', 'cost', '0', ['id' => $instance->id]);
        access::reset_caches();
        $this->assertFalse(access::is_course_discoverable((int) $course->id), 'An instance with no price is no route in.');

        // Control: the site default price applies to an instance that has none.
        $plugin->set_config('cost', 15);
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'Control: with the site default price the same instance is a route in again.'
        );
    }

    /**
     * An enabled guest instance is a route in for a logged-in user, whether or not it asks for a key.
     *
     * Core's require_login() offers guest access to any user who is not enrolled, so the course
     * is one the viewer may enter. A key is asked for on entry and does not change that.
     *
     * @return void
     */
    public function test_an_enabled_guest_instance_is_a_route_in(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $guest = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'guest'], '*', MUST_EXIST);
        $this->keep_only_instance($course, (int) $guest->id);
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['id' => $guest->id]);
        $this->set_unlisted((int) $course->id, true);

        $this->setUser($generator->create_user());
        access::reset_caches();
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'Control: a disabled guest instance is no route in.'
        );

        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['id' => $guest->id]);
        access::reset_caches();
        $this->assertTrue(access::is_course_discoverable((int) $course->id), 'An enabled guest instance is a route in.');

        $DB->set_field('enrol', 'password', 'secret', ['id' => $guest->id]);
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'A guest instance that asks for a key is still a route in.'
        );
    }

    /**
     * An autoenrol instance is a route in while the plugin's own rule admits the viewer.
     *
     * The control switches off one condition only that plugin knows about, new enrolments, so
     * the answer can only have come from asking the plugin.
     *
     * @return void
     */
    public function test_an_autoenrol_instance_is_a_route_in_while_its_rule_admits_the_viewer(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $instance = $this->add_enrol_instance($course, 'autoenrol', ['customint1' => 0, 'customint4' => 1, 'customint8' => 0]);
        if (!$instance || !is_callable([enrol_get_plugin('autoenrol'), 'enrol_allowed'])) {
            $this->markTestSkipped('enrol_autoenrol, with enrol_allowed(), is not installed.');
        }
        $this->keep_only_instance($course, (int) $instance->id);
        $this->set_unlisted((int) $course->id, true);

        $this->setUser($generator->create_user());
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'An instance whose rule admits the viewer is a route in.'
        );

        $DB->set_field('enrol', 'customint4', 0, ['id' => $instance->id]);
        access::reset_caches();
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'With new enrolments off the plugin admits nobody, and the instance is no route in.'
        );
    }

    /**
     * A course completed instance keeps the course discoverable for a viewer working towards its
     * prerequisite, while its enrolment window is open.
     *
     * It enrols nobody now; the viewer will be enrolled on completing the course it names. A row
     * of the viewer's own on the instance, and a closed window, each end that.
     *
     * @return void
     */
    public function test_a_course_completed_instance_keeps_the_course_discoverable_while_its_window_is_open(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $prerequisite = $generator->create_course();
        $course = $generator->create_course();
        $instance = $this->add_enrol_instance($course, 'coursecompleted', ['customint1' => $prerequisite->id]);
        if (!$instance) {
            $this->markTestSkipped('enrol_coursecompleted is not installed.');
        }
        $this->keep_only_instance($course, (int) $instance->id);
        $this->set_unlisted((int) $course->id, true);

        $viewer = $generator->create_user();
        $this->setUser($viewer);
        access::reset_caches();
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'A viewer with no enrolment in the prerequisite has no tie to it and may not find the course.'
        );

        $generator->enrol_user($viewer->id, $prerequisite->id);
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'A viewer who will be enrolled on completing the prerequisite may find the course.'
        );

        $now = time();
        $holder = $this->add_enrolment_row((int) $instance->id, ENROL_USER_ACTIVE, $now - 10 * DAYSECS, $now - 5 * DAYSECS);
        // Working towards the prerequisite, so that only the holder's own row withholds the course.
        $generator->enrol_user($holder->id, $prerequisite->id);
        $this->setUser($holder);
        access::reset_caches();
        $this->assertSame(
            access::RELATIONSHIP_NONE,
            access::get_enrolment_state((int) $course->id)['type'],
            'Precondition: an enrolment whose end date has passed is no relationship.'
        );
        $this->assertTrue(
            is_enrolled(\core\context\course::instance((int) $prerequisite->id), $holder, '', true),
            'Precondition: the holder is working towards the prerequisite, so the prerequisite term holds.'
        );
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'An instance the viewer already holds a row on is no route in.'
        );

        $this->setUser($viewer);
        $DB->set_field('enrol', 'enrolenddate', $now - DAYSECS, ['id' => $instance->id]);
        access::reset_caches();
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'After the window closes the instance enrols nobody, and it is no route in.'
        );
    }

    /**
     * The guard in access::viewer_context() answers null for a guest and for nobody logged in.
     *
     * The outcome tests cannot hold the guard when enrol_apply is not installed, because core refuses
     * a guest on the self enrolment path anyway; this one reads the guard itself, with a logged-in
     * user as the control that proves the method does return a context.
     *
     * @return void
     */
    public function test_the_viewer_context_guard_refuses_a_guest_and_a_visitor(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $method = new \ReflectionMethod(access::class, 'viewer_context');

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertNotNull($method->invoke(null, (int) $course->id), 'Control: a logged-in user gets the course context.');

        $this->setGuestUser();
        $this->assertNull($method->invoke(null, (int) $course->id), 'A guest must be refused before any enrol plugin is asked.');

        $this->setUser(0);
        $this->assertNull($method->invoke(null, (int) $course->id), 'A visitor must be refused before any enrol plugin is asked.');
    }

    /**
     * Rows and the relationship classify_enrolment() gives them, judged at a fixed time.
     *
     * @return array Name => [status, timestart, timeend, enrol, instancestatus, expected type, startsat, endsat].
     */
    public static function enrolment_row_provider(): array {
        $now = 1000000;
        $on = ENROL_USER_ACTIVE;
        $off = ENROL_USER_SUSPENDED;
        $enabled = ENROL_INSTANCE_ENABLED;
        $disabled = ENROL_INSTANCE_DISABLED;
        // The waiting-list status of enrol_apply, whose constant is not defined on a site without that plugin.
        $wait = 2;
        $e = access::RELATIONSHIP_ENROLLED;
        $s = access::RELATIONSHIP_SCHEDULED;
        $p = access::RELATIONSHIP_PENDING;
        $n = access::RELATIONSHIP_NONE;
        return [
            'open-ended and started' => [$on, 0, 0, 'manual', $enabled, $e, 0, 0],
            'inside its window' => [$on, $now - 10, $now + 10, 'manual', $enabled, $e, $now - 10, $now + 10],
            'starting right now' => [$on, $now, 0, 'manual', $enabled, $e, $now, 0],
            'start date ahead' => [$on, $now + 50, 0, 'manual', $enabled, $s, $now + 50, 0],
            'start date ahead with an end' => [$on, $now + 50, $now + 90, 'self', $enabled, $s, $now + 50, $now + 90],
            'expired' => [$on, $now - 90, $now - 10, 'manual', $enabled, $n, 0, 0],
            'ends right now' => [$on, $now - 90, $now, 'manual', $enabled, $n, 0, 0],
            'end before start' => [$on, $now + 50, $now + 10, 'manual', $enabled, $n, 0, 0],
            'disabled instance, started' => [$on, 0, 0, 'manual', $disabled, $n, 0, 0],
            'disabled instance, start ahead' => [$on, $now + 50, 0, 'manual', $disabled, $n, 0, 0],
            'suspended, start ahead' => [$off, $now + 50, 0, 'manual', $enabled, $n, 0, 0],
            'suspended manual' => [$off, 0, 0, 'manual', $enabled, $n, 0, 0],
            'suspended self' => [$off, 0, 0, 'self', $enabled, $n, 0, 0],
            'waiting application' => [$off, 0, 0, 'apply', $enabled, $p, 0, 0],
            'approved application, started' => [$on, 0, 0, 'apply', $enabled, $e, 0, 0],
            'application with an end date ahead' => [$off, $now - 90, $now + 10, 'apply', $enabled, $p, $now - 90, $now + 10],
            'application whose end date has passed' => [$off, $now - 90, $now - 10, 'apply', $enabled, $n, 0, 0],
            'application ending right now' => [$off, 0, $now, 'apply', $enabled, $n, 0, 0],
            'application, end before start' => [$off, $now + 50, $now + 10, 'apply', $enabled, $p, $now + 50, $now + 10],
            'application on a disabled instance' => [$off, 0, 0, 'apply', $disabled, $p, 0, 0],
            'waiting list' => [$wait, 0, 0, 'apply', $enabled, $p, 0, 0],
            'waiting list whose end date has passed' => [$wait, 0, $now - 10, 'apply', $enabled, $n, 0, 0],
        ];
    }

    /**
     * classify_enrolment() is the one rule that turns an enrolment row into a relationship.
     *
     * @param int $status The user_enrolments status.
     * @param int $timestart The start date.
     * @param int $timeend The end date.
     * @param string $enrol The enrol plugin name.
     * @param int $instancestatus The instance status.
     * @param string $type The expected relationship.
     * @param int $startsat The expected start date.
     * @param int $endsat The expected end date.
     * @return void
     */
    #[DataProvider('enrolment_row_provider')]
    public function test_classify_enrolment(
        int $status,
        int $timestart,
        int $timeend,
        string $enrol,
        int $instancestatus,
        string $type,
        int $startsat,
        int $endsat
    ): void {
        $row = (object) [
            'status' => $status,
            'timestart' => $timestart,
            'timeend' => $timeend,
            'enrol' => $enrol,
            'instancestatus' => $instancestatus,
        ];

        $this->assertSame(
            ['type' => $type, 'startsat' => $startsat, 'endsat' => $endsat],
            access::classify_enrolment($row, 1000000)
        );
    }

    /**
     * An enrolment that starts later ties the user to the course now.
     *
     * Core does not call such a user enrolled until the start date, but an administrator decided
     * they take part, so an unlisted course must not be ghosted for them in the meantime. The
     * rows that must not count are the controls: a suspended or expired one, one on a disabled
     * instance, and a plain outsider, all in the same run so the predicate demonstrably ran.
     *
     * @return void
     */
    public function test_an_enrolment_that_starts_later_keeps_an_unlisted_course_discoverable(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        // A cohort gate nobody passes, so that can_enrol() cannot be what keeps the course visible.
        $this->add_self_enrol($course, (int) $generator->create_cohort()->id);
        $this->set_unlisted((int) $course->id, true);

        $start = time() + 3 * DAYSECS;
        $scheduled = $generator->create_user();
        $generator->enrol_user($scheduled->id, $course->id, 'student', 'manual', $start, 0);

        $this->setUser($scheduled);
        access::reset_caches();
        $this->assertFalse(
            is_enrolled(\core\context\course::instance($course->id), $scheduled, '', true),
            'Precondition: core does not call a user whose start date is ahead enrolled.'
        );
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'A user enrolled from a later date must keep the course discoverable.'
        );
        $this->assertSame(
            ['type' => access::RELATIONSHIP_SCHEDULED, 'startsat' => $start, 'endsat' => 0],
            access::get_enrolment_state((int) $course->id)
        );

        // Control: a suspended future enrolment is a decision already taken.
        $suspended = $generator->create_user();
        $generator->enrol_user($suspended->id, $course->id, 'student', 'manual', $start, 0, ENROL_USER_SUSPENDED);
        $this->setUser($suspended);
        access::reset_caches();
        $this->assertFalse(access::is_course_discoverable((int) $course->id), 'A suspended enrolment does not count.');
        $this->assertSame(access::RELATIONSHIP_NONE, access::get_enrolment_state((int) $course->id)['type']);

        // Control: an enrolment that ended before it was ever active.
        $expired = $generator->create_user();
        $generator->enrol_user($expired->id, $course->id, 'student', 'manual', time() - 2 * DAYSECS, time() - DAYSECS);
        $this->setUser($expired);
        access::reset_caches();
        $this->assertFalse(access::is_course_discoverable((int) $course->id), 'An expired enrolment does not count.');

        // Control: a plain outsider.
        $this->setUser($generator->create_user());
        access::reset_caches();
        $this->assertFalse(access::is_course_discoverable((int) $course->id), 'Control: an outsider is refused.');

        // Control: the scheduled user loses it when the instance is disabled.
        $manual = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['id' => $manual->id]);
        $this->setUser($scheduled);
        access::reset_caches();
        $this->assertFalse(access::is_course_discoverable((int) $course->id), 'A disabled instance does not count.');
    }

    /**
     * With several rows the strongest relationship wins, and a scheduled one reports its earliest start.
     *
     * @return void
     */
    public function test_get_enrolment_state_picks_the_strongest_row(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $self = $this->add_self_enrol($course, 0);

        $user = $generator->create_user();
        $later = time() + 5 * DAYSECS;
        $sooner = time() + 2 * DAYSECS;
        $generator->enrol_user($user->id, $course->id, 'student', 'manual', $later, 0);
        enrol_get_plugin('self')->enrol_user($self, $user->id, null, $sooner, 0);

        $this->setUser($user);
        access::reset_caches();
        $state = access::get_enrolment_state((int) $course->id);
        $this->assertSame(access::RELATIONSHIP_SCHEDULED, $state['type']);
        $this->assertSame($sooner, $state['startsat'], 'The earliest start date is the one a user waits for.');

        // An active row beats the scheduled ones.
        $generator->enrol_user($user->id, $course->id, 'student', 'manual', time() - DAYSECS, 0);
        access::reset_caches();
        $this->assertSame(access::RELATIONSHIP_ENROLLED, access::get_enrolment_state((int) $course->id)['type']);

        // A visitor and a guest have none, and the site course has no rows.
        $this->setGuestUser();
        access::reset_caches();
        $this->assertSame(access::RELATIONSHIP_NONE, access::get_enrolment_state((int) $course->id)['type']);
        $this->setUser($user);
        access::reset_caches();
        $this->assertSame(access::RELATIONSHIP_NONE, access::get_enrolment_state(SITEID)['type']);
    }

    /**
     * A primed scheduled course answers as a relationship without asking the database.
     *
     * @return void
     */
    public function test_prime_relationships_accepts_scheduled_courses(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $control = $generator->create_course();
        $this->add_self_enrol($course, (int) $generator->create_cohort()->id);
        $this->add_self_enrol($control, (int) $generator->create_cohort()->id);
        $this->set_unlisted((int) $course->id, true);
        $this->set_unlisted((int) $control->id, true);

        $this->setUser($generator->create_user());
        access::reset_caches();
        access::prime_relationships([], [], [(int) $course->id]);

        $this->assertTrue(access::is_course_discoverable((int) $course->id), 'The primed scheduled course is a relationship.');
        $this->assertFalse(access::is_course_discoverable((int) $control->id), 'Control: an unprimed course is not.');
    }

    /**
     * A manager keeps seeing an unlisted course they are not enrolled in.
     *
     * Without the staff escape an unlisted course vanishes from the listing of
     * the very people who administer it: a manager is not enrolled, is not in
     * the gating cohort, and the enrol plugins answer no for them like anybody
     * else.
     *
     * @return void
     */
    public function test_a_manager_keeps_seeing_an_unlisted_course(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $cohort = $generator->create_cohort();
        $this->add_self_enrol($course, (int) $cohort->id);
        $this->set_unlisted((int) $course->id, true);

        $manager = $generator->create_user();
        role_assign(
            $DB->get_field('role', 'id', ['shortname' => 'manager']),
            $manager->id,
            \core\context\coursecat::instance($course->category)->id
        );

        $this->setUser($manager);
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'A manager over the category must keep normal visibility.'
        );

        // Control: a plain user in the same run is still refused.
        $this->setUser($generator->create_user());
        access::reset_caches();
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'Control: the gate must still be refusing an ordinary user.'
        );
    }

    /**
     * A course in an unlisted category is withheld from listings, but stays discoverable on its own.
     *
     * The category term applies to filter_courses() and nothing else.
     * is_course_discoverable() answers on the course's own state alone, because
     * the theme's after_config guard ghosts the enrolment and hotsite pages off
     * that method, and a listing rule must never become an enrolment block.
     *
     * @return void
     */
    public function test_a_course_in_an_unlisted_category_is_withheld_from_listings_but_stays_discoverable(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $unlistedcategory = $generator->create_category();
        $courseincategory = $generator->create_course(['category' => $unlistedcategory->id]);
        $listedcategory = $generator->create_category();
        $siblingcourse = $generator->create_course(['category' => $listedcategory->id]);
        $this->set_category_unlisted((int) $unlistedcategory->id, true);

        $this->setUser($generator->create_user());
        access::reset_caches();

        $courses = [
            'a' => (object) ['id' => (int) $courseincategory->id],
            'b' => (object) ['id' => (int) $siblingcourse->id],
        ];
        $kept = access::filter_courses($courses);
        $this->assertSame(
            ['b'],
            array_keys($kept),
            'Control: a sibling course in a listed category must survive the same filter_courses() call.'
        );

        // The course's own discoverability answer is unchanged: the category term applies to listings only.
        $this->assertTrue(access::is_course_discoverable((int) $courseincategory->id));
    }

    /**
     * An enrolled student keeps a course in an unlisted category in listings.
     *
     * @return void
     */
    public function test_an_enrolled_student_keeps_a_course_in_an_unlisted_category_in_listings(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $this->set_category_unlisted((int) $category->id, true);

        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id);

        $this->setUser($student);
        access::reset_caches();
        $kept = access::filter_courses([(object) ['id' => (int) $course->id]]);
        $this->assertCount(1, $kept, 'An enrolled student keeps a course in an unlisted category.');

        // Control: an outsider in the same run does not.
        $this->setUser($generator->create_user());
        access::reset_caches();
        $this->assertCount(
            0,
            access::filter_courses([(object) ['id' => (int) $course->id]]),
            'Control: the category clamp must still be refusing an outsider.'
        );
    }

    /**
     * A pending applicant keeps a course in an unlisted category in listings.
     *
     * @return void
     */
    public function test_a_pending_applicant_keeps_a_course_in_an_unlisted_category_in_listings(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $instance = $this->add_apply_enrol($course, 0);
        if (!$instance) {
            $this->markTestSkipped('enrol_apply is not installed.');
        }
        $this->set_category_unlisted((int) $category->id, true);

        $applicant = $generator->create_user();
        $plugin = enrol_get_plugin('apply');
        $plugin->enrol_user(
            $instance,
            $applicant->id,
            $DB->get_field('role', 'id', ['shortname' => 'student']),
            0,
            0,
            ENROL_USER_SUSPENDED
        );

        $this->setUser($applicant);
        access::reset_caches();
        $this->assertCount(
            1,
            access::filter_courses([(object) ['id' => (int) $course->id]]),
            'An applicant awaiting a decision keeps a course in an unlisted category.'
        );

        // Control: someone who never applied does not.
        $this->setUser($generator->create_user());
        access::reset_caches();
        $this->assertCount(0, access::filter_courses([(object) ['id' => (int) $course->id]]));
    }

    /**
     * Course staff keep a course in an unlisted category in listings.
     *
     * @return void
     */
    public function test_course_staff_keep_a_course_in_an_unlisted_category_in_listings(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $this->set_category_unlisted((int) $category->id, true);

        $manager = $generator->create_user();
        role_assign(
            $DB->get_field('role', 'id', ['shortname' => 'manager']),
            $manager->id,
            \core\context\coursecat::instance($category->id)->id
        );

        $this->setUser($manager);
        access::reset_caches();
        $this->assertCount(
            1,
            access::filter_courses([(object) ['id' => (int) $course->id]]),
            'Course staff keep a course in an unlisted category in listings.'
        );

        // Control: a plain user in the same run does not.
        $this->setUser($generator->create_user());
        access::reset_caches();
        $this->assertCount(0, access::filter_courses([(object) ['id' => (int) $course->id]]));
    }

    /**
     * Being able to self-enrol does not rescue a course in an unlisted category in listings.
     *
     * @return void
     */
    public function test_being_able_to_self_enrol_does_not_rescue_a_course_in_an_unlisted_category_in_listings(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $instance = $this->add_self_enrol($course, 0);
        $this->set_category_unlisted((int) $category->id, true);

        $user = $generator->create_user();
        $this->setUser($user);
        access::reset_caches();

        $plugin = enrol_get_plugin('self');
        $this->assertTrue(
            $plugin->can_self_enrol($instance, false) === true,
            'Precondition: the user really could self-enrol right now.'
        );
        $this->assertCount(
            0,
            access::filter_courses([(object) ['id' => (int) $course->id]]),
            'Being able to self-enrol must not rescue a course in an unlisted category.'
        );

        // Control: with the category listed, the same self-enrolable course is kept for the same user.
        $this->set_category_unlisted((int) $category->id, false);
        access::reset_caches();
        $this->assertCount(1, access::filter_courses([(object) ['id' => (int) $course->id]]));
    }

    /**
     * A cohort member of the unlisted category sees its courses in listings.
     *
     * @return void
     */
    public function test_a_cohort_member_of_the_category_sees_its_courses_in_listings(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $this->set_category_unlisted((int) $category->id, true);

        $cohort = $generator->create_cohort(['contextid' => \core\context\coursecat::instance($category->id)->id]);
        $member = $generator->create_user();
        cohort_add_member($cohort->id, $member->id);

        $this->setUser($member);
        access::reset_caches();
        $this->assertCount(
            1,
            access::filter_courses([(object) ['id' => (int) $course->id]]),
            'A cohort member of the unlisted category sees its courses in listings.'
        );

        // Control: an outsider does not.
        $this->setUser($generator->create_user());
        access::reset_caches();
        $this->assertCount(0, access::filter_courses([(object) ['id' => (int) $course->id]]));
    }

    /**
     * filter_courses() fetches the category of items that do not carry it, in one query for all of them.
     *
     * The second half measures that claim instead of asserting it in prose. The
     * two calls run the same code over the same state and differ only in how
     * many categories have to be fetched, so a per-course lookup is the only
     * thing that could make the larger listing read more than the smaller one.
     *
     * @return void
     */
    public function test_filter_courses_fetches_the_category_of_items_that_do_not_carry_it(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $hidden = $generator->create_course(['category' => $category->id]);
        $this->set_category_unlisted((int) $category->id, true);
        $open = $generator->create_course();

        $this->setUser($generator->create_user());
        access::reset_caches();

        $courses = [
            'x' => (object) ['id' => (int) $hidden->id],
            'y' => (object) ['id' => (int) $open->id],
        ];
        $kept = access::filter_courses($courses);

        $this->assertSame(['y'], array_keys($kept), 'Items without ->category must still be resolved and filtered.');

        $listed = $generator->create_category();
        $small = [];
        for ($i = 0; $i < 2; $i++) {
            $small[] = (object) ['id' => (int) $generator->create_course(['category' => $listed->id])->id];
        }
        $large = [];
        for ($i = 0; $i < 8; $i++) {
            $large[] = (object) ['id' => (int) $generator->create_course(['category' => $listed->id])->id];
        }

        access::reset_caches();
        $before = $DB->perf_get_reads();
        $this->assertCount(2, access::filter_courses($small));
        $smallreads = $DB->perf_get_reads() - $before;

        access::reset_caches();
        $before = $DB->perf_get_reads();
        $this->assertCount(8, access::filter_courses($large));
        $largereads = $DB->perf_get_reads() - $before;

        $this->assertLessThanOrEqual(
            $smallreads,
            $largereads,
            'The category fetch must not grow with the listing: eight items must not read more than two.'
        );
    }

    /**
     * The memoised discoverability answer is keyed by the viewer, not held globally.
     *
     * An unlisted course's answer is entirely a property of the viewer -
     * eligible() reads $USER through is_enrolled(), has_capability() and
     * can_self_enrol() - so a memo keyed by the course alone would answer for
     * whoever asked first. There is deliberately no reset between the two
     * viewers below: that is the whole point of the test.
     *
     * @return void
     */
    public function test_the_discoverability_memo_is_keyed_by_the_viewer(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        $cohort = $generator->create_cohort();
        $this->add_self_enrol($course, (int) $cohort->id);
        $this->set_unlisted((int) $course->id, true);

        $member = $generator->create_user();
        cohort_add_member($cohort->id, $member->id);
        $outsider = $generator->create_user();

        $this->setUser($member);
        access::reset_caches();
        $this->assertTrue(
            access::is_course_discoverable((int) $course->id),
            'Precondition: the gating cohort must be admitting its member.'
        );

        // Deliberately no reset_caches() here: the memo must be keyed by the viewer, not global.
        $this->setUser($outsider);
        $this->assertFalse(
            access::is_course_discoverable((int) $course->id),
            'A different viewer, with no reset in between, must get their own answer.'
        );
    }

    /**
     * Put a course in any state as admin, leaving the test's viewer where it was.
     *
     * @param int $courseid The course id.
     * @param int $state One of the state constants.
     * @return void
     */
    private function set_course_state(int $courseid, int $state): void {
        $current = $GLOBALS['USER'];
        $this->setAdminUser();
        discoverability::set_state($courseid, $state);
        $this->setUser($current);
        access::reset_caches();
    }

    /**
     * Put a category in any state as admin, leaving the test's viewer where it was.
     *
     * @param int $categoryid The category id.
     * @param int $state One of the state constants.
     * @return void
     */
    private function set_category_state(int $categoryid, int $state): void {
        $current = $GLOBALS['USER'];
        $this->setAdminUser();
        category_discoverability::set_state($categoryid, $state);
        $this->setUser($current);
        access::reset_caches();
    }

    /**
     * Public, visible course rows in a category, written straight to the tables.
     *
     * A budget fixture: the predicate reads id, category and visible plus the
     * state row, and a generator course at this size costs minutes for parts of
     * a course nothing here looks at.
     *
     * @param int $categoryid The category the courses sit in.
     * @param int $count How many to create.
     * @return array The course ids.
     */
    private function seed_public_courses(int $categoryid, int $count): array {
        global $DB;

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = (int) $DB->insert_record('course', (object) [
                'category' => $categoryid,
                'fullname' => 'Budget course ' . $categoryid . '-' . $i,
                'shortname' => 'bc' . $categoryid . '-' . $i,
                'visible' => 1,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }
        $rows = [];
        foreach ($ids as $courseid) {
            $rows[] = (object) [
                'courseid' => $courseid,
                'state' => discoverability::STATE_PUBLIC,
                'usermodified' => 0,
                'timemodified' => time(),
            ];
        }
        $DB->insert_records(discoverability::TABLE, $rows);
        return $ids;
    }

    /**
     * filter_courses_public() keeps a course only when its own state is public.
     *
     * Inside a public category, a listed course is withheld because it has no
     * anonymous page to offer, and an unlisted course because its own state
     * outranks the category above it. A hidden course and a course in an
     * unlisted subcategory are withheld too. The incoming keys and their order
     * survive.
     *
     * @return void
     */
    public function test_filter_courses_public_keeps_only_courses_whose_own_state_is_public(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $parent = $generator->create_category();
        $category = $generator->create_category(['parent' => $parent->id]);
        $subcategory = $generator->create_category(['parent' => $category->id]);
        $this->setAdminUser();
        $this->set_category_state((int) $category->id, category_discoverability::STATE_PUBLIC);
        $this->set_category_state((int) $subcategory->id, category_discoverability::STATE_UNLISTED);

        $kept = $generator->create_course(['category' => $category->id]);
        $second = $generator->create_course(['category' => $category->id]);
        $unlisted = $generator->create_course(['category' => $category->id]);
        $listed = $generator->create_course(['category' => $category->id]);
        $insub = $generator->create_course(['category' => $subcategory->id]);
        $hidden = $generator->create_course(['category' => $category->id, 'visible' => 0]);

        foreach ([$kept, $second, $insub, $hidden] as $course) {
            $this->set_course_state((int) $course->id, discoverability::STATE_PUBLIC);
        }
        $this->set_course_state((int) $unlisted->id, discoverability::STATE_UNLISTED);

        $courses = [
            'hidden' => (object) ['id' => (int) $hidden->id],
            'insub' => (object) ['id' => (int) $insub->id],
            'second' => (object) ['id' => (int) $second->id],
            'listed' => (object) ['id' => (int) $listed->id],
            'unlisted' => (object) ['id' => (int) $unlisted->id],
            'kept' => (object) ['id' => (int) $kept->id],
        ];

        access::reset_caches();
        $this->assertSame(
            ['second', 'kept'],
            array_keys(access::filter_courses_public($courses)),
            'Only the two public courses survive, with their keys and their order.'
        );

        /* The answer is a property of the courses, never of who is asking: the same call
           as a visitor who is not logged in returns exactly the same set. */
        $this->setUser(0);
        access::reset_caches();
        $this->assertSame(['second', 'kept'], array_keys(access::filter_courses_public($courses)));
    }

    /**
     * filter_courses_public() over 200 courses costs what it costs over 2.
     *
     * The large set is spread over twenty categories against the small set's one,
     * for the reason discoverability_test gives on the predicate this filter calls:
     * with everything in a single category the equality would also hold for an
     * implementation that batched per distinct category rather than per request.
     *
     * @return void
     */
    public function test_filter_courses_public_reads_the_same_for_two_courses_and_for_two_hundred(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category();
        $category = $generator->create_category(['parent' => $parent->id]);

        $small = [];
        foreach ($this->seed_public_courses((int) $category->id, 2) as $courseid) {
            $small[] = (object) ['id' => $courseid];
        }
        $large = [];
        $largeids = [];
        for ($i = 0; $i < 20; $i++) {
            $spread = $generator->create_category(['parent' => $parent->id]);
            foreach ($this->seed_public_courses((int) $spread->id, 10) as $courseid) {
                $largeids[] = $courseid;
                $large[] = (object) ['id' => $courseid];
            }
        }
        [$insql, $params] = $DB->get_in_or_equal($largeids, SQL_PARAMS_NAMED, 'crs');
        $this->assertCount(
            20,
            array_unique($DB->get_fieldset_select('course', 'category', "id $insql", $params)),
            'The large set must be spread over twenty distinct categories, not one.'
        );

        $this->setUser(0);
        access::reset_caches();
        $before = $DB->perf_get_reads();
        $this->assertCount(2, access::filter_courses_public($small));
        $smallreads = $DB->perf_get_reads() - $before;

        access::reset_caches();
        $before = $DB->perf_get_reads();
        $this->assertCount(200, access::filter_courses_public($large));
        $largereads = $DB->perf_get_reads() - $before;

        $this->assertSame($smallreads, $largereads, 'The anonymous filter must cost the same for 200 courses as for 2.');
    }

    /**
     * A primed relationship is answered from memory, at the cost of the state lookup alone.
     *
     * The control is the whole test: the same call without the primer costs
     * strictly more, so the measurement cannot pass by the relationship never
     * being asked for at all.
     *
     * @return void
     */
    public function test_prime_relationships_answers_without_the_enrolment_statements(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $unlisted = $generator->create_course(['category' => $category->id]);
        $listed = $generator->create_course(['category' => $category->id]);
        $warmup = $generator->create_course(['category' => $category->id]);
        $viewer = $generator->create_user();
        $generator->enrol_user($viewer->id, $unlisted->id);
        $this->set_course_state((int) $unlisted->id, discoverability::STATE_UNLISTED);

        $this->setUser($viewer);
        $unlisteditem = [(object) ['id' => (int) $unlisted->id, 'category' => (int) $category->id]];
        $listeditem = [(object) ['id' => (int) $listed->id, 'category' => (int) $category->id]];

        // Warm whatever core caches on first use, so the first measurement is not the one paying for it.
        access::reset_caches();
        access::filter_courses([(object) ['id' => (int) $warmup->id, 'category' => (int) $category->id]]);

        access::reset_caches();
        access::prime_relationships([(int) $unlisted->id]);
        $before = $DB->perf_get_reads();
        $this->assertCount(1, access::filter_courses($unlisteditem), 'The primed course must survive the filter.');
        $primedreads = $DB->perf_get_reads() - $before;

        access::reset_caches();
        $before = $DB->perf_get_reads();
        $this->assertCount(1, access::filter_courses($listeditem));
        $listedreads = $DB->perf_get_reads() - $before;

        access::reset_caches();
        $before = $DB->perf_get_reads();
        $this->assertCount(1, access::filter_courses($unlisteditem));
        $unprimedreads = $DB->perf_get_reads() - $before;

        $this->assertSame(
            $listedreads,
            $primedreads,
            'A primed unlisted course must cost what a listed one costs: the state lookup and nothing else.'
        );
        $this->assertGreaterThan(
            $primedreads,
            $unprimedreads,
            'Control: without the primer the same call really does read the enrolment tables.'
        );
    }

    /**
     * The primer is keyed by the viewer, and a reset drops it.
     *
     * @return void
     */
    public function test_prime_relationships_is_keyed_by_the_viewer_and_dropped_by_a_reset(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $this->set_category_unlisted((int) $category->id, true);

        $first = $generator->create_user();
        $second = $generator->create_user();
        $item = [(object) ['id' => (int) $course->id, 'category' => (int) $category->id]];

        $this->setUser($first);
        access::reset_caches();
        $this->assertCount(0, access::filter_courses($item), 'Precondition: the category term withholds it.');

        access::prime_relationships([(int) $course->id]);
        $this->assertCount(1, access::filter_courses($item), 'A primed relationship rescues it from the category term.');

        // Deliberately no reset: the primer belongs to the viewer who supplied it.
        $this->setUser($second);
        $this->assertCount(
            0,
            access::filter_courses($item),
            'The second viewer must have their own answer computed, never the first one\'s.'
        );

        // Control: the same primer for the second viewer keeps it, and a reset drops it again.
        access::prime_relationships([], [(int) $course->id]);
        $this->assertCount(1, access::filter_courses($item));
        access::reset_caches();
        $this->assertCount(0, access::filter_courses($item), 'reset_caches() drops what was primed.');
    }
}
