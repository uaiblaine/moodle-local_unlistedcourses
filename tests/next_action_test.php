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
 * Unlisted courses - Tests for the relationship vocabulary and the next action
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for the relationships, the discoverability allow-list, the next action and its batch.
 *
 * The shapes that need enrol_apply, enrol_autoenrol or enrol_coursecompleted are built only where
 * those plugins are installed, so on a runner without them the tests still run, over the core
 * shapes alone.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(access::class)]
final class next_action_test extends \advanced_testcase {
    /** The waiting-list status of enrol_apply, whose constant is not defined on a site without that plugin. */
    private const WAIT = 2;

    /**
     * Every test writes to the database, and the plugin's request caches are static.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        access::reset_caches();
    }

    /**
     * Put a course in a discoverability state, as admin, whoever is logged in.
     *
     * @param int $courseid The course.
     * @param int $state A discoverability::STATE_* constant.
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
     * Put a category in a discoverability state, as admin, whoever is logged in.
     *
     * @param int $categoryid The category.
     * @param int $state A category_discoverability::STATE_* constant.
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
     * Log in as somebody and forget every answer memoised for the previous viewer.
     *
     * @param \stdClass|int $user The user, or 0 for a visitor.
     * @return void
     */
    private function become($user): void {
        $this->setUser($user);
        access::reset_caches();
    }

    /**
     * Enable an enrol plugin site-wide; a fresh test site enables manual, guest, self and cohort only.
     *
     * @param string $enrol The plugin name.
     * @return void
     */
    private function enable_plugin(string $enrol): void {
        $enabled = array_keys(enrol_get_plugins(true));
        if (!in_array($enrol, $enabled, true)) {
            $enabled[] = $enrol;
            set_config('enrol_plugins_enabled', implode(',', $enabled));
        }
    }

