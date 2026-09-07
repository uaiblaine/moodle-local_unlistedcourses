# Course discoverability + Open Graph — implementation brief

Design record and implementation brief for making a **chosen** course readable by the
anonymous internet, so that a link pasted into WhatsApp, Facebook, LinkedIn or Telegram
shows a rich preview — on a site that keeps `$CFG->forcelogin = 1`.

Written 2026-09-02 from a measured feasibility study. `docs/` is `export-ignore`'d, so this
never ships in a release zip.

Full assessment, with the rejected alternatives and the reasoning:
https://claude.ai/code/artifact/38a5c4e7-d6f6-4643-bc5c-66790e761fb3

---

## 1. What is being built, and where

Two existing repos. **No new plugin.**

| Repo | Gains |
|---|---|
| `local_unlistedcourses` | The discoverability state (its first table), the predicate, the publish capability, the course-form control, backup/restore |
| `theme_boost_union_fundaseg` | The public surface (`hotsite.php` serves anonymous visitors), the `og:` tags, the image route |

The split follows the fleet rule that a theme owns presentation, never data schema. The
state is an access-control input, so it belongs in the `local_` plugin; the page and the
image route are presentation, so they stay in the theme.

The component name `local_unlistedcourses` becomes narrower than its scope (it will own
"public" as well as "unlisted"). Renaming a Moodle component means uninstall + reinstall and
losing the data, so **keep the name**, rewrite the user-facing strings to talk about
discoverability, and record the mismatch in `CLAUDE.md`.

## 2. Environment posture

- Moodle **5.2 only** on both repos (`$plugin->supported = [502, 502]`), developed on `m502`.
- `forcelogin = 1` and **stays 1**. Nothing in this work writes it at runtime.
- `opentowebcrawlers = 0` and stays 0.
- The environment is **new**: courses are configured by hand, one at a time. This removes the
  whole backfill/migration problem the original design had to carry. Retire the old fields
  outright in the same release rather than staging it.
- The theme already declares the dependency: `version.php` has
  `'local_unlistedcourses' => 2026082900`, and `ci.yml` pins
  `uaiblaine/moodle-local_unlistedcourses,main` in `plugin-dependencies`. Bump the version
  constraint when the new API lands, and **push the local plugin first** — the theme's CI
  clones the dependency from GitHub `main`, not from the local working tree.

## 3. Verified facts — do not re-derive these

Every line below was read in `~/dev/moodle-502` or measured on the running `m502` stack on
2026-09-02. They are the reason the design looks the way it does.

**Why an anonymous page is possible at all.** `$CFG->forcelogin` is not an ambient gate — it
is roughly twenty explicit reads, and there is **no allowlist, exemption constant or per-page
opt-out anywhere in core**. `login/index.php` is anonymous for one reason only: it never
calls `require_login()`. A page that also declines to call it is anonymous with `forcelogin`
left at 1.

**The two guards that block the hotsite today**, both measured by removing them and scraping
as `facebookexternalhit`:

| Guard | Symptom while present |
|---|---|
| `hotsite.php:61` — the `!empty($CFG->forcelogin) \|\|` term | 303 to login |
| `hotsite.php:77` — `core_course_category::can_view_course_info()` | **500**, `error/coursehidden` |

