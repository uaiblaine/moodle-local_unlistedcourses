# Claude instructions for `local_unlistedcourses`

Fleet-wide standards are in `~/dev/CLAUDE.md` (auto-loaded) — coding style, CI gates,
lang-string rules, the `mdl` environment, git rules. This file keeps only what is true
for this plugin.

A **local plugin** that owns one decision per course: who may learn that it exists —
listed, unlisted, or public (served to visitors who are not logged in, under
`forcelogin = 1`). One table (`local_unlistedcourses_state`, a row only for non-default
states), one capability (`local/unlistedcourses:publish`), one event, no settings, no
output. **The component name is narrower than its scope** — it was born as "unlisted
courses" and now owns "public" too; renaming a component means uninstall + reinstall, so
the name stays and the strings talk about discoverability. Moodle **5.2 only**
(`$plugin->supported = [502, 502]`); one CI job in `.github/workflows/ci.yml` — update it
when the range changes. Mounted on m502 at `local/unlistedcourses`. The implementation
brief for the public-page work, with the measured facts it rests on, is
`docs/discoverability/README.md` (export-ignored).

```sh
mdl phpunit m502 local_unlistedcourses
mdl behat m502 @local_unlistedcourses
mdl ci moodle-local_unlistedcourses --matrix
mdl mutate moodle-local_unlistedcourses \
  /Users/uaiblaine/dev/moodle-local_unlistedcourses/mutations/gates.conf
```

## Architecture gotchas

- **The capability check lives in `discoverability::set_state()` and nowhere else.** The
  course form is one of four writers — the web service and `tool_uploadcourse` dispatch the
  same `after_form_submission` hook (from `create_course()` / `update_course()`, before the
  course row is written), and restore calls the class directly. A check in the form is a
  check three of them route around. Only transitions that enter or leave PUBLIC are gated,
  and both directions need the same capability: un-publishing is the publishing decision
  reversed. Listed↔unlisted is deliberately ungated here — every caller is already behind
  `moodle/course:update` or the restore capabilities, and core itself applies `visible` on
  restore without asking `moodle/course:visibility`; a second gate would only ever refuse a
  restoring teacher, in the un-hiding direction.
- **A call that changes nothing needs no capability.** That is what lets the course form
  re-submit "public" from a frozen control when an editor who may not publish saves an
  unrelated change. Remove the short circuit and that save throws.
- **The frozen control must persist.** `$mform->freeze()` on its own exports the element's
  DEFAULT, not its value (`formslib.php:2410`); `setPersistantFreeze(true)` before the
  freeze renders a hidden input carrying the current value. `hardFreeze()` sets persistence
  off explicitly and must not be used here.
- **`is_public()` reproduces core's visibility answer instead of calling it.**
  `core_course_category::can_view_course_info()` ends in `has_capability()`, and
  `accesslib.php:475-477` hard-denies every capability for user id 0 while `forcelogin` is
  on. So the course row and the whole category path are checked directly, with no
  capability involved. A missing ancestor fails closed. It is a property of the course, not
  the viewer, unlike everything in `access`.
- **`access` still reads the state through `discoverability::get_states()`**, one query per
  request; `access::reset_caches()` resets both caches, and `set_state()` calls it.
- **Restore goes through `set_state()` and nothing else — no clamp ahead of it.** The
  write is `set_state($courseid, $state, $userid)` with the task's user, not `$USER`, so a
  restorer who may not publish is refused inside the gate; the refusal is caught and logged
  (`restore_publicclamped` when the backup was public, `restore_statenotapplied` otherwise),
  and the target keeps the state it already had — listed for a new course, unchanged for an
  overwrite. The first draft clamped an incoming PUBLIC to listed before calling
  `set_state()`; the adversarial review showed that on an overwrite restore into an UNLISTED
  course by a non-publisher the clamp turned a refusal into an un-hiding write (DEFAULT from
  UNLISTED passes the gate), and that on a new course it was untestable because `set_state()`
  refuses the same way. Removed.
