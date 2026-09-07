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

## Categories

`\local_unlistedcourses\category_discoverability` holds the same decision for a **course
category**, with two states, listed (no row) and unlisted: `get_states()`, `get_state()`,
`is_unlisted()`, `unlisted_ids()` and `set_state()` - **the one place
`local/unlistedcourses:managecategorystate` is checked**, on every real transition in both
directions. A call that changes nothing needs no capability. Every change fires
`\local_unlistedcourses\event\category_state_updated`.

`\local_unlistedcourses\category_access` answers the per-viewer question for categories:
`is_category_discoverable(int)`, `are_categories_discoverable(array)`,
`filter_categories(array)`. The state is a property of the **path**: a category is
effectively unlisted when it or any ancestor carries the row, and the viewer must satisfy
**every** unlisted category on that path through one of three terms:

- membership of a cohort whose context is that category's own - never an ancestor's, never
  the system one - read from `{cohort_members}` directly, so an invisible cohort still grants;
- a role assignment in that category's context or in an ancestor category context; a role at
  a category below, or at a course inside, is a relationship with something it contains;
- `moodle/category:viewhiddencategories` there, core's own idiom for staff, which is what
  admits a manager or a course creator assigned at the system context.

Site admins discover everything; visitors and guests discover nothing unlisted, at no query.
With no category unlisted the whole predicate costs one query.

**The category term applies to listings only.** `access::filter_courses()` also drops a
course whose category is effectively unlisted unless the viewer is enrolled in it, has an
application pending or is staff of it; being able to self-enrol right now does not rescue
it. `is_course_discoverable()` keeps its meaning, because the theme ghosts the enrolment
page, the course info page and the public landing page off it, and a listing rule must not
become an enrolment block. `discoverability::is_public()` refuses a course with an unlisted
category on its path: an anonymous visitor can satisfy none of the three terms.

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

A category's state is written from one page, `local/unlistedcourses/category.php`, reached
from the category's settings menu (the "More" menu of a category page): core dispatches no
hook from the category form and has no custom field handler for categories. The page shows
who will still see the category - the cohorts defined at it, behind `moodle/cohort:view`,
the people holding a role here or above, and how many people it stays visible to besides
staff, intersected over every unlisted category above it - and warns when that is nobody and
when category themes are enabled. There is no backup or restore of a category's state:
Moodle has no category backup a plugin could attach to.

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
web service layer each reach course names by their own route. For a category the list is
longer and is written down in `docs/discoverability/README.md`, section 14: the course web
services (`core_course_search_courses` is `ajax => true`, so any logged-in browser session
can call it), the mobile app, the Navigation block, `block_course_list`, Boost Union smart
menus, the report builder's category entity and the course request page all name a category
by their own route. Unlisting is a listing rule, not a permission.

## Consequences worth knowing

Both enrol predicates enforce the enrolment window and the places limit, so an unlisted
course disappears from listings while its enrolment window is shut or once it is full. That
is the same answer core's own enrolment icons give, but it does make the listing
time-dependent.

A public course is armed but inert until the site's theme gives it a landing page: the
plugin only says "may be served anonymously", it serves nothing itself.

## Privacy

Both state tables record who last changed a state (`usermodified`). A deletion request
detaches the user from the row and leaves the state in place: removing the row would
un-hide or un-publish a course, or un-hide a category, as a side effect of somebody leaving
the site.

## Requirements

Moodle 5.2. Brazilian Portuguese and English language packs.
