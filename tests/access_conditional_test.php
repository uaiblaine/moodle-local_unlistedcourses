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

namespace local_unlistedcourses;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unlisted courses - Tests for the enrol_conditional route
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Tests that an enrol_conditional instance is a route in exactly where the plugin would enrol the viewer.
 *
 * Every verdict is read through the three doors that reach the one rule
 * ({@see access::conditional_outcome()}): the per-course next action with nothing primed, the
 * same answer after filter_courses() primed the listing's facts, and the batch. Each refusal is
 * asserted beside a control that the same viewer is admitted once the refusing fact changes, and
 * every verdict is held to enrol_conditional's own can_self_enrol($instance, false). The
 * conditions are the engine's real built-in ones: userlist (one statement) and date (none).
 *
 * Every test skips on a site without enrol_conditional and its evaluate_conditions().
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(access::class)]
final class access_conditional_test extends \advanced_testcase {
    /** @var \enrol_plugin The enrol_conditional plugin. */
    private \enrol_plugin $plugin;

    /** @var \stdClass The viewer the userlist names. */
    private \stdClass $meets;

    /** @var \stdClass A viewer the userlist does not name. */
    private \stdClass $fails;

    /** @var \stdClass Somebody else, whose rows fill a capped instance. */
    private \stdClass $other;

    /**
     * Every test writes to the database, and the plugin's request caches are static.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        access::reset_caches();

        $plugin = enrol_get_plugin('conditional');
        if (!$plugin || !is_callable([$plugin, 'evaluate_conditions'])) {
            $this->markTestSkipped('enrol_conditional, with evaluate_conditions(), is not installed.');
        }
        // A fresh test site does not enable the method.
        \core\plugininfo\enrol::enable_plugin('conditional', true);
        $this->plugin = enrol_get_plugin('conditional');

        $generator = $this->getDataGenerator();
        $this->meets = $generator->create_user(['username' => 'meets']);
        $this->fails = $generator->create_user(['username' => 'fails']);
        $this->other = $generator->create_user(['username' => 'other']);
    }

    /**
     * A condition tree naming users by username, through the engine's userlist condition.
     *
     * @param array $usernames Lowercase usernames.
     * @param bool $negate Whether the condition is negated: met by everybody not on the list.
     * @return string The tree JSON.
     */
    private static function userlist(array $usernames, bool $negate = false): string {
        return json_encode(['op' => 'and', 'children' => [
            ['type' => 'userlist', 'negate' => $negate, 'config' => ['usernames' => $usernames]],
        ]]);
    }

    /**
     * A condition tree of one period, through the engine's date condition, which reads no table.
     *
     * @param int $from The start of the period, 0 for none.
     * @param int $to The end of the period, 0 for none.
     * @return string The tree JSON.
     */
    private static function period(int $from, int $to): string {
        return json_encode(['op' => 'and', 'children' => [
            ['type' => 'date', 'config' => ['from' => $from, 'to' => $to]],
        ]]);
    }

