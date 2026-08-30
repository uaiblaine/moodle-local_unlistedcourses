# Claude instructions for `local_unlistedcourses`

Fleet-wide standards are in `~/dev/CLAUDE.md` (auto-loaded) — coding style, CI gates,
lang-string rules, the `mdl` environment, git rules. This file keeps only what is true
for this plugin.

A **local plugin** that owns one decision: may the current user learn that a given course
exists? It has no tables, no capabilities, no settings and no output. Moodle **5.2 only**
(`$plugin->supported = [502, 502]`); one CI job in `.github/workflows/ci.yml` — update it
when the range changes. Mounted on m502 at `local/unlistedcourses`.

```sh
mdl phpunit m502 local_unlistedcourses
mdl ci moodle-local_unlistedcourses --matrix
mdl mutate moodle-local_unlistedcourses \
  /Users/uaiblaine/dev/moodle-local_unlistedcourses/mutations/gates.conf
```

## Architecture gotchas

- **The rendering lives in `theme_boost_union_fundaseg`, and has to.** Overriding a core
  renderer is a theme privilege: `theme_config::renderer_prefixes()` builds only
  `theme_<name>` prefixes. **`db/renderers.php` does not exist in Moodle** — zero
  occurrences in the whole 5.2 tree; it is a Totara mechanism. Do not add one here.
- **`can_self_enrol()` is not a generic question.** `enrol_plugin::can_self_enrol()` is
  `return false` in the base class and only `enrol_self` overrides it in the whole of core.
  The fork `enrol_apply` answers through `allow_apply()` instead. A loop that asks every
  plugin through `can_self_enrol()` reports "cannot" for `apply` and hides the course from
  the very people it is open to. Dispatch per plugin, with `is_callable()`.
- **`=== true`, never `!== false`.** `can_self_enrol()` returns `true`, an error string, or
  **`null`** — the last when `customint5` names a deleted cohort, which
  `cohort_delete_cohort()` never clears. Only the strict comparison fails closed.
- **The applicant cap lives OUTSIDE `allow_apply()`.** Check `customint3` separately, as
  the theme's own hotsite resolver does.
- **A pending application is not an enrolment.** `enrol_apply` writes `user_enrolments`
  with a non-active status, so `is_enrolled(..., onlyactive: true)` is false for an
  applicant. `has_pending_enrolment()` therefore matches `status <> ENROL_USER_ACTIVE`, and
  the `<>` is load bearing: the first draft matched *any* row, which made it subsume the
  `is_enrolled()` short circuit — the suite stayed green and the mutation sweep is what
  caught it.
- **Staff must never lose a course.** Without the `is_viewing()` /
  `moodle/course:viewhiddencourses` escape, an unlisted course vanishes from the listing of
  the people who administer it, the site admin included. This was found by running the
  validation matrix, not by the suite; it has its own test now.
- **Never cache the answer across requests.** `tool_dynamic_cohorts` writes
  `cohort_members` in bulk without firing `cohort_member_added`/`removed` — its own comment
  says the bulk path "deliberately skips" them. Request scope only.
- **`is_enrolled()` returns true unconditionally for SITEID**, so the frontpage exemption is
  only observable through a guest, which is what its test uses.

## Testing notes

- **Every "is hidden" assertion carries a control** — a course or user that must still be
  visible in the same run. Without it the assertion passes just as happily when the
  predicate never ran.
- **`enrol_apply` is absent from a fresh test site's `enrol_plugins_enabled`.** It is a
  third-party plugin, so `enrol_get_instances($id, true)` filters its instances out and the
  predicate answers "cannot enrol" for a reason that has nothing to do with the applicant.
  `add_apply_enrol()` enables it first. This cost a debugging round.
- **Save custom field values as admin.** `instance_form_save()` runs the field list through
  `get_editable_fields()`, which silently drops anything the current user cannot edit — so
  saving as anyone else writes nothing and the test passes against an unmarked course.
- **Run the mutation sweep, not just the suite.** `mutations/gates.conf` currently holds
  seven guards and all seven redden. Adding a guard without adding a mutation for it is how
  the untested ones got in.