The second one is not obvious: it ends in `has_capability('moodle/category:viewcourselist')`,
and `accesslib.php:475-477` hard-denies **every** capability for `userid == 0` while
`forcelogin` is on. With both fixed the page returns **200, 48 208 bytes**, the course name
renders, `</head>` lands at byte 9 961 (inside Slack's 32 KB unfurl window), and
`theme/styles.php` plus the favicon serve 200 anonymously. Blocks, navigation and
`enrol_page_hook()` did **not** throw for an anonymous user — that was the main risk and it
did not materialise.

**`before_http_headers` is useless for this.** It is dispatched from
`core_renderer::header()`, so a request ending in `redirect()` never reaches it. This is why
the third-party `local_open_graph` plugin cannot help and must not be installed (it has four
further defects: an application-mode cache with no invalidation events, a course branch that
misses the hotsite's category context, double escaping, and zero visibility checking).

**File serving.** `file_pluginfile()` dispatches on `$filefunction = $component . '_pluginfile'`
(`lib/filelib.php:5397`) — the function name comes from the **file's component**, not the
active theme, and the generic third-party branch applies **no** login check and reads
`forcelogin` zero times. That is what makes an anonymous image route possible. It is also why
a grandchild theme cannot close Boost Union's `courseheaderimage` hole: files stored under
`theme_boost_union` always reach `theme_boost_union_pluginfile()`.

**Course custom fields are the wrong store for this.** `customfield_select` stores the
**position** of the option, not its text (`field_controller.php:55-67` returns
`array_merge([''], …)` over a free-text textarea; `data_controller.php:42-43` datafield is
`intvalue`). Reordering options at `/course/customfield.php` silently reassigns every stored
row, and the accident points toward publishing. Separately, `course_handler::can_view()`
returns `false` unconditionally for a NOTVISIBLE field and `get_instance_data()` defaults to
`$returnall = false`, which is why both existing consumers already read past the handler.

**The course form hook pair.** `\core_course\hook\after_form_definition` adds the control;
`\core_course\hook\after_form_submission` persists it. The latter is dispatched at
`course/lib.php:2022` in `update_course()` **before** `$DB->update_record('course', $data)` at
`:2026`, and at `:1903` in `create_course()` — so it fires for the web service and
`tool_uploadcourse` too, not just the form. Put the write there, not in the form.

**Backup/restore for a local plugin is available at course level.** `backup_local_plugin` and
`restore_local_plugin` exist, and course structures add them:
`backup_stepslib.php:586` and `restore_stepslib.php:2000`.

**A capability with no lang string is fatal on 5.x**, not cosmetic — it kills the roles
permissions page mid-table. Ship `en` and `pt_br` in the same commit.

## 4. Stage 1 — the state (`local_unlistedcourses`)

This plugin has **no `db/install.xml`, no `db/upgrade.php` and no `db/access.php` today**.
All three are new.

1. **Table** `local_unlistedcourses_state`: `id`, `courseid` (unique index), `state` (int),
   `usermodified`, `timemodified`. **Only non-default courses get a row** — absence means
   default. Declare `SEQUENCE` explicitly on every field and validate with `xmllint` against
   `public/lib/xmldb/xmldb.xsd`.
2. **Class** `\local_unlistedcourses\discoverability` with explicit constants
   (`STATE_DEFAULT`, `STATE_UNLISTED`, `STATE_PUBLIC`) and:
   - `get_states(array $courseids): array` — one query, request-cached, the shape
     `access::are_courses_discoverable()` already uses.
   - `get_state(int)`, `is_public(int)`, `is_unlisted(int)`.
   - `set_state(int $courseid, int $state): void` — **the capability check lives in here**,
     not in the form, so the web service, CSV and restore paths cannot route around it.
   - `is_public()` must ALSO require `$course->visible` and every category on the course's
     path being visible. It is the gate for an anonymous page; it cannot delegate that.
   - Keep the existing "never cache across requests" rule and its docblock reasoning.
3. **Capability** `local/unlistedcourses:publish` — course context, captype write, risk
   `SPAM | PERSONAL`, **manager only, and no `clonepermissionsfrom`** so no upgrade
   back-fills it from `moodle/course:update`. Hiding your own course is an editing act;
   publishing it to the internet is not, and the two must not travel together.
4. **Rewire `access.php`** — replace `unlisted_flags()`'s raw SQL with
   `discoverability::get_states()`. Everything else in `eligible()` stays exactly as it is,
   including the staff escape, the `=== true`, and the pending-enrolment term.
5. **Form control** via `db/hooks.php`: `after_form_definition` adds a three-option select
   (offering PUBLIC only to a user who holds the capability — and when the course is
   *already* public, freeze the control **with its current value still submitting**, so
   saving an unrelated change never silently un-publishes); `after_form_submission` calls
   `set_state()`.
6. **Retire the old fields.** `db/upgrade.php` deletes the `unlisted` checkbox definition,
   and `classes/local/fields.php` stops provisioning it. Because the environment is new,
   copy the handful of existing rows first if you want (`intvalue = 1` → `STATE_UNLISTED`)
   — it is ten idempotent lines — but hand-setting them is equally fine. **On m502 today:
   courses 86 and 89 are unlisted, course 85 has `hotsite_publico = 1`.** Course 2 carries
   only `hotsite_modelo` and must get nothing; write that as an assertion, it is the case a
   careless query gets wrong.
7. **Backup/restore classes** under `backup/moodle2/`. Without them the state is lost on
   every course duplicate and restore, silently and in the un-hiding direction. On restore,
   clamp an incoming PUBLIC to default unless the restoring user holds the publish capability
   in the target course, and log it — nobody consents to publishing a course by restoring a
   backup.

## 5. Stage 2 — the public surface (`theme_boost_union_fundaseg`)

1. `hotsite.php:61` — replace the `forcelogin`/`$values->publico` condition with
   `\local_unlistedcourses\discoverability::is_public($course->id)`, guarded by
   `class_exists()` and **failing closed** (treat a missing dependency as not public). Note
   this sits ten lines from `redirector::is_ghosted()`, which deliberately fails **open**.
   Document the asymmetry in both docblocks and pin it with a test that removes the class,
   or someone will "harmonise" them and break one.
2. `hotsite.php:77` — the `can_view_course_info()` guard must not run for an anonymous
   visitor (it cannot pass). Gate it on `isloggedin()`. The category-visibility half of what
   it was doing now lives inside `is_public()`.
3. **`hotsite_page::summary_html()` passes `noclean => true`** (`:551-563`), which skips
   HTMLPurifier unconditionally — bypassing even the trusted-author gate, because
   `formatting.php:185-192` only consults trust when `$clean` is left null.
   `moodle/course:update` is `RISK_XSS` by core's own classification and is CAP_ALLOW for
   `editingteacher`. Behind login this is the accepted trust model; on a page the institution
   pushes to its own logged-in students by WhatsApp it is stored XSS executing same-origin.
   **This is blocking.** Drop `noclean` on the public path at minimum.
4. **Remove `hotsite_publico`** from `hotsitefields.php` (const at `:83`, definition at
   `:204`, read at `:468-469`) and from the `values()` payload. Remove the now-pointless
   forced-login hiding in `classes/local/hook/course/after_form_definition.php:77` — but keep
   that file's other job, the hotsite link placement, and its mutation tag.
5. **Emit the `og:` tags** before `$OUTPUT->header()`. Escaping, one pass, and note the
   direction: take values in the **plain** spelling
   (`format_string(..., ['escape' => false])`, and `format_text()` → `strip_tags()` →
   `shorten_text(…, 200)` for the description) and apply `s()` exactly once for the
   `content="…"` attribute. Course 85 is named `Atendimento Pré-Hospitalar & Resgate` —
   an ampersand — so it is the fixture that proves the escaping, and a
   `<b>x</b>` fixture would prove nothing.
6. `og:url` and `<link rel="canonical">` point at the hotsite's own URL. Emit `og:locale` as
   `pt_BR` (Moodle's language code is `pt_br`; a direct copy is invalid).

## 6. Stage 3 — the image

`theme_boost_union_fundaseg_pluginfile()` in the theme's `lib.php` (the theme has no such
function today), filearea `ogimage`, URL carrying `<courseid>/<contenthash>/og.jpg`.

- Re-run `discoverability::is_public($courseid)` **from scratch** — the page that minted the
  URL is not trusted — and verify the courseid matches the context's `instanceid`, so a URL
  cannot pair one course's context with another's itemid.
- Serve the course's own `course` / `overviewfiles` file. Do not copy it.
- **`cacheability => 'private'`.** `send_stored_file()` defaults to `public` for any
  anonymous requester (`filelib.php:2563-2577` only downgrades for a logged-in non-guest), so
  a shared proxy would keep serving an unpublished course's image for the whole max-age with
  nothing at the Moodle layer able to clear it.
- The **contenthash in the path is the cache-buster**, and it is not optional: Facebook caches
  a scrape ~24 h and only re-fetches via the Sharing Debugger, X and LinkedIn 7 days,
  Telegram indefinitely. A stable filename means a replaced image never updates the preview.
- Refuse with a bare `send_header_404()` and `exit`, **not** `send_file_not_found()` — that
  throws, and the handler renders a full themed error page through `core_renderer::header()`
  for an anonymous user, which is the render this whole design avoids. (Observed live: a
  deleted probe file returned 500, not 404.)
- Consider a 1200×630 JPEG derivative cached in `$CFG->localcachedir`, following
  `theme_boost_union/lib.php:1030-1035`'s own precedent. It is what actually enforces
  WhatsApp's ~600 KB ceiling instead of hoping the editor uploaded something small.
- **Course 85 has no overview image**, so seed one before testing this stage.

## 7. Stage 4 — gates

- `mdl phpunit m502 local_unlistedcourses` and `… theme_boost_union_fundaseg`.
- **Add an eighth tag to `mutations/gates.conf`** with its own `.pl` script for the new
  predicate. This repo proves every guard by mutation; a new public method must not be the
  exception. `mdl mutate moodle-local_unlistedcourses mutations/gates.conf`.
- Behat: **the fleet stacks and their behat sites carry `forcelogin = 1` from the stack
  config.** Scenarios exercising the public page must not switch it off — the whole point is
  that it works with it on. Re-run `mdl behat-init` after any `version.php` bump.
- `mdl ci moodle-local_unlistedcourses --matrix` and the same for the theme, both green,
  local plugin pushed first.
- Version bump + `CHANGELOG.md` in the same commit as the change that needs it, both repos.
- `lang/en` and `lang/pt_br` in lockstep, alphabetically sorted, in the same commit.
- Sweep for capabilities missing lang strings after installing (the query is in
  `~/dev/CLAUDE.md`).

## 8. Verification that the thing actually works

Do this with real bytes, not by reading code:

```sh
curl -s -D - -A 'facebookexternalhit/1.1' \
  'http://localhost:8502/theme/boost_union_fundaseg/hotsite.php?id=<public course>' -o /tmp/card.html
grep -o 'pluginfile.php[^"]*\|flavours/[^"]*' /tmp/card.html | sort -u | while read u; do
  printf '%s %s\n' "$(curl -s -o /dev/null -w '%{http_code}' -A 'facebookexternalhit/1.1' "http://localhost:8502/$u")" "$u"
done
```

Assert: 200 on the page; every sub-resource 200; `og:title`, `og:description`, `og:image`,
`og:url` present; `</head>` inside 32 768 bytes; and a **non-public** course id returns the
same refusal as a nonexistent one.

Then confirm the negative case: an unlisted course and a default course must both still 303
to login for the same anonymous client.

## 9. Known gaps to decide, not to code around

- **Flavours will not brand the anonymous page.** `theme_boost_union_flavours_require_login_for_file()`
  (`theme_boost_union/lib.php:1227-1252`) calls `require_login()` whenever `forcelogin` is on,
  so a flavoured category's logo and background 303 to login. Course 85's category carries no
  flavour, so this was never exercised — verify it on a flavoured course before shipping.
  Separately, `flavourslib.php` keys flavours on the visitor's cohorts, and an anonymous
  visitor has none, so a cohort-scoped flavour can never apply anonymously.
- **Boost Union already leaks every course header image.** Demonstrated: a probe file on
  course 86 (flagged unlisted) returned 200 with `Cache-Control: public, max-age=21600` to an
  anonymous client under `forcelogin = 1`, while the course page 303'd. It cannot be fixed
  from a child theme. Patch the fork, report upstream, or confirm the filearea is unused —
  m502 holds zero such files. **This is a separate ticket, not part of this work.**
- **`after_config` never fires on a router-served request.** `public/r.php:28` defines
  `ABORT_AFTER_CONFIG` and `setup.php:607` returns ~600 lines before the dispatch at `:1209`.
  The theme's existing enrolment forwarding and ghosting are silently absent from anything
  the routing engine answers. Nothing breaks today because the intercepted URLs are real
  files. **Separate ticket.**
- **Every anonymous hit sets `MoodleSession`** with no consent surface (observed). Core's own
  comparable endpoints define `NO_MOODLE_COOKIES`; a themed page cannot. Decide whether that
  is acceptable for a public marketing page under LGPD.
- **A public course with no `hotsite_modelo` is armed but inert** — the hotsite redirects, so
  there is no anonymous page to reach. The editor sets the strongest state and observes
  nothing. Say so in the help string.

## 10. Owner decisions still open

1. **Re-sign stage 8 of `docs/hotsite/README.md`** in the theme repo ("forcelogin stays ON,
   hotsites serve authenticated users"). This narrows that dated production decision; it
   needs the same signature, recorded as a new stage row rather than quietly amended.
2. **Indexed by Google or not?** A 200-returning public page is Googlebot-fetchable and
   Moodle ships no `robots.txt`, so the choice has to be made in a meta tag.
3. **After un-publishing: hard 404 or an informative page?** Uniform refusal is what defeats
   enumeration, and it turns every already-shared link into a dead end for your own audience.
   The two goals are in direct conflict.
4. **Is `$course->summary` the right public text**, or does this need a separate reviewed
   "public description" field? Summaries were written for an internal audience.
5. **Which brand does an anonymous preview carry**, given flavours cannot resolve without a
   cohort? Does `og:site_name` need a per-course override?

---

## 11. Stage 1 status (2026-09-02)

Delivered in the working tree of `local_unlistedcourses`, not yet committed, pending review:

- `db/install.xml` (validated against `xmldb.xsd`), `db/upgrade.php` (creates the table,
  copies ticked `unlisted` rows to UNLISTED, retires the field and its category),
  `db/access.php`, `db/hooks.php`.
- `classes/discoverability.php`, `classes/event/course_state_updated.php`,
  `classes/hook_callbacks.php`, `classes/local/courseform.php`,
  `classes/local/legacy_field.php`, a full privacy provider, and
  `backup/moodle2/{backup,restore}_local_unlistedcourses_plugin.class.php`.
- `access.php` rewired onto `discoverability::get_states()`; `fields.php` and
  `db/install.php` removed.
- Tests: `discoverability_test`, `local/courseform_test` (hook wiring proven through
  `create_course()` / `update_course()`), `backup_restore_test`, `privacy/provider_test`,
  `local/legacy_field_test`, `access_test` adapted, and `tests/behat/discoverability.feature`.
  Ten new mutations in `mutations/gates.conf`.

**One deviation from section 4, with the reason.** Item 2 says the capability check lives
in `set_state()`; it does, for every transition that enters or leaves PUBLIC. Listed ↔
unlisted carries no gate of its own in `set_state()`. A `moodle/course:visibility` gate was
tried and dropped: every writer is already behind `moodle/course:update` or the restore
capabilities, and core applies `visible` on restore without asking
`moodle/course:visibility` (`restore_stepslib.php`, `process_course()` checks only
`changeidnumber`, `changesummary` and `setforcedlanguage`) — so the extra gate would only
ever refuse a restoring editing teacher, and refuse them in the un-hiding direction. The
form still offers the control only to holders of `moodle/course:visibility`, mirroring
core's own visibility select.

**A second deviation, from item 7, found by the adversarial review.** The restore does not
clamp an incoming PUBLIC to default ahead of the write; it writes through `set_state()` with
the restoring user and logs the refusal. The clamp was untestable on a new course (the gate
refuses identically) and wrong on an overwrite restore into an unlisted course, where
clamping to default slipped past the gate and un-hid the course. The backup also emits the
element for every course, listed included, so that "overwrite course configuration" resets
the state the way core resets `visible`. And on a new course the form asks
`guess_if_creator_will_have_course_capability()`, as core's own visibility select does.

Measured on m502 after `mdl upgrade`: courses 86 and 89 carried over as UNLISTED, course 2
got nothing, the `unlisted` field and its category are gone, the capability string sweep is
empty, and both course forms render the control directly after "Course visibility".

Two things the upgrade taught, recorded in the plugin's `CLAUDE.md`:
`core_customfield\handler::reset_caches()` throws outside PHPUnit (the first upgrade run
died after the migration and before the savepoint; the step is idempotent, so the second
run completed), and a stack whose mounted code is newer than its installed plugin answers
every course form with a 500 until the upgrade runs, because `has_capability()` on a
capability that is not installed yet is a `debugging()` call that Whoops turns fatal.

Owner decisions taken the same day, recorded for stage 2: (1) a new stage row in the
theme's hotsite README, forcelogin stays 1; (2) `noindex`; (3) a non-public course answers
anonymous visitors with the same 303 to login as today, id existing or not; (4) a new
plain-text public description field in the theme's Hotsite category, `og:description`
omitted when empty; (5) `og:site_name` is the site name, no per-course override.

**Gates run on 2026-09-02.** `mdl phpunit m502 local_unlistedcourses`: 42 tests, 157
assertions, green. `mdl behat m502 @local_unlistedcourses`: 2 scenarios, 34 steps, green.
`mdl ci --only phpcs,phpdoc,phplint,validate,savepoints`: all green (these gates are
branch-independent and ran on the 5.1 leg). **`mdl ci --matrix` cannot run on the corporate
network**: every 5.02 leg dies in moodle-plugin-ci's install step because Moodle 5.2's
`npm run update-packages` fetches the React bundle from esm.sh through Node's native
`fetch`, which ignores the proxy — the fleet memory note on the Squid proxy records this as
unsolved and expected; GitHub's runners have direct egress, so the PHP × DB legs (MariaDB
included) run there after the push. The plugin's SQL is portable by construction
(`get_in_or_equal`, `get_records_list`, `count_records_select`, a `LEFT JOIN … IS NULL`).

**Theme suite measured against this version (2026-09-02, m502):** 85 tests, 289 assertions,
**1 error** — `redirector_test::test_an_unlisted_course_ghosts_only_the_ineligible` still calls
`\local_unlistedcourses\local\fields::ensure_fields()`. That, plus the four scenarios in
`theme_boost_union_fundaseg_unlisted.feature` that seed `customfield_unlisted`, is the whole
coupling stage 2 has to move onto `discoverability::set_state()`.

**Home network, same day: the matrix ran.** `mdl ci --matrix --behat`: 8.4/pgsql (every
static gate + PHPUnit + Behat), 8.3/pgsql and 8.3/MariaDB all PASS; 8.4/MariaDB died in
`git clone` of core with a transient HTTP/2 error before any gate ran; re-run alone it passed
every step, Behat included. **All four legs GitHub runs are green locally.**
A second 5.2 stack, `m502b` on `localhost:9502`, now exists for parallel test runs: the same
suite took 59 s there while the same run on m502 took 577 s, 518 s of it queued behind
another session's mutation sweep.

---

## 12. Stage 2 status (2026-09-03)

Stage 1 was merged into `main` through PR #2 after its GitHub run went green. Stage 2 is
commit 883829a on the theme's `public-hotsite` branch, pull request #3:

- `hotsite.php`: the visitor gate runs first and answers every non-public or nonexistent
  course with the login page; `can_view_course_info()` is skipped for visitors; a public
  course registers its head tags before `$OUTPUT->header()`.
- `classes/local/hotsite/publicaccess.php` (fail-closed adapter, pinned by a test that hands
  it a class that does not exist) beside `redirector::is_ghosted()`, which gained the same
  seam to pin its fail-open answer; both docblocks explain the asymmetry.
- `classes/output/opengraph.php` + `templates/opengraph.mustache` + the
  `before_standard_head_html_generation` callback. The robots directive and the canonical
  link are literal strings in the callback: the template lint validates a fragment as body
  content and rejects a `meta` with a `name` and a canonical `link` there, while the RDFa
  `og:` metas pass. Values travel plain and are escaped once.
- `descricao_publica` (text, 200, NOTVISIBLE) as the only source of `og:description`;
  `hotsite_publico` deleted by the upgrade; `noclean` off on public courses; the forced-login
  hiding removed from the course-form hook.
- Behat: a theme step (`the course "X" is "public" for discoverability`) writing through
  `set_state()` as admin; the public scenarios keep `forcelogin = 1`.

Measured on m502 after `mdl upgrade` and publishing course 85 with a description carrying
an ampersand: **200**, 51 295 bytes, `</head>` at 10 508, every tag present, `&amp;` once,
no double escape; courses 2, 86, 89 and a nonexistent id all **303 to login**.

The adversarial review of stage 2 (13 agents) confirmed one blocking defect, fixed before the
commit: the three textarea sections reached the public page through `export_value()`, which
honours the value's trust mark, so with `enabletrusttext` on an editor holding
`moodle/site:trustcontent` shipped uncleaned HTML - the summary had been fixed and the
sections, 190 lines away on the same page, had not. Lesson recorded in the theme's
`CLAUDE.md`: fix this class of defect by the sink, every triple stash on the public page,
not by the field. The review also measured what stage 3 must own: under `forcelogin`,
`file_pluginfile()` runs `require_login()` for `course/overviewfiles` and `course/summary`
(`lib/filelib.php` ~4964), so the hero image and images embedded in the summary answer a
visitor with 303 while textarea images serve. The theme's own file route, re-checking
`is_public()`, has to carry all three: `og:image`, the hero, and summary files.

Stage 3 (the image) is not started: `og:image` is absent until it is.

## 13. Stage 3 status (2026-09-03)

Stage 2 was merged through the theme's PR #3. Stage 3 is on the theme's `public-image`
branch: the file route of section 6, extended to carry the two things the stage 2 review
measured as missing besides `og:image` - the course image itself and the images embedded in
the summary, which core answered with 303 for a visitor.

- `classes/local/hotsite/publicfiles.php` holds the route; `lib.php` gains the
  `theme_boost_union_fundaseg_pluginfile()` shim, which does nothing but call
  `publicfiles::serve()`. Three areas, the course id as the item id in each:
  `hero/<courseid>/<contenthash>/<filename>` (the first overview image, bytes as stored),
  `ogimage/<courseid>/<contenthash>/og.jpg` (a 1200x630 JPEG derivative made once per
  content under `localcachedir`, cover-scaled and centre-cropped, quality 82, turned upright
  first through core's `stored_file::rotate_image()` when the photo carries an EXIF
  orientation) and `summary/<courseid>/<filepath><filename>` (an image of the
  `course/summary` area - images only, so that an editor-left `.html` in that area cannot
  become same-origin script for every visitor).
- `resolve()` is where every check lives, and it is the unit-tested half: the context must
  be a course's; the course id in the path must be that context's own instance - the summary
  area looks its file up by the context, so that is the sink a mismatch would reach; the
  course must be public at the moment of the request AND carry a hotsite model, the two
  conditions under which `hotsite.php` serves a visitor, so the route is reachable exactly
  when the page is (this keeps section 9's "armed but inert" true: a public course without a
  model exposes nothing); a hashed area must name the current content hash and its own file
  name (`og.jpg` for the derivative, the file's name for the hero) in exactly three segments;
  a summary path must reach a file that GD accepts as an image. `serve()` sends and dies and
  is verified with real requests below; the og area sends the derivative or 404, never the
  original under a name that promised a 1200x630 JPEG. A refusal is `send_header_404(); die`.
- `opengraph::for_course()` asks `publicfiles::ogimage()` for the image: the derivative with
  `og:image:type`, `og:image:width`/`height` 1200x630 and `og:image:alt` (the title) when it
  can be made; the original through the hero route, typed but unsized, when it cannot AND it
  is a raster of at most 600 KB - the ceiling the derivative exists to enforce; no image tag
  otherwise. The derivative is made at page render, once, so the tags only claim a size the
  route will serve. The cache file is named by the content hash plus the canvas and quality
  it was made with, so a changed constant is a new file and nothing needs invalidating.
- Two ceilings, both measured on the stack rather than assumed. Sources above 25 million
  pixels get no derivative: under `MEMORY_EXTRA` (384 MB on 64-bit), decoding a 25-megapixel
  PNG, rotating it and covering the canvas peaks 200 MB over the request's baseline (a
  30-megapixel PNG without rotation, 203 MB), which leaves room for the raw bytes and the page
  while still admitting a 24-megapixel camera photo. Quality 82 holds a 1200x630 of pure
  noise - the worst case for JPEG - at 519 KB (665 KB at 90, 871 KB at 95), so the ~600 KB
  WhatsApp accepts holds for every source.
- On a public course `hotsite_page` mints the hero URL and rewrites the summary's
  `@@PLUGINFILE@@` links through the route, whoever is looking - a public page is the same
  page for everybody; the non-public page keeps core's URLs and is the control in the tests.
- No version bump (no schema, services or AMD change), no new strings.

Measured on m502 after seeding course 85 with a 2400x1350 JPEG course image and a 600x400
PNG in the summary, as `facebookexternalhit/1.1` with no session - section 8's loop, run
verbatim over every `pluginfile.php`/`flavours/` URL the page emits (the page emits three;
its category carries no flavour):

| request | answer |
|---|---|
| `hotsite.php?id=85` | **200**, 50 173 bytes, `</head>` at 10 905; `og:image`, `og:image:type` image/jpeg, `og:image:alt`, `og:image:width` 1200, `og:image:height` 630 present |
| `…/theme_boost_union_fundaseg/hero/85/<hash>/capa.jpg` | **200** image/jpeg 74 703 B, `Cache-Control: private, max-age=604800` |
| `…/theme_boost_union_fundaseg/ogimage/85/<hash>/og.jpg` | **200** image/jpeg 20 830 B, 1200x630 baseline q82; cached as `<hash>-1200x630-q82.jpg` |
| `…/theme_boost_union_fundaseg/summary/85/foto.png` | **200** image/png 2 699 B, `private, max-age=3600` |
| hero and summary under course 86's id, a zeroed hash, a foreign file name, a fourth segment, `capa.jpg` under the og area, a missing summary file, an unknown area, the system context | **404**, 0 bytes each |
| core's `course/overviewfiles/capa.jpg` and `course/summary/foto.png` | **303** to login, unchanged |
| `hotsite.php` for courses 2, 86, 89 and a nonexistent id | **303** to login, unchanged |

Tests: 13 in `publicfiles_test` (hero pick and parity with core's course image, URLs, public-now,
model required, cross-course id through the summary sink and a category inserted under the
course's own id so that only the context level refuses it, stale hash, foreign name, fourth
segment, unknown area, summary path and directory entry, images only, derivative size and
reuse, noise under 600 KB, EXIF upright, ceiling and fallback), two in `opengraph_test` (the
tags with and without an image; the unsized fallback set rendered) and one in
`hotsite_page_test` (the public page routes the hero and the summary through the theme, the
internal page does not), plus a Behat scenario with a theme step that stores a GD-made course
image and asserts the `og:image` tags and the hero style; 13 scenarios, 99 steps green.
Eighteen mutation gates added (`files_public_recheck`, `files_course_match`,
`files_context_level`, `files_hash_match`, `files_model_required`,
`files_arg_count`, `files_name_match`, `files_summary_type`, `derivative_canvas`,
`derivative_ceiling`, `derivative_rotation`, `derivative_quality`, `og_image_omitted`,
`og_image_size_claimed`, `og_fallback_size`, `hero_route_public`, `summary_route_public`,
`pluginfile_wired`), every one reddening a named test. A nineteenth, on the summary area's
`is_directory()` check, reddened NOTHING on the first sweep: once the area was limited to
images, a directory entry - which has no mimetype - was refused by `is_valid_image()` as
well, so the guard was dead and was removed rather than kept for show. `cacheability =>
private` and the bare 404 are curl facts, not gates, because `serve()` cannot run under
PHPUnit.

The adversarial review (16 agents: seven lenses, a skeptic per blocking or required finding,
a completeness critic) confirmed no hole in the authorisation surface and produced the
changes above that were not in the first draft: the summary area limited to images, the
hotsite model required by the route, the EXIF rotation, the 600 KB gate on the fallback, the
og area answering 404 rather than the original, the cache name carrying canvas and quality,
the cross-course test through the summary sink, the unsized tag set rendered by a test, and
the memory ceiling measured rather than estimated. Declined, with reasons: making the
derivative out of band (an adhoc task) - the ceiling is measured and the work happens once
per content; a rate limit on derivative builds - a build needs a public course's current
hash and happens once per purge; `og:image:secure_url` - meaningful only behind HTTPS and
redundant with an https `og:image`; serving a modeless public course's files to an entitled
logged-in user through the theme route - the internal page mints core's URLs for them.

Two traps met while writing the tests, recorded in the theme's `CLAUDE.md`:
`$DB->insert_record()` silently drops a given `id` (only `insert_record_raw()` with
`$customsequence = true` writes a row under a chosen id), and two GD images of the same size
and colour are the same bytes, so two fixtures meant to differ shared one content hash.

## 14. Unlisted categories (2026-09-05 to 2026-09-07)

Design record and status of the category extension. The assessment this rests on - the
measured facts, the judge panel over three designs, the refuted claims and the critic's
corrections - is https://claude.ai/code/artifact/93b8097c-731e-4422-acd3-2e6e46f3916f.

### What was built, and where

| repo | branch | commits |
|---|---|---|
| `local_unlistedcourses` | `category-discoverability` | `da7678a` the state; `c2410e4` the predicate, the listing term and the public clamp; `03a1e39` the editing page |
| `theme_boost_union_fundaseg` | `category-discoverability`, from `fix/hotsite-ghost` (`6a26c0e`, the hotsite ghost fix) | `92c10ac` the renderer withholding, the direct-link ghost, the kicker, the action bar template |

Two shapes the request could not keep, both measured rather than chosen: the control is a
page in the category's settings menu, not a field on the core form (`course/editcategory.php`
and `core_course_editcategory_form` dispatch no hook, `core_course\hook\after_form_definition`
is type-hinted to the COURSE form, and there is no custom field handler for categories); and
enforcement stays in the theme's renderers, not in the capability layer. Writing `CAP_PREVENT`
on `moodle/category:viewcourselist` in the category context was proven to work (a throwaway
PHPUnit proof, 10 tests, on m502b) and is the only strategy that reaches web services, the
mobile app, the navigation tree and `block_course_list` - but the manager role holds no
`viewcourselist` of its own (`lib/db/access.php:740-748` lists `guest` and `user` only), so
managers and teachers go dark unless re-granted; the `coursecat` session cache is invalidated
by no capability or role write; and cohort membership would be materialised into role rows,
which is the one thing this plugin forbids caching. It stays documented as the escalation.

### Decisions, closed by the owner on 2026-09-06

| id | decision |
|---|---|
| D1 | Only cohorts whose context IS the unlisted category's own context count. Not ancestors, not system. |
| D2 | Any role assignment in the category's context or an ancestor CATEGORY context counts, plus `moodle/category:viewhiddencategories` there as the staff escape. System-context assignments do not. |
| D3 | "Could self-enrol right now" never rescues a course from an unlisted category in listings. |
| D4 | Unlisting a category withholds the courses inside it from listings, subject to the enrolled, pending-application and course-staff escapes. |
| D5 | The editing UI is a page linked from the category settings menu, not a copy of `course/editcategory.php`. |
| D6 | Enforcement in the theme's renderers plus one URL guard; the capability override is the documented escalation. |
| D7 | The navigation tree and the breadcrumb are not pruned; recorded as residue. |
| D8 | A new capability, `local/unlistedcourses:managecategorystate`, gates the state; not `moodle/category:manage`, which carries `RISK_XSS` and lets its holder rename, move and delete. |
| D9 | The category selector of the action bar is removed for everyone by a template override: `make_categories_list()` names every category on the site with no capability required. |
| D10 | An invisible cohort still grants; membership is read from `{cohort_members}` directly. |
| D11 | The pre-existing hotsite gap is closed first: `hotsite.php` now asks `redirector::is_ghosted()` on its logged-in branch. |
| D12 | The category term applies to LISTINGS only. `is_course_discoverable()` keeps its meaning, because the theme ghosts `enrol/index.php`, `course/info.php` and the hotsite off it, and a listing rule must not become an enrolment block. |

### Semantics

A category is effectively unlisted when it or any ancestor carries the row
(`course_categories.path`). For EVERY unlisted category on the path the viewer must satisfy
one term: cohort (D1), role (D2) or staff escape. Site admins see everything; visitors and
guests see nothing unlisted, at no query. With no category unlisted the whole predicate costs
one statement, and that fast path is held by a mutation gate. Role switching inside a course
does not change a category answer, by choice: the raw role read ignores it, and
`has_capability()` at a category context ignores a switch made at a descendant.

| actor | sees the category in listings | opens `?categoryid=` | sees its courses |
|---|---|---|---|
| site admin, manager or course creator anywhere on the path | yes | yes | yes |
| any role at the category or an ancestor category | yes | yes | yes |
| member of a cohort at the category | yes | yes | yes |
| member of a system cohort only; a bespoke system role | no | ghost | only their own |
| editing teacher of a course inside, enrolled student, pending applicant | no | ghost | their own course, yes |
| someone who merely could self-enrol | no | ghost | not in listings; a direct enrol link follows the course rule (D12) |
| anonymous or guest | no | core's own refusal | no |

### Coverage and residue

Covered by the theme: the category page tree and its paging bar, the front page combo and
categories lists, the AJAX expander (`course/category.ajax.php`), the node of any category
handed to the renderer, the expand arrow and the tree's "Expand all" control when every child
is unlisted, search results and tagged courses (through the plugin's listing term), the
"Category: name" line of a course box, Boost Union's card and list badge, a direct
`/course/index.php?categoryid=N` link, the hotsite kicker for a logged-in viewer, the
anonymous hotsite and its Open Graph tags (through `is_public()`), and the category selector
of the action bar (D9).

What still names an unlisted category, worst first. Every row is a consequence of enforcing
above the capability layer; this is a listing rule, not a permission.

| # | surface | leaks | disposition |
|---|---|---|---|
| 1 | `core_course_search_courses`, `core_course_get_courses_by_field` (`lib/db/services.php:681-689`, `:732-738`) | category id and name and the courses; the search one is `ajax => true`, callable from any logged-in browser session | structural; remove from the site's services when the app is not in use |
| 2 | `core_course_get_categories`, `core_course_get_courses`, `core_enrol_get_users_courses`, the Moodle App | names and ids | structural; revisit D6 if the app is in scope |
| 3 | Boost Union smart menu items listing courses (`smartmenu_item.php:1024-1077`, `:1576-1588`) | every course of an unlisted category, cached per user; pre-existing for unlisted courses too | operational: no course-listing smart menu over unlisted categories |
| 4 | navigation tree, course index drawer, `block_navigation` | name and link | structural: node builders run at page init (D7) |
| 5 | `block_course_list` | top-level names as raw anchors | structural: no renderer call; do not place the block |
| 6 | breadcrumb on a course page inside the category | name and link | reachable only by someone already on a course there (D7) |
| 7 | report builder category entity and categories datasource (`course_category.php:129` bypasses visibility) | full nested path to any report audience | operational: no category columns in reports shared beyond staff |
| 8 | `course/request.php:78` heading | the requested category's name | operational: keep course requests off |
| 9 | `block_myoverview` and friends (`course_summary_exporter.php:68`) | raw category name of the viewer's own courses | low: courses the viewer already keeps |
| 10 | calendar paths that bypass visibility (`calendar/lib.php:826,1138,2677`, `coursecat_proxy.php:86`) | a category event's title | accept, staff-adjacent |
| 11 | theme switch vectors: `allowcategorythemes` (set from the category form itself), `allowcohortthemes`, `allowuserthemes`, `allowcoursethemes`, `allowthemechangeonurl` | everything, by leaving the enforcing theme | operational: all five off; the editing page warns on the first |
| 12 | manage-categories action bar, course settings category select, course search list, backup copy form | names as options | accept: behind staff capabilities |
| 13 | existence oracle on an id: core's `unknowncategory` carries the id, the ghost is uniform | whether an id exists | accepted, same shape as the course ghost |
| 14 | any future caller of `core_course_category::get($id, $strictness, true)` | name | twelve sites in core 5.2 today; sweep on each upgrade |
| 15 | RSS and feed paths | verified clean 2026-09-06: no feed library fills an item category | closed |

### Verified facts, not to be re-derived

- `core_course_category::can_view_category()`: `course/classes/category.php:684-693`; `get()` throws `cannotviewcategory` at `:279-283`; `get_not_visible_children_ids()` at `:1219-1247` caches per session (`lib/db/caches.php:194-201`, TTL 600, invalidated by no role or capability write).
- No hook on the category form: `course/editcategory.php` and `course/classes/editcategory_form.php`, byte-identical on 5.3-dev; `get_plugins_callback_function()` is used only for `pre_course_category_delete` (`:2031`) and `pre_course_category_delete_move` (`:2203`), which pass a record and a `core_course_category` object respectively.
- `local_<plugin>_extend_settings_navigation()` runs on category pages with the category's context; a node under `categorysettings` (`settings_navigation.php:1523`) reaches the secondary navigation's More menu (`views/secondary.php:736`). The callback list is cached by the versions hash: a version bump is what makes it found.
- `cohort_is_member()` is a bare `record_exists` (`cohort/lib.php:239-243`); `cohort_get_user_cohorts()` filters `visible = 1` (`:558-565`); `cohort_delete_category()` moves a deleted category's cohorts up (`:164-181`); `cohort_get_cohorts()` returns `['totalcohorts', 'cohorts', 'allcohorts']`, paginated and capability-free (`:445-490`).
- The dynamic cohorts fork writes `cohort_members` in bulk with no member events (`rule_manager.php:356-404`): nothing here caches across requests.
- Boost Union overrides `coursecat_category` (675), `coursecat_tree` (762) and `course_category` (837) and not `coursecat_subcategories` or `course_category_name`; its card presentation names the category through `util\course::get_category()` (`classes/util/course.php:113-124`), never through core's line.
- Core's `course/templates/category_actionbar.mustache` example context declares `additionaloptions` as an object where the action bar exports a string (`category_action_bar.php:182-186`): a verbatim copy fails the mustache lint.
- `$PAGE->set_category_by_id()` reads the raw record and checks nothing (`lib/pagelib.php`); `core_course_category::get()` must come first on the editing page.
- On m502 (2026-09-06): every theme-switch setting off except `allowthemechangeonurl`; 1,802 cohorts in category contexts against 202 at system; no smart menu items; web services and the app disabled. Production was not checked from here.

### Stage status

| stage | gates, as measured |
|---|---|
| 0 baseline (2026-09-06) | plugin 47 tests, 4 matrix legs, 20 of 20 gates; theme 231 tests, 4 legs; sweeps: RSS clean, indexes present, `alwaysreturnhidden` sites listed |
| D11 | Behat 6 of 6 with the ghost scenario proven red by a hand mutation; theme matrix 4 of 4 |
| 1 the state | schema validates; upgrade on both 5.2 stacks; 5 static steps; 60 tests; capability-string sweep empty; 6 of 6 gates |
| 2 the predicate | 86 tests; 5 static steps; 14 of 14 new gates, 41 of 41 in a full sweep; a latent defect in the course memo (keyed by course alone) found by the review and fixed |
| 3 the page | 105 tests; 6 static steps with mustache; Behat 5 of 5; 8 of 8 gates |
| 4 the theme | 246 tests; 7 static steps; Behat 10 of 10; 11 of 11 gates; the card-presentation gap found by the review and closed |
| 5 hand-off | this section; both matrices with Behat and the plugin's full sweep are the remaining gates before any push |
