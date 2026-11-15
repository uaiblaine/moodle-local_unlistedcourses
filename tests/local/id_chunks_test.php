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

namespace local_unlistedcourses\local;

use local_unlistedcourses\access;
use local_unlistedcourses\category_access;
use local_unlistedcourses\category_discoverability;
use local_unlistedcourses\discoverability;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests that an id list longer than a driver takes is read in chunks, and read whole.
 *
 * PostgreSQL refuses a statement with more than 65,535 bind parameters; the lists here are
 * 70,000 ids that name nothing plus a few that name real rows, placed first and last, so they
 * fall in the first and the last chunk. On PostgreSQL an unchunked read throws; on any database
 * a merge that dropped a chunk loses one of the real rows.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(id_chunks::class)]
#[CoversClass(discoverability::class)]
#[CoversClass(category_discoverability::class)]
#[CoversClass(access::class)]
#[CoversClass(category_access::class)]
final class id_chunks_test extends \advanced_testcase {
    /** @var int How many ids that name nothing a long list carries. */
    private const FILLER = 70000;

    /**
     * Reset the request caches between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        access::reset_caches();
    }

    /**
     * A long list: a real id first, ids that name nothing, a real id last.
     *
     * @param int $first The real id that opens the list.
     * @param int $last The real id that closes it.
     * @return array The ids.
     */
    private static function long_list(int $first, int $last): array {
        $filler = range(900000000, 900000000 + self::FILLER - 1);
        return array_merge([$first], $filler, [$last]);
    }

    /**
     * Up to the chunk size the read runs once on the list as given; past it, once per chunk, merged by key.
     *
     * @return void
     */
    public function test_a_list_is_read_whole_up_to_the_size_and_in_chunks_past_it(): void {
        $calls = [];
        $read = function (array $ids) use (&$calls): array {
            $calls[] = $ids;
            return array_fill_keys($ids, true);
        };

        $page = [7, 3, 7, 11];
        $this->assertSame([7 => true, 3 => true, 11 => true], id_chunks::read($page, $read));
        $this->assertSame([$page], $calls, 'A short list is one call with the list unchanged.');

        $calls = [];
        $exact = range(1, id_chunks::SIZE);
        $this->assertCount(id_chunks::SIZE, id_chunks::read($exact, $read));
        $this->assertCount(1, $calls, 'A list of exactly the size is still one call.');

        $calls = [];
        $long = range(1, 2 * id_chunks::SIZE + 5);
        $merged = id_chunks::read($long, $read);
        $this->assertSame([id_chunks::SIZE, id_chunks::SIZE, 5], array_map('count', $calls));
        $this->assertSame($long, array_keys($merged), 'Every chunk is merged, first chunk first.');
        $this->assertSame([1, id_chunks::SIZE + 1, 2 * id_chunks::SIZE + 1], array_column($calls, 0));
    }

    /**
     * The states and public answers of a long list are read, rows in the first and last chunk included.
     *
     * @return void
     */
    public function test_states_and_public_answers_of_a_list_past_the_parameter_limit(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $unlisted = $generator->create_course(['category' => $category->id]);
        $first = $generator->create_course(['category' => $category->id]);
        $last = $generator->create_course(['category' => $category->id]);
        $unlistedcat = $generator->create_category();
        $firstcat = $generator->create_category();
        $lastcat = $generator->create_category();
        $this->setAdminUser();
        discoverability::set_state((int) $unlisted->id, discoverability::STATE_UNLISTED);
        discoverability::set_state((int) $first->id, discoverability::STATE_PUBLIC);
        discoverability::set_state((int) $last->id, discoverability::STATE_PUBLIC);
        category_discoverability::set_state((int) $unlistedcat->id, category_discoverability::STATE_UNLISTED);
        category_discoverability::set_state((int) $firstcat->id, category_discoverability::STATE_PUBLIC);
        category_discoverability::set_state((int) $lastcat->id, category_discoverability::STATE_PUBLIC);
        access::reset_caches();

        $states = discoverability::get_states(self::long_list((int) $unlisted->id, (int) $last->id));
        $this->assertCount(self::FILLER + 2, $states);
        $this->assertSame(discoverability::STATE_UNLISTED, $states[(int) $unlisted->id]);
        $this->assertSame(discoverability::STATE_PUBLIC, $states[(int) $last->id]);
        $this->assertSame(discoverability::STATE_DEFAULT, $states[900000000]);

        $states = category_discoverability::get_states(self::long_list((int) $unlistedcat->id, (int) $lastcat->id));
        $this->assertCount(self::FILLER + 2, $states);
        $this->assertSame(category_discoverability::STATE_UNLISTED, $states[(int) $unlistedcat->id]);
        $this->assertSame(category_discoverability::STATE_PUBLIC, $states[(int) $lastcat->id]);

        access::reset_caches();
        $public = array_keys(array_filter(discoverability::are_public(self::long_list((int) $first->id, (int) $last->id))));
        $this->assertSame([(int) $first->id, (int) $last->id], $public);

        $public = category_discoverability::are_public(self::long_list((int) $firstcat->id, (int) $lastcat->id));
        $this->assertSame([(int) $firstcat->id, (int) $lastcat->id], array_keys(array_filter($public)));

        // The anonymous listing filter, handed one item per id.
        access::reset_caches();
        $items = array_map(fn(int $id): \stdClass => (object) ['id' => $id], self::long_list((int) $first->id, (int) $last->id));
        $kept = access::filter_courses_public($items);
        $this->assertSame([0, self::FILLER + 1], array_keys($kept));
    }

