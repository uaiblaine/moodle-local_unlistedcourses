# Claude instructions for `local_unlistedcourses`

Fleet-wide standards are in `~/dev/CLAUDE.md` (auto-loaded) — coding style, CI gates,
lang-string rules, the `mdl` environment, git rules. This file keeps only what is true
for this plugin.

A **local plugin** that owns one decision per course: who may learn that it exists —
listed, unlisted, or public (served to visitors who are not logged in, under
`forcelogin = 1`) — and **the same three states per course category**. Two
tables (`local_unlistedcourses_state`, `local_unlistedcourses_catstate`, a row only for
non-default states), **three capabilities** (`local/unlistedcourses:publish`,
`local/unlistedcourses:managecategorystate`, `local/unlistedcourses:publishcategory`), two
events, two settings (the default state of a new course and of a course restored from a
backup without one, listed or unlisted, never public), one page
(`category.php`, the category's editing surface, with one template for its preview). **The
component name is narrower than its scope** — it was born as "unlisted courses" and now
owns "public" and categories too; renaming a component means uninstall + reinstall, so the
name stays and the strings talk about discoverability. The design record of the category
work is `docs/discoverability/README.md`, sections 14 (unlisted categories) and 15
(public categories). Moodle **5.2 only**
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

## Agent orchestration budget (fleet rule, repeated here on purpose)

Section 6 of `~/dev/CLAUDE.md` (`moodle-dev/CLAUDE.fleet.md`) is the authority and says why.
This short copy reaches sessions that do not load that file: cloud sessions and checkouts
outside `~/dev`. Every subagent gets the model and effort of its role from its agent
definition, and none runs on the session model.

| Role | model | effort | agent |
|---|---|---|---|
| Mechanical sweeps, greps, renames, counts, log reading | `haiku` | `medium` | `fleet-sweeper` |
| Checklists against evidence (handoff counts, spec lines against a sweep log, lang lockstep) | `haiku` | `high` | `fleet-checker` |
| Readers, measurers, graders | `sonnet` | `medium` | `fleet-reader` |
| Refuters and verifiers of a blocking finding | `sonnet` | `high` | `fleet-verifier` |
| Well-scoped implementation (established cause, settled design, written recipe) | `sonnet` | `medium` | `fleet-fixer` |
| Non-trivial implementation (open design, several files, long tasks) | `opus` | `high` | `fleet-implementer` |
| Consolidators, critics, estimators, ADR and documentation drafters | `opus` | `high` | `fleet-synthesist` |

- Launch the `Agent` tool with `subagent_type: "fleet-*"`; it has no `effort` parameter, so
  the role's effort comes from that definition (`mdl claude-setup` installs them). Where they
  are not installed, pass `model`. Aliases only; never `fable`; `xhigh` only for a long-horizon
  implementer whose prompt says why; never `xhigh`/`max` on Sonnet or Haiku.
- No long command inside a subagent: `mdl ci --matrix`, `mdl mutate` and Behat run from the
  main session in a background Bash command; a subagent runs the fast gate its prompt names and
  reports the command with its counts.
- Workflows only on the user's opt-in, every `agent()` with `agentType: 'fleet-*'`, under 10
  agents. Advisor off by default.

## Versions

`main` is the 5.2 branch (`supported = [502, 502]`, `MATURITY_STABLE`) and numbers itself in the 5.2
namespace, `20260420XX`: the first release, `v5.2-r1`, is `2026042000` (its `$plugin->requires`),
and every later change that needs a bump adds 1 to that counter. The 5.3 branch is
`MOODLE_503_STABLE` (`20261005XX`) and counts on its own; 4.5 and 5.1 are not
supported. Upgrade steps stay inside the namespace of the branch they live on. The earlier
date-based numbers (`2026090200` to `2026091301`, releases `v5.2-r3` and `r4`) were never
published and no longer exist; a stack or site that ran them needs its stored version set back
before `mdl upgrade` (`php admin/cli/cfg.php --component=local_unlistedcourses --name=version
--set=2026042000`). The rule is in the fleet file, "Versioning / upgrade discipline".

## Architecture gotchas

- **`is_public()` and `are_public()` live on the STATE classes, never on `access` or
  `category_access`.** Everything in those two predicates is an answer about a viewer — a
  cohort membership, a role assignment, a capability — and "public" is a property of the
  thing, composed from the stored state and from rows of the course and category tables with
  no capability involved (`accesslib.php:475-477` hard-denies every capability for user id 0
  under `forcelogin`). Putting the anonymous answer beside the per-viewer one invites a
  viewer term into an answer that must not have one. `access::filter_courses_public()` is the
  single exception and proves the rule: a listing filter that asks the state class and reads
  no `$USER` at all — it is the ONLY predicate the anonymous shell may use.
- **A category has three states too, and the second capability is what makes the third one
  safe.** `category_discoverability::set_state()` checks `CAPABILITY_MANAGE` on every real
  transition AND `CAPABILITY_PUBLISH` on every transition that enters or leaves PUBLIC, in
  that order. A category is effectively public when its own state says so, it is visible,
  every ancestor exists and is visible, and no ancestor is unlisted — ancestors need NOT be
  public (D15). The most-specific rule decides the rest: an UNLISTED course inside a PUBLIC
  category is never public, and neither is an UNLISTED subcategory.
- **`are_public()` is four statements for any N, and `is_public()` delegates to it** on both
  classes, so the two can never disagree. It is memoised per id for the request with NO
  viewer in the key, and it is **non-throwing**: the ids reach it from an anonymous surface,
  so a missing course or category is an answer (false) and never an exception — every read
  is a plain one, no `MUST_EXIST` anywhere. Because it is memoised, a test that writes
  `visible` straight to a table must reset the caches before it re-reads.
- **`access::prime_relationships()` only ever writes TRUE.** A caller that has read the
  viewer's whole `{user_enrolments}` in one statement hands the course ids over and
  `has_course_relationship()` answers from memory — which is the one part of
  `filter_courses()` that is not flat (measured: 105 probed courses, 113 statements). The
  caller is vouching for a relationship it read; it is NOT authoritative about the absence of
  one, because being staff of a course is a relationship too and no enrolment table carries
  it. Viewer-keyed, dropped by `reset_caches()`.
- **The capability check lives in `discoverability::set_state()` and nowhere else.** The
  course form is one of four writers — the web service and `tool_uploadcourse` dispatch the
  same `after_form_submission` hook (`create_course()` dispatches it after the course row is
  inserted, `update_course()` before the row is updated), and restore calls the class directly. A check in the form is a
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
  DEFAULT, not its value (`formslib.php:2410`). Both forms set that default to the current
  state, so a plain freeze would resubmit the same state, but the persistent freeze makes the
  submitted value explicit: `setPersistantFreeze(true)` before the freeze renders a hidden
  input carrying the current value. `hardFreeze()` sets persistence
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
  `COALESCE(s.state, 0)`). Core processes `course.xml` only into a new course, when
  "overwrite course configuration" is on, or on a `tool_uploadcourse` template restore
  (`restore_course_task::build()`), and then rewrites the course settings from the backup; an
  absent element would mean "keep the target's state", so a listed backup restored over an
  unlisted course would leave the stale state behind. With the element always present the state
  follows core's rule for `visible`, with one difference: a template restore keeps a `visible`
  column given in the CSV over the template's, and no CSV column does the same for this state. Course
  duplicate (`MODE_SAMESITE`, new course) carries it too.
