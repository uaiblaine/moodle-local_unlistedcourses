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

$string['applydefault'] = 'Apply the default to existing courses';
$string['applydefault_changed'] = 'The default discoverability for new courses has changed since this task was queued. No course was changed.';
$string['applydefault_confirm'] = '{$a->count} courses created before {$a->date} have no discoverability of their own. Each of them will become {$a->state}, in a background task that runs with the next cron.

That date is when the default for new courses became {$a->state} on the settings page, or now if the change left no record there. Courses created since then received the default, or a choice somebody made, and are left as they are, as are courses whose discoverability somebody has set and the site home. A course somebody moved back to Listed before that date has no discoverability of its own either, and will become {$a->state} too.';
$string['applydefault_desc'] = 'The default reaches a course only when it is created. <a href="{$a}">Apply it to the existing courses</a> that have no discoverability of their own, such as the courses this site had before the plugin was installed or before the default was chosen.';
$string['applydefault_done'] = 'Default discoverability applied: {$a->applied} courses changed, {$a->skipped} skipped because somebody set their discoverability meanwhile, {$a->failed} failed.';
$string['applydefault_listed'] = 'The default discoverability for new courses is Listed, which is what a course without a discoverability of its own already is, so there is nothing to apply. Choose Unlisted as the default first.';
$string['applydefault_none'] = 'Every course created before {$a->date} already has a discoverability of its own. There is nothing to change.';
$string['applydefault_queued'] = 'The default discoverability is being applied to the existing courses. This page updates when the task has finished.';
$string['applydefault_stale'] = 'Nothing was queued: the default discoverability for new courses was saved again, or the task was queued already, since the page was shown. Review the courses it would change and confirm again.';
$string['applydefault_task'] = 'Apply the default discoverability to existing courses';
$string['categorystate'] = 'Discoverability';
$string['categorystate_help'] = 'Who may learn that this category exists.

* **Listed**: the category and its courses appear in listings like any other.
* **Unlisted**: the category is named only to members of a cohort defined at this category, to people holding a role in this category or in a category above it, and to staff (managers and course creators). Everyone else stops seeing the category, its subcategories and its courses in the course index, on the front page and in search results; a course inside it stays on the listings of the people already enrolled in it (including an enrolment that starts at a later date), awaiting a decision on an application or on its waiting list, or teaching it. A public course inside an unlisted category is no longer public.
* **Public**: in addition to being listed, the category\'s page can be read by visitors who are not logged in. Inside it, only the courses whose own state is Public are served to them: an unlisted course stays withheld, and a listed course is not offered to a visitor at all. Only a user allowed to publish categories can set or unset this. A hidden category, a category inside a hidden category, and a category inside an unlisted category are never public, whatever this says.