    /**
     * The per-viewer listing filters and the batched next action read a long list, first and last chunk included.
     *
     * @return void
     */
    public function test_listing_filters_and_next_actions_of_a_list_past_the_parameter_limit(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $hiddenfirst = $generator->create_course(['category' => $category->id]);
        $hiddenlast = $generator->create_course(['category' => $category->id]);
        $selffirst = $generator->create_course(['category' => $category->id]);
        $selflast = $generator->create_course(['category' => $category->id]);
        $listed = $generator->create_category();
        $unlistedcat = $generator->create_category();
        $this->setAdminUser();
        discoverability::set_state((int) $hiddenfirst->id, discoverability::STATE_UNLISTED);
        discoverability::set_state((int) $hiddenlast->id, discoverability::STATE_UNLISTED);
        $self = enrol_get_plugin('self');
        foreach ([$selffirst, $selflast] as $course) {
            $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'self'], '*', MUST_EXIST);
            $self->update_status($instance, ENROL_INSTANCE_ENABLED);
        }
        $this->setUser($generator->create_user());
        access::reset_caches();

        // Two unlisted courses the viewer has no way into, first and last: only they are withheld.
        $items = array_map(
            fn(int $id): \stdClass => (object) ['id' => $id],
            self::long_list((int) $hiddenfirst->id, (int) $hiddenlast->id)
        );
        $kept = access::filter_courses($items);
        $this->assertCount(self::FILLER, $kept);
        $this->assertArrayNotHasKey(0, $kept);
        $this->assertArrayNotHasKey(self::FILLER + 1, $kept);

        $actions = access::get_next_actions(self::long_list((int) $selffirst->id, (int) $selflast->id));
        $this->assertCount(self::FILLER + 2, $actions);
        $this->assertSame(access::NEXT_OPEN, $actions[(int) $selffirst->id]['type']);
        $this->assertSame(access::NEXT_OPEN, $actions[(int) $selflast->id]['type']);
        $this->assertSame(access::NEXT_NONE, $actions[900000000]['type']);

        // A listed category first and an unlisted one last; ids that name nothing are not discoverable.
        $this->setAdminUser();
        category_discoverability::set_state((int) $unlistedcat->id, category_discoverability::STATE_UNLISTED);
        $this->setUser($generator->create_user());
        access::reset_caches();
        $kept = category_access::filter_categories(self::long_list((int) $listed->id, (int) $unlistedcat->id));
        $this->assertSame([0 => (int) $listed->id], $kept);
    }

    /**
     * A cohort opens its category when the site holds more unlisted categories than one statement takes.
     *
     * The cohort lookup is handed the id of every unlisted category. The control is a second
     * unlisted category the viewer holds nothing at, which stays withheld.
     *
     * @return void
     */
    public function test_a_cohort_opens_its_category_among_more_unlisted_categories_than_a_statement_takes(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $opened = $generator->create_category();
        $withheld = $generator->create_category();
        $this->setAdminUser();
        category_discoverability::set_state((int) $opened->id, category_discoverability::STATE_UNLISTED);
        category_discoverability::set_state((int) $withheld->id, category_discoverability::STATE_UNLISTED);

        $rows = [];
        foreach (range(900000000, 900000000 + self::FILLER - 1) as $categoryid) {
            $rows[] = (object) [
                'categoryid' => $categoryid,
                'state' => category_discoverability::STATE_UNLISTED,
                'usermodified' => 0,
                'timemodified' => 0,
            ];
        }
        $DB->insert_records(category_discoverability::TABLE, $rows);

        $user = $generator->create_user();
        $cohort = $generator->create_cohort(['contextid' => \core\context\coursecat::instance($opened->id)->id]);
        cohort_add_member($cohort->id, $user->id);

        $this->setUser($user);
        category_discoverability::reset_caches();
        category_access::reset_caches();
        access::reset_caches();
        $this->assertSame(
            [(int) $opened->id => true, (int) $withheld->id => false],
            category_access::are_categories_discoverable([(int) $opened->id, (int) $withheld->id])
        );
    }
}
