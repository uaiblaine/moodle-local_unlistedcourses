# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Added

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