This is a listing rule, not a permission. It hides the category from the pages this site renders; web services, the mobile app, the Navigation block and course-list smart menus still name it, and a direct link to a course keeps following the course\'s own rules. Cohorts created at the site level do not count, and neither does the cohort an enrolment method restricts enrolments to: only a cohort defined at this category, or a role assigned here, opens it.';
$string['categorystate_saved'] = 'Category discoverability saved';
$string['defaultstate'] = 'Default discoverability for new courses';
$string['defaultstate_desc'] = 'The discoverability a new course receives when the person or process creating it does not choose one. It is what the Discoverability field of the course form starts at, and the state given to a course created through a web service, a CSV upload, an approved course request or any other route that leaves that field out. A value chosen on the course form always wins. Public is not offered: publishing a course needs the permission to publish courses, held by whoever creates it. Only courses created after a change are affected.';
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
$string['preview_public'] = 'This category is public: its page can be read by visitors who are not logged in. Inside it, only courses whose own state is Public are served to them; an unlisted course and an unlisted subcategory stay withheld, and a listed course is not offered to a visitor at all.';
$string['preview_publicancestor'] = 'A category above this one is unlisted, so this category is not public after all: a visitor who is not logged in satisfies none of the terms that open an unlisted category, and that rule applies to everything below it.';
$string['preview_publicheading'] = 'What visitors see of this public category';
$string['preview_publichidden'] = 'This category, or a category above it, is hidden. A hidden category is never public, whatever this setting says.';
$string['preview_roleholders'] = '{$a} people hold a role in this category or in a category above it.';
$string['preview_theme'] = 'Category themes are enabled on this site (allowcategorythemes). A theme set on this category would switch its pages away from the theme that withholds it.';
$string['preview_visible'] = 'Besides staff, this category is visible to {$a} people while it is unlisted.';
$string['preview_visiblenone'] = 'Unlisted and visible to nobody besides staff: no cohort member and no role holder. Every other user will stop seeing this category and its courses.';
$string['privacy:metadata:local_unlistedcourses_catstate'] = 'The discoverability state of each course category that is not in the default state, and who last changed it.';
$string['privacy:metadata:local_unlistedcourses_catstate:categoryid'] = 'The course category the state belongs to.';
$string['privacy:metadata:local_unlistedcourses_catstate:state'] = 'The state: listed, unlisted or public.';
$string['privacy:metadata:local_unlistedcourses_catstate:timemodified'] = 'When the state was last changed.';
$string['privacy:metadata:local_unlistedcourses_catstate:usermodified'] = 'The user who last changed the state.';
$string['privacy:metadata:local_unlistedcourses_state'] = 'The discoverability state of each course that is not in the default state, and who last changed it.';
$string['privacy:metadata:local_unlistedcourses_state:courseid'] = 'The course the state belongs to.';
$string['privacy:metadata:local_unlistedcourses_state:state'] = 'The state: listed, unlisted or public.';
$string['privacy:metadata:local_unlistedcourses_state:timemodified'] = 'When the state was last changed.';
$string['privacy:metadata:local_unlistedcourses_state:usermodified'] = 'The user who last changed the state.';
$string['restore_publicclamped'] = 'The course was public in the backup, but the user restoring it may not publish courses here. The public state was not applied.';
$string['restore_statenotapplied'] = 'The discoverability state in the backup was not applied: the user restoring it may not change the state of the target course.';
$string['restoredefaultstate'] = 'Discoverability of a restored course whose backup has none';
$string['restoredefaultstate_desc'] = 'The discoverability a course receives when a backup is restored as a new course and the backup carries no discoverability, as a backup taken on a site without this plugin does. A backup that carries a value this version applies keeps it; one whose value is unknown here, or is public and refused to the person restoring, receives the default too. A restore into an existing course leaves that course\'s state as it is. A course created by a CSV upload from a template backup is created first, so it receives the default for new courses instead.';
$string['state'] = 'Discoverability';
$string['state_default'] = 'Listed';
$string['state_help'] = 'Who may learn that this course exists.

* **Listed**: the course appears in course listings and on the enrolment page like any other course.
* **Unlisted**: the course is named only to people who are enrolled (including those whose enrolment starts at a later date), have an application awaiting a decision or on its waiting list, could enrol right now, will be enrolled once they complete another course, or are staff. A suspended enrolment, or one that has ended, does not count. It stays visible for them and keeps working through a direct link. This is not the same as hiding the course, which makes it unavailable to everyone.
* **Public**: in addition to being listed, the course\'s landing page can be read by visitors who are not logged in, so a shared link shows a preview in messaging apps and social networks. Only a user allowed to publish courses can set or unset this. A hidden course, or a course inside a hidden category, is never public whatever this says. Where the course has no landing page configured, this setting has no visible effect until one is.';
$string['state_public'] = 'Public';
$string['state_unlisted'] = 'Unlisted';
$string['unlistedcourses:managecategorystate'] = 'Change the discoverability state of a course category';
$string['unlistedcourses:publish'] = 'Publish a course to visitors who are not logged in';
$string['unlistedcourses:publishcategory'] = 'Publish a course category to visitors who are not logged in';
