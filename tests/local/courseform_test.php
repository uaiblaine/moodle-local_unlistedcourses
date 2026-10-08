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
 * Course discoverability - Tests for the course form control
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\local;

use local_unlistedcourses\access;
use local_unlistedcourses\discoverability;
use local_unlistedcourses\hook_callbacks;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the course form control and its persistence, hook wiring included.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(courseform::class)]
#[CoversClass(hook_callbacks::class)]
final class courseform_test extends \advanced_testcase {
    /**
     * Reset the request caches between tests.
     *
     * @return void
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        require_once($CFG->libdir . '/formslib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        access::reset_caches();
    }

    /**
     * A bare form carrying the core elements the control is placed around.
     *
     * @return \MoodleQuickForm The form.
     */
    private function make_form(): \MoodleQuickForm {
        $mform = new \MoodleQuickForm('testform', 'post', '/');
        $mform->addElement('header', 'general', 'General');
        $mform->addElement('text', 'fullname', 'Course full name');
        $mform->setType('fullname', PARAM_TEXT);
        $mform->addElement('select', 'visible', 'Course visibility', [1 => 'Show', 0 => 'Hide']);
        $mform->addElement('date_time_selector', 'startdate', 'Course start date');
        return $mform;
    }

    /**
     * The names of a form's elements, in the order they will be rendered.
     *
     * @param \MoodleQuickForm $mform The form.
     * @return array List of element names.
     */
    private function element_order(\MoodleQuickForm $mform): array {
        $names = [];
        foreach ($mform->_elements as $element) {
            $names[] = $element->getName();
        }
        return $names;
    }

    /**
     * The option values the control offers.
     *
     * @param \MoodleQuickForm $mform The form.
     * @return array List of int option values.
     */
    private function options(\MoodleQuickForm $mform): array {
        $values = [];
        foreach ($mform->getElement(courseform::ELEMENT)->_options as $option) {
            $values[] = (int) $option['attr']['value'];
        }
        return $values;
    }

    /**
     * A manager over the course's category.
     *
     * @param int $categoryid The category id.
     * @return \stdClass The user.
     */
    private function create_manager(int $categoryid): \stdClass {
        global $DB;

        $manager = $this->getDataGenerator()->create_user();
        role_assign(
            $DB->get_field('role', 'id', ['shortname' => 'manager']),
            $manager->id,
            \core\context\coursecat::instance($categoryid)->id
        );
        return $manager;
    }

    /**
     * The control sits right after "Course visibility" and offers a manager all three states.
     *
     * @return void
     */
    public function test_the_control_sits_after_course_visibility_and_offers_a_manager_every_state(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->create_manager((int) $course->category));

        $mform = $this->make_form();
        courseform::extend($course, \core\context\course::instance($course->id), $mform);

