# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

Version `2026100502`, `MATURITY_STABLE`: the Moodle 5.3 branch (`MOODLE_503_STABLE`), numbered in
the 5.3 namespace (`20261005XX`, the core version 5.3.0 shipped with). It is the 5.2 code with its
own `version.php`, the single CI job against core `MOODLE_503_STABLE` and the README compatibility
line. The asynchronous-deletion gap that kept it alpha is closed by the course-deletion change
below, and a test that runs the real asynchronous path pins it. The enrolment fixes and the new
routes below are `2026100502`; the rest was `2026100501`.

### Added

- **Three more relationships: waitlisted, suspended and expired.** `access::classify_enrolment()`
  now tells an `enrol_apply` waiting-list row (status 2) that has not ended (`waitlisted`) from a
  fresh application (`pending`), a suspended row that has not ended (`suspended`), and a row whose
  end date has passed, whatever its status (`expired`), from no relationship at all. A row on a
  disabled instance, and one that ends before it starts, are still `none`; an application or a
  waiting-list row awaiting a decision is still judged by `enrol_apply`'s queue, whatever the
  instance says. `get_enrolment_state()` ranks them enrolled, scheduled, pending, waitlisted,
  suspended, expired, none, with the winning row's start and end dates (of two expired rows, the
  later end), so a surface can say when an enrolment starts or ended. A waiting-list row whose end
  date has passed is `expired`.
- **`access::get_next_action($courseid)`: what the current user may do now to join a course**,
  whatever their relationship with it: `open` with its routes (`self`, `apply`, `fee`, `paypal`,
  `autoenrol`), `guest` (`free`, or behind a `key`), `conditional` (an `enrol_coursecompleted`
  instance, for a viewer actively enrolled in its prerequisite), `blocked` with the most useful
  reason (`window`, `full`, `cohort`, `own_row`, `off`), or `none`. Each method is asked its own
  question, as `can_enrol()` did; public constants name every value. A visitor, the guest account
  and the site course are offered nothing. With `get_enrolment_state()`, `get_next_actions()` and
  `classify_enrolment()` it is the supported API other plugins read enrolment state through.
- **`access::get_next_actions($courseids)`: the same answer for a page of courses in at most four
  statements**, whatever the page holds, ported from the theme's listing classifier. It drops the
  two checks SQL cannot read, the self enrolment capability and `enrol_autoenrol`'s rule, so it may
  answer open where the per-course form answers blocked, never the reverse; it is never used to
  decide whether a course may be named.

### Changed

- **The relationships that keep an unlisted course discoverable are an explicit list**: enrolled,
  scheduled, pending and waitlisted. The rule used to be "anything but none", which would have
  opened unlisted courses to every suspended or expired viewer the moment those types existed. No
  listing changes: a waiting-list row was pending before, and suspended and expired rows were none.
- `can_enrol()` is now "the next action is open, guest or conditional", with the per-method rules
  unchanged. It does not work out why a method refuses, so a listing pays nothing for that.
- The help of the course and category discoverability settings names the waiting list, and says
  that a suspended or ended enrolment does not count.

### Fixed

- **An application whose end date has passed is no longer pending.** A row of an `enrol_apply`
  instance that is not active now counts as an application awaiting a decision only while its end
  date is unset or still ahead, which is `enrol_apply`'s own queue rule. An approved enrolment
  that the expiry sweep suspended after its end date read like a fresh application: it kept an
  unlisted course discoverable and the theme's course card said it was under review. It now
  keeps nothing (it is `expired`, see above). A row on the waiting list still keeps the course.
- **An `enrol_apply` instance on which the viewer already holds a row is no longer a way in.**
  `enrol_apply` takes no second application on that instance, so a learner whose approved
  enrolment had ended kept an unlisted course discoverable through an application they could not
  make.
- The help of the course and category discoverability settings names an enrolment that starts
  later as one of the relationships that keep an unlisted course visible.

### Changed