    /**
     * Create an unlisted course whose only self-service method will be the conditional instance.
     *
     * A generated course carries a manual instance, which offers a learner nothing, and disabled
     * guest and self ones, removed here so nothing but the conditional instance decides.
     *
     * @param int|null $categoryid The category, the default one when null.
     * @return \stdClass The course.
     */
    private function unlisted_course(?int $categoryid = null): \stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course($categoryid ? ['category' => $categoryid] : []);
        $DB->delete_records_select('enrol', "courseid = :courseid AND enrol <> 'manual'", ['courseid' => $course->id]);
        $current = $GLOBALS['USER'];
        $this->setAdminUser();
        discoverability::set_state((int) $course->id, discoverability::STATE_UNLISTED);
        $this->setUser($current);
        return $course;
    }

    /**
     * Add an enabled conditional instance, open to new enrolments, through the plugin.
     *
     * @param \stdClass $course The course.
     * @param string|null $tree The condition tree JSON, '' for none; the userlist of "meets" when null.
     * @param array $fields Extra instance fields.
     * @return \stdClass The instance, as stored.
     */
    private function add_conditional(\stdClass $course, ?string $tree = null, array $fields = []): \stdClass {
        global $DB;

        $id = $this->plugin->add_instance($course, ['customtext2' => $tree ?? self::userlist(['meets'])] + $fields + [
            'status' => ENROL_INSTANCE_ENABLED,
            'customint6' => 1,
            'roleid' => (int) $DB->get_field('role', 'id', ['shortname' => 'student']),
        ]);
        return $DB->get_record('enrol', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Write one user_enrolments row straight to the table, with no role, group or message.
     *
     * @param int $enrolid The instance.
     * @param int $userid The user.
     * @param int $status The row status.
     * @return void
     */
    private function add_row(int $enrolid, int $userid, int $status): void {
        global $DB;

        $DB->insert_record('user_enrolments', (object) [
            'enrolid' => $enrolid,
            'userid' => $userid,
            'status' => $status,
            'timestart' => 0,
            'timeend' => 0,
            'modifierid' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Log in as somebody and forget every answer memoised before, as a new request would.
     *
     * The viewer's access data is loaded, as require_login() has loaded it before any listing.
     *
     * @param \stdClass|int $user The user, or 0 for a visitor.
     * @return void
     */
    private function become($user): void {
        $this->setUser($user);
        access::reset_caches();
        \core\context_helper::reset_caches();
        has_capability('moodle/site:config', \core\context\system::instance());
    }

    /**
     * Statements issued by a callable.
     *
     * @param callable $fn The code to meter.
     * @return int The reads.
     */
    private function reads(callable $fn): int {
        global $DB;

        $before = (int) $DB->perf_get_reads();
        $fn();
        return (int) $DB->perf_get_reads() - $before;
    }

    /**
     * Ask every door about one course, each as a fresh request would.
     *
     * @param \stdClass|int $viewer The viewer.
     * @param \stdClass $course The course.
     * @return array {single: per course, nothing primed; primed: per course after filter_courses();
     *         batch: get_next_actions(); kept: whether filter_courses() kept it; discoverable:
     *         is_course_discoverable(); facts: the facts prime_probe() stored, by memo key}.
     */
    private function doors($viewer, \stdClass $course): array {
        $courseid = (int) $course->id;
        $items = [$courseid => (object) ['id' => $courseid, 'category' => (int) $course->category]];

        $this->become($viewer);
        $single = access::get_next_action($courseid);

        $this->become($viewer);
        $kept = access::filter_courses($items);
        $primed = access::get_next_action($courseid);
        $facts = (new \ReflectionProperty(access::class, 'selffacts'))->getValue();

        $this->become($viewer);
        $batch = access::get_next_actions([$courseid])[$courseid];

        $this->become($viewer);
        $discoverable = access::is_course_discoverable($courseid);

        return [
            'single' => $single,
            'primed' => $primed,
            'batch' => $batch,
            'kept' => isset($kept[$courseid]),
            'discoverable' => $discoverable,
            'facts' => $facts,
        ];
    }

    /**
     * What enrol_conditional itself answers for this viewer: can_self_enrol($instance, false), strictly true.
     *
     * @param \stdClass $viewer The viewer.
     * @param \stdClass $instance The instance; read again from the table.
     * @return bool True when the plugin would enrol the viewer now.
     */
    private function plugin_admits(\stdClass $viewer, \stdClass $instance): bool {
        global $DB;

        $this->become($viewer);
        $fresh = $DB->get_record('enrol', ['id' => $instance->id], '*', MUST_EXIST);
        return $this->plugin->can_self_enrol($fresh, false) === true;
    }

    /**
     * Assert one verdict on every door, and that it is enrol_conditional's own.
     *
     * @param \stdClass $viewer The viewer.
     * @param \stdClass $course The course.
     * @param \stdClass $instance The conditional instance.
     * @param string|null $blocked Null when the instance must be a route in, else the BLOCKED_* reason.
     * @param int|null $opens The opening date the refusal must carry.
     * @param string $label What the case is, for the messages.
     * @return array The answers of {@see doors()}.
     */
    private function assert_verdict(
        \stdClass $viewer,
        \stdClass $course,
        \stdClass $instance,
        ?string $blocked,
        ?int $opens,
        string $label
    ): array {
        $answers = $this->doors($viewer, $course);
        if ($blocked === null) {
            $expected = [
                'type' => access::NEXT_OPEN,
                'routes' => [['kind' => 'conditional', 'instanceid' => (int) $instance->id]],
                'guest' => null,
                'conditional' => null,
                'blocked' => null,
                'opens' => null,
            ];
        } else {
            $expected = [
                'type' => access::NEXT_BLOCKED,
                'routes' => [],
                'guest' => null,
                'conditional' => null,
                'blocked' => $blocked,
                'opens' => $opens,
            ];
        }
        $this->assertSame($expected, $answers['single'], "{$label}: per course, nothing primed");
        $this->assertSame($expected, $answers['primed'], "{$label}: per course, primed by the listing");
        $this->assertSame($expected, $answers['batch'], "{$label}: the batch");
        $this->assertSame($blocked === null, $answers['discoverable'], "{$label}: is_course_discoverable()");
        $this->assertSame($blocked === null, $answers['kept'], "{$label}: filter_courses()");
        $this->assertSame(
            $this->plugin_admits($viewer, $instance),
            $blocked === null,
            "{$label}: the verdict is enrol_conditional's own can_self_enrol()"
        );
        return $answers;
    }

    /**
     * A viewer who meets the conditions is offered the route and may find the course; one who does not, neither.
     *
     * The control is the same instance asked by the other viewer, so the answer can only have come
     * from evaluating the conditions. The primed door answers from facts prime_probe() read.
     *
     * @return void
     */
    public function test_a_viewer_who_meets_the_conditions_may_enrol_and_find_the_course(): void {
        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course);

        $meets = $this->assert_verdict($this->meets, $course, $instance, null, null, 'meets');
        $this->assertArrayHasKey(
            $this->meets->id . ':' . $instance->id,
            $meets['facts'],
            'Control: the primed door answered from the facts the listing read.'
        );
        $fails = $this->assert_verdict($this->fails, $course, $instance, access::BLOCKED_OFF, null, 'fails');
        $this->assertArrayHasKey($this->fails->id . ':' . $instance->id, $fails['facts']);
    }

    /**
     * A window not open yet refuses, and dates its opening only for a viewer who meets the conditions now.
     *
     * The control is the same instance with its window open.
     *
     * @return void
     */
    public function test_a_window_not_open_yet_refuses_and_dates_the_opening_only_for_a_viewer_who_meets(): void {
        global $DB;

        $start = time() + DAYSECS;
        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course, null, ['enrolstartdate' => $start]);

        $this->assert_verdict($this->meets, $course, $instance, access::BLOCKED_WINDOW, $start, 'not open yet, meets');
        $this->assert_verdict($this->fails, $course, $instance, access::BLOCKED_WINDOW, null, 'not open yet, fails');

        $DB->set_field('enrol', 'enrolstartdate', time() - DAYSECS, ['id' => $instance->id]);
        $this->assert_verdict($this->meets, $course, $instance, null, null, 'opened');
    }

    /**
     * A closed window refuses; the same window still open admits.
     *
     * @return void
     */
    public function test_a_closed_window_refuses(): void {
        global $DB;

        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course, null, ['enrolenddate' => time() - DAYSECS]);

        $this->assert_verdict($this->meets, $course, $instance, access::BLOCKED_WINDOW, null, 'closed');

        $DB->set_field('enrol', 'enrolenddate', time() + DAYSECS, ['id' => $instance->id]);
        $this->assert_verdict($this->meets, $course, $instance, null, null, 'closing tomorrow');
    }

    /**
     * New enrolments off refuses; switched on again, the same viewer is admitted.
     *
     * @return void
     */
    public function test_new_enrolments_off_refuses(): void {
        global $DB;

        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course, null, ['customint6' => 0]);

        $this->assert_verdict($this->meets, $course, $instance, access::BLOCKED_OFF, null, 'new enrolments off');

        $DB->set_field('enrol', 'customint6', 1, ['id' => $instance->id]);
        $this->assert_verdict($this->meets, $course, $instance, null, null, 'new enrolments on');
    }

    /**
     * The viewer's own row on the instance refuses, whatever its status; without it they are admitted.
     *
     * A suspended row keeps nobody's unlisted course, so the course is not discoverable either.
     *
     * @return void
     */
    public function test_the_viewers_own_row_refuses(): void {
        global $DB;

        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course);
        $this->add_row((int) $instance->id, (int) $this->meets->id, ENROL_USER_SUSPENDED);

        $answers = $this->assert_verdict($this->meets, $course, $instance, access::BLOCKED_OWN_ROW, null, 'own row');
        $this->assertArrayHasKey($this->meets->id . ':' . $instance->id, $answers['facts'], 'The row came from the primed facts.');

        $DB->delete_records('user_enrolments', ['enrolid' => $instance->id]);
        $this->assert_verdict($this->meets, $course, $instance, null, null, 'no row');
    }

    /**
     * A full instance refuses; with one place left the same viewer is admitted.
     *
     * @return void
     */
    public function test_a_full_instance_refuses_and_one_place_left_admits(): void {
        global $DB;

        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course, null, ['customint3' => 1]);
        $this->add_row((int) $instance->id, (int) $this->other->id, ENROL_USER_ACTIVE);

        $this->assert_verdict($this->meets, $course, $instance, access::BLOCKED_FULL, null, 'full');

        $DB->set_field('enrol', 'customint3', 2, ['id' => $instance->id]);
        $this->assert_verdict($this->meets, $course, $instance, null, null, 'one place left');
    }

    /**
     * Without enrol/conditional:enrolself the viewer is refused, for a reason that does not say which check refused.
     *
     * The control is the same viewer and instance before the prohibition.
     *
     * @return void
     */
    public function test_a_prohibited_capability_refuses(): void {
        global $DB;

        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course);
        $this->assert_verdict($this->meets, $course, $instance, null, null, 'allowed');

        $userrole = (int) $DB->get_field('role', 'id', ['shortname' => 'user']);
        assign_capability(
            'enrol/conditional:enrolself',
            CAP_PROHIBIT,
            $userrole,
            \core\context\course::instance((int) $course->id)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
        $this->assert_verdict($this->meets, $course, $instance, access::BLOCKED_OFF, null, 'prohibited');
    }

    /**
     * A tree the engine cannot judge refuses everybody, as the plugin refuses a new enrolment on "unknown".
     *
     * Two kinds of unknown: a condition type that is not installed, and a tree the engine reads as
     * invalid (it says so through debugging()). The control is the same instance with a tree the
     * viewer meets.
     *
     * @return void
     */
    public function test_an_unknown_tree_fails_closed(): void {
        global $DB;

        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course);
        $this->assert_verdict($this->meets, $course, $instance, null, null, 'a tree the viewer meets');

        // Written straight to the table: add_instance() would refuse to store either tree.
        $missing = '{"op":"and","children":[{"type":"nosuchtype","config":{}}]}';
        $DB->set_field('enrol', 'customtext2', $missing, ['id' => $instance->id]);
        $this->assert_verdict($this->meets, $course, $instance, access::BLOCKED_OFF, null, 'an uninstalled condition type');

        $DB->set_field('enrol', 'customtext2', '{"op":"xor"}', ['id' => $instance->id]);
        $this->assert_verdict($this->meets, $course, $instance, access::BLOCKED_OFF, null, 'an invalid tree');
        $this->assertNotEmpty($this->getDebuggingMessages(), 'The engine reported the invalid tree.');
        $this->resetDebugging();
    }

    /**
     * An instance with no conditions admits everybody the other checks admit.
     *
     * @return void
     */
    public function test_an_empty_tree_admits(): void {
        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course, '');
        $this->assertNull($instance->customtext2, 'Precondition: the plugin stores no tree.');

        $this->assert_verdict($this->meets, $course, $instance, null, null, 'empty tree, meets');
        $this->assert_verdict($this->fails, $course, $instance, null, null, 'empty tree, not on any list');
    }

    /**
     * A visitor and the guest account are offered nothing, for no statement: the engine is not asked about them.
     *
     * The tree is a negated userlist, which anybody not on it meets, so a visitor or a guest would
     * be admitted by the conditions if they were asked. The control is a logged-in user who is
     * not on the list either, and is offered the route.
     *
     * @return void
     */
    public function test_a_visitor_and_the_guest_account_are_offered_nothing_and_the_engine_is_not_asked(): void {
        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course, self::userlist(['meets'], true));
        $courseid = (int) $course->id;

        $this->assert_verdict($this->fails, $course, $instance, null, null, 'control: a logged-in user off the list');

        foreach (['visitor' => 0, 'guest' => guest_user()] as $label => $viewer) {
            $this->become($viewer);
            $single = [];
            $this->assertSame(0, $this->reads(function () use ($courseid, &$single): void {
                $single = access::get_next_action($courseid);
            }), "{$label}: nothing is read about the course, the engine included");
            $this->assertSame(access::NEXT_NONE, $single['type'], "{$label}: per course");

            $this->become($viewer);
            $batch = [];
            $this->assertSame(0, $this->reads(function () use ($courseid, &$batch): void {
                $batch = access::get_next_actions([$courseid]);
            }), "{$label}: the batch reads nothing either");
            $this->assertSame(access::NEXT_NONE, $batch[$courseid]['type'], "{$label}: the batch");

            $this->become($viewer);
            $this->assertFalse(access::is_course_discoverable($courseid), "{$label}: the unlisted course stays hidden");
            $this->assertSame([], access::filter_courses([(object) ['id' => $courseid, 'category' => (int) $course->category]]));
        }
    }

    /**
     * The primed facts belong to the viewer they were read for.
     *
     * One viewer holds a row on the instance and is refused from the facts their listing primed;
     * another, asked in the same request with nothing reset, holds none and is admitted. The
     * instance has no conditions, so only the row tells the two apart.
     *
     * @return void
     */
    public function test_the_conditional_facts_are_keyed_by_the_viewer(): void {
        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course, '');
        $this->add_row((int) $instance->id, (int) $this->meets->id, ENROL_USER_SUSPENDED);
        $courseid = (int) $course->id;

        $this->become($this->meets);
        $this->assertSame([], access::filter_courses([(object) ['id' => $courseid, 'category' => (int) $course->category]]));
        $this->assertSame(access::BLOCKED_OWN_ROW, access::get_next_action($courseid)['blocked']);
        $this->assertArrayHasKey(
            $this->meets->id . ':' . $instance->id,
            (new \ReflectionProperty(access::class, 'selffacts'))->getValue(),
            'Precondition: the refusal came from the primed facts.'
        );

        $this->setUser($this->fails);
        $this->assertSame(access::NEXT_OPEN, access::get_next_action($courseid)['type'], 'Another viewer holds no row.');
    }

    /**
     * The rule refuses a disabled instance, which no door hands it, as can_self_enrol() does.
     *
     * @return void
     */
    public function test_the_rule_refuses_a_disabled_instance(): void {
        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course);
        $rule = new \ReflectionMethod(access::class, 'conditional_outcome');
        $facts = ['holdsrow' => false, 'taken' => 0];
        $this->become($this->meets);

        $this->assertSame(access::NEXT_OPEN, $rule->invoke(null, $instance, $this->plugin, time(), $facts)['type'], 'Control.');
        $instance->status = ENROL_INSTANCE_DISABLED;
        $outcome = $rule->invoke(null, $instance, $this->plugin, time(), $facts);
        $this->assertSame([access::NEXT_BLOCKED, access::BLOCKED_OFF], [$outcome['type'], $outcome['blocked']]);
        $this->assertNotTrue($this->plugin->can_self_enrol($instance, false), 'And so does enrol_conditional.');
    }

    /**
     * The methods of enrol_conditional the rule copies are the ones it was copied from.
     *
     * can_self_enrol() and is_self_enrol_available() delegate to the two protected methods that
     * hold the checks, so all four are fingerprinted. A drift alarm, not a behaviour test: when
     * enrol_conditional changes any of them, this reddens, and {@see access::conditional_outcome()}
     * is brought back in step with the new code (and the fingerprints updated) before anything
     * else, because a check the plugin adds that the copy lacks would name an unlisted course to
     * somebody the plugin refuses.
     *
     * @return void
     */
    public function test_the_copied_conditional_checks_have_not_changed(): void {
        $fingerprint = function (string $method): string {
            $reflection = new \ReflectionMethod(get_class($this->plugin), $method);
            $start = $reflection->getStartLine();
            $body = array_slice(file($reflection->getFileName()), $start - 1, $reflection->getEndLine() - $start + 1);
            // Whitespace runs collapse, so a reindented file is not a change of behaviour.
            return sha1(preg_replace('/\s+/', ' ', implode('', $body)));
        };

        $expected = [
            'can_self_enrol' => 'ea190c79dd63deff39670546975a438e64d077f3',
            'get_self_enrol_status' => '5b67309b90aed52fc6b14a6e7223a7a470c8d808',
            'is_self_enrol_available' => 'ba0f0446076f0dd55ae899b1b34034206e472f5d',
            'get_instance_status' => 'aaaf169358dd1646208b5a2cd5a80f5c7f5947bb',
        ];
        $this->assertSame(
            $expected,
            array_combine(array_keys($expected), array_map($fingerprint, array_keys($expected))),
            'enrol_conditional changed: bring access::conditional_outcome() in step with it, then update these fingerprints.'
        );
    }

    /**
     * The rows the engine leaves behind are classified as rows, through the real enforcer.
     *
     * Suspended by the engine is suspended, which keeps nobody's unlisted course (the control is
     * the active row before it, which did); reactivated is enrolled again; removed is no row.
     *
     * @return void
     */
    public function test_the_rows_the_engine_leaves_are_classified_as_rows(): void {
        global $DB;

        $enforcer = new \local_enrolconditions\local\enforcer();
        $course = $this->unlisted_course();
        $instance = $this->add_conditional($course, null, [
            'conditionaction' => \local_enrolconditions\enforceable::ACTION_SUSPEND,
            'conditionreactivate' => 1,
        ]);
        $this->plugin->enrol_user($instance, (int) $this->meets->id);
        $courseid = (int) $course->id;

        $this->become($this->meets);
        $this->assertSame(access::RELATIONSHIP_ENROLLED, access::get_enrolment_state($courseid)['type']);
        $this->assertTrue(access::is_course_discoverable($courseid), 'Control: the active row keeps the unlisted course.');

        $row = ['enrolid' => $instance->id, 'userid' => $this->meets->id];
        $DB->set_field('enrol', 'customtext2', self::userlist(['other']), ['id' => $instance->id]);
        $instance = $DB->get_record('enrol', ['id' => $instance->id], '*', MUST_EXIST);
        $enforcer->check_user($instance, (int) $this->meets->id);
        $this->assertEquals(ENROL_USER_SUSPENDED, $DB->get_field('user_enrolments', 'status', $row), 'Suspended by the engine.');
        $this->become($this->meets);
        $this->assertSame(access::RELATIONSHIP_SUSPENDED, access::get_enrolment_state($courseid)['type']);
        $this->assertFalse(access::is_course_discoverable($courseid), 'A row the engine suspended keeps nothing.');
        $this->assertSame(access::BLOCKED_OWN_ROW, access::get_next_action($courseid)['blocked']);

        $DB->set_field('enrol', 'customtext2', self::userlist(['meets']), ['id' => $instance->id]);
        $instance = $DB->get_record('enrol', ['id' => $instance->id], '*', MUST_EXIST);
        $enforcer->check_user($instance, (int) $this->meets->id);
        $this->assertEquals(ENROL_USER_ACTIVE, $DB->get_field('user_enrolments', 'status', $row), 'The engine reactivated it.');
        $this->become($this->meets);
        $this->assertSame(access::RELATIONSHIP_ENROLLED, access::get_enrolment_state($courseid)['type']);
        $this->assertTrue(access::is_course_discoverable($courseid));

        $removing = $this->unlisted_course();
        $other = $this->add_conditional($removing, null, ['conditionaction' => \local_enrolconditions\enforceable::ACTION_REMOVE]);
        $this->plugin->enrol_user($other, (int) $this->meets->id);
        $DB->set_field('enrol', 'customtext2', self::userlist(['other']), ['id' => $other->id]);
        $other = $DB->get_record('enrol', ['id' => $other->id], '*', MUST_EXIST);
        $enforcer->check_user($other, (int) $this->meets->id);
        $this->assertFalse(
            $DB->record_exists('user_enrolments', ['enrolid' => $other->id, 'userid' => $this->meets->id]),
            'The engine removed the row.'
        );
        $this->become($this->meets);
        $this->assertSame(access::RELATIONSHIP_NONE, access::get_enrolment_state((int) $removing->id)['type']);
        $this->assertFalse(access::is_course_discoverable((int) $removing->id), 'No row, and the conditions refuse.');
    }

    /**
     * A primed listing of conditional courses costs the same for two courses as for twelve, and so does the batch.
     *
     * Each course holds one conditional instance behind a date condition, which reads no table, so
     * every statement left is the probe's or the batch's own: no own-row or count statement per
     * course. Every course is kept and open, so the budget cannot be met by a door that stopped
     * asking.
     *
     * @return void
     */
    public function test_a_primed_listing_costs_the_same_for_two_conditional_courses_and_for_twelve(): void {
        $categoryid = (int) $this->getDataGenerator()->create_category()->id;
        $tree = self::period(time() - DAYSECS, 0);
        $seed = function (int $count) use ($categoryid, $tree): array {
            $items = [];
            for ($i = 0; $i < $count; $i++) {
                $course = $this->unlisted_course($categoryid);
                $this->add_conditional($course, $tree);
                $items[(int) $course->id] = (object) ['id' => (int) $course->id, 'category' => $categoryid];
            }
            return $items;
        };
        $warm = $seed(2);
        $small = $seed(2);
        $large = $seed(12);

        $listing = function (array $items): array {
            $this->become($this->fails);
            $kept = [];
            $reads = $this->reads(function () use ($items, &$kept): void {
                $kept = access::filter_courses($items);
            });
            return [count($kept), $reads];
        };
        $listing($warm);
        [$smallkept, $smallreads] = $listing($small);
        [$largekept, $largereads] = $listing($large);
        $this->assertSame([2, 12], [$smallkept, $largekept], 'Every course is kept.');
        $this->assertSame($smallreads, $largereads, "{$smallreads} reads for two courses, {$largereads} for twelve.");

        $batch = function (array $items): array {
            $this->become($this->fails);
            $answers = [];
            $reads = $this->reads(function () use ($items, &$answers): void {
                $answers = access::get_next_actions(array_keys($items));
            });
            return [array_count_values(array_column($answers, 'type')), $reads];
        };
        $batch($warm);
        [$smalltypes, $smallreads] = $batch($small);
        [$largetypes, $largereads] = $batch($large);
        $this->assertSame([[access::NEXT_OPEN => 2], [access::NEXT_OPEN => 12]], [$smalltypes, $largetypes]);
        $this->assertSame($smallreads, $largereads, "Batch: {$smallreads} reads for two courses, {$largereads} for twelve.");
        $this->assertSame(2, $largereads, 'The batch: the instances and the viewer\'s rows.');
    }
}
