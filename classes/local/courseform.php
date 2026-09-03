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
 * Course discoverability - The control in the course settings form
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_unlistedcourses\local;

use local_unlistedcourses\discoverability;

/**
 * Adds the discoverability select to the course settings form and reads it back.
 *
 * The control mirrors core's own "Course visibility" select: it sits right
 * after it, and it is offered to the same people (moodle/course:visibility).
 * The public option is added only for a user who may publish - except when
 * the course is ALREADY public, in which case the control is shown frozen
 * with the current value still submitting, through a persistent freeze
 * (a hidden input). Without that, an editor who may not publish would save an
 * unrelated change and silently un-publish the course, because a frozen
 * element that does not persist is exported as its default.
 *
 * None of this is a security boundary. The boundary is
 * {@see discoverability::set_state()}; this class only decides what the form
 * shows, and what it shows is what set_state() will accept.
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courseform {
    /** @var string Name of the form element. */
    public const ELEMENT = 'local_unlistedcourses_state';

    /** @var string The core element the control is inserted ahead of: the one after "Course visibility". */
    private const ANCHOR = 'startdate';

    /** @var string The capability core requires to offer its own visibility select. */
    private const CAPABILITY_VISIBILITY = 'moodle/course:visibility';

    /**
     * Add the control to the form.
     *
     * @param \stdClass $course The course being edited; no id when it is being created.
     * @param \core\context $context The course context, or the category context for a new course.
     * @param \MoodleQuickForm $mform The course form.
     * @return void
     */
    public static function extend(\stdClass $course, \core\context $context, \MoodleQuickForm $mform): void {
        if (!empty($course->id) && $course->id == SITEID) {
            return;
        }
        if ($mform->elementExists(self::ELEMENT)) {
            return;
        }

        /* For a course being created the context is the category's and the creator's own
           role in the new course does not exist yet, so core asks what the creator WILL
           hold (guess_if_creator_will_have_course_capability(), the call behind its own
           visibility select). A plain has_capability() there would hide the control from
           a course creator, who gains moodle/course:visibility through creatornewroleid. */
        $creating = empty($course->id);
        if (!self::will_hold(self::CAPABILITY_VISIBILITY, $context, $creating)) {
            return;
        }

        $current = $creating
            ? discoverability::STATE_DEFAULT
            : discoverability::get_state((int) $course->id);
        $canpublish = self::will_hold(discoverability::CAPABILITY_PUBLISH, $context, $creating);

        $options = [
            discoverability::STATE_DEFAULT => get_string('state_default', 'local_unlistedcourses'),
            discoverability::STATE_UNLISTED => get_string('state_unlisted', 'local_unlistedcourses'),
        ];
        if ($canpublish || $current === discoverability::STATE_PUBLIC) {
            $options[discoverability::STATE_PUBLIC] = get_string('state_public', 'local_unlistedcourses');
        }

        $element = $mform->createElement('select', self::ELEMENT, get_string('state', 'local_unlistedcourses'), $options);
        if ($mform->elementExists(self::ANCHOR)) {
            $mform->insertElementBefore($element, self::ANCHOR);
        } else {
            $mform->addElement($element);
        }
        $mform->setType(self::ELEMENT, PARAM_INT);
        $mform->setDefault(self::ELEMENT, $current);
        $mform->addHelpButton(self::ELEMENT, 'state', 'local_unlistedcourses');

        if ($current === discoverability::STATE_PUBLIC && !$canpublish) {
            /* Persistent freeze: the value is displayed as text AND re-submitted through a
               hidden input, so saving the form leaves the state exactly where it was. A
               plain freeze would export the element's default instead. */
            $mform->getElement(self::ELEMENT)->setPersistantFreeze(true);
            $mform->freeze(self::ELEMENT);
        }
    }

    /**
     * Whether the current user holds, or will hold once the course exists, a capability.
     *
     * @param string $capability The capability.
     * @param \core\context $context The course context, or the category context when creating.
     * @param bool $creating Whether the course is being created.
     * @return bool True when the control may be offered on that capability.
     */
    private static function will_hold(string $capability, \core\context $context, bool $creating): bool {
        if ($creating) {
            return guess_if_creator_will_have_course_capability($capability, $context);
        }
        return has_capability($capability, $context);
    }

    /**
     * Persist the submitted state, if the submission carried one.
     *
     * A submission without the element - a web service call, a CSV upload, a
     * form rendered without the control - leaves the state untouched. The site
     * course is never touched.
     *
     * @param \stdClass $data The submitted course data.
     * @return void
     */
    public static function save(\stdClass $data): void {
        $state = $data->{self::ELEMENT} ?? null;
        if ($state === null || empty($data->id) || $data->id == SITEID) {
            return;
        }
        discoverability::set_state((int) $data->id, (int) $state);
    }
}
