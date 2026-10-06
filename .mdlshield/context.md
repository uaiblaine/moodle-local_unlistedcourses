# Review context for local_unlistedcourses

`local_unlistedcourses` ("Course discoverability") owns one decision per course and per course
category: who may learn that it exists. There are three states: listed (the default, no row),
unlisted (named only to people entitled to it) and public (the page may be served to visitors
who are not logged in, on a site that keeps `forcelogin` on). It is an API plus the places that
write the state; **it renders nothing**: listings, the enrolment page and any public landing
page belong to a theme, which calls the predicates. Moodle 5.2 only, on one branch, maturity
alpha. Two tables: `local_unlistedcourses_state` and `local_unlistedcourses_catstate`, a row only
for a non-default state, each recording `usermodified`.

## Who is trusted

- Site administrators are fully trusted.
- Three capabilities, all manager-only by default and declared without `clonepermissionsfrom`,
  so no upgrade back-fills them from an existing one. `local/unlistedcourses:publish` (course
  context, `RISK_SPAM | RISK_PERSONAL`) enters or leaves the public state of a course.
  `local/unlistedcourses:managecategorystate` (category context, no risk bitmask) sets any
  category state. `local/unlistedcourses:publishcategory` (category context, `RISK_SPAM`)
  enters or leaves the public state of a category.
- Setting a course to or from unlisted needs no capability of its own here: every route to it is
  already behind `moodle/course:visibility` on the form, or the update and restore capabilities.
- The viewer is untrusted. A viewer may discover an unlisted course only through a relationship
  with it (enrolled, also from a later date; application pending; staff; able to enrol right
  now; or due to be enrolled on completing another course) and an unlisted
  category only through a cohort at that category, a role there or above, or
  `moodle/category:viewhiddencategories`.
- Anonymous visitors are untrusted and only ever meet `is_public()` / `are_public()` and
  `access::filter_courses_public()`, which read no viewer.

## Surfaces

- **No web service of its own** (no `db/services.php`), no scheduled or adhoc task, no
  observer, no settings and no file serving.
- One page script, `category.php`: `require_login()`, then `core_course_category::get($id,
  MUST_EXIST)`, then `require_capability(managecategorystate)`; its form saves through
  `category_discoverability::set_state()`. A settings-navigation node leads to it for holders
  of the capability. One template, `category_preview.mustache`.
- Hooks: `after_form_definition` and `after_form_submission` (the course form's
  "Discoverability" select, also reached by the course web service and `tool_uploadcourse`
  because core dispatches the hook from `create_course()` and `update_course()`) and
  `before_course_deleted`. `lib.php` also cleans a category's row on core's category delete
  and delete-move callbacks. Two events, `course_state_updated` and `category_state_updated`.
- Course backup carries the state for every course; restore writes it through `set_state()`
  with the restoring user. Categories have no backup in core, so none here.
- Privacy provider: full (metadata, request, userlist). A deletion request detaches the user
  from `usermodified` and keeps the row, because deleting it would un-hide or un-publish
  something as a side effect of a person leaving.

## Facts that look like findings but are by design

- **The publish capability is checked in `discoverability::set_state()` and nowhere else.**
  The form is one of four writers; a check in the form would be routed around by the others.
  A call that changes nothing needs no capability, which lets the form re-submit a frozen
  "public" value for an editor who may not publish. The same holds for the category class,
  where the manage capability is also checked on every real transition.
- **Restore never clamps; it calls `set_state()` with the task's user.** A restorer who may not
  publish is refused inside the gate, the refusal is logged, and the target keeps its state.
- **Unlisting is a listing rule, not a permission.** An unlisted course stays `visible` and
  works through a direct link; course web services, the mobile app, navigation blocks and the
  report builder name courses by their own routes. Do not read that as a leak in this plugin.
- **`is_public()` reproduces core's visibility answer instead of calling it**, because core's
  ends in a capability check that denies user id 0 under `forcelogin`. It is non-throwing and
  fails closed for a missing course or ancestor, and the viewer is not in its memo key.
- **The category term lives only in `access::filter_courses()`.** Moving it into
  `is_course_discoverable()` would turn a listing rule into an enrolment block, because the
  theme ghosts the enrolment and course info pages off that method.
- **`access` answers for the current user only and caches only within the request**, because
  bulk cohort writes fire no event a cache could be invalidated by.
- **The category page shows cohort names only with `moodle/cohort:view`**, while its counts
  are computed over every cohort at the category and deliberately not gated on it, so that
  "is anybody left" does not depend on who is reading.
- **`$PAGE->set_category_by_id()` checks nothing**, so the `get()` ahead of it in `category.php`
  is the visibility gate. Reordering them is a finding.
- A public course or category is inert until a theme serves it; the plugin only says "may".

## De-emphasise

- `docs/**`, `mutations/**`, `lang/**` and `tests/**` carry no production behaviour.
- Wording and layout of `category_preview.mustache`, unless it shows names or counts to a
  reader who may not see them.