- **More enrolment methods are a way into an unlisted course.** Besides self enrolment and
  `enrol_apply`, an unlisted course is now discoverable to a logged-in user who could pay for it
  (`enrol_fee` and `enrol_paypal`: the enrolment window open, a price, no row of theirs on the
  instance), enter it as a guest (an enabled guest instance, with or without a key), be enrolled
  by `enrol_autoenrol` (the plugin's own `enrol_allowed()` rule), or who will be enrolled by
  `enrol_coursecompleted` once they complete another course (an instance inside its enrolment
  window, no row of theirs on it, and an active enrolment of theirs in the prerequisite course it
  names; anyone else has no tie to that course and does not find this one). These courses used to be hidden from exactly the people they
  were open to.
- **An enrolment that starts later is a relationship with the course.** A user enrolled with a
  start date still ahead (a manual enrolment scheduled by an administrator, say) is not "enrolled"
  to core until that date, which used to ghost an unlisted course for exactly the people who were
  told they belong to it. The relationship term now also counts an active row on an enabled
  instance whose `timestart` is ahead. A suspended future row, one on a disabled instance and an
  expired row still count for nothing.
- **`access::classify_enrolment()` and `access::get_enrolment_state()`** give a caller the rule
  and the dates: the type (`enrolled`, `scheduled`, `pending`, `none`) and the row's start and end
  dates. A theme that reads the enrolment rows itself calls the pure function on them, so the
  rule has one owner; `get_enrolment_state($courseid)` is the one-statement form for a page that
  holds no rows. `prime_relationships()` takes a third argument for scheduled courses.
- **A course's state row is dropped when core has deleted the course, not when the deletion is
  requested.** The `before_course_deleted` hook is replaced by an observer of
  `\core\event\course_deleted` (`db/events.php`). Core runs the hook once, when a deletion is
  requested, and does not run it again when the cron performs an asynchronous deletion, so a
  course whose deletion was queued lost its state at once and kept it lost if the deletion never
  finished.

## v5.2-r1 (2026042000) - 2026-10-02

First published release, for Moodle 5.2 only (`MATURITY_STABLE`). The plugin now numbers its
versions in the Moodle 5.2 namespace (`20260420XX`), as the fleet rule for one branch per Moodle
version requires, so the earlier date-based numbers and the releases `v5.2-r3` and `v5.2-r4`
(`2026090200` to `2026091301`) are gone: they were never published. Release notes below cover
everything built before the renumbering.

Removed: the upgrade steps and the migration of the retired "unlisted" course custom field into
the plugin's own table. A site that ran a pre-release build has to treat this as a fresh
install, and set its stored plugin version back to `2026042000` before upgrading.

Also in this release, from the review of the comments and tests before publication:

- **Only an enrol_apply application counts as pending.** The relationship term that keeps an
  unlisted course visible to someone who applied now matches inactive rows of `enrol_apply`
  instances only. A suspended manual or self enrolment no longer keeps the course visible: it is
  a decision already taken, not an application.
- The category preview says "visible to N people while it is unlisted" instead of "currently
  visible", because for a listed category the count is what would stay visible.
- Tests: the guest guard of `access::viewer_context()` is pinned without `enrol_apply`, the
  privacy userlist tests tell a detached row from a deleted one, the query-budget test measures a
  cold call as well as a warm one, the page-size test reads `category_preview::PERPAGE`, and the
  markup helper has a negative control.


### Added

- **A bulk statement can read every course's own state in its own SELECT** (version
  `2026091301`, release `v5.2-r4`): `discoverability::state_sql($coursealias)` returns the
  `LEFT JOIN` on the state table and a `COALESCE`d column for the caller to select and compare
  against the `STATE_*` constants. It exists because `get_states()` hands the ids it is asked
  about to the database as one `IN` list, which is the right shape for a page and the wrong one
  for a category subtree of thousands of courses - `get_in_or_equal()` never chunks - and the
  theme's category listing runs one statement over `{course}` anyway. Two rules travel with the
  helper and its test pins both: a course without a row reads as listed, and an unknown value in
  the table must be read as listed and never as public, so an anonymous caller compares
  `= STATE_PUBLIC` and never `<> STATE_UNLISTED`. Both aliases are checked against the shape of an
  identifier before they are interpolated.

- **Course categories have a third discoverability state: public** (version `2026091300`,
  release `v5.2-r3`). A public category's page may be served to visitors who are not logged
  in, and inside it **only the courses whose own state is Public are served to them** - an
  unlisted course and an unlisted subcategory stay withheld, and a listed course is not
  offered to a visitor at all, because a course reaches the internet when, and only when,
  somebody said so about that course. Entering or leaving the state needs a capability of its
  own, **`local/unlistedcourses:publishcategory`** (coursecat context, `RISK_SPAM`, manager by
  default, deliberately without `clonepermissionsfrom`), checked in
  `category_discoverability::set_state()` on top of the manage capability that already gates
  every real transition: hiding a category from listings is an editing act, publishing it to
  the open web is not, and folding the second into the first would grant the larger power
  through a rename. A save that changes nothing still needs neither.
- **The predicate that decides it**, `category_discoverability::is_public()` and its batched
  twin `are_public()`: own state public, own row visible, every category on the path existing
  and visible, no category on the path unlisted. **Ancestors need not be public** - the same
  composition `discoverability::is_public()` has always used for courses, so a site does not
  have to publish its whole root to publish one programme area. Viewer-independent,
  fail-closed, and non-throwing for an id that does not exist, because the ids reach it from
  an anonymous surface. `discoverability` gains the same batched twin, `are_public()`, and
  `is_public()` now delegates to it so the two can never disagree. Both cost four statements
  for any number of ids, measured by a test comparing 200 against 2.