- **The two defaults ride on seams core already calls; neither has a hook of its own.** Creation:
  `create_course()` dispatches `after_form_submission` with `isnewcourse = true`
  (`course/lib.php:1908-1910` on 5.3), `update_course()` without it, so `courseform::save()` gives a
  new course without the element `defaultstate` and leaves an update alone; a form value always
  wins because it is the same call. Restore: core inserts the course row itself, so no creation
  hook fires; `after_execute_course()` runs after course.xml is parsed whether or not the element
  was in it (`restore_structure_step::execute()` -> `launch_after_execute_methods()`), and
  `process_local_unlistedcourses_state()` records that it ran. Only an absent element on
  `TARGET_NEW_COURSE` gets `restoredefaultstate`. A `tool_uploadcourse` template restore is
  `create_course()` then `TARGET_CURRENT_ADDING`, so it gets the creation default.
- **On a new course the form asks what the creator WILL hold**, through core's own
  `guess_if_creator_will_have_course_capability()` (the call behind the core visibility
  select): the category context is all there is, the creator's role in the course does not
  exist yet, and a course creator gains `moodle/course:visibility` only through
  `creatornewroleid`. A plain `has_capability()` hid the control from course creators.
- **The site course has no state.** `set_state()` refuses it; the form skips it; a row
  written by hand for it is what the SITEID exemption in `access::compute_course_relationship()`
  exists for, and its test writes exactly that row.
