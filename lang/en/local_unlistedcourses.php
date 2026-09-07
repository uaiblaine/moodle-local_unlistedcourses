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
 * Course discoverability - Language pack (English)
 *
 * @package    local_unlistedcourses
 * @copyright  2026 Anderson Blaine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['event_category_state_updated'] = 'Course category discoverability state updated';
$string['event_course_state_updated'] = 'Course discoverability state updated';
$string['pluginname'] = 'Course discoverability';
$string['privacy:metadata:local_unlistedcourses_catstate'] = 'The discoverability state of each course category that is not in the default state, and who last changed it.';
$string['privacy:metadata:local_unlistedcourses_catstate:categoryid'] = 'The course category the state belongs to.';
$string['privacy:metadata:local_unlistedcourses_catstate:state'] = 'The state: listed or unlisted.';
$string['privacy:metadata:local_unlistedcourses_catstate:timemodified'] = 'When the state was last changed.';
$string['privacy:metadata:local_unlistedcourses_catstate:usermodified'] = 'The user who last changed the state.';
$string['privacy:metadata:local_unlistedcourses_state'] = 'The discoverability state of each course that is not in the default state, and who last changed it.';
$string['privacy:metadata:local_unlistedcourses_state:courseid'] = 'The course the state belongs to.';
$string['privacy:metadata:local_unlistedcourses_state:state'] = 'The state: listed, unlisted or public.';
$string['privacy:metadata:local_unlistedcourses_state:timemodified'] = 'When the state was last changed.';
$string['privacy:metadata:local_unlistedcourses_state:usermodified'] = 'The user who last changed the state.';
$string['restore_publicclamped'] = 'The course was public in the backup, but the user restoring it may not publish courses here. The public state was not applied.';
$string['restore_statenotapplied'] = 'The discoverability state in the backup was not applied: the user restoring it may not change the state of the target course.';
$string['state'] = 'Discoverability';
$string['state_default'] = 'Listed';
$string['state_help'] = 'Who may learn that this course exists.

* **Listed**: the course appears in course listings and on the enrolment page like any other course.
* **Unlisted**: the course is named only to people who are enrolled, have an application awaiting a decision, could enrol right now, or are staff. It stays visible for them and keeps working through a direct link. This is not the same as hiding the course, which makes it unavailable to everyone.
* **Public**: in addition to being listed, the course\'s landing page can be read by visitors who are not logged in, so a shared link shows a preview in messaging apps and social networks. Only a user allowed to publish courses can set or unset this. A hidden course, or a course inside a hidden category, is never public whatever this says. Where the course has no landing page configured, this setting has no visible effect until one is.';
$string['state_public'] = 'Public';
$string['state_unlisted'] = 'Unlisted';
$string['unlistedcourses:managecategorystate'] = 'Set whether a course category is listed or unlisted';
$string['unlistedcourses:publish'] = 'Publish a course to visitors who are not logged in';
