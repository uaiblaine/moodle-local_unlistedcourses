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
 * Unlisted courses - Tests for the listing probe primed in bulk
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Tests that a listing's probe costs a fixed number of statements and still gives core's verdicts.
 *
 * Every viewer kind is pinned in both directions over the same eight course shapes: a student
 * keeps the four the enrol methods or their rows admit them to and loses the other four, a
 * manager and a teacher keep what a student loses, a visitor and the guest account keep
 * nothing. The cost is measured against a listing of the same size whose courses are all
 * listed, so the probe's own statements are what the budget counts.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(access::class)]
final class access_probe_test extends \advanced_testcase {
    /** The shapes a student keeps: their rows or an enrol method admit them. */
    private const KEPT = ['enrolled', 'open', 'member', 'room'];

    /** The shapes a student loses: every method refuses them and no row ties them to the course. */
    private const DROPPED = ['outsider', 'full', 'ownrow', 'none'];

    /** @var \stdClass The student every shape is judged for. */
    private \stdClass $student;

    /** @var \stdClass Somebody else, whose rows fill the capped instances. */
    private \stdClass $other;

    /** @var int The cohort the student is a member of. */
    private int $cohortin;

    /** @var int A cohort the student is not a member of. */
    private int $cohortout;

    /**
     * Every test writes to the database, and the plugin's request caches are static.
     *
     * @return void
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        access::reset_caches();

        $generator = $this->getDataGenerator();
        $this->student = $generator->create_user();
        $this->other = $generator->create_user();
        $this->cohortin = (int) $generator->create_cohort()->id;
        $this->cohortout = (int) $generator->create_cohort()->id;
        cohort_add_member($this->cohortin, (int) $this->student->id);
        $this->assertTrue($DB->record_exists('cohort_members', ['cohortid' => $this->cohortin, 'userid' => $this->student->id]));
    }

    /**
     * Log in as somebody and forget every answer memoised for the previous viewer, as a new request would.
     *
     * The context cache is dropped too, so the probe pays for the contexts as a fresh request does.
     * The viewer's access data is loaded, as require_login() has loaded it before any listing of
     * a real request: the first capability check of a request pays that one statement, which is
     * not the probe's.
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
     * Add an enabled self enrolment instance, open to new enrolments, with extra fields.
     *
     * @param \stdClass $course The course.
     * @param array $fields Extra instance fields.
     * @return \stdClass The instance.
     */
    private function add_self(\stdClass $course, array $fields = []): \stdClass {
        global $DB;

        $id = enrol_get_plugin('self')->add_instance($course, $fields + [
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
     * Create one course of a shape in a category, in a discoverability state.
     *
     * A generated course carries an enabled manual instance and disabled guest and self ones, so
     * every shape has instances the probe must read past.
     *
     * @param string $shape One of KEPT or DROPPED.
     * @param int $categoryid The category.
     * @param int $state A discoverability::STATE_* constant.
     * @return \stdClass The course.
     */
    private function create_shape(string $shape, int $categoryid, int $state): \stdClass {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['category' => $categoryid]);
        switch ($shape) {
            case 'enrolled':
                $generator->enrol_user($this->student->id, $course->id);
                break;
            case 'open':
                $this->add_self($course);
                break;
            case 'member':
                $this->add_self($course, ['customint5' => $this->cohortin]);
                break;
            case 'outsider':
                $this->add_self($course, ['customint5' => $this->cohortout]);
                break;
            case 'full':
                $full = $this->add_self($course, ['customint3' => 1]);
                $this->add_row((int) $full->id, (int) $this->other->id, ENROL_USER_ACTIVE);
                break;
            case 'room':
                $room = $this->add_self($course, ['customint3' => 5]);
                $this->add_row((int) $room->id, (int) $this->other->id, ENROL_USER_ACTIVE);
                break;
            case 'ownrow':
                $own = $this->add_self($course);
                $this->add_row((int) $own->id, (int) $this->student->id, ENROL_USER_SUSPENDED);
                break;
        }
        if ($state !== discoverability::STATE_DEFAULT) {
            $current = $GLOBALS['USER'];
            $this->setAdminUser();
            discoverability::set_state((int) $course->id, $state);
            $this->setUser($current);
        }
        return $course;
    }

    /**
     * A listing of the given shapes, repeated, as the items a course renderer hands over.
     *
     * @param array $shapes Shape names.
     * @param int $copies How many courses of each shape.
     * @param int $state The discoverability state of every course.
     * @param int|null $categoryid The category, a new one when null.
     * @return array [items keyed by course id, map of courseid => shape]
     */
    private function listing(array $shapes, int $copies, int $state, ?int $categoryid = null): array {
        $categoryid = $categoryid ?? (int) $this->getDataGenerator()->create_category()->id;
        $items = [];
        $shapeof = [];
        for ($i = 0; $i < $copies; $i++) {
            foreach ($shapes as $shape) {
                $course = $this->create_shape($shape, $categoryid, $state);
                $items[(int) $course->id] = (object) ['id' => (int) $course->id, 'category' => $categoryid];
                $shapeof[(int) $course->id] = $shape;
            }
        }
        return [$items, $shapeof];
    }

    /**
     * The shapes of the courses a filter kept, sorted, one entry per shape.
     *
     * @param array $kept What filter_courses() returned.
     * @param array $shapeof Map of courseid => shape.
     * @return array The distinct shapes kept.
     */
    private static function shapes_kept(array $kept, array $shapeof): array {
        $shapes = array_values(array_unique(array_map(fn(\stdClass $item): string => $shapeof[$item->id], $kept)));
        sort($shapes);
        return $shapes;
    }

    /**
     * Run one listing through the filter as a fresh request would: memos and contexts dropped first.
     *
     * @param \stdClass|int $viewer The viewer.
     * @param array $items The items.
     * @return array [kept items, statements]
     */
    private function filter_as($viewer, array $items): array {
        $this->become($viewer);
        $kept = [];
        $reads = $this->reads(function () use ($items, &$kept): void {
            $kept = access::filter_courses($items);
        });
        return [$kept, $reads];
    }

    /**
     * A student's listing costs the same for two unlisted courses and for two hundred, and four statements more than a listed one.
     *
     * The four are the probe's own: the contexts with the instances, the viewer's rows, the
     * viewer's cohorts and the count on the capped instances. The two-course listing holds a
     * cohort-gated and a capped instance, so it pays for all four as well; the large one holds
     * every shape twenty-five times. Both keep exactly the shapes core admits the student to, so
     * the budget cannot be met by a probe that stopped asking.
     *
     * @return void
     */
    public function test_a_students_listing_reads_the_same_for_two_unlisted_courses_and_for_two_hundred(): void {
        $shapes = array_merge(self::KEPT, self::DROPPED);
        [$warm] = $this->listing($shapes, 1, discoverability::STATE_UNLISTED);
        [$small, $smallshapes] = $this->listing(['member', 'full'], 1, discoverability::STATE_UNLISTED);
        [$large, $largeshapes] = $this->listing($shapes, 25, discoverability::STATE_UNLISTED);
        [$listed] = $this->listing(['none', 'open'], 1, discoverability::STATE_DEFAULT);
        $this->assertCount(200, $large);

        // Warm what core loads once per request (access data, role definitions, plugin objects).
        $this->filter_as($this->student, $warm);

        [$keptsmall, $smallreads] = $this->filter_as($this->student, $small);
        [$keptlarge, $largereads] = $this->filter_as($this->student, $large);
        [$keptlisted, $listedreads] = $this->filter_as($this->student, $listed);

        $this->assertSame(['member'], self::shapes_kept($keptsmall, $smallshapes));
        $this->assertSame(['enrolled', 'member', 'open', 'room'], self::shapes_kept($keptlarge, $largeshapes));
        $this->assertCount(100, $keptlarge, 'Every copy of a kept shape is kept, every copy of a dropped one is not.');
        $this->assertCount(2, $keptlisted, 'Control: listed courses are all kept.');
        $this->assertSame($smallreads, $largereads, 'The probe must cost the same for 200 unlisted courses as for 2.');
        $this->assertSame($listedreads + 4, $largereads, 'The probe costs its four bulk statements and nothing per course.');

        // The course rule on its own, as is_course_discoverable() and the theme's page gates reach it.
        $ask = function (array $items): array {
            $this->become($this->student);
            $answers = [];
            $reads = $this->reads(function () use ($items, &$answers): void {
                $answers = access::are_courses_discoverable(array_keys($items));
            });
            return [count(array_filter($answers)), $reads];
        };
        [$smallkept, $smallasked] = $ask($small);
        [$largekept, $largeasked] = $ask($large);
        $this->assertSame([1, 100], [$smallkept, $largekept]);
        $this->assertSame($smallasked, $largeasked, 'are_courses_discoverable() primes its unlisted courses as well.');
    }

    /**
     * The self enrolment facts belong to the viewer they were read for.
     *
     * The student holds a row on the instance and is refused; somebody else, asked in the same
     * request with nothing reset, holds none and is admitted; the student asked again is still
     * refused, from their own facts. Before that last question only the answer memos are dropped,
     * so the verdict is worked out again from the primed facts and not read back.
     *
     * @return void
     */
    public function test_the_self_enrolment_facts_are_keyed_by_the_viewer(): void {
        [$items] = $this->listing(['ownrow'], 1, discoverability::STATE_UNLISTED);

        $this->become($this->student);
        $this->assertSame([], access::filter_courses($items), 'The student\'s own row refuses them.');
        $this->setUser($this->other);
        $this->assertCount(1, access::filter_courses($items), 'Control: somebody without a row is admitted.');
        $this->setUser($this->student);
        foreach (['discoverable', 'related', 'nextaction'] as $memo) {
            (new \ReflectionProperty(access::class, $memo))->setValue(null, []);
        }
        $this->assertSame([], access::filter_courses($items), 'The student is still refused, by their own facts.');
    }

    /**
     * Courses a withheld category puts in front of the probe are read for in the same statements.
     *
     * The category term asks the relationship of every course inside an unlisted category,
     * listed courses included; those join the unlisted ones in the one prime, so a listing that
     * mixes both costs no more than either.
     *
     * @return void
     */
    public function test_courses_of_a_withheld_category_join_the_same_bulk_read(): void {
        $generator = $this->getDataGenerator();
        $shapes = array_merge(self::KEPT, self::DROPPED);
        [$warm] = $this->listing($shapes, 1, discoverability::STATE_UNLISTED);
        $hidden = (int) $generator->create_category()->id;
        $this->setAdminUser();
        category_discoverability::set_state($hidden, category_discoverability::STATE_UNLISTED);
        [$withheld, $withheldshapes] = $this->listing($shapes, 5, discoverability::STATE_DEFAULT, $hidden);
        [$unlisted] = $this->listing($shapes, 5, discoverability::STATE_UNLISTED);
        [$withheldsmall] = $this->listing(['member', 'full'], 1, discoverability::STATE_DEFAULT, $hidden);
        [$unlistedsmall] = $this->listing(['member', 'full'], 1, discoverability::STATE_UNLISTED);

        $this->filter_as($this->student, $warm);
        [$kept, $mixedreads] = $this->filter_as($this->student, $withheld + $unlisted);
        [, $smallreads] = $this->filter_as($this->student, $withheldsmall + $unlistedsmall);

        $inside = array_intersect_key($kept, $withheld);
        $this->assertSame(
            ['enrolled'],
            self::shapes_kept($inside, $withheldshapes),
            'Inside an unlisted category only a relationship keeps a course; an open method does not.'
        );
        $this->assertCount(20, array_intersect_key($kept, $unlisted), 'Control: the unlisted half keeps its four shapes.');
        $this->assertSame($smallreads, $mixedreads, 'A withheld category adds nothing per course to the probe.');
    }

    /**
     * A manager keeps every shape, for one statement however many courses: staff is asked before any row.
     *
     * The control is the student, who loses four shapes of the same listing and pays the three
     * statements a manager never reaches.
     *
     * @return void
     */
    public function test_a_manager_keeps_every_course_for_one_statement(): void {
        $generator = $this->getDataGenerator();
        $shapes = array_merge(self::KEPT, self::DROPPED);
        $category = (int) $generator->create_category()->id;
        [$warm] = $this->listing($shapes, 1, discoverability::STATE_UNLISTED, $category);
        [$small] = $this->listing(['member', 'full'], 1, discoverability::STATE_UNLISTED, $category);
        [$large, $shapeof] = $this->listing($shapes, 25, discoverability::STATE_UNLISTED, $category);
        [$listed] = $this->listing(['none', 'open'], 1, discoverability::STATE_DEFAULT, $category);
        $manager = $generator->create_user();
        $generator->role_assign('manager', (int) $manager->id, \core\context\coursecat::instance($category)->id);

        $this->filter_as($manager, $warm);
        [, $smallreads] = $this->filter_as($manager, $small);
        [$kept, $largereads] = $this->filter_as($manager, $large);
        [, $listedreads] = $this->filter_as($manager, $listed);
        $this->filter_as($this->student, $warm);
        [$studentkept, $studentreads] = $this->filter_as($this->student, $large);
        [, $studentlistedreads] = $this->filter_as($this->student, $listed);

        $this->assertCount(200, $kept, 'A manager keeps every unlisted course.');
        $this->assertCount(100, $studentkept, 'Control: the student loses the four refused shapes.');
        $this->assertSame($smallreads, $largereads, 'The manager\'s probe costs the same for 200 courses as for 2.');
        $this->assertSame($listedreads + 1, $largereads, 'One statement: the contexts with the instances, then staff answers.');
        $this->assertSame($studentlistedreads + 4, $studentreads, 'Control: the student pays for the rows, cohorts and counts.');
    }

    /**
     * A teacher of one course keeps it without an enrolment; the same teacher does not keep the course next to it.
     *
     * @return void
     */
    public function test_a_teacher_keeps_their_own_unlisted_course_only(): void {
        $generator = $this->getDataGenerator();
        [$items, $shapeof] = $this->listing(['none', 'outsider'], 1, discoverability::STATE_UNLISTED);
        $teacher = $generator->create_user();
        $ownid = (int) array_search('none', $shapeof, true);
        $generator->role_assign('teacher', (int) $teacher->id, \core\context\course::instance($ownid)->id);

        [$kept] = $this->filter_as($teacher, $items);

        $this->assertSame([$ownid], array_keys($kept), 'The teacher keeps their course and only it.');
    }

    /**
     * A visitor and the guest account keep no unlisted course, open ones included, and the probe reads nothing for them.
     *
     * @return void
     */
    public function test_a_visitor_and_the_guest_keep_nothing_and_cost_nothing(): void {
        $shapes = array_merge(self::KEPT, self::DROPPED);
        [$items] = $this->listing($shapes, 2, discoverability::STATE_UNLISTED);
        [$listed] = $this->listing($shapes, 2, discoverability::STATE_DEFAULT);

        foreach (['visitor' => 0, 'guest' => guest_user()] as $label => $viewer) {
            $this->filter_as($viewer, $listed);
            [$kept, $reads] = $this->filter_as($viewer, $items);
            [, $listedreads] = $this->filter_as($viewer, $listed);
            $this->assertSame([], $kept, "The {$label} keeps no unlisted course.");
            $this->assertSame($listedreads, $reads, "The probe reads nothing for the {$label}.");
        }

        [$kept] = $this->filter_as($this->student, $items);
        $this->assertCount(8, $kept, 'Control: the student keeps the open shapes of the same listing.');
    }

    /**
     * Every self enrolment shape the prime reads facts for gets the answer enrol_self itself gives.
     *
     * Each course holds one self instance and nothing that ties the student to it, so the
     * course is kept exactly when can_self_enrol($instance, false) is true. The shapes walk every
     * check of that method: the window both ways, new enrolments off, the viewer's own row, the
     * places limit both ways, the cohort both ways and deleted, and the capability prohibited.
     *
     * @return void
     */
    public function test_the_self_enrolment_mirror_agrees_with_enrol_self_on_every_shape(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $category = (int) $generator->create_category()->id;
        $now = time();
        $gone = (int) $generator->create_cohort()->id;
        $shapes = [
            'open' => [],
            'notyetopen' => ['enrolstartdate' => $now + DAYSECS],
            'opened' => ['enrolstartdate' => $now - DAYSECS],
            'closed' => ['enrolenddate' => $now - DAYSECS],
            'closing' => ['enrolenddate' => $now + DAYSECS],
            'nonew' => ['customint6' => 0],
            'ownrow' => [],
            'full' => ['customint3' => 1],
            'room' => ['customint3' => 2],
            'member' => ['customint5' => $this->cohortin],
            'outsider' => ['customint5' => $this->cohortout],
            'deletedcohort' => ['customint5' => $gone],
            'prohibited' => [],
        ];
        $items = [];
        $instances = [];
        foreach ($shapes as $name => $fields) {
            $course = $generator->create_course(['category' => $category]);
            $instances[$name] = $this->add_self($course, $fields);
            $items[(int) $course->id] = (object) ['id' => (int) $course->id, 'category' => $category];
            $this->setAdminUser();
            discoverability::set_state((int) $course->id, discoverability::STATE_UNLISTED);
        }
        $this->add_row((int) $instances['ownrow']->id, (int) $this->student->id, ENROL_USER_SUSPENDED);
        $this->add_row((int) $instances['full']->id, (int) $this->other->id, ENROL_USER_ACTIVE);
        $this->add_row((int) $instances['room']->id, (int) $this->other->id, ENROL_USER_ACTIVE);
        $DB->delete_records('cohort', ['id' => $gone]);
        $userrole = (int) $DB->get_field('role', 'id', ['shortname' => 'user']);
        assign_capability(
            'enrol/self:enrolself',
            CAP_PROHIBIT,
            $userrole,
            \core\context\course::instance((int) $instances['prohibited']->courseid)->id
        );

        $this->become($this->student);
        $plugin = enrol_get_plugin('self');
        $expected = [];
        foreach ($instances as $name => $instance) {
            $expected[$name] = $plugin->can_self_enrol($instance, false) === true;
        }
        $this->assertContains(true, $expected, 'Precondition: some shape is admitted.');
        $this->assertContains(false, $expected, 'Precondition: some shape is refused.');

        [$kept] = $this->filter_as($this->student, $items);
        foreach ($instances as $name => $instance) {
            $this->assertSame(
                $expected[$name],
                isset($kept[(int) $instance->courseid]),
                "The probe and can_self_enrol() must agree on the {$name} shape."
            );
        }
        $this->assertNotEmpty(
            (new \ReflectionProperty(access::class, 'selffacts'))->getValue(),
            'Control: the answers came from the primed facts, not from the plugin.'
        );
    }

    /**
     * The mirror refuses a disabled instance, which the prime never hands it, as can_self_enrol() does.
     *
     * @return void
     */
    public function test_the_self_enrolment_mirror_refuses_a_disabled_instance(): void {
        $course = $this->getDataGenerator()->create_course();
        $instance = $this->add_self($course);
        $mirror = new \ReflectionMethod(access::class, 'self_enrol_admits');
        $facts = ['holdsrow' => false, 'taken' => 0, 'incohort' => false];
        $this->setUser($this->student);

        $this->assertTrue($mirror->invoke(null, $instance, $facts), 'Control: the enabled instance admits.');
        $instance->status = ENROL_INSTANCE_DISABLED;
        $this->assertFalse($mirror->invoke(null, $instance, $facts));
        $this->assertNotTrue(enrol_get_plugin('self')->can_self_enrol($instance, false), 'And so does enrol_self.');
    }

    /**
     * The two core methods the mirror copies are the ones it was copied from.
     *
     * A drift alarm, not a behaviour test: when core changes either method on this branch, this
     * reddens, and {@see access::self_enrol_admits()} is brought back in step with the new code
     * (and the fingerprints updated) before anything else, because a check core adds that the
     * copy lacks would name an unlisted course to somebody enrol_self refuses.
     *
     * @return void
     */
    public function test_the_copied_self_enrolment_checks_have_not_changed_in_core(): void {
        global $CFG;

        require_once($CFG->dirroot . '/enrol/self/lib.php');
        $fingerprint = function (string $method): string {
            $reflection = new \ReflectionMethod(\enrol_self_plugin::class, $method);
            $start = $reflection->getStartLine();
            $body = array_slice(file($reflection->getFileName()), $start - 1, $reflection->getEndLine() - $start + 1);
            // Whitespace runs collapse, so a reindented core file is not a change of behaviour.
            return sha1(preg_replace('/\s+/', ' ', implode('', $body)));
        };

        $expected = [
            'can_self_enrol' => 'c595839397fd7869371a22599fbfcf1d3697a3b6',
            'is_self_enrol_available' => 'ae32e50c76057321b0fd2a1722048e8d211fa2d3',
        ];
        $this->assertSame(
            $expected,
            array_combine(array_keys($expected), array_map($fingerprint, array_keys($expected))),
            'enrol_self changed: bring access::self_enrol_admits() in step with it, then update these fingerprints.'
        );
    }

    /**
     * The primed instances are what enrol_get_instances($courseid, true) returns, in its order and with its filter.
     *
     * The course holds an instance of every kind the filter drops - disabled, of a plugin the
     * site has disabled, of a plugin listed as enabled whose code is absent - between enabled
     * ones whose sort order runs against their ids; a second course holds none at all.
     *
     * @return void
     */
    public function test_the_primed_instances_are_enrol_get_instances(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $empty = $generator->create_course();
        $DB->delete_records('enrol', ['courseid' => $empty->id]);
        $guest = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'guest'], '*', MUST_EXIST);
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['id' => $guest->id]);
        $first = $this->add_self($course);
        $second = $this->add_self($course, ['name' => 'Second']);
        $DB->set_field('enrol', 'sortorder', 9, ['id' => $first->id]);
        $DB->set_field('enrol', 'sortorder', 8, ['id' => $second->id]);
        $this->add_self($course, ['status' => ENROL_INSTANCE_DISABLED]);
        $now = time();
        foreach (['cohort', 'nosuchplugin'] as $name) {
            $DB->insert_record('enrol', (object) [
                'enrol' => $name,
                'courseid' => $course->id,
                'status' => ENROL_INSTANCE_ENABLED,
                'sortorder' => 3,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
        $enabled = array_diff(explode(',', get_config('core', 'enrol_plugins_enabled')), ['cohort']);
        set_config('enrol_plugins_enabled', implode(',', array_merge($enabled, ['nosuchplugin'])));

        $this->become($this->student);
        $prime = new \ReflectionMethod(access::class, 'prime_probe');
        $prime->invoke(null, [(int) $course->id, (int) $empty->id]);
        $primed = (new \ReflectionProperty(access::class, 'instances'))->getValue();

        foreach ([$course, $empty] as $which) {
            $expected = enrol_get_instances((int) $which->id, true);
            $this->assertSame(array_keys($expected), array_keys($primed[(int) $which->id]), 'Same instances, same order.');
            foreach ($expected as $id => $record) {
                $this->assertEquals((array) $record, (array) $primed[(int) $which->id][$id], "Instance {$id} is the same record.");
            }
        }
        $this->assertSame(
            [(int) $guest->id, (int) $second->id, (int) $first->id],
            array_values(array_intersect(array_keys($primed[(int) $course->id]), [$guest->id, $first->id, $second->id])),
            'Control: the sort order, not the id, decides the order.'
        );
    }

    /**
     * The primed enrolment state is the one the per-course read gives, for every mix of rows.
     *
     * @return void
     */
    public function test_the_primed_enrolment_state_is_the_per_course_one(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $now = time();
        $mixes = [
            'enrolled over expired' => [[ENROL_USER_ACTIVE, 0, $now - DAYSECS], [ENROL_USER_ACTIVE, 0, 0]],
            'earlier of two scheduled' => [[ENROL_USER_ACTIVE, $now + 3 * DAYSECS, 0], [ENROL_USER_ACTIVE, $now + DAYSECS, 0]],
            'later of two expired' => [[ENROL_USER_ACTIVE, 0, $now - 3 * DAYSECS], [ENROL_USER_ACTIVE, 0, $now - DAYSECS]],
            'suspended' => [[ENROL_USER_SUSPENDED, 0, 0]],
            'none' => [],
        ];
        $courses = [];
        foreach ($mixes as $name => $rows) {
            $course = $generator->create_course();
            $courses[$name] = (int) $course->id;
            foreach ($rows as $i => [$status, $start, $end]) {
                $instance = $i === 0
                    ? $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST)
                    : $this->add_self($course);
                $this->add_row((int) $instance->id, (int) $this->student->id, $status, $start, $end);
            }
        }

        $this->become($this->student);
        $expected = array_map(fn(int $courseid): array => access::get_enrolment_state($courseid), $courses);

        $this->become($this->student);
        (new \ReflectionMethod(access::class, 'prime_probe'))->invoke(null, array_values($courses));
        $reads = $this->reads(function () use ($courses, &$primed): void {
            $primed = array_map(fn(int $courseid): array => access::get_enrolment_state($courseid), $courses);
        });

        $this->assertSame($expected, $primed);
        $this->assertSame(0, $reads, 'Control: the primed answers were read from the memo.');
        $this->assertSame(access::RELATIONSHIP_SCHEDULED, $primed['earlier of two scheduled']['type']);
        $this->assertSame($now + DAYSECS, $primed['earlier of two scheduled']['startsat']);
    }

    /**
     * Facts are read only for self enrolment instances, and only in courses the probe still asks about.
     *
     * A cohort-gated self instance in a course the student is enrolled in, and a capped instance
     * of another plugin in a course with no tie, need neither the cohort statement nor the count:
     * the enrolment keeps the first course before any method is asked, and only enrol_self's
     * checks are answered from facts.
     *
     * @return void
     */
    public function test_facts_are_read_only_for_self_instances_the_probe_will_ask(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $category = (int) $generator->create_category()->id;
        [$warm] = $this->listing(array_merge(self::KEPT, self::DROPPED), 1, discoverability::STATE_UNLISTED, $category);
        $enrolled = $this->create_shape('enrolled', $category, discoverability::STATE_UNLISTED);
        $this->add_self($enrolled, ['customint5' => $this->cohortout, 'customint3' => 1]);
        $manualonly = $this->create_shape('none', $category, discoverability::STATE_UNLISTED);
        $DB->set_field('enrol', 'customint3', 5, ['courseid' => $manualonly->id, 'enrol' => 'manual']);
        $DB->set_field('enrol', 'customint5', $this->cohortout, ['courseid' => $manualonly->id, 'enrol' => 'manual']);
        $items = [];
        foreach ([$enrolled, $manualonly] as $course) {
            $items[(int) $course->id] = (object) ['id' => (int) $course->id, 'category' => $category];
        }
        [$listed] = $this->listing(['none', 'none'], 1, discoverability::STATE_DEFAULT, $category);

        $this->filter_as($this->student, $warm);
        [$kept, $reads] = $this->filter_as($this->student, $items);
        [, $listedreads] = $this->filter_as($this->student, $listed);

        $this->assertSame([(int) $enrolled->id], array_keys($kept), 'The enrolled course is kept, the other is not.');
        $this->assertSame($listedreads + 2, $reads, 'The contexts with the instances, and the rows: no cohort, no count.');
    }
}
