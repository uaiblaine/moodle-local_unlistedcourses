# Course discoverability (local_unlistedcourses)

[![Moodle Plugin CI](https://github.com/uaiblaine/moodle-local_unlistedcourses/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/uaiblaine/moodle-local_unlistedcourses/actions/workflows/ci.yml?query=branch%3Amain)
[![MDL Shield](https://img.shields.io/endpoint?url=https%3A%2F%2Fmdlshield.com%2Fapi%2Fbadge%2Fpublisher%2Fuaiblaine%2Flocal_unlistedcourses)](https://mdlshield.com/reviews/publisher/uaiblaine/local_unlistedcourses)

Owns one decision per course: **who may learn that this course exists.** Three states,
stored in the plugin's own table (`local_unlistedcourses_state`, see [Data stored](#data-stored))
and edited from the course settings form:

| State | Meaning |
|---|---|
| **Listed** (default, no row) | A course like any other. |
| **Unlisted** | Named only to people who are enrolled (also from a later date), have an application awaiting a decision, could enrol right now, will be enrolled once they complete another course, or are staff. The course stays `visible = 1` and keeps working through a direct link; it simply stops being named to anyone who is not entitled to it. |
| **Public** | In addition to being listed, the course's landing page may be served to visitors who are **not logged in**, on a site that keeps `forcelogin = 1` - so a link pasted into a messaging app shows a preview. |

The component name is narrower than its scope. It was born as "unlisted courses" and now
owns "public" too; renaming a Moodle component means uninstall, reinstall and losing the
data, so the name stays and the user-facing strings talk about discoverability.

## The API

`\local_unlistedcourses\discoverability`:

- `get_states(array $courseids)`, `get_state(int)`, `is_unlisted(int)` - the stored state,
  one query per request for any number of courses.
- `state_sql(string $coursealias, string $alias = 'lus')` - the `LEFT JOIN` and the
  `COALESCE`d column that read each course's state inside a statement of the caller's own over
  `{course}`: the way to read the state of a whole population, which `get_states()`, one `IN`
  list, is not.
- `is_public(int)` / `are_public(array)` - the gate for an anonymous page. It also requires the course to be
  visible and **every category on its path** to be visible, because core's own visibility
  answer ends in a capability check that fails for user id 0 under `forcelogin`. It is a
  property of the course, not of the viewer. `are_public()` is the batched form, four
  statements for any number of courses plus the request's memoised read of the unlisted
  categories, and `is_public()` delegates to it so the two can
  never disagree; a course that is not there answers `false` and throws nothing.
- `set_state(int $courseid, int $state, ?int $userid = null)` - **the one place the
  publish capability is checked.** Entering or leaving the public state requires
  `local/unlistedcourses:publish` (manager only, no `clonepermissionsfrom`). A call that
  changes nothing needs no capability. Every change fires
  `\local_unlistedcourses\event\course_state_updated`.

`\local_unlistedcourses\access` answers the per-viewer question for unlisted courses:
`is_course_discoverable(int)`, `are_courses_discoverable(array)`, `filter_courses(array)`.
It also carries two methods for listings:
`filter_courses_public(array)`, the **only** predicate a page served to visitors who are not
logged in may use - it keeps a course when `discoverability::are_public()` says so and consults
no viewer at all - and
`prime_relationships(array $enrolled, array $pending = [], array $scheduled = [])`, which fills
the request-scoped relationship memo for the current viewer from an enrolment map the caller has
already read, so `filter_courses()` stops probing the enrolment tables one course at a time. The primer
writes **only** true: the caller vouches for a relationship it read, and is not
authoritative about the absence of one, because being staff of a course is a relationship
too. It is keyed by the viewer and dropped by `reset_caches()`.

Two more answers about the current user and a course, for the theme and blocks that print them:

- `classify_enrolment(\stdClass $row, int $now)` and `get_enrolment_state(int $courseid)`: the
  relationship the user's enrolment rows give them, with the row's dates - `enrolled`,
  `scheduled` (an active row whose start date is ahead), `pending` (an `enrol_apply` application
  awaiting a decision), `waitlisted` (on that plugin's waiting list), `suspended`, `expired` (an end
  date that has passed) or `none` (no row, a row on a disabled instance, or one that ends before it
  starts). When several rows exist the best wins, in that order.
- `get_next_action(int $courseid)`: what they may do now to join, whatever the relationship -
  `open` with its routes (`self`, `apply`, `fee`, `paypal`, `autoenrol`), `guest` (`free`, or
  behind a `key`), `conditional` (an `enrol_coursecompleted` instance, for a viewer enrolled in its
  prerequisite), `blocked` with the most useful reason (`window`, `full`, `cohort`, `own_row`,
  `off`), or `none`. Every value is a public constant of `access`. It asks each method its own
  question, with statements per instance. `get_next_actions(array $courseids)` answers a page of
  courses in at most four statements, whatever the page holds: it drops the two checks SQL cannot
  read (the self enrolment capability and `enrol_autoenrol`'s rule), so it may answer open where
  the per-course form answers blocked, never the reverse, and it never decides discoverability.
  Both answer `opens` when the course is `blocked` only because a route's enrolment window has not
  opened yet: the earliest such start date, a timestamp, or null. A surface that says when
  enrolment opens prints this date and never works one out; the batch may leave it null where
  the per-course form dates it, never the reverse.

Somebody may discover an unlisted course when any of these holds:

- they are actively enrolled, or enrolled on an enabled instance from a start date still ahead;
- they have an application awaiting a decision or on its waiting list - an `enrol_apply` row that
  is not active and whose end date is unset or still ahead, which is `enrol_apply`'s own queue
  rule (without this term, applying would make the course vanish the moment you applied);
- they could enrol right now (their next action is open or guest), each method asked what its own
  enrolment page asks:
  `enrol_self::can_self_enrol()`; for an `enrol_apply` build that provides `allow_apply()`, that
  method plus the applicant cap that lives outside it, on an instance where they hold no row yet; for
  `enrol_fee` and `enrol_paypal`, the enrolment window open, a price, and no row of theirs on the
  instance; any enabled guest instance, with or without a key; and `enrol_autoenrol`'s own
  `enrol_allowed()`;
- they will be enrolled by `enrol_coursecompleted` once they complete another course (their next
  action is conditional): an instance inside its enrolment window on which they hold no row, for a
  viewer actively enrolled in the prerequisite course the instance names;
- they are staff - `moodle/course:view` or `moodle/course:viewhiddencourses` on the course.

The relationships in that list are an explicit allow-list - enrolled, scheduled, pending,
waitlisted. A suspended or expired enrolment keeps nothing by itself, though the course stays
discoverable to that user through any route the next action still offers them.

There is no cohort setting of this plugin's own: restricting who may join an unlisted course
is the enrolment method's job, through `enrol.customint5`, the "only cohort members" field
`enrol_self` and `enrol_apply` already carry.

## Categories

`\local_unlistedcourses\category_discoverability` holds the same decision for a **course
category**, with the same three states: `get_states()`, `get_state()`, `is_unlisted()`,
`unlisted_ids()`, `public_ids()` and `set_state()` - **the one place
`local/unlistedcourses:managecategorystate` is checked**, on every real transition in both
directions, **and `local/unlistedcourses:publishcategory` on every transition that enters or
leaves the public state** (manager only, no `clonepermissionsfrom`: hiding a category from
listings is an editing act, publishing it to the open web is not). A call that changes
nothing needs no capability. Every change fires
`\local_unlistedcourses\event\category_state_updated`.

`is_public(int)` / `are_public(array)` answer whether a category may be served to a visitor
who is not logged in: its own state public, its own row visible, every category on its path
existing and visible, and no category on that path unlisted. **Ancestors need not be
public** - the same composition the course side uses - and the most-specific rule decides
what is inside: an unlisted course in a public category is never public, and neither is an
unlisted subcategory. Like the course predicate it is viewer-independent, fail-closed,
non-throwing for an id that is not there, and four statements for any number of ids.

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

Site admins discover everything; visitors and guests discover nothing unlisted, without a
cohort or role query. With no category unlisted the whole predicate costs one query.

**The category term applies to listings only.** `access::filter_courses()` also drops a
course whose category is effectively unlisted unless the viewer is enrolled in it, has an
application pending or is staff of it; being able to self-enrol right now does not rescue
it. `is_course_discoverable()` keeps its meaning, because a theme gates the enrolment page,
the course info page and any landing page reached by a link on it, and a listing rule must not
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

### Defaults for new courses

Two settings, under *Site administration > Plugins > Local plugins > Course discoverability*,
decide what a course gets when nobody chose its state. Each offers listed or unlisted, and both
default to listed. Public is never a choice:
publishing needs `local/unlistedcourses:publish` of whoever creates or restores the course, and
a web service call, a CSV upload or a restore run by somebody without it would be refused.

- **Default discoverability for new courses** (`defaultstate`). The course form's select starts
  at it for a new course, and a course created without the select - by the course web service,
  `tool_uploadcourse`, an approved course request, `create_course()` from code, or a form shown
  to somebody who may not set course visibility - receives it. A value chosen on the form always
  wins, and updating a course never applies it.
- **Discoverability of a restored course whose backup has none** (`restoredefaultstate`). A
  backup taken on a site without this plugin carries no state; restored as a new course, it gets
  this one. A backup whose state is applied keeps it; a state this version does not know, or a
  public one refused because the restorer may not publish, gets the default too. A restore
  into an existing course leaves that course's state alone. A course created by a CSV
  upload from a template backup is created first, so it gets the default for new courses.

A category's state is written from one page, `local/unlistedcourses/category.php`, reached
from the category's settings menu (the "More" menu of a category page): core dispatches no
hook from the category form and has no custom field handler for categories. The page shows
who will still see the category - the cohorts defined at it, behind `moodle/cohort:view`,
the people holding a role here or above, and how many people it stays visible to besides
staff, intersected over every unlisted category above it - and warns when that is nobody and
when category themes are enabled. There is no backup or restore of a category's state:
Moodle has no category backup a plugin could attach to.

Its form follows the course form exactly: the "Public" option is offered only to a user
holding `local/unlistedcourses:publishcategory`, and a category that is already public shows
the control frozen with its value still submitting, so saving an unrelated change never
un-publishes it and never fails over a value the editor did not touch. For a public category
the page replaces the cohort and role accounting with what a visitor will be served, and
warns when an unlisted or hidden category on the path makes the public state inert.

### When a course or category is deleted

A course's row is dropped by an observer of `\core\event\course_deleted`, not by the
`before_course_deleted` hook: with asynchronous course deletion core runs the hook when the
deletion is requested and the course goes on existing, hidden, until the cron deletes it, and
the state must stay with it until then. A category's row is dropped from the
`pre_course_category_delete` and `pre_course_category_delete_move` callbacks in `lib.php`; when
a category's contents are moved, its children keep their own states.

## What this plugin does not do

**It renders nothing.** Overriding a course renderer is a theme privilege in Moodle -
`theme_config::renderer_prefixes()` returns only `theme_<name>` prefixes, and Moodle has no
`db/renderers.php` through which a plugin could substitute one. The course and category
listings, the gating of the enrolment and course info pages, and any public landing page served
to visitors who are not logged in belong to the site's theme, which consumes these predicates.
`theme_dimensions`, a Boost Union child
theme for Moodle 5.3, is the reference implementation.

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
longer - the course web services (`core_course_search_courses` is `ajax => true`, so any
logged-in browser session can call it), the mobile app, the Navigation block,
`block_course_list`, Boost Union smart menus, the report builder's category entity and the
course request page all name a category by their own route - and is written down, with what to
do about each, in [the developer guide](docs/developer-guide.md#73-it-does-not-cover-every-surface).
Unlisting is a listing rule, not a permission.

## Consequences worth knowing

The enrolment methods enforce their windows and places limits, so an unlisted course
disappears from the listings of somebody who could otherwise join it while its enrolment window
is shut or once it is full. That is the same answer core's own enrolment icons give, but it does
make the listing time-dependent.

A public course is armed but inert until the site's theme gives it a landing page: the
plugin only says "may be served anonymously", it serves nothing itself.

## For developers

[`docs/developer-guide.md`](docs/developer-guide.md) is the technical guide for theme and plugin
developers: the data model and who may write each transition, every public predicate with its
contract and query budget, the relationship and next-action vocabulary, the category semantics,
how a theme integrates the answers (listings, pages reached by a link, anonymous pages and files
on a site that forces login), worked examples from `theme_dimensions`, `block_compass` and
`local_dimensions`, and how to test an integration. The class docblocks in `classes/` remain the
source of truth.

## Settings

Under *Site administration > Plugins > Local plugins > Course discoverability*, both stored in
`config_plugins` and described in [Defaults for new courses](#defaults-for-new-courses):

- `local_unlistedcourses/defaultstate` (default: listed) - the state of a course created without
  one chosen.
- `local_unlistedcourses/restoredefaultstate` (default: listed) - the state of a course restored
  as a new course from a backup that carries none.

Each offers listed or unlisted; public is never a choice.

## Capabilities

| Capability | Context | Default | What it allows |
|---|---|---|---|
| `local/unlistedcourses:publish` | course | manager | Making a course public, or taking it out of the public state. `RISK_SPAM` and `RISK_PERSONAL`: the published page carries author-written text to the open web and can name participants. |
| `local/unlistedcourses:managecategorystate` | category | manager | Changing a category's state at all (listed, unlisted or public). |
| `local/unlistedcourses:publishcategory` | category | manager | Making a category public, or taking it out of the public state, on top of the previous one. `RISK_SPAM`. |

None of them is cloned from a core capability, so no upgrade back-fills them. Moving a course
between listed and unlisted needs no capability of this plugin: the select is offered to whoever
may set course visibility (`moodle/course:visibility`) on a form they may already save.

## Data stored

Two tables, each holding a row only for something in a non-default state:

- `local_unlistedcourses_state` - one row per unlisted or public course: the course, its state,
  the user who last changed the state (`usermodified`) and when (`timemodified`).
- `local_unlistedcourses_catstate` - the same for an unlisted or public course category.

A row lives as long as its course or category stays unlisted or public. It is deleted when the
state returns to listed, when the course is deleted (an observer of
`\core\event\course_deleted`) or when the category is deleted, and both tables are dropped when
the plugin is uninstalled. A course backup carries the course's state and nothing about who set
it. The two settings above are site configuration. Each state change fires an event
(`course_state_updated`, `category_state_updated`), which the site's log stores keep under their
own retention and privacy rules. The plugin stores no files and no user preferences.

The only personal data is `usermodified`, and the Privacy API covers it. A data request exports,
for every course and category whose state the user last changed, that state and the time of the
change. A deletion request detaches the user from the row (`usermodified` becomes 0) and leaves
the state in place: removing the row would un-hide or un-publish a course, or un-hide a category,
as a side effect of somebody leaving the site.

## Requirements

Moodle 5.2 on this branch (`MOODLE_502_STABLE`, release `v5.2-r1`). Moodle 5.3 is served by `main`,
identical to `MOODLE_503_STABLE`, release `v5.3-r1`. Moodle 4.5 and 5.1 are not supported.

## Installation

Copy the plugin to `local/unlistedcourses` in your Moodle directory (`public/local/unlistedcourses`
on Moodle 5.1 and later), or install the release zip from the Moodle plugins directory, then
visit *Site administration > Notifications* to run the installation.

## Translations

The English strings ship in the package. The Brazilian Portuguese translation is maintained in this
repository (`lang/pt_br`) and reaches sites through [AMOS](https://lang.moodle.org/), as the
plugins directory requires; it is left out of the release package.

## License

GNU GPL v3 or later, <http://www.gnu.org/copyleft/gpl.html>. Copyright 2026 Anderson Blaine.