        $order = $this->element_order($mform);
        $this->assertSame(
            ['visible', courseform::ELEMENT, 'startdate'],
            array_slice($order, array_search('visible', $order, true), 3)
        );
        $this->assertSame([0, 1, 2], $this->options($mform));
        $this->assertEquals([discoverability::STATE_DEFAULT], $mform->getElement(courseform::ELEMENT)->getSelected());
        $this->assertFalse($mform->getElement(courseform::ELEMENT)->isFrozen());
    }

    /**
     * The public option is offered only to a user who may publish.
     *
     * @return void
     */
    public function test_public_is_offered_only_to_a_publisher(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \core\context\course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->setUser($teacher);
        $mform = $this->make_form();
        courseform::extend($course, $context, $mform);
        $this->assertSame([0, 1], $this->options($mform));

        // Control: the manager gets the third option from the same code.
        $this->setUser($this->create_manager((int) $course->category));
        $mform = $this->make_form();
        courseform::extend($course, $context, $mform);
        $this->assertSame([0, 1, 2], $this->options($mform));
    }

    /**
     * A public course shows a frozen "Public" to an editor who may not publish, and the value still submits.
     *
     * @return void
     */
    public function test_a_public_course_shows_frozen_public_to_an_editor_who_may_not_publish(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \core\context\course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $manager = $this->create_manager((int) $course->category);

        $this->setUser($manager);
        discoverability::set_state((int) $course->id, discoverability::STATE_PUBLIC);

        $this->setUser($teacher);
        $mform = $this->make_form();
        courseform::extend($course, $context, $mform);
        $element = $mform->getElement(courseform::ELEMENT);
        $this->assertContains(2, $this->options($mform), 'The current value must be displayable.');
        $this->assertTrue($element->isFrozen());
        $this->assertEquals([discoverability::STATE_PUBLIC], $element->getSelected());
        $frozen = $element->getFrozenHtml();
        $this->assertStringContainsString('type="hidden"', $frozen, 'The value must still submit.');
        $this->assertStringContainsString('value="2"', $frozen);
        $this->assertStringContainsString('name="' . courseform::ELEMENT . '"', $frozen);

        // Control: the manager gets the same course unfrozen.
        $this->setUser($manager);
        $mform = $this->make_form();
        courseform::extend($course, $context, $mform);
        $this->assertFalse($mform->getElement(courseform::ELEMENT)->isFrozen());
    }

    /**
     * No control without moodle/course:visibility, and none on the site course.
     *
     * @return void
     */
    public function test_the_control_is_absent_without_course_visibility_and_on_the_site_course(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $this->setUser($student);
        $mform = $this->make_form();
        courseform::extend($course, \core\context\course::instance($course->id), $mform);
        $this->assertFalse($mform->elementExists(courseform::ELEMENT));

        $this->setAdminUser();
        $mform = $this->make_form();
        courseform::extend(get_site(), \core\context\course::instance(SITEID), $mform);
        $this->assertFalse($mform->elementExists(courseform::ELEMENT));

        // Control: the admin gets it on an ordinary course.
        $mform = $this->make_form();
        courseform::extend($course, \core\context\course::instance($course->id), $mform);
        $this->assertTrue($mform->elementExists(courseform::ELEMENT));
    }

    /**
     * A course being created is listed by default, and the category context decides what is offered.
     *
     * @return void
     */
    public function test_a_new_course_is_listed_by_default_and_gates_on_the_category(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $context = \core\context\coursecat::instance($category->id);
        $newcourse = (object) ['category' => $category->id];

        $this->setUser($this->create_manager((int) $category->id));
        $mform = $this->make_form();
        courseform::extend($newcourse, $context, $mform);
        $this->assertSame([0, 1, 2], $this->options($mform));
        $this->assertEquals([discoverability::STATE_DEFAULT], $mform->getElement(courseform::ELEMENT)->getSelected());

        // An editing teacher over the category may hide but not publish.
        $teacher = $this->getDataGenerator()->create_user();
        role_assign($DB->get_field('role', 'id', ['shortname' => 'editingteacher']), $teacher->id, $context->id);
        $this->setUser($teacher);
        $mform = $this->make_form();
        courseform::extend($newcourse, $context, $mform);
        $this->assertSame([0, 1], $this->options($mform));

        // Control: with no role at the category there is no control at all.
        $this->setUser($this->getDataGenerator()->create_user());
        $mform = $this->make_form();
        courseform::extend($newcourse, $context, $mform);
        $this->assertFalse($mform->elementExists(courseform::ELEMENT));
    }

    /**
     * A course creator gets the control through the role they will receive, as with core's visibility select.
     *
     * @return void
     */
    public function test_a_course_creator_gets_the_control_through_the_role_they_will_receive(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $context = \core\context\coursecat::instance($category->id);
        $newcourse = (object) ['category' => $category->id];
        $creator = $this->getDataGenerator()->create_user();
        role_assign($DB->get_field('role', 'id', ['shortname' => 'coursecreator']), $creator->id, $context->id);
        $this->setUser($creator);

        $this->assertFalse(
            has_capability('moodle/course:visibility', $context),
            'Precondition: the creator does not hold the capability at the category themselves.'
        );
        $mform = $this->make_form();
        courseform::extend($newcourse, $context, $mform);
        $this->assertTrue($mform->elementExists(courseform::ELEMENT));
        $this->assertSame([0, 1], $this->options($mform), 'The role they will receive may hide, not publish.');

        // Control: with no role to receive, the same creator gets nothing.
        set_config('creatornewroleid', 0);
        $mform = $this->make_form();
        courseform::extend($newcourse, $context, $mform);
        $this->assertFalse($mform->elementExists(courseform::ELEMENT));
    }

    /**
     * save() acts only when the submission carries the element.
     *
     * @return void
     */
    public function test_save_acts_only_when_the_submission_carries_the_element(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        discoverability::set_state((int) $course->id, discoverability::STATE_UNLISTED);

        courseform::save((object) ['id' => $course->id, 'fullname' => 'Renamed']);
        $this->assertTrue(discoverability::is_unlisted((int) $course->id), 'A save without the element keeps the state.');

        courseform::save((object) [courseform::ELEMENT => discoverability::STATE_DEFAULT]);
        $this->assertTrue(discoverability::is_unlisted((int) $course->id), 'A save without a course id does nothing.');

        courseform::save((object) ['id' => SITEID, courseform::ELEMENT => discoverability::STATE_UNLISTED]);
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state(SITEID));

        // Control: with the element present the state changes.
        courseform::save((object) ['id' => $course->id, courseform::ELEMENT => discoverability::STATE_DEFAULT]);
        $this->assertFalse(discoverability::is_unlisted((int) $course->id));
    }

    /**
     * The hooks are wired: create_course() and update_course() persist the element, gate included.
     *
     * @return void
     */
    public function test_the_hooks_are_wired_through_create_course_and_update_course(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $manager = $this->create_manager((int) $category->id);

        $this->setUser($manager);
        $course = create_course((object) [
            'fullname' => 'Wired',
            'shortname' => 'wired',
            'category' => $category->id,
            courseform::ELEMENT => discoverability::STATE_UNLISTED,
        ]);
        $this->assertTrue(discoverability::is_unlisted((int) $course->id), 'create_course() must persist the element.');

        update_course((object) ['id' => $course->id, courseform::ELEMENT => discoverability::STATE_PUBLIC]);
        $this->assertTrue(discoverability::is_public((int) $course->id), 'update_course() must persist the element.');

        // The gate holds on the same path: an editing teacher cannot un-publish through update_course().
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        try {
            update_course((object) ['id' => $course->id, courseform::ELEMENT => discoverability::STATE_UNLISTED]);
            $this->fail('update_course() must not let an editing teacher un-publish.');
        } catch (\required_capability_exception $e) {
            $this->assertTrue(discoverability::is_public((int) $course->id));
        }

        // Control: the same teacher saving without the element is fine, and the course stays public.
        update_course((object) ['id' => $course->id, 'fullname' => 'Wired, renamed']);
        $this->assertTrue(discoverability::is_public((int) $course->id));
    }

    /**
     * Set the default for new courses.
     *
     * @param int $state One of the state constants.
     * @return void
     */
    private function set_creation_default(int $state): void {
        set_config(discoverability::SETTING_DEFAULT, $state, 'local_unlistedcourses');
    }

    /**
     * Create a course the way a web service or a CSV upload does: without the element.
     *
     * @param int $categoryid The category id.
     * @param string $shortname The short name.
     * @return int The new course id.
     */
    private function create_without_element(int $categoryid, string $shortname): int {
        $course = create_course((object) [
            'fullname' => 'Course ' . $shortname,
            'shortname' => $shortname,
            'category' => $categoryid,
        ]);
        return (int) $course->id;
    }

    /**
     * A course created without the element receives the site's default, whichever of the two it is.
     *
     * @return void
     */
    public function test_a_course_created_without_the_element_gets_the_site_default(): void {
        global $DB;

        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $this->setAdminUser();

        // Never set: listed, as before the setting existed.
        $unset = $this->create_without_element((int) $category->id, 'unset');
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state($unset));
        $this->assertFalse($DB->record_exists(discoverability::TABLE, ['courseid' => $unset]));

        $this->set_creation_default(discoverability::STATE_UNLISTED);
        $unlisted = $this->create_without_element((int) $category->id, 'unlisted');
        $this->assertTrue(discoverability::is_unlisted($unlisted), 'The default must reach a course created without the element.');

        $this->set_creation_default(discoverability::STATE_DEFAULT);
        $listed = $this->create_without_element((int) $category->id, 'listed');
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state($listed));

        // A public value written by hand is not a choice: it reads as listed, never as public.
        $this->set_creation_default(discoverability::STATE_PUBLIC);
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_creation_default());
        $handwritten = $this->create_without_element((int) $category->id, 'handwritten');
        $this->assertFalse(discoverability::is_public($handwritten));
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state($handwritten));

        // Control: the earlier course keeps its state; the default touches only the course being created.
        $this->assertTrue(discoverability::is_unlisted($unlisted));
    }

    /**
     * The course web service creates without the element, so its course receives the default.
     *
     * @return void
     */
    public function test_the_course_web_service_applies_the_default(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $this->setAdminUser();
        $this->set_creation_default(discoverability::STATE_UNLISTED);
        $_POST['sesskey'] = sesskey();

        $response = \core_external\external_api::call_external_function('core_course_create_courses', [
            'courses' => [['fullname' => 'From a web service', 'shortname' => 'ws', 'categoryid' => (int) $category->id]],
        ]);

        $this->assertFalse($response['error'], json_encode($response));
        $this->assertTrue(discoverability::is_unlisted((int) $response['data'][0]['id']));
    }

    /**
     * The form starts at the default for a new course, and an explicit value always beats the default.
     *
     * @return void
     */
    public function test_the_form_value_wins_over_the_default(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $context = \core\context\coursecat::instance($category->id);
        $this->setUser($this->create_manager((int) $category->id));
        $this->set_creation_default(discoverability::STATE_UNLISTED);

        $mform = $this->make_form();
        courseform::extend((object) ['category' => $category->id], $context, $mform);
        $this->assertEquals(
            [discoverability::STATE_UNLISTED],
            $mform->getElement(courseform::ELEMENT)->getSelected(),
            'A new course starts at the default.'
        );

        // Explicitly listed on the form, against an unlisted default.
        $course = create_course((object) [
            'fullname' => 'Explicitly listed',
            'shortname' => 'explicit',
            'category' => $category->id,
            courseform::ELEMENT => discoverability::STATE_DEFAULT,
        ]);
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state((int) $course->id));

        // Control: an existing course's form starts at its own state, not at the default.
        $mform = $this->make_form();
        courseform::extend($course, \core\context\course::instance($course->id), $mform);
        $this->assertEquals([discoverability::STATE_DEFAULT], $mform->getElement(courseform::ELEMENT)->getSelected());
    }

    /**
     * Updating a course without the element never applies the default.
     *
     * @return void
     */
    public function test_an_update_without_the_element_never_applies_the_default(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $this->setAdminUser();
        $listed = $this->create_without_element((int) $category->id, 'listed');
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state($listed), 'Precondition: listed.');

        $this->set_creation_default(discoverability::STATE_UNLISTED);
        update_course((object) ['id' => $listed, 'fullname' => 'Renamed']);
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state($listed), 'An update must keep the state.');

        courseform::save((object) ['id' => $listed, 'fullname' => 'Renamed again']);
        $this->assertSame(discoverability::STATE_DEFAULT, discoverability::get_state($listed));

        // Control: the same default does reach a course being created, through save() and through create_course().
        courseform::save((object) ['id' => $listed], true);
        $this->assertTrue(discoverability::is_unlisted($listed));
        $this->assertTrue(discoverability::is_unlisted($this->create_without_element((int) $category->id, 'fresh')));
    }
}
