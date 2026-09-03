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
 * Course discoverability - Tests for the retired custom field migration
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\local;

use core_customfield\category_controller;
use core_customfield\field_controller;
use local_unlistedcourses\access;
use local_unlistedcourses\discoverability;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for reading the retired "unlisted" checkbox into the state table.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(legacy_field::class)]
final class legacy_field_test extends \advanced_testcase {
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
     * A course custom field category.
     *
     * @param string $name The category name.
     * @return category_controller The category.
     */
    private function create_category(string $name): category_controller {
        return $this->getDataGenerator()->get_plugin_generator('core_customfield')->create_category([
            'component' => 'core_course',
            'area' => 'course',
            'name' => $name,
        ]);
    }

    /**
     * A field inside a category.
     *
     * @param category_controller $category The category.
     * @param string $shortname The shortname.
     * @param string $type The field type.
     * @return field_controller The field.
     */
    private function create_field(category_controller $category, string $shortname, string $type): field_controller {
        return $this->getDataGenerator()->get_plugin_generator('core_customfield')->create_field([
            'categoryid' => $category->get('id'),
            'shortname' => $shortname,
            'name' => $shortname,
            'type' => $type,
        ]);
    }

    /**
     * Only ticked rows of the unlisted field are copied, and never twice.
     *
     * @return void
     */
    public function test_migrate_copies_only_the_ticked_rows_of_the_unlisted_field(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $cfgenerator = $generator->get_plugin_generator('core_customfield');
        $category = $this->create_category('Course visibility');
        $unlisted = $this->create_field($category, legacy_field::SHORTNAME, 'checkbox');
        $other = $this->create_field($this->create_category('Hotsite'), 'hotsite_modelo', 'checkbox');

        $ticked = $generator->create_course();
        $unticked = $generator->create_course();
        $otheronly = $generator->create_course();
        $alreadypublic = $generator->create_course();
        $cfgenerator->add_instance_data($unlisted, (int) $ticked->id, 1);
        $cfgenerator->add_instance_data($unlisted, (int) $unticked->id, 0);
        $cfgenerator->add_instance_data($other, (int) $otheronly->id, 1);
        $cfgenerator->add_instance_data($unlisted, (int) $alreadypublic->id, 1);
        $this->setAdminUser();
        discoverability::set_state((int) $alreadypublic->id, discoverability::STATE_PUBLIC);

        $this->assertSame(1, legacy_field::migrate());
        $this->assertTrue(discoverability::is_unlisted((int) $ticked->id));
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state((int) $unticked->id));
        $this->assertSame(
            discoverability::STATE_DEFAULT,
            discoverability::get_state((int) $otheronly->id),
            'A course carrying only some OTHER field must get nothing.'
        );
        $this->assertTrue(discoverability::is_public((int) $alreadypublic->id), 'An existing row is never overwritten.');
        $this->assertSame(0, (int) $DB->get_field(discoverability::TABLE, 'usermodified', ['courseid' => $ticked->id]));

