# Course discoverability (local_unlistedcourses)

Owns one decision per course: **who may learn that this course exists.** Three states,
stored in the plugin's own table and edited from the course settings form:

| State | Meaning |
|---|---|
| **Listed** (default, no row) | A course like any other. |
| **Unlisted** | Named only to people who are actively enrolled, have an application awaiting a decision, could enrol right now, or are staff. The course stays `visible = 1` and keeps working through a direct link; it simply stops being named to anyone who is not entitled to it. |
| **Public** | In addition to being listed, the course's landing page may be served to visitors who are **not logged in**, on a site that keeps `forcelogin = 1` - so a link pasted into a messaging app shows a preview. |

The component name is narrower than its scope. It was born as "unlisted courses" and now
owns "public" too; renaming a Moodle component means uninstall, reinstall and losing the
data, so the name stays and the user-facing strings talk about discoverability.

## The API

`\local_unlistedcourses\discoverability`:

- `get_states(array $courseids)`, `get_state(int)`, `is_unlisted(int)` - the stored state,
  one query per request for any number of courses.
- `is_public(int)` - the gate for an anonymous page. It also requires the course to be
  visible and **every category on its path** to be visible, because core's own visibility
  answer ends in a capability check that fails for user id 0 under `forcelogin`. It is a
  property of the course, not of the viewer.
- `set_state(int $courseid, int $state, ?int $userid = null)` - **the one place the
  publish capability is checked.** Entering or leaving the public state requires
  `local/unlistedcourses:publish` (manager only, no `clonepermissionsfrom`). A call that
  changes nothing needs no capability. Every change fires
  `\local_unlistedcourses\event\course_state_updated`.

`\local_unlistedcourses\access` answers the per-viewer question for unlisted courses:
`is_course_discoverable(int)`, `are_courses_discoverable(array)`, `filter_courses(array)`.
Somebody may discover an unlisted course when any of these holds:

- they are actively enrolled;
- they have an application awaiting a decision (an `enrol_apply` row that is not active -
  without this term, applying would make the course vanish the moment you applied);
- they could enrol right now - `enrol_self::can_self_enrol()` or, for the fleet's
  `enrol_apply` fork, `allow_apply()` plus the applicant cap that lives outside it;
- they are staff - `moodle/course:view` or `moodle/course:viewhiddencourses` on the course.

The gate itself is native: `enrol.customint5`, the "only cohort members" field both enrol
plugins already carry.

## Where the state is written

Four writers, one check. The course settings form gains a "Discoverability" select right
after "Course visibility", offered to whoever may set course visibility; the "Public"
option appears only for a user who may publish, and a course that is already public shows
the control frozen **with its value still submitting**, so saving an unrelated change never
un-publishes it. The value is persisted from the `after_form_submission` hook, which core
dispatches from `create_course()` and `update_course()` - so the web service and
`tool_uploadcourse` reach the same `set_state()`, and the same capability check. Course
backup carries the state, for every course; a restore writes it through the same
`set_state()`, so a restoring user who may not publish is refused there, the refusal is
logged, and the target keeps the state it had. Nobody consents to publishing a course by
restoring a backup.

## What this plugin does not do

**It renders nothing.** Overriding a course renderer is a theme privilege in Moodle -
`theme_config::renderer_prefixes()` returns only `theme_<name>` prefixes, and there is no
`db/renderers.php` in Moodle at all (that is a Totara mechanism). The listings, the
enrolment page and the public landing page live in `theme_boost_union_fundaseg`, which
consumes these predicates.

**`access` is current-user only.** `can_self_enrol()` and `allow_apply()` both read `$USER`
rather than taking a user id, so answering for an arbitrary user would mean reimplementing
both - and a reimplementation that drifts from the plugin it mirrors fails open.

**It caches nothing beyond the request.** The answer depends on cohort membership, and
`tool_dynamic_cohorts` writes `cohort_members` in bulk without firing
`cohort_member_added`/`removed`. A cache invalidated by those events would keep showing a
course to somebody just removed from the cohort that gated it.

**It does not cover every surface.** The predicate is only consulted where something calls
it. Course files served through `pluginfile.php`, badge pages, the report builder and the
web service layer each reach course names by their own route.

## Consequences worth knowing

Both enrol predicates enforce the enrolment window and the places limit, so an unlisted
course disappears from listings while its enrolment window is shut or once it is full. That
is the same answer core's own enrolment icons give, but it does make the listing
time-dependent.

A public course is armed but inert until the site's theme gives it a landing page: the
plugin only says "may be served anonymously", it serves nothing itself.

## Privacy

The state table records who last changed a course's state (`usermodified`). A deletion
request detaches the user from the row and leaves the state in place: removing the row
would un-hide or un-publish a course as a side effect of somebody leaving the site.

## Requirements

Moodle 5.2. Brazilian Portuguese and English language packs.
