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

$string['categorystate'] = 'Discoverability';
$string['categorystate_help'] = 'Who may learn that this category exists.

* **Listed**: the category and its courses appear in listings like any other.
* **Unlisted**: the category is named only to members of a cohort defined at this category, to people holding a role in this category or in a category above it, and to staff (managers and course creators). Everyone else stops seeing the category, its subcategories and its courses in the course index, on the front page and in search results; a course inside it stays on the listings of the people already enrolled in it, awaiting a decision on an application, or teaching it. A public course inside an unlisted category is no longer public.

This is a listing rule, not a permission. It hides the category from the pages this site renders; web services, the mobile app, the Navigation block and course-list smart menus still name it, and a direct link to a course keeps following the course\'s own rules. Cohorts created at the site level do not count, and neither does the cohort an enrolment method restricts enrolments to: only a cohort defined at this category, or a role assigned here, opens it.';
$string['categorystate_saved'] = 'Category discoverability saved';
$string['event_category_state_updated'] = 'Course category discoverability state updated';
$string['event_course_state_updated'] = 'Course discoverability state updated';
$string['pluginname'] = 'Course discoverability';
$string['preview_ancestor'] = 'A category above this one is unlisted, so its rule applies here as well: a person must satisfy every unlisted category on the path.';
$string['preview_cohortmembers'] = 'Members: {$a}';
$string['preview_cohorts'] = 'Cohorts defined at this category';
$string['preview_cohortsmore'] = '{$a} more not shown';
$string['preview_cohortsnone'] = 'No cohort is defined at this category. Cohorts created at the site level do not count here, and neither does the cohort an enrolment method restricts enrolments to: create a cohort at this category, or assign roles here.';
$string['preview_cohortsnoview'] = 'You may not view the cohorts of this category.';
$string['preview_heading'] = 'Who sees this category while it is unlisted';
$string['preview_managecohorts'] = 'Manage the cohorts of this category';
$string['preview_roleholders'] = '{$a} people hold a role in this category or in a category above it.';
$string['preview_theme'] = 'Category themes are enabled on this site (allowcategorythemes). A theme set on this category would switch its pages away from the theme that withholds it.';
$string['preview_visible'] = 'Besides staff, this category is currently visible to {$a} people.';
$string['preview_visiblenone'] = 'Unlisted and visible to nobody besides staff: no cohort member and no role holder. Every other user will stop seeing this category and its courses.';
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