- **The backup writes the element for EVERY course, listed included** (`set_source_sql` with
  `COALESCE(s.state, 0)`). Core processes `course.xml` only into a new course or when
  "overwrite course configuration" is on (`restore_course_task::build()`), and then rewrites
  every setting from the backup; an absent element would mean "keep the target's state", so a
  listed backup restored over an unlisted course would leave the stale state behind. With the
  element always present the state follows exactly core's rule for `visible`. Course
  duplicate (`MODE_SAMESITE`, new course) carries it too.
- **On a new course the form asks what the creator WILL hold**, through core's own
  `guess_if_creator_will_have_course_capability()` (the call behind the core visibility
  select): the category context is all there is, the creator's role in the course does not
  exist yet, and a course creator gains `moodle/course:visibility` only through
  `creatornewroleid`. A plain `has_capability()` hid the control from course creators.
- **The site course has no state.** `set_state()` refuses it; the form skips it; a row
  written by hand for it is what `access::eligible()`'s SITEID exemption exists for, and
  its test writes exactly that row.
- **An unknown stored value reads as listed, never as public.** Both `get_states()` and
  `set_state()`'s "current" read normalise it; the restore skips it.
- **`core_customfield\handler::reset_caches()` is test-only** — it throws
  `coding_exception` outside PHPUnit, and it took the first `mdl upgrade` down mid-step
  (after the migration had run and before the savepoint). `legacy_field::remove()` uses
  plain `$DB` deletes and no cache reset for that reason. The step is idempotent, which is
  what made the second run clean.
- **Between deploying the code and running the upgrade, every course form is a 500.** The
  hook calls `has_capability('local/unlistedcourses:publish')`, the capability is not
  installed yet, `debugging()` fires, and Whoops turns it into an exception. Not a bug to
  fix in the plugin — it is the standard "upgrade pending" window — but it is what a 500 on
  `course/edit.php` means on a stack whose mounted code is newer than its site.
- **Never cache the answer across requests.** `tool_dynamic_cohorts` writes
  `cohort_members` in bulk without firing `cohort_member_added`/`removed`. Request scope
  only.
- The `access` predicate gotchas that predate the state table — `can_self_enrol()` is not
  generic, `=== true` never `!== false`, the applicant cap lives outside `allow_apply()`, a
  pending application is not an enrolment, staff must never lose a course,
  `is_enrolled()` is unconditionally true on SITEID — are documented in that class's
  docblocks and held by `tests/access_test.php`.

## Testing notes

- **Every refusal has a control**: the same call succeeds for somebody who may make it, in
  the same test. Every "still in state X" assertion follows a refused attempt to leave it.
- **The hook wiring is tested through core**: `courseform_test` calls `create_course()` and
  `update_course()` with the element set and asserts the state — that is what proves
  `db/hooks.php` is registered, not the unit tests of `courseform` itself.
- **Backup/restore tests mirror core's helper** (`moodle2_test::backup_and_restore()`): an
  import-mode backup is not zipped, and a general-mode restore into a new course runs
  `restore_check::check_security()` for the given user. A restoring teacher gets
  `editingteacher` **at the category**, which grants the restore capabilities in the new
  course too; a manager role there would also grant publish and void the clamp test.
- **`enrol_apply` is absent from a fresh test site's `enrol_plugins_enabled`** —
  `add_apply_enrol()` in `access_test` enables it first.
- **Run the mutation sweep, not just the suite.** `mutations/gates.conf` holds nineteen
  guards, and each must redden a test. Adding a guard without a mutation is how untested
  ones get in. The first sweep found one that reddened nothing — the restore clamp above —
  and it turned out to be wrong code, not a missing test.
- **Restore into an EXISTING course is a different path from restore into a new one**, and
  the review found both defects above on it: `TARGET_EXISTING_ADDING` with `overwrite_conf`
  on, once as a manager and once as a teacher without publish. `backup_restore_test` covers
  it; do not let a future edit collapse it into the new-course helper.
- **Never edit a file while `mdl mutate` is sweeping it.** The sweep restores each file from
  a snapshot taken at the start and refuses to overwrite content it did not write; an edit
  made mid-sweep is either lost to the restore or left with the last mutation still applied.
  Wait for the exit marker, then diff.
- `theme_boost_union_fundaseg` couples to this plugin: its `redirector_test` and
  `unlisted.feature` set the flag through the retired custom field and go red on a stack
  where this version is installed — they move to `set_state()` / the state table with the
  theme's own stage of this work.
