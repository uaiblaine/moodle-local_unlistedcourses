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

/**
 * Tests for the discoverability predicate.
 *
 * Every test that asserts a course is HIDDEN also asserts that some control
 * course or control user is visible in the same run. Without the control a
 * hidden-course assertion passes just as happily when the predicate never ran
 * at all, which is the failure mode this whole plugin would be blind to.
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
     * A course without the flag is discoverable by anyone, marked courses notwithstanding.
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

        // Control: an identically placed user who is NOT enrolled must be refused.
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
     * A visitor and a guest never discover an unlisted course.
     *
     * can_self_enrol($instance, false) skips its own guest check, so an
     * unrestricted self enrolment instance would otherwise let a guest through.
     *
     * @return void
     */
    public function test_a_guest_never_discovers_an_unlisted_course(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $course = $generator->create_course();
        // No cohort restriction: the case where the enrol plugin itself would say yes.
        $this->add_self_enrol($course, 0);
        $this->set_unlisted((int) $course->id, true);

        $this->setGuestUser();
        access::reset_caches();
        $this->assertFalse(access::is_course_discoverable((int) $course->id));

        // Control: an ordinary authenticated user IS admitted by that same instance.
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
     * @return \stdClass|null The enrol instance record, or null when the fork is absent.
     */
    private function add_apply_enrol(\stdClass $course, int $cohortid = 0): ?\stdClass {
        global $DB;

        $plugin = enrol_get_plugin('apply');
        if (!$plugin || !is_callable([$plugin, 'allow_apply'])) {
            return null;
        }

        /* enrol_apply is a third-party plugin, so it is absent from the default
           enrol_plugins_enabled a fresh test site carries - and an instance of a
           disabled plugin is filtered out by enrol_get_instances($id, true), which
           made the predicate answer "cannot enrol" for a reason that has nothing to
           do with the applicant. */
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
            $this->markTestSkipped('enrol_apply (fleet fork) is not installed.');
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
            $this->markTestSkipped('enrol_apply (fleet fork) is not installed.');
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
     * A manager keeps seeing an unlisted course they are not enrolled in.
     *
     * Without the staff escape an unlisted course vanishes from the listing of
     * the very people who administer it: a manager is not enrolled, is not in
     * the gating cohort, and the enrol plugins answer no for them like anybody
     * else. This was caught by running the validation matrix, not by the suite,
     * which is why it has a test of its own.
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
}