        // Idempotent: a second pass writes nothing and the unique key is never hit.
        $this->assertSame(0, legacy_field::migrate());
        $this->assertSame(1, $DB->count_records(discoverability::TABLE, ['courseid' => $ticked->id]));
    }

    /**
     * The site course never gets a row, whatever the field said about it.
     *
     * @return void
     */
    public function test_migrate_never_writes_a_row_for_the_site_course(): void {
        global $DB;

        $this->resetAfterTest();
        $cfgenerator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $unlisted = $this->create_field($this->create_category('Course visibility'), legacy_field::SHORTNAME, 'checkbox');
        $ticked = $this->getDataGenerator()->create_course();
        $cfgenerator->add_instance_data($unlisted, SITEID, 1);
        $cfgenerator->add_instance_data($unlisted, (int) $ticked->id, 1);

        $this->assertSame(1, legacy_field::migrate());
        $this->assertFalse($DB->record_exists(discoverability::TABLE, ['courseid' => SITEID]));
        $this->assertTrue(discoverability::is_unlisted((int) $ticked->id), 'Control: an ordinary course is still copied.');
    }

    /**
     * Without the field there is nothing to migrate.
     *
     * @return void
     */
    public function test_migrate_without_the_field_does_nothing(): void {
        $this->resetAfterTest();
        $this->assertNull(legacy_field::find());
        $this->assertSame(0, legacy_field::migrate());
    }

    /**
     * remove() deletes the field, its data and its empty category, and nothing else.
     *
     * @return void
     */
    public function test_remove_deletes_the_field_and_its_empty_category_but_nothing_else(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $cfgenerator = $generator->get_plugin_generator('core_customfield');
        $category = $this->create_category('Course visibility');
        $unlisted = $this->create_field($category, legacy_field::SHORTNAME, 'checkbox');
        $othercategory = $this->create_category('Hotsite');
        $other = $this->create_field($othercategory, 'hotsite_modelo', 'checkbox');
        $course = $generator->create_course();
        $cfgenerator->add_instance_data($unlisted, (int) $course->id, 1);
        $cfgenerator->add_instance_data($other, (int) $course->id, 1);
        set_config(legacy_field::CATEGORYID_CONFIG, $category->get('id'), 'local_unlistedcourses');

        legacy_field::remove();

        $this->assertFalse($DB->record_exists('customfield_field', ['id' => $unlisted->get('id')]));
        $this->assertFalse($DB->record_exists('customfield_data', ['fieldid' => $unlisted->get('id')]));
        $this->assertFalse($DB->record_exists('customfield_category', ['id' => $category->get('id')]));
        $this->assertFalse(get_config('local_unlistedcourses', legacy_field::CATEGORYID_CONFIG));
        // Control: the other category, field and data are intact.
        $this->assertTrue($DB->record_exists('customfield_field', ['id' => $other->get('id')]));
        $this->assertTrue($DB->record_exists('customfield_data', ['fieldid' => $other->get('id')]));
        $this->assertTrue($DB->record_exists('customfield_category', ['id' => $othercategory->get('id')]));

        // A second call is harmless.
        legacy_field::remove();
        $this->assertNull(legacy_field::find());
    }

    /**
     * A category that still holds another field survives remove().
     *
     * @return void
     */
    public function test_remove_keeps_a_category_that_still_holds_another_field(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->create_category('Mixed');
        $unlisted = $this->create_field($category, legacy_field::SHORTNAME, 'checkbox');
        $other = $this->create_field($category, 'sibling', 'text');

        legacy_field::remove();

        $this->assertFalse($DB->record_exists('customfield_field', ['id' => $unlisted->get('id')]));
        $this->assertTrue($DB->record_exists('customfield_field', ['id' => $other->get('id')]));
        $this->assertTrue($DB->record_exists('customfield_category', ['id' => $category->get('id')]));
    }

    /**
     * find() prefers the category this plugin provisioned, and ignores fields of another type or handler.
     *
     * @return void
     */
    public function test_find_prefers_the_remembered_category_and_ignores_lookalikes(): void {
        $this->resetAfterTest();
        $first = $this->create_category('First');
        $second = $this->create_category('Second');
        $this->create_field($first, legacy_field::SHORTNAME, 'checkbox');
        $this->create_field($second, legacy_field::SHORTNAME, 'checkbox');

        $this->assertSame((int) $first->get('id'), (int) legacy_field::find()->categoryid, 'Without config the lowest id wins.');

        set_config(legacy_field::CATEGORYID_CONFIG, $second->get('id'), 'local_unlistedcourses');
        $this->assertSame((int) $second->get('id'), (int) legacy_field::find()->categoryid);

        // A same-named field of another TYPE, or under another handler, is not the retired field.
        $generator = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $foreign = $generator->create_category(['component' => 'core_cohort', 'area' => 'cohort', 'name' => 'Cohort']);
        $generator->create_field([
            'categoryid' => $foreign->get('id'),
            'shortname' => legacy_field::SHORTNAME,
            'name' => 'x',
            'type' => 'checkbox',
        ]);
        $this->create_field($this->create_category('Third'), legacy_field::SHORTNAME, 'text');
        set_config(legacy_field::CATEGORYID_CONFIG, $foreign->get('id'), 'local_unlistedcourses');
        $this->assertSame((int) $first->get('id'), (int) legacy_field::find()->categoryid);
    }
}