- **`access::filter_courses_public()`**, the one predicate the anonymous surface may use: it
  keeps a course when `discoverability::are_public()` says so, preserves the caller's keys,
  and consults no viewer at all - the same answer for a visitor, an administrator and a
  crawler.
- **`access::prime_relationships()`**, a request-scoped primer for the relationship memo. A
  caller that has already read the viewer's `{user_enrolments}` in one statement hands the
  course ids over, and `filter_courses()` stops probing the enrolment tables one course at a
  time - the only part of a listing that was not flat, measured at 105 probed courses for 113
  statements. It writes **only** true, because the caller is vouching for a relationship it
  read and is not authoritative about the absence of one; it is keyed by the viewer, and
  `reset_caches()` drops it.
- **The editing page offers the third option**, gated on the new capability, and freezes the
  control persistently when the category is already public and the editor may not publish - so
  the value still submits and an unrelated save never un-publishes a category. Its preview
  panel gains a public branch: what a visitor is served, plus a warning when an unlisted
  ancestor or a hidden category on the path makes the public state inert. The privacy provider
  labels the new state, and the Behat generator step accepts `"public"`.

- **Course categories have a discoverability state of their own: listed or unlisted.** Stored
  in a second table, `local_unlistedcourses_catstate`, a row only for an unlisted category;
  read through `\local_unlistedcourses\category_discoverability` (`get_states()`,
  `get_state()`, `is_unlisted()`, `unlisted_ids()`) and written through its `set_state()`,
  **the one place the new capability `local/unlistedcourses:managecategorystate` is checked**
  (manager by default; deliberately not `moodle/category:manage`, which lets its holder
  rename, move and delete categories). A call that changes nothing needs no capability and
  fires nothing; every change fires `category_state_updated`. The row follows the category:
  core's `pre_course_category_delete` and `pre_course_category_delete_move` callbacks in
  `lib.php` drop it. The privacy provider covers the new table the way it covers the course
  one - a deletion request detaches the user, never the state. What an unlisted category
  withholds, and from whom, is the predicate and the theme-side filtering of the next stages;
  this stage is the state alone.
- **Who an unlisted category is named to, and what that withholds from a listing.**
  `\local_unlistedcourses\category_access` (`is_category_discoverable()`,
  `are_categories_discoverable()`, `filter_categories()`) answers per viewer, and the state is
  a property of the PATH: a category is effectively unlisted when it or any ancestor carries
  the row, and the viewer must satisfy EVERY unlisted category on that path, not just one of
  them. Satisfying one means belonging to a cohort whose context is that category's own,
  holding any role in its context or in an ancestor CATEGORY context, or holding
  `moodle/category:viewhiddencategories` there - core's own idiom for staff, which is what
  admits a manager or a course creator assigned at the system context. Cohort membership is
  read straight from `{cohort_members}`, never through `cohort_get_user_cohorts()`, so an
  invisible cohort still grants; a cohort at the system context grants nothing, and neither
  does a role at a course inside the category or at a category below it. Site admins discover
  everything, visitors and guests discover nothing unlisted, and with no category unlisted the
  whole predicate costs one query and answers yes. **The category term applies to LISTINGS
  only**: `access::filter_courses()` now also drops a course whose category is effectively
  unlisted, unless the viewer is enrolled in it, has an application pending, or is staff of
  it - being able to self-enrol right now does not rescue it, which is the whole point, since
  an open self-enrolment instance is the normal case inside such a category.
  `access::is_course_discoverable()` keeps its present meaning on purpose: it gates the
  enrolment page, the course info page and the public landing page, and a listing rule must
  not become an enrolment block. `discoverability::is_public()` does clamp, because an
  anonymous visitor can satisfy none of the three terms, so a public course inside an unlisted
  category has nobody it could be served to.
- **A page to set a category's state, and a preview of who that leaves it visible to.**
  `local/unlistedcourses/category.php`, reached from **Discoverability** in the category's
  own settings menu - core dispatches no hook on `course/editcategory.php` and categories
  have no custom field handler, so the control is a page of the plugin's own, hung on the
  `categorysettings` container that core sweeps into the category page's "More" menu
  (`local_unlistedcourses_extend_settings_navigation()` in `lib.php`, guarded on the context
  class, then the manage capability, then the container, in that order because it runs on
  every page of the site). The page carries what a field on the core form never could:
  `\local_unlistedcourses\output\category_preview` names the cohorts defined **at this
  category** with their member counts, counts the people holding a role here or in a category
  above, and states how many distinct people besides staff the category stays visible to -
  intersected with the eligible set of every unlisted category above it, because the predicate
  ANDs over the path and a cohort defined here admits nobody an ancestor withholds. It warns
  when an ancestor is already unlisted (the rules compound), when the category has no
  cohort of its own (a site-level cohort and an enrolment method's cohort restriction both
  grant nothing here - the mistake the string exists to pre-empt), when unlisting would leave
  the category visible to **nobody**, and when `allowcategorythemes` is on, since a theme set
  on the category would switch its pages away from the theme that withholds it. Cohort names
  are read only by a viewer holding `moodle/cohort:view`; the counts, which are a fact about
  the site rather than about the reader, are not gated on it. `category_state_updated` now
  links to this page instead of the category listing. **The capability check stays where it
  was**: `category_discoverability::set_state()` is the boundary, and the page checks the same
  capability only so that nobody is shown a form their save will refuse. Second version bump
  of this branch, deliberately - `get_plugin_list_with_function()` caches the callback list
  against the site's versions hash, so without it the navigation callback is simply not found.

