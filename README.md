# Unlisted courses (local_unlistedcourses)

Answers one question, for one user, about one course: **may this person learn that this
course exists?**

A course is *unlisted* when an author ticks the `unlisted` course custom field this plugin
provisions. An unlisted course stays `visible = 1` in the database and behaves normally for
everyone entitled to it; it simply stops being named to anyone who is not.

Somebody is entitled to discover an unlisted course when any of these holds:

- they are actively enrolled;
- they have an application awaiting a decision (an `enrol_apply` row that is not active —
  without this term, applying would make the course vanish the moment you applied);
- they could enrol right now — `enrol_self::can_self_enrol()` or, for the fleet's
  `enrol_apply` fork, `allow_apply()` plus the applicant cap that lives outside it;
- they are staff — `moodle/course:view` or `moodle/course:viewhiddencourses` on the course.

The gate itself is native: `enrol.customint5`, the "only cohort members" field both enrol
plugins already carry. This plugin adds no gate of its own, no capability and no table.

## What this plugin does not do

**It renders nothing.** Overriding a course renderer is a theme privilege in Moodle —
`theme_config::renderer_prefixes()` returns only `theme_<name>` prefixes, and there is no
`db/renderers.php` in Moodle at all (that is a Totara mechanism). The listings and the
enrolment page are filtered by `theme_boost_union_fundaseg`, which consumes this predicate.

**It is current-user only.** `can_self_enrol()` and `allow_apply()` both read `$USER`
rather than taking a user id, so answering for an arbitrary user would mean reimplementing
both — and a reimplementation that drifts from the plugin it mirrors fails open.

**It caches nothing beyond the request.** The answer depends on cohort membership, and
`tool_dynamic_cohorts` writes `cohort_members` in bulk without firing
`cohort_member_added`/`removed`. A cache invalidated by those events would keep showing a
course to somebody just removed from the cohort that gated it.

**It does not cover every surface.** The predicate is only consulted where something calls
it. Course files served through `pluginfile.php`, badge pages, the report builder and the
web service layer each reach course names by their own route. See
`NOTES-visibilidade-cohort.md` in the fleet root for the measured inventory.

## Consequences worth knowing

Both enrol predicates enforce the enrolment window and the places limit, so an unlisted
course disappears from listings while its enrolment window is shut or once it is full. That
is the same answer core's own enrolment icons give, but it does make the listing
time-dependent.

## Requirements

Moodle 5.2. Brazilian Portuguese and English language packs.