- **An unknown stored value reads as listed, never as public.** Both `get_states()` and
  `set_state()`'s "current" read normalise it; the restore skips it.
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
- **The relationships that keep an unlisted course are an allow-list**
  (`access::DISCOVERABLE_RELATIONSHIPS`: enrolled, scheduled, pending, waitlisted), never
  "anything but none". A relationship type added later stays hidden until it is put there on
  purpose; suspended and expired are deliberately out. `next_action_test` runs every row shape
  through both halves of the rule (the unlisted predicate and the category-term escape).
- **The next action has two implementations, and only one of them may decide
  discoverability.** `get_next_action()` asks each enrol plugin; `get_next_actions()` reads
  the same rules from at most four bulk statements and drops what SQL cannot read (the self
  enrolment capability, autoenrol's rule), so it errs towards open by design. Wiring the batch
  into `eligible()` or `filter_courses()` would name unlisted courses to people the plugins
  refuse. Its statement-count and parity tests are in `next_action_test`.
- **`can_enrol()` evaluates without reasons** (`evaluate_next_action($courseid, false)`): a
  listing probes unrelated unlisted courses, every one refused by something, and must not pay
  statements to explain refusals nobody reads. `test_the_listing_does_not_pay_for_the_reason_of_a_refusal`
  holds it.
- **The lapsed waiting-list branch of `classify_enrolment()` returns what the end-date test
  after it would** (expired, the owner's decision of 2026-10-06). It is kept so the waiting
  list's rule reads in one place; its gate flips it to waitlisted rather than deleting it,
  because deleting it reddens nothing.
- **`get_enrolment_state()`, `get_next_action()`, `get_next_actions()` and
  `classify_enrolment()` are the supported API** (the class docblock says so): the theme, Compass
  (a hard dependency) and `local_dimensions` (behind its optional provider switch) read enrolment
  state through them. Their shapes and constant values are a contract; change them only with
  those consumers.

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
  course too; a manager role there would also grant publish and void the test that a restorer who
  may not publish is refused.
- **`enrol_apply` is absent from a fresh test site's `enrol_plugins_enabled`** —
  `add_apply_enrol()` in `access_test` enables it first.
- **Run the mutation sweep, not just the suite.** `mutations/gates.conf` holds a hundred and
  five guards, and each must redden a test. The apply, autoenrol and coursecompleted gates need a
  stack that mounts those plugins: m503 and m503b mount autoenrol and coursecompleted, and no
  5.3 stack mounts enrol_apply, whose supported range ends at 5.2. `mdl ci`, and so
  `mdl mutate --fast`, installs none of them, skips their tests and reads those gates as held
  by nothing. Adding a
  guard without a mutation is how untested ones get in. The first sweep found one that reddened nothing — the restore clamp above —
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
  `unlisted.feature` write through `set_state()` as admin, and its renderer and
  `after_config` hook consume `access::filter_courses()` and `access::is_course_discoverable()`.
  A change to either predicate's meaning is a change to that theme's tests too.
- **Every per-viewer memo is keyed by the viewer as well as the thing asked about.**
  `setUser()` in a test and "log in as" on the site switch `$USER` inside one request, and a
  memo keyed by the course alone handed the first viewer's answer to the second. The review
  of the category predicate found the course memo doing exactly that; `course_memo_viewer`
  and `cat_memo_viewer` are the gates, and `access::memo_key()` is the one place the key is built.
- **A category's state is a property of the PATH, and the quantifier over the path is AND.**
  `category_access` reads `course_categories.path`, and for every unlisted category on it the
  viewer must satisfy one of three terms: a cohort whose context IS that category's own (never
  an ancestor's, never the system one, and read from `{cohort_members}` with no `visible`
  filter, because `cohort_get_user_cohorts()` filters `visible = 1` and an invisible cohort
  still grants); a role at that category or an ancestor CATEGORY context, matched against the
  path prefix up to that category so a role below it grants nothing; or
  `moodle/category:viewhiddencategories` there. The empty-set fast path — one query when no
  category is unlisted — is load-bearing and has its own gate, because an optimisation that
  can silently disable the feature must not be free to.
- **The category term lives in `access::filter_courses()` and nowhere else.** Never move it
  into `is_course_discoverable()`: the theme's `after_config` hook ghosts `enrol/index.php`,
  `course/info.php` and the hotsite off that method, so a listing rule placed there becomes an
  enrolment block on a platform whose product is enrolment. The three escapes from the term
  are relationships with the course — enrolled, application pending, staff — and `can_enrol()`
  is deliberately not one of them.
- **`$PAGE->set_category_by_id()` checks nothing.** It reads the raw record. On
  `category.php` the `core_course_category::get($id, MUST_EXIST)` call is the only visibility
  gate and must stay ahead of it.
- **`local_unlistedcourses_extend_settings_navigation()` is found through
  `get_plugin_list_with_function()`, which caches by the versions hash.** Adding or renaming
  a plugin callback in `lib.php` needs a version bump, or the callback is not found for a
  reason that reads as a code fault.
- **`cohort_get_cohorts()` returns `['totalcohorts', 'cohorts', 'allcohorts']`, paginated, and
  checks no capability.** The preview passes an explicit page size, reads that shape, and sits
  behind `moodle/cohort:view` for the NAMES only; the counts are computed over every cohort at
  the category, gated on nothing, because "is anybody left" must not depend on who is reading.
  Names go into a double stash in the plain spelling. The visible-to-N count intersects the
  eligible set of every unlisted ancestor, as the predicate ANDs them.
- **The mustache lint sees ONE branch of `category_preview.mustache`.** It renders a
  template against the single `Example context (json)` block in its docblock and
  validates the HTML that comes out; `public` and its inverse are mutually exclusive, so
  whichever context the block carries, the other branch's markup is never parsed by the
  gate. The block carries the unlisted branch; `category_preview_test` renders the public
  one and asserts the fragment parses, which is what stands in for the lint there.
- **There is no backup or restore of a category's state.** Moodle has no category backup a
  plugin could attach to; the row follows the category through core's
  `pre_course_category_delete` and `pre_course_category_delete_move` callbacks in `lib.php`.
- **Core's Behat cohort generator places a cohort in a category context** through
  `contextlevel | reference` columns (`behat_core_generator::preprocess_cohort()`); the
  plugin's own context file, `tests/behat/behat_local_unlistedcourses.php`, provides only the
  category-state step, and carries no `MOODLE_INTERNAL` guard for the reason the fleet file
  gives.

## MDL Shield reviews

Pull request reviews by MDL Shield are **manual only**. Comment `!mdlshield review` (what is
new since the last review) or `!mdlshield review full` (the whole pull request) on a pull
request; the commands work for the repository owner and the `trusted_users` of
`.mdlshield/config.yml`, and a forced command ignores the filters and a draft's silence.
Nothing runs on its own because `include.branches` is an empty list, which MDL Shield reads
as a rule that matches no branch.

- `.mdlshield/config.yml` and `.mdlshield/context.md` are read **from the default branch
  only** (`main`), so a pull request cannot change the rules that judge it, and the pull
  request that first adds them is judged by the website settings instead. Both are
  `export-ignore`d and never reach the release zip. The file overrides the website for each
  key it names; an invalid file stops reviews for the repository until it is fixed, and an
  unknown key only raises a warning on the summary comment.
- **One Moodle version per repository.** There is no per-branch mapping, so the file pins
  `moodle.versions: ["5.2"]`. A pull request that targets another branch of this repository is reviewed against that same core.
- `fail_on.severity` is `high`: the check fails on an open finding at or above it. Code
  quality findings never block, only security findings do.
- The context file is project knowledge that **influences** the reviewer and is not a
  filter. Keep it in step with the code it states: capabilities, the web service list and
  the facts it calls deliberate. The website's own review context is replaced entirely by
  this file, not merged.
- On the website the repository still needs *Enable pull request reviews* and a granted
  preview access; the file cannot do either.