### Changed

- **The discoverability state moves out of the course custom field and into the plugin's
  own table**, `local_unlistedcourses_state`, with three states: listed (the default, no
  row), unlisted, and public. A custom field cannot hold the public state safely
  (`customfield_select` stores the option's position, so reordering options reassigns every
  stored value, and the accident points toward publishing) and cannot enforce a capability
  on every write path. The upgrade copies every ticked "unlisted" checkbox into the table
  and retires the field and its category.
- User-facing strings now talk about discoverability rather than unlisted courses; the
  component name stays, because renaming a Moodle component means losing the data.
- `access` reads the state through `discoverability::get_states()`; its per-viewer
  predicate is otherwise unchanged.

### Added

- `\local_unlistedcourses\discoverability`: `get_states()`, `get_state()`, `is_unlisted()`,
  `is_public()` and `set_state()`. **The publish capability is checked inside
  `set_state()`**, the one place every writer goes through - the course form, the course
  web service, `tool_uploadcourse` and course restore. Entering or leaving the public state
  requires it; a call that changes nothing needs nothing. `is_public()` also requires the
  course and every category on its path to be visible, because it is the gate for a page
  served to visitors who are not logged in.
- Capability `local/unlistedcourses:publish` - course context, manager only, `RISK_SPAM |
  RISK_PERSONAL`, and no `clonepermissionsfrom`, so nothing back-fills it from
  `moodle/course:update`.
- A "Discoverability" select in the course settings form, right after "Course visibility".
  "Public" is offered only to a user who may publish; a course that is already public shows
  the control frozen with its value still submitting, so saving an unrelated change never
  un-publishes it.
- Event `\local_unlistedcourses\event\course_state_updated`, fired on every change with the
  old and new states.
- Course backup and restore of the state, written for every course so that a restore which
  overwrites course configuration resets it the way core resets every other setting. The
  restore writes through `set_state()`: a restoring user who may not publish is refused
  there, the refusal is logged, and the target keeps the state it had. Course duplicate
  carries the state.
- A full privacy provider: the table records who last changed each state, and a deletion
  request detaches the user rather than removing the state.
- Ten more mutations in `mutations/gates.conf`, one per new guard; the existing
  `unlisted_lookup` mutation targets the new lookup.
- A Behat feature for the form control: what a teacher and a manager are offered, and that
  a teacher saving an unrelated change leaves a public course public.

### Removed

- The `unlisted` course custom field and the class that provisioned it. The upgrade
  carries its ticked rows over and deletes the definition; nothing else reads it.

### Fixed

- **Retiring the `unlisted` custom field left the files of its description behind.** The upgrade
  deleted the field with plain deletes and skipped the cleanup core does for a field's description
  file area, so any file embedded in that description stayed orphaned. The upgrade now deletes that
  file area before the field.

- **A course whose enrolment places had been freed by expiry still showed as closed.** The check
  for "this course is full" was re-implemented here rather than asked of `enrol_apply`, and that
  copy counted enrolments whose period had already run out. Since the plugin changed its own
  answer, the two disagreed: `enrol_apply` offered the button and accepted the application while
  this surface went on treating the course as full. The question is now put to the plugin, so the
  two cannot drift again.

### Added

- First release. Provisions the `unlisted` course custom field and exposes
  `local_unlistedcourses\access`, the predicate that answers whether the current user may
  discover a given course: enrolled, awaiting a decision on an application, able to enrol,
  or staff. A course that does not carry the flag is always discoverable, so the plugin is
  inert until an author marks something.
- The field is provisioned with `NOTVISIBLE` visibility, so it never appears on a course
  card — printing "Unlisted course: Yes" would announce exactly what the flag withholds.
- A mutation spec under `mutations/` (not shipped in the release zip). `mdl mutate
  moodle-local_unlistedcourses mutations/gates.conf` breaks each guard in turn and reports
  which tests go red; every guard is currently held by at least one test.