    /**
     * Add an enabled instance through its plugin, enabling the plugin site-wide first.
     *
     * @param \stdClass $course The course.
     * @param string $enrol The plugin name.
     * @param array $fields Instance fields.
     * @return \stdClass|null The instance, or null when the plugin is not installed.
     */
    private function add_instance(\stdClass $course, string $enrol, array $fields = []): ?\stdClass {
        global $DB;

        $plugin = enrol_get_plugin($enrol);
        if (!$plugin) {
            return null;
        }
        $this->enable_plugin($enrol);
        $fields += ['roleid' => (int) $DB->get_field('role', 'id', ['shortname' => 'student'])];
        $id = $plugin->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED] + $fields);
        return $DB->get_record('enrol', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Enable the course's default instance of a method, with extra fields.
     *
     * @param \stdClass $course The course.
     * @param string $enrol The plugin name.
     * @param array $fields Extra fields.
     * @return \stdClass The instance.
     */
    private function enable_default(\stdClass $course, string $enrol, array $fields = []): \stdClass {
        global $DB;

        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => $enrol], '*', MUST_EXIST);
        foreach ($fields + ['status' => ENROL_INSTANCE_ENABLED] as $name => $value) {
            $instance->$name = $value;
        }
        $DB->update_record('enrol', $instance);
        return $DB->get_record('enrol', ['id' => $instance->id], '*', MUST_EXIST);
    }

    /**
     * Write an instance straight to the table, so the test needs neither the plugin nor its defaults.
     *
     * @param int $courseid The course.
     * @param string $enrol The plugin name.
     * @param int $status The instance status.
     * @param array $fields Extra fields.
     * @return int The instance id.
     */
    private function raw_instance(int $courseid, string $enrol, int $status, array $fields = []): int {
        global $DB;

        $now = time();
        return (int) $DB->insert_record('enrol', (object) ($fields + [
            'enrol' => $enrol,
            'courseid' => $courseid,
            'status' => $status,
            'sortorder' => 50 + (int) $DB->count_records('enrol', ['courseid' => $courseid]),
            'timecreated' => $now,
            'timemodified' => $now,
        ]));
    }

    /**
     * Write one user_enrolments row straight to the table, with no role, group or message.
     *
     * @param int $enrolid The instance.
     * @param int $userid The user.
     * @param int $status The row status.
     * @param int $timestart The start date.
     * @param int $timeend The end date.
     * @return void
     */
    private function add_row(int $enrolid, int $userid, int $status, int $timestart = 0, int $timeend = 0): void {
        global $DB;

        $DB->insert_record('user_enrolments', (object) [
            'enrolid' => $enrolid,
            'userid' => $userid,
            'status' => $status,
            'timestart' => $timestart,
            'timeend' => $timeend,
            'modifierid' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
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
     * One row of each relationship, the type it is, and whether it keeps an unlisted course.
     *
     * The offsets are seconds from now, 0 meaning the date is unset.
     *
     * @return array Name => [enrol, instance status, row status, start offset, end offset, type, keeps].
     */
    public static function relationship_shape_provider(): array {
        $on = ENROL_USER_ACTIVE;
        $off = ENROL_USER_SUSPENDED;
        $enabled = ENROL_INSTANCE_ENABLED;
        $disabled = ENROL_INSTANCE_DISABLED;
        return [
            'enrolled' => ['manual', $enabled, $on, 0, 0, access::RELATIONSHIP_ENROLLED, true],
            'scheduled' => ['manual', $enabled, $on, 3 * DAYSECS, 0, access::RELATIONSHIP_SCHEDULED, true],
            'pending' => ['apply', $enabled, $off, 0, 0, access::RELATIONSHIP_PENDING, true],
            'waitlisted' => ['apply', $enabled, self::WAIT, 0, 0, access::RELATIONSHIP_WAITLISTED, true],
            'waitlisted on a disabled instance' => ['apply', $disabled, self::WAIT, 0, 0, access::RELATIONSHIP_WAITLISTED, true],
            'suspended' => ['manual', $enabled, $off, 0, 0, access::RELATIONSHIP_SUSPENDED, false],
            'expired' => ['manual', $enabled, $on, -10 * DAYSECS, -5 * DAYSECS, access::RELATIONSHIP_EXPIRED, false],
            'lapsed application' => ['apply', $enabled, $off, -10 * DAYSECS, -5 * DAYSECS, access::RELATIONSHIP_EXPIRED, false],
            'lapsed waiting list' => ['apply', $enabled, self::WAIT, 0, -5 * DAYSECS, access::RELATIONSHIP_EXPIRED, false],
            'on a disabled instance' => ['manual', $disabled, $on, 0, 0, access::RELATIONSHIP_NONE, false],
        ];
    }

    /**
     * Only enrolled, scheduled, pending and waitlisted keep an unlisted course; no listing result changed.
     *
     * Both halves of the one rule are asked: the unlisted course's own predicate, and the
     * relationship escape from an unlisted category in a listing. A waiting-list row read pending
     * before the vocabulary grew, and suspended and expired rows read none, so this is the same
     * answer each shape always had. A cohort nobody belongs to gates the only route, so being
     * able to enrol cannot be what keeps a course; an actively enrolled user is the control that
     * the predicate ran and can answer yes.
     *
     * @param string $enrol The plugin the row is on.
     * @param int $instancestatus The instance status.
     * @param int $status The row status.
     * @param int $startoffset The start date, as an offset from now.
     * @param int $endoffset The end date, as an offset from now.
     * @param string $type The relationship the row is.
     * @param bool $keeps Whether it keeps an unlisted course.
     * @return void
     */
    #[DataProvider('relationship_shape_provider')]
    public function test_only_the_allowed_relationships_keep_an_unlisted_course(
        string $enrol,
        int $instancestatus,
        int $status,
        int $startoffset,
        int $endoffset,
        string $type,
        bool $keeps
    ): void {
        $generator = $this->getDataGenerator();
        $now = time();
        $category = $generator->create_category();
        $hiddencategory = $generator->create_category();
        $unlisted = $generator->create_course(['category' => $category->id]);
        $inside = $generator->create_course(['category' => $hiddencategory->id]);
        $viewer = $generator->create_user();
        $control = $generator->create_user();
        foreach ([$unlisted, $inside] as $course) {
            // First, while the course has one manual instance: the generator enrols nobody once there are two.
            $generator->enrol_user($control->id, $course->id);
            $this->add_instance($course, 'self', ['customint6' => 1, 'customint5' => (int) $generator->create_cohort()->id]);
            $enrolid = $this->raw_instance((int) $course->id, $enrol, $instancestatus);
            $this->add_row(
                $enrolid,
                (int) $viewer->id,
                $status,
                $startoffset ? $now + $startoffset : 0,
                $endoffset ? $now + $endoffset : 0
            );
        }
        $this->set_course_state((int) $unlisted->id, discoverability::STATE_UNLISTED);
        $this->set_category_state((int) $hiddencategory->id, category_discoverability::STATE_UNLISTED);
        $item = [(object) ['id' => (int) $inside->id, 'category' => (int) $hiddencategory->id]];

        $this->become($viewer);
        $this->assertSame($type, access::get_enrolment_state((int) $unlisted->id)['type'], 'Precondition: the row is its type.');
        $this->assertSame($keeps, access::is_course_discoverable((int) $unlisted->id), 'The unlisted course.');
        $this->assertCount($keeps ? 1 : 0, access::filter_courses($item), 'The course inside an unlisted category.');

        $this->become($control);
        $this->assertTrue(access::is_course_discoverable((int) $unlisted->id), 'Control: an enrolled user keeps the course.');
        $this->assertCount(1, access::filter_courses($item), 'Control: and keeps it in the listing.');
    }

    /**
     * Two rows of adjacent rank, and the one that must win.
     *
     * @return array Name => [first row, second row, the winning type], each row
     *         [enrol, instance status, row status, start offset, end offset].
     */
    public static function rank_pair_provider(): array {
        $on = ENROL_USER_ACTIVE;
        $off = ENROL_USER_SUSPENDED;
        $enabled = ENROL_INSTANCE_ENABLED;
        $disabled = ENROL_INSTANCE_DISABLED;
        $active = ['manual', $enabled, $on, 0, 0];
        $later = ['manual', $enabled, $on, 3 * DAYSECS, 0];
        $applied = ['apply', $enabled, $off, 0, 0];
        $waiting = ['apply', $enabled, self::WAIT, 0, 0];
        $suspended = ['manual', $enabled, $off, 0, 0];
        $ended = ['manual', $enabled, $on, -10 * DAYSECS, -5 * DAYSECS];
        $nothing = ['manual', $disabled, $on, 0, 0];
        return [
            'enrolled over scheduled' => [$active, $later, access::RELATIONSHIP_ENROLLED],
            'scheduled over pending' => [$later, $applied, access::RELATIONSHIP_SCHEDULED],
            'pending over waitlisted' => [$applied, $waiting, access::RELATIONSHIP_PENDING],
            'waitlisted over suspended' => [$waiting, $suspended, access::RELATIONSHIP_WAITLISTED],
            'suspended over expired' => [$suspended, $ended, access::RELATIONSHIP_SUSPENDED],
            'expired over none' => [$ended, $nothing, access::RELATIONSHIP_EXPIRED],
        ];
    }

    /**
     * Of two rows the higher rank wins, whichever was written first.
     *
     * @param array $first One row.
     * @param array $second The other.
     * @param string $winner The type that must win.
     * @return void
     */
    #[DataProvider('rank_pair_provider')]
    public function test_the_higher_relationship_wins_whichever_row_came_first(
        array $first,
        array $second,
        string $winner
    ): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $now = time();
        $ids = [];
        foreach ([$first, $second] as $row) {
            $ids[] = $this->raw_instance((int) $course->id, $row[0], $row[1]);
        }
        foreach ([[0, 1], [1, 0]] as $order) {
            $user = $generator->create_user();
            foreach ($order as $index) {
                $row = [$first, $second][$index];
                $start = $row[3] ? $now + $row[3] : 0;
                $end = $row[4] ? $now + $row[4] : 0;
                $this->add_row($ids[$index], (int) $user->id, $row[2], $start, $end);
            }
            $this->become($user);
            $this->assertSame($winner, access::get_enrolment_state((int) $course->id)['type'], implode(',', $order));
        }
    }

    /**
     * Between two expired rows the later end is reported: it is when the access ended.
     *
     * @return void
     */
    public function test_two_expired_rows_report_the_later_end(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $now = time();
        $sooner = $this->raw_instance((int) $course->id, 'manual', ENROL_INSTANCE_ENABLED);
        $later = $this->raw_instance((int) $course->id, 'manual', ENROL_INSTANCE_ENABLED);
        $this->add_row($later, (int) $user->id, ENROL_USER_ACTIVE, $now - 20 * DAYSECS, $now - 2 * DAYSECS);
        $this->add_row($sooner, (int) $user->id, ENROL_USER_ACTIVE, $now - 30 * DAYSECS, $now - 9 * DAYSECS);

        $this->become($user);
        $this->assertSame(
            ['type' => access::RELATIONSHIP_EXPIRED, 'startsat' => $now - 20 * DAYSECS, 'endsat' => $now - 2 * DAYSECS],
            access::get_enrolment_state((int) $course->id)
        );
    }

    /**
     * A suspended or ended enrolment reports the row's own dates, which a surface prints.
     *
     * @return void
     */
    public function test_a_suspended_or_ended_enrolment_reports_its_dates(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $now = time();
        $suspended = $generator->create_user();
        $ended = $generator->create_user();
        $generator->enrol_user(
            $suspended->id,
            $course->id,
            'student',
            'manual',
            $now - 10 * DAYSECS,
            $now + 10 * DAYSECS,
            ENROL_USER_SUSPENDED
        );
        $generator->enrol_user($ended->id, $course->id, 'student', 'manual', $now - 10 * DAYSECS, $now - 3 * DAYSECS);

        $this->become($suspended);
        $this->assertSame(
            ['type' => access::RELATIONSHIP_SUSPENDED, 'startsat' => $now - 10 * DAYSECS, 'endsat' => $now + 10 * DAYSECS],
            access::get_enrolment_state((int) $course->id)
        );
        $this->become($ended);
        $this->assertSame(
            ['type' => access::RELATIONSHIP_EXPIRED, 'startsat' => $now - 10 * DAYSECS, 'endsat' => $now - 3 * DAYSECS],
            access::get_enrolment_state((int) $course->id)
        );
    }

    /**
     * An active enrolment keeps an unlisted course before the enrolment rows are read.
     *
     * The relationship term would keep the course too, so only the cost tells the two apart:
     * core's is_enrolled() answers first, from its own cache when it can, and the row statement
     * of get_enrolment_state() is never paid. The control is a user enrolled from a later date,
     * whom is_enrolled() refuses, and whose answer does need the rows.
     *
     * @return void
     */
    public function test_an_active_enrolment_answers_before_the_enrolment_rows_are_read(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $active = $generator->create_user();
        $later = $generator->create_user();
        $generator->enrol_user($active->id, $course->id);
        $generator->enrol_user($later->id, $course->id, 'student', 'manual', time() + 3 * DAYSECS, 0);
        $this->set_course_state((int) $course->id, discoverability::STATE_UNLISTED);
        $memo = new \ReflectionProperty(access::class, 'enrolmentstate');

        $this->become($active);
        $this->assertTrue(access::is_course_discoverable((int) $course->id));
        $this->assertSame([], $memo->getValue(), 'The rows were never read for an active enrolment.');

        $this->become($later);
        $this->assertTrue(access::is_course_discoverable((int) $course->id), 'Control: a scheduled user keeps it too.');
        $this->assertCount(1, $memo->getValue(), 'Control: and for them the rows were read.');
    }

    /**
     * The summary of a course's outcomes: the order of the types, free before key, the most useful reason.
     *
     * Pure, so it is asked through reflection with outcomes of every shape, plugins or no plugins.
     *
     * @return void
     */
    public function test_the_summary_orders_the_offers_and_the_reasons(): void {
        $method = new \ReflectionMethod(access::class, 'summarise');
        $open = self::outcome_of(access::NEXT_OPEN, 'self', 1);
        $free = self::outcome_of(access::NEXT_GUEST, 'guest', 2, access::GUEST_FREE);
        $key = self::outcome_of(access::NEXT_GUEST, 'guest', 3, access::GUEST_KEY);
        $conditional = self::outcome_of(access::NEXT_CONDITIONAL, 'coursecompleted', 4, null, 77);
        $refusal = fn(string $reason, int $id) => self::outcome_of(access::NEXT_BLOCKED, 'self', $id, null, 0, $reason);

        $all = $method->invoke(null, [$refusal(access::BLOCKED_OFF, 9), $conditional, $key, $free, $open]);
        $this->assertSame(access::NEXT_OPEN, $all['type'], 'Open beats everything.');
        $this->assertSame([['kind' => 'self', 'instanceid' => 1]], $all['routes']);
        $this->assertSame(access::GUEST_FREE, $all['guest'], 'The guest offer is reported beside a route.');
        $this->assertSame(['prerequisiteid' => 77, 'instanceid' => 4], $all['conditional'], 'So is the conditional one.');
        $this->assertNull($all['blocked'], 'A reason is reported only for a blocked summary.');

        $guest = $method->invoke(null, [$refusal(access::BLOCKED_WINDOW, 9), $conditional, $key]);
        $this->assertSame(access::NEXT_GUEST, $guest['type'], 'Guest beats conditional.');
        $this->assertSame(access::GUEST_KEY, $guest['guest']);
        $this->assertSame(access::GUEST_FREE, $method->invoke(null, [$key, $free])['guest'], 'Free beats a key, after it.');
        $this->assertSame(access::GUEST_FREE, $method->invoke(null, [$free, $key])['guest'], 'Free beats a key, before it.');

        $cond = $method->invoke(null, [$refusal(access::BLOCKED_WINDOW, 9), $conditional]);
        $this->assertSame(access::NEXT_CONDITIONAL, $cond['type'], 'Conditional beats blocked.');
        $this->assertSame([], $cond['routes']);

        $priority = [
            access::BLOCKED_WINDOW,
            access::BLOCKED_FULL,
            access::BLOCKED_COHORT,
            access::BLOCKED_OWN_ROW,
            access::BLOCKED_OFF,
        ];
        for ($better = 0; $better < count($priority) - 1; $better++) {
            $first = $refusal($priority[$better], 5);
            $second = $refusal($priority[$better + 1], 6);
            $this->assertSame($priority[$better], $method->invoke(null, [$first, $second])['blocked'], $priority[$better]);
            $this->assertSame($priority[$better], $method->invoke(null, [$second, $first])['blocked'], $priority[$better]);
            $this->assertSame(access::NEXT_BLOCKED, $method->invoke(null, [$second, $first])['type']);
        }

        $this->assertSame(
            ['type' => access::NEXT_NONE, 'routes' => [], 'guest' => null, 'conditional' => null, 'blocked' => null],
            $method->invoke(null, []),
            'Nothing on offer is none.'
        );
    }

    /**
     * One instance's outcome, as access::summarise() reads it.
     *
     * @param string $type A NEXT_* constant.
     * @param string $kind The enrol plugin name.
     * @param int $id The instance id.
     * @param string|null $guest A GUEST_* constant, for guest access.
     * @param int $prerequisite The prerequisite course, for a conditional outcome.
     * @param string|null $blocked A BLOCKED_* constant, for a refusal.
     * @return array The outcome.
     */
    private static function outcome_of(
        string $type,
        string $kind,
        int $id,
        ?string $guest = null,
        int $prerequisite = 0,
        ?string $blocked = null
    ): array {
        return [
            'type' => $type,
            'kind' => $kind,
            'instanceid' => $id,
            'guest' => $guest,
            'prerequisiteid' => $prerequisite,
            'blocked' => $blocked,
        ];
    }

    /**
     * Build one course per shape, for the shape tests below.
     *
     * Each entry carries what the per-course path must answer and, where the batch drops a check
     * it cannot read in SQL, what the batch answers instead.
     *
     * @param \stdClass $viewer The viewer the answers are for.
     * @return array Label => ['course' => int, 'expected' => compact answer, 'batch' => compact answer|null].
     */
    private function build_shapes(\stdClass $viewer): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $other = $generator->create_user();
        $member = $generator->create_cohort();
        $foreign = $generator->create_cohort();
        cohort_add_member($member->id, $viewer->id);
        $now = time();
        $shapes = [];
        $make = function (
            string $label,
            array $expected,
            callable $setup,
            ?array $batch = null
        ) use (
            &$shapes,
            $generator
        ): void {
            $course = $generator->create_course(['fullname' => $label]);
            $setup($course);
            $shapes[$label] = ['course' => (int) $course->id, 'expected' => $expected, 'batch' => $batch];
        };
        $open = fn(array $kinds, ?string $guest = null) => [access::NEXT_OPEN, $kinds, $guest, null, null];
        $blocked = fn(string $reason) => [access::NEXT_BLOCKED, [], null, null, $reason];

        $make('self open', $open(['self']), function ($c) {
            $this->add_instance($c, 'self', ['customint6' => 1]);
        });
        $make('self new enrolments off', $blocked(access::BLOCKED_OFF), function ($c) {
            $this->add_instance($c, 'self', ['customint6' => 0]);
        });
        $make('self window not open yet', $blocked(access::BLOCKED_WINDOW), function ($c) use ($now) {
            $this->add_instance($c, 'self', ['customint6' => 1, 'enrolstartdate' => $now + DAYSECS]);
        });
        $make('self window closed', $blocked(access::BLOCKED_WINDOW), function ($c) use ($now) {
            $this->add_instance($c, 'self', ['customint6' => 1, 'enrolenddate' => $now - DAYSECS]);
        });
        $make('self full', $blocked(access::BLOCKED_FULL), function ($c) use ($other) {
            $instance = $this->add_instance($c, 'self', ['customint6' => 1, 'customint3' => 1]);
            $this->add_row((int) $instance->id, (int) $other->id, ENROL_USER_ACTIVE);
        });
        $make('self cohort member', $open(['self']), function ($c) use ($member) {
            $this->add_instance($c, 'self', ['customint6' => 1, 'customint5' => $member->id]);
        });
        $make('self cohort non-member', $blocked(access::BLOCKED_COHORT), function ($c) use ($foreign) {
            $this->add_instance($c, 'self', ['customint6' => 1, 'customint5' => $foreign->id]);
        });
        $make('self cohort deleted', $blocked(access::BLOCKED_COHORT), function ($c) {
            $this->add_instance($c, 'self', ['customint6' => 1, 'customint5' => 999999]);
        });
        $make('self own row', $blocked(access::BLOCKED_OWN_ROW), function ($c) use ($viewer) {
            $instance = $this->add_instance($c, 'self', ['customint6' => 1]);
            $this->add_row((int) $instance->id, (int) $viewer->id, ENROL_USER_SUSPENDED);
        });
        $make('self capability prohibited', $blocked(access::BLOCKED_OFF), function ($c) use ($DB) {
            $this->add_instance($c, 'self', ['customint6' => 1]);
            $userrole = (int) $DB->get_field('role', 'id', ['shortname' => 'user']);
            assign_capability('enrol/self:enrolself', CAP_PROHIBIT, $userrole, \core\context\course::instance($c->id)->id, true);
        }, $open(['self']));
        $make('guest free', [access::NEXT_GUEST, [], access::GUEST_FREE, null, null], function ($c) {
            $this->enable_default($c, 'guest');
        });
        $make('guest behind a key', [access::NEXT_GUEST, [], access::GUEST_KEY, null, null], function ($c) {
            $this->enable_default($c, 'guest', ['password' => 'chave']);
        });
        $make('guest free beside a keyed one', [access::NEXT_GUEST, [], access::GUEST_FREE, null, null], function ($c) {
            $this->enable_default($c, 'guest', ['password' => 'chave']);
            $this->raw_instance((int) $c->id, 'guest', ENROL_INSTANCE_ENABLED, ['password' => '']);
        });
        $make('guest beside an open self', $open(['self'], access::GUEST_FREE), function ($c) {
            $this->enable_default($c, 'guest');
            $this->add_instance($c, 'self', ['customint6' => 1]);
        });
        $make('fee priced', $open(['fee']), function ($c) {
            $this->add_instance($c, 'fee', ['cost' => 10, 'currency' => 'USD']);
        });
        $make('fee own row', $blocked(access::BLOCKED_OWN_ROW), function ($c) use ($viewer, $now) {
            $instance = $this->add_instance($c, 'fee', ['cost' => 10, 'currency' => 'USD']);
            $this->add_row((int) $instance->id, (int) $viewer->id, ENROL_USER_ACTIVE, $now - 10 * DAYSECS, $now - 5 * DAYSECS);
        });
        $make('fee window closed', $blocked(access::BLOCKED_WINDOW), function ($c) use ($now) {
            $this->add_instance($c, 'fee', ['cost' => 10, 'currency' => 'USD', 'enrolenddate' => $now - DAYSECS]);
        });
        $make('fee without a price', $blocked(access::BLOCKED_OFF), function ($c) {
            $this->add_instance($c, 'fee', ['cost' => 0, 'currency' => 'USD']);
        });
        $make('paypal priced', $open(['paypal']), function ($c) {
            $this->add_instance($c, 'paypal', ['cost' => 10, 'currency' => 'USD']);
        });
        $make('two routes', $open(['self', 'fee']), function ($c) {
            $this->add_instance($c, 'self', ['customint6' => 1]);
            $this->add_instance($c, 'fee', ['cost' => 10, 'currency' => 'USD']);
        });
        $make('the most useful reason wins', $blocked(access::BLOCKED_WINDOW), function ($c) use ($now) {
            $this->add_instance($c, 'fee', ['cost' => 0, 'currency' => 'USD']);
            $this->add_instance($c, 'self', ['customint6' => 1, 'enrolstartdate' => $now + DAYSECS]);
        });
        $make('manual only', [access::NEXT_NONE, [], null, null, null], function ($c) {
        });

        if (is_callable([enrol_get_plugin('apply'), 'allow_apply'])) {
            $make('apply open', $open(['apply']), function ($c) {
                $this->add_instance($c, 'apply', ['customint6' => 1]);
            });
            $make('apply own row', $blocked(access::BLOCKED_OWN_ROW), function ($c) use ($viewer) {
                $instance = $this->add_instance($c, 'apply', ['customint6' => 1]);
                $this->add_row((int) $instance->id, (int) $viewer->id, ENROL_USER_SUSPENDED);
            });
            $make('apply cohort non-member', $blocked(access::BLOCKED_COHORT), function ($c) use ($foreign) {
                $this->add_instance($c, 'apply', ['customint6' => 1, 'customint5' => $foreign->id]);
            });
            $make('apply cohort unresolved', $blocked(access::BLOCKED_COHORT), function ($c) {
                $this->add_instance($c, 'apply', ['customint6' => 1, 'customint5' => -1]);
            });
            $make('apply full', $blocked(access::BLOCKED_FULL), function ($c) use ($other) {
                $instance = $this->add_instance($c, 'apply', ['customint6' => 1, 'customint3' => 1]);
                $this->add_row((int) $instance->id, (int) $other->id, ENROL_USER_SUSPENDED);
            });
            $make('apply window not open yet', $blocked(access::BLOCKED_WINDOW), function ($c) use ($now) {
                $this->add_instance($c, 'apply', ['customint6' => 1, 'enrolstartdate' => $now + DAYSECS]);
            });
            $make('apply new applications off', $blocked(access::BLOCKED_OFF), function ($c) {
                $this->add_instance($c, 'apply', ['customint6' => 0]);
            });
        }
        if (is_callable([enrol_get_plugin('autoenrol'), 'enrol_allowed'])) {
            $make('autoenrol open', $open(['autoenrol']), function ($c) {
                $this->add_instance($c, 'autoenrol', ['customint1' => 0, 'customint4' => 1, 'customint8' => 0]);
            });
            $make('autoenrol new enrolments off', $blocked(access::BLOCKED_OFF), function ($c) {
                $this->add_instance($c, 'autoenrol', ['customint1' => 0, 'customint4' => 0, 'customint8' => 0]);
            });
            $make('autoenrol beside an enrolment elsewhere', $blocked(access::BLOCKED_OWN_ROW), function ($c) use ($viewer) {
                $this->add_instance($c, 'autoenrol', ['customint1' => 0, 'customint4' => 1, 'customint8' => 0]);
                $this->getDataGenerator()->enrol_user($viewer->id, $c->id, null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
            });
        }
        if (enrol_get_plugin('coursecompleted')) {
            $prerequisite = $generator->create_course();
            $generator->enrol_user($viewer->id, $prerequisite->id);
            $unrelated = $generator->create_course();
            $conditional = [access::NEXT_CONDITIONAL, [], null, (int) $prerequisite->id, null];
            $make('course completed', $conditional, function ($c) use ($prerequisite) {
                $this->add_instance($c, 'coursecompleted', ['customint1' => $prerequisite->id]);
            });
            $make('course completed with no tie', [access::NEXT_NONE, [], null, null, null], function ($c) use ($unrelated) {
                $this->add_instance($c, 'coursecompleted', ['customint1' => $unrelated->id]);
            });
            $make('course completed window closed', $blocked(access::BLOCKED_WINDOW), function ($c) use ($prerequisite, $now) {
                $this->add_instance($c, 'coursecompleted', ['customint1' => $prerequisite->id, 'enrolenddate' => $now - 9]);
            });
            $guestfirst = [access::NEXT_GUEST, [], access::GUEST_FREE, (int) $prerequisite->id, null];
            $make('guest beats course completed', $guestfirst, function ($c) use ($prerequisite) {
                $this->enable_default($c, 'guest');
                $this->add_instance($c, 'coursecompleted', ['customint1' => $prerequisite->id]);
            });
        }
        set_config('cost', 0, 'enrol_fee');
        set_config('cost', 0, 'enrol_paypal');
        accesslib_clear_all_caches_for_unit_testing();
        return $shapes;
    }

    /**
     * An answer in compact form: type, route kinds, guest, the conditional prerequisite, the reason.
     *
     * @param array $answer An answer of get_next_action().
     * @return array The compact form.
     */
    private static function compact(array $answer): array {
        return [
            $answer['type'],
            array_column($answer['routes'], 'kind'),
            $answer['guest'],
            $answer['conditional'] ? $answer['conditional']['prerequisiteid'] : null,
            $answer['blocked'],
        ];
    }

    /**
     * Each shape's next action, and the batch beside it: never closed where the per-course path is open.
     *
     * The per-course answer of every shape is asserted, closed ones included, so neither path can
     * pass by calling everything open. The batch must give the same answer, routes and instance
     * ids included, except where it drops a check it cannot read in SQL - here the self
     * enrolment capability - and there it may only answer open where the per-course path says
     * blocked. can_enrol() is asked through the unlisted predicate on every shape: beside a
     * relationship that keeps the course, it must say yes exactly where the next action is open,
     * guest or conditional.
     *
     * @return void
     */
    public function test_each_shape_and_the_batch_never_closes_what_the_course_opens(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $viewer = $this->getDataGenerator()->create_user();
        $shapes = $this->build_shapes($viewer);
        $ids = array_column($shapes, 'course');

        $this->become($viewer);
        $batch = access::get_next_actions($ids);
        $this->assertSame($ids, array_keys($batch), 'The batch answers every id, in the order given.');
        $enrolable = [access::NEXT_OPEN, access::NEXT_GUEST, access::NEXT_CONDITIONAL];
        $exceptions = 0;
        foreach ($shapes as $label => $shape) {
            $single = access::get_next_action($shape['course']);
            $this->assertSame($shape['expected'], self::compact($single), "{$label}: per course");
            $many = $batch[$shape['course']];
            if ($shape['batch'] === null) {
                $this->assertSame($single, $many, "{$label}: the batch gives the same answer");
            } else {
                $exceptions++;
                $this->assertSame($shape['batch'], self::compact($many), "{$label}: the batch drops a check");
            }
            if (in_array($single['type'], $enrolable, true)) {
                $this->assertContains($many['type'], $enrolable, "{$label}: the batch never closes what the course opens");
            }
            if ($single['type'] === access::NEXT_OPEN) {
                $this->assertSame(access::NEXT_OPEN, $many['type'], "{$label}: an open course is open in the batch");
            }
        }
        $this->assertGreaterThanOrEqual(22, count($shapes), 'Precondition: every core shape was built.');
        $this->assertSame(1, $exceptions, 'Precondition: the dropped check was exercised.');

        foreach ($shapes as $shape) {
            $this->set_course_state($shape['course'], discoverability::STATE_UNLISTED);
        }
        $this->become($viewer);
        $keeping = [
            access::RELATIONSHIP_ENROLLED,
            access::RELATIONSHIP_SCHEDULED,
            access::RELATIONSHIP_PENDING,
            access::RELATIONSHIP_WAITLISTED,
        ];
        foreach ($shapes as $label => $shape) {
            // The viewer is staff of none of them, so the predicate is the relationship or can_enrol().
            $related = in_array(access::get_enrolment_state($shape['course'])['type'], $keeping, true);
            $this->assertSame(
                $related || in_array(access::get_next_action($shape['course'])['type'], $enrolable, true),
                access::is_course_discoverable($shape['course']),
                "{$label}: can_enrol() follows the next action"
            );
        }
    }

    /**
     * The batch costs the same statements for two courses and for forty, and never one per course.
     *
     * Both sets have the same shape - a capped self instance gated on the viewer's cohort, a
     * priced fee instance and guest access - so both pay every conditional statement: the
     * instances, the viewer's rows, the viewer's cohorts and the grouped count. Every statement is
     * array-returning, so the count is the same on PostgreSQL, where a recordset costs three
     * reads, and on MariaDB. Both sets are asked once before either is metered, so neither pays
     * the warm-up of the other.
     *
     * @return void
     */
    public function test_the_batch_costs_the_same_for_two_courses_and_for_forty(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $generator = $this->getDataGenerator();
        $viewer = $generator->create_user();
        $cohort = $generator->create_cohort();
        cohort_add_member($cohort->id, $viewer->id);
        $seed = function (int $count) use ($generator, $cohort): array {
            $ids = [];
            for ($i = 0; $i < $count; $i++) {
                $course = $generator->create_course();
                $this->add_instance($course, 'self', ['customint6' => 1, 'customint3' => 5, 'customint5' => $cohort->id]);
                $this->add_instance($course, 'fee', ['cost' => 10, 'currency' => 'USD']);
                $this->enable_default($course, 'guest');
                $ids[] = (int) $course->id;
            }
            return $ids;
        };
        $small = $seed(2);
        $large = $seed(40);

        $this->become($viewer);
        access::get_next_actions($small);
        access::get_next_actions($large);
        $smallreads = $this->reads(fn() => access::get_next_actions($small));
        $largereads = $this->reads(function () use ($large) {
            $answers = access::get_next_actions($large);
            $this->assertSame(access::NEXT_OPEN, $answers[$large[39]]['type']);
            $this->assertSame(['self', 'fee'], array_column($answers[$large[39]]['routes'], 'kind'));
        });
        $this->assertSame($smallreads, $largereads, "{$smallreads} reads for two courses, {$largereads} for forty.");
        $this->assertSame(4, $largereads, 'The instances, the viewer\'s rows, their cohorts and the grouped count.');
    }

    /**
     * A visitor, the guest account and the site course are offered nothing; nobody is asked about them.
     *
     * The control is a logged-in user, offered the guest access the same course has.
     *
     * @return void
     */
    public function test_a_visitor_the_guest_account_and_the_site_course_are_offered_nothing(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->enable_default($course, 'guest');
        $this->raw_instance(SITEID, 'guest', ENROL_INSTANCE_ENABLED, ['password' => '']);
        $none = ['type' => access::NEXT_NONE, 'routes' => [], 'guest' => null, 'conditional' => null, 'blocked' => null];
        $courseid = (int) $course->id;

        $this->become($generator->create_user());
        $this->assertSame(access::NEXT_GUEST, access::get_next_action($courseid)['type'], 'Control: a user is offered guest.');
        $this->assertSame(access::NEXT_GUEST, access::get_next_actions([$courseid])[$courseid]['type'], 'Control: batch too.');
        $this->assertSame($none, access::get_next_action(SITEID), 'The site course offers nothing.');
        $this->assertSame([SITEID => $none], access::get_next_actions([SITEID]), 'Nor in the batch.');

        foreach (['visitor' => 0, 'guest account' => guest_user()] as $label => $who) {
            $this->become($who);
            $this->assertSame($none, access::get_next_action($courseid), $label);
            $this->assertSame(0, $this->reads(function () use ($courseid, $none, $label) {
                $this->assertSame([$courseid => $none], access::get_next_actions([$courseid]), $label);
            }), "{$label}: the batch asks nothing");
            $this->assertSame(access::RELATIONSHIP_NONE, access::get_enrolment_state($courseid)['type'], $label);
        }
    }

    /**
     * The batch answers an id that is no course, and an id given twice, with none and once.
     *
     * @return void
     */
    public function test_the_batch_answers_an_unknown_id_and_a_repeated_one(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->add_instance($course, 'self', ['customint6' => 1]);
        $this->become($generator->create_user());

        $answers = access::get_next_actions([(int) $course->id, 999999, (int) $course->id]);
        $this->assertSame([(int) $course->id, 999999], array_keys($answers));
        $this->assertSame(access::NEXT_OPEN, $answers[(int) $course->id]['type']);
        $this->assertSame(access::NEXT_NONE, $answers[999999]['type']);
        $this->assertSame(access::NEXT_NONE, access::get_next_action(999999)['type'], 'The per-course path agrees.');
        $this->assertSame([], access::get_next_actions([]));
    }

    /**
     * The answer is memoised per viewer and course, and dropped by a reset.
     *
     * @return void
     */
    public function test_the_next_action_is_memoised_per_viewer_and_dropped_by_a_reset(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $instance = $this->add_instance($course, 'self', ['customint6' => 1]);
        $first = $generator->create_user();
        $second = $generator->create_user();
        $courseid = (int) $course->id;

        $this->become($first);
        $this->assertSame(access::NEXT_OPEN, access::get_next_action($courseid)['type']);
        $this->add_row((int) $instance->id, (int) $second->id, ENROL_USER_SUSPENDED);
        $this->setUser($second);
        $this->assertSame(
            [access::NEXT_BLOCKED, [], null, null, access::BLOCKED_OWN_ROW],
            self::compact(access::get_next_action($courseid)),
            'The second viewer is answered for, never handed the first one\'s answer.'
        );

        $DB->set_field('enrol', 'customint6', 0, ['id' => $instance->id]);
        $this->setUser($first);
        $this->assertSame(access::NEXT_OPEN, access::get_next_action($courseid)['type'], 'Memoised for the request.');
        access::reset_caches();
        $this->assertSame(
            [access::NEXT_BLOCKED, [], null, null, access::BLOCKED_OFF],
            self::compact(access::get_next_action($courseid)),
            'A reset drops the memo.'
        );
    }

    /**
     * Deciding whether an unlisted course is discoverable never pays to explain a refusal.
     *
     * A listing probes unlisted courses nobody is related to, and every one of them is refused by
     * something; the reason is the next action's business, not the listing's. A self instance
     * whose window is shut is refused by enrol_self without a statement, so the course costs the
     * listing exactly what a course with no route at all costs. The next action of the same
     * course does explain it.
     *
     * @return void
     */
    public function test_the_listing_does_not_pay_for_the_reason_of_a_refusal(): void {
        $generator = $this->getDataGenerator();
        $shut = $generator->create_course();
        $this->add_instance($shut, 'self', ['customint6' => 1, 'enrolstartdate' => time() + DAYSECS]);
        $bare = $generator->create_course();
        $warm = $generator->create_course();
        foreach ([$shut, $bare, $warm] as $course) {
            $this->set_course_state((int) $course->id, discoverability::STATE_UNLISTED);
        }

        $this->become($generator->create_user());
        access::is_course_discoverable((int) $warm->id);
        access::reset_caches();
        $shutreads = $this->reads(fn() => $this->assertFalse(access::is_course_discoverable((int) $shut->id)));
        access::reset_caches();
        $barereads = $this->reads(fn() => $this->assertFalse(access::is_course_discoverable((int) $bare->id)));
        $this->assertSame($barereads, $shutreads, 'A refusal must not cost the listing a statement.');

        $this->assertSame(
            [access::NEXT_BLOCKED, [], null, null, access::BLOCKED_WINDOW],
            self::compact(access::get_next_action((int) $shut->id)),
            'Control: the next action explains the refusal.'
        );
    }
}
