# Developer guide

A technical guide for theme and plugin developers who integrate with **Course discoverability**
(`local_unlistedcourses`). It explains the data model, every public predicate with its contract,
the category semantics, how a theme puts the answers on screen, what the plugin deliberately does
not do, and how to test an integration.

The plugin **decides and renders nothing**. It answers one question per course and per course
category - *who may learn that this exists?* - and leaves every listing, page and image to the
site's theme and to the plugins that ask it. [`theme_dimensions`](#theme_dimensions) is the
reference implementation; [`block_compass`](#block_compass) and
[`local_dimensions`](#local_dimensions) consume the enrolment vocabulary.

The source of truth is always the class docblocks in `classes/`. This guide names them; when the
two disagree, the code and its tests win and this guide is wrong.

Paths into Moodle core below are relative to the `public/` directory of a Moodle 5.3 checkout.

## Contents

1. [The model](#1-the-model)
2. [Reading the state](#2-reading-the-state)
3. [Category semantics](#3-category-semantics)
4. [Integrating a theme](#4-integrating-a-theme)
5. [Worked examples](#5-worked-examples)
6. [The supported API and what a change breaks](#6-the-supported-api-and-what-a-change-breaks)
7. [What the plugin does not do](#7-what-the-plugin-does-not-do)
8. [Testing your integration](#8-testing-your-integration)

---

## 1. The model

### 1.1 Three states, two tables

Courses and course categories each carry one of three states.

| Constant | Value | Course | Category |
|---|---|---|---|
| `STATE_DEFAULT` | `0` | **Listed**: a course like any other. | **Listed**: a category like any other. |
| `STATE_UNLISTED` | `1` | Named only to viewers who have a relationship with the course, could join it now, or are its staff. Stays `visible = 1` and keeps working through a direct link. | Named only to viewers who hold a cohort at it, a role at it or above it, or `moodle/category:viewhiddencategories` there. Withholds its whole subtree from listings. |
| `STATE_PUBLIC` | `2` | Listed, and in addition its landing page may be served to visitors who are not logged in, on a site that keeps `forcelogin` on. | Listed, and in addition its page may be served to visitors who are not logged in; inside it, only courses whose **own** state is public. |

The constants exist on both state classes, `\local_unlistedcourses\discoverability` (courses) and
`\local_unlistedcourses\category_discoverability` (categories), with the same values.

| Table | Key | Columns |
|---|---|---|
| `local_unlistedcourses_state` | `courseid`, foreign-unique to `course` | `state`, `usermodified`, `timemodified` |
| `local_unlistedcourses_catstate` | `categoryid`, foreign-unique to `course_categories` | `state`, `usermodified`, `timemodified` |

**Only a non-default state has a row**; absence means listed. Setting a course or category back to
listed deletes its row. A stored value that is not one of the three constants is read as
**listed, never as public**, by `get_states()` on both classes and by the "current state" read
inside `set_state()`: the table is the gate for an anonymous page, so an unknown value must fail
closed. The site course and the category top level (id 0) have no state and are refused by
`set_state()`.

### 1.2 Who may write each transition

| Transition | Course: `discoverability::set_state()` | Category: `category_discoverability::set_state()` |
|---|---|---|
| no change (same state) | no capability, no write, no event | no capability, no write, no event |
| listed and unlisted, either way | no capability of its own (see below) | `local/unlistedcourses:managecategorystate` |
| entering or leaving public | `local/unlistedcourses:publish` | `managecategorystate` **and** `local/unlistedcourses:publishcategory`, in that order |

The three capabilities (`db/access.php`):

| Capability | Context | Risk | Archetypes |
|---|---|---|---|
| `local/unlistedcourses:publish` | course | `RISK_SPAM \| RISK_PERSONAL` | manager |
| `local/unlistedcourses:managecategorystate` | category | none | manager |
| `local/unlistedcourses:publishcategory` | category | `RISK_SPAM` | manager |

None of them declares `clonepermissionsfrom`, on purpose: hiding your own course from listings is
an editing act, publishing it to the open web is not, and no upgrade may back-fill the larger power
from the smaller one. Un-publishing needs the same capability as publishing, because it is the same
decision reversed. The category manage capability exists so a site can delegate "hide this
programme from listings" without handing over `moodle/category:manage`, which carries `RISK_XSS`
and lets its holder rename, move and delete categories.

Moving a course between listed and unlisted carries no gate in `set_state()` because every writer
already sits behind `moodle/course:update` or the restore capabilities, and core itself applies a
course's `visible` flag on restore without asking `moodle/course:visibility`. A second gate would
only ever refuse a restoring teacher, in the un-hiding direction.

**A call that changes nothing needs no capability.** That short circuit is load-bearing: it lets
the course form re-submit "public" from a frozen control when an editor who may not publish saves
an unrelated change (section 1.4).

### 1.3 `set_state()` is the single gate

```php
\local_unlistedcourses\discoverability::set_state(int $courseid, int $state, ?int $userid = null): void;
\local_unlistedcourses\category_discoverability::set_state(int $categoryid, int $state, ?int $userid = null): void;
```

- **The capability check lives here and nowhere else.** Every writer goes through it, so a check
  placed in a form would be a check the other writers route around. Pages and forms check the same
  capabilities only to avoid showing a control whose save would be refused; that is UX, not a
  boundary.
- `$userid` is the user the capability is checked against and who is recorded in
  `usermodified`; `null` means `$USER`. Restore passes the restoring user, which is not
  necessarily `$USER`.
- It throws `coding_exception` for an unknown state, the site course or the category top level,
  and `required_capability_exception` when the user may not make the transition. The category
  method resolves the category context with `MUST_EXIST`, so an unknown category throws before
  anything is read or written.
- After a real change it resets every request memo that depends on the state
  (`access::reset_caches()`, which cascades to the category side) and fires an event (section
  1.5).

There is deliberately **no data generator** for either state: tests and Behat steps write through
`set_state()`, as an administrator would (section 8).

### 1.4 The writers

**Courses** have four writers, all ending in `discoverability::set_state()`:

1. **The course settings form.** `db/hooks.php` registers `\core_course\hook\after_form_definition`
   and `\core_course\hook\after_form_submission`; `classes/hook_callbacks.php` hands both to
   `classes/local/courseform.php`. The form gains a "Discoverability" select, inserted before the
   start date (right below core's "Course visibility"), offered to whoever holds
   `moodle/course:visibility`. For a course being created the context is the category's and the
   creator's role in the new course does not exist yet, so the form asks core's
   `guess_if_creator_will_have_course_capability()` - the call behind core's own visibility
   select - instead of `has_capability()`. The "Public" option appears only for a user who may
   publish. A course that is already public, edited by somebody who may not publish, shows the
   control frozen with `setPersistantFreeze(true)`, which renders a hidden input carrying the
   current value, so saving an unrelated change re-submits "public" and the no-change short circuit
   lets it through. (`hardFreeze()` turns persistence off and must not be used there.)
2. **The course web services and `tool_uploadcourse`**, and an approved course request. Core
   dispatches `after_form_submission` from `create_course()` (after the course row is inserted,
   with `isnewcourse = true`; `course/lib.php:1908-1910`) and from `update_course()` (before the
   row is updated; `course/lib.php:2027-2029`). `core_course_create_courses`,
   `core_course_update_courses`, `tool_uploadcourse` and `course_request::approve()` all call
   those functions, so they reach the same callback and the same capability check.
   `courseform::save()` applies the submitted element when it is present; without it, a new course
   receives the site's creation default and an update leaves the state alone.
3. **Course restore.** `backup/moodle2/backup_local_unlistedcourses_plugin.class.php` writes a
   `state` element for **every** course, a listed one included (`COALESCE(s.state, 0)`), because
   core processes `course.xml` only into a new course, with "overwrite course configuration" on, or
   for a `tool_uploadcourse` template restore, and then rewrites the course settings from the
   backup; an absent element would mean "keep whatever the target has". The restore plugin calls
   `set_state($courseid, $state, $restoringuserid)`. A restorer who may not publish is refused
   inside the gate; the refusal is caught, logged as a restore warning, and the target keeps the
   state it already had. There is no clamp of an incoming public state to listed ahead of the call:
   on an overwrite restore into an unlisted course a clamp would turn a refusal into an un-hiding
   write. Course duplication goes through the same backup and restore.
4. **Code** - your plugin, a test, a Behat step - calling `set_state()` directly.

Two admin settings (`settings.php`, *Site administration > Plugins > Local plugins > Course
discoverability*) supply a state when nobody chose one. Both offer listed or unlisted, default to
listed, and never offer public, because publishing needs the publish capability of whoever creates
or restores the course and a web service call, an upload or a restore run by somebody without it
would be refused:

| Setting | Read by | Applies to |
|---|---|---|
| `local_unlistedcourses/defaultstate` | `discoverability::get_creation_default()` | the form's initial value for a new course, and every course created without the element |
| `local_unlistedcourses/restoredefaultstate` | `discoverability::get_restore_default()` | a backup restored **as a new course** whose state was not applied: no element (a backup from a site without this plugin), a value this version does not know, or a public value refused to the restorer |

The restore default is applied from `after_execute_course()`, which core runs after parsing
`course.xml` whether or not the element was there. A restore into an existing course never
receives it. A `tool_uploadcourse` template restore is `create_course()` followed by a restore into
the current course, so it gets the creation default. An unset or hand-written setting value outside
the two choices reads as listed.

**Categories** have one writer in the UI: `category.php`, reached from the category's settings
menu (the "More" menu of a category page). Core dispatches no hook from the category settings form
(`course/editcategory.php` and its form) and has no custom field handler for categories, so the
control is a page of this plugin's own. `local_unlistedcourses_extend_settings_navigation()` in
`lib.php` hangs it on core's `categorysettings` container, node key `unlistedcoursescatstate`, for a
viewer holding `managecategorystate` in that category. The page:

- calls `core_course_category::get($id, MUST_EXIST)` **before** `$PAGE->set_category_by_id()`,
  which reads the raw record and checks nothing, so a category the viewer may not see is never
  named;
- checks `managecategorystate` (UX only; the boundary is `set_state()`);
- offers "Public" only to a holder of `publishcategory`, and freezes an already public value with a
  persistent freeze, exactly as the course form does;
- renders `classes/output/category_preview.php`: for an unlisted (or about to be unlisted) category,
  the cohorts defined at it (names behind `moodle/cohort:view`, counts for everyone), the people
  holding a role at it or above, and how many people it stays visible to besides staff,
  intersected over every unlisted category above it, with a warning when that is nobody and when
  `$CFG->allowcategorythemes` is on; for a public category, what a visitor will be served and a
  warning when an unlisted or hidden category on its path makes the public state inert.

There is no backup or restore of a category's state: Moodle has no category backup a plugin could
attach to.

### 1.5 Events

| Event | Fired by | Context | `objectid` / `objecttable` | `other` |
|---|---|---|---|---|
| `\local_unlistedcourses\event\course_state_updated` | `discoverability::set_state()` on a real change | course | course id / `course` | `oldstate`, `newstate` (ints) |
| `\local_unlistedcourses\event\category_state_updated` | `category_discoverability::set_state()` on a real change | category | category id / `course_categories` | `oldstate`, `newstate` (ints) |

Both are `crud = 'u'`, `edulevel = LEVEL_OTHER`, and carry the acting user (`$userid` of
`set_state()`) as `userid`. A no-change call fires nothing. Deletion clean-up (below) fires nothing:
core's own deletion is the audited act.

### 1.6 Deletion

- **Courses**: `db/events.php` observes `\core\event\course_deleted` and drops the row
  (`observer::course_deleted()` -> `discoverability::on_course_deleted()`). It is the event and not
  the `before_course_deleted` hook on purpose. With asynchronous course deletion
  (`moodlecourse/enablecourseasyncdeletion`) core runs the `pre_course_delete` callbacks and the
  hook when the deletion is requested, marks the course for deletion and hides it, and does not run
  them again when the adhoc task deletes it (`lib/moodlelib.php`, `delete_course()`). A row dropped
  at request time would leave a course that still exists - and may never finish deleting - without
  its state. `course_deleted` fires once, after the course is gone, in both modes. While the
  deletion is pending the course is hidden, so it is not public either.
- **Categories**: core has no hook for category deletion, so `lib.php` implements the two legacy
  callbacks `local_unlistedcourses_pre_course_category_delete()` and
  `local_unlistedcourses_pre_course_category_delete_move()`, which drop the deleted category's row.
  `delete_full()` recurses and calls the callback once per category; `delete_move()` moves the
  children, which keep their own state and from then on sit under the new parent's.

### 1.7 Privacy

Both tables record who last changed a state (`usermodified`), which is the only personal data the
plugin holds. A deletion request **detaches** the user (sets `usermodified` to 0) and leaves the
state in place: removing the row would un-hide or un-publish a course or category as a side effect
of somebody leaving the site. Each table is keyed to one context level, and the provider resolves
the table from the context it is handed.

---

## 2. Reading the state

### 2.1 Rules shared by every reader

- **Current user only.** Every per-viewer predicate answers for `$USER`. `enrol_self`'s
  `can_self_enrol()` and `enrol_apply`'s `allow_apply()` read `$USER` and take no user id, and a
  reimplementation for another user would fail open as soon as it drifted from the plugin it
  mirrors. To ask about somebody else in a test, `setUser()` first.
- **Request memos, keyed by the viewer.** Every per-viewer answer is memoised for the request under
  `"<userid>:<id>"`, because `setUser()` and "log in as" switch users inside one request. The
  anonymous answers (`is_public()`/`are_public()`) and the stored states are memoised by id alone:
  they have no viewer in them.
- **`access::reset_caches()` forgets everything** - its own memos, `discoverability`'s,
  `category_access`'s and `category_discoverability`'s. `set_state()` on either side calls it. Call
  it yourself when something the answers depend on changes inside one request: an enrolment you
  just wrote, a `visible` flag written straight to a table, a cohort membership.
- **Nothing is cached across requests**, on purpose (section 7.2).
- **A layer on top of core visibility, never a replacement.** The per-viewer predicates read no
  `visible` column and no `moodle/category:viewcourselist`: they answer `true` wholesale for a
  listed course, for a site admin, and for every category while nothing is unlisted. A course core
  hides still passes them. Ask core's own visibility question first (as core's listings already
  do) and this plugin's after it. The anonymous predicates are the exception: they reproduce the
  visibility half of core's answer because core's cannot run for user id 0 (section 2.4).
- **Answers are maps keyed by id**, deduplicated, in the order given. Ids are cast to `int`.

### 2.2 The stored state

| Method | Contract |
|---|---|
| `discoverability::get_states(array $courseids): array` | `courseid => STATE_*`. One query for every id not already known in the request; a missing row is listed. The right shape for a page of courses. |
| `discoverability::get_state(int)`, `is_unlisted(int)` | One course, through `get_states()`. The state only - not whether this viewer may still discover it. |
| `discoverability::state_sql(string $coursealias, string $alias = 'lus'): array` | `['join' => LEFT JOIN ..., 'column' => COALESCE(state, 0)]` for a statement of your own over `{course}`. **The only way to read the state of a population**: `get_states()` puts the ids in one `IN` list and `get_in_or_equal()` never chunks. No parameters. Both aliases must be plain lowercase identifiers and must differ, or it throws. Compare the column with `= STATE_PUBLIC` on an anonymous surface, never with `<> STATE_UNLISTED`, so an unknown stored value fails closed. |
| `discoverability::get_creation_default()`, `get_restore_default()` | The two settings, read as listed when unset or invalid. |
| `category_discoverability::get_states(array)`, `get_state(int)`, `is_unlisted(int)` | The category's **own** row only; an ancestor's state is not consulted here. |
| `category_discoverability::unlisted_ids(): int[]` | Every category whose own row is unlisted, ascending. One query per request. This is the empty-set fast path of the whole category predicate. |
| `category_discoverability::public_ids(): int[]` | Every category whose own row is public, ascending. Own state only; effective publicity is `is_public()`. |

### 2.3 Per-viewer predicates for courses (`\local_unlistedcourses\access`)

| Method | Contract |
|---|---|
| `is_course_discoverable(int $courseid): bool` | Whether `$USER` may learn that this course exists, from **the course's own state only**. An id with no row - including one that does not exist - answers `true`: existence and visibility are core's question. |
| `are_courses_discoverable(array $courseids): array` | The same for many: one statement for the states, then the eligibility rule below **only for the unlisted ones**. |
| `filter_courses(array $courses): array` | The listing filter. Accepts anything with `->id` (records, `core_course_list_element`), preserves keys. Applies the course rule **and the category term** (section 3.3). Reads `->category` off the item when present, one query for the rest. |
| `filter_courses_public(array $courses): array` | The **only** filter an anonymous page may use: keeps a course when `discoverability::are_public()` says so, reads no `$USER`, and ignores any `->category` or `->visible` the item carries (those are what a stale or hand-built list element gets wrong). Fails closed on an id it got no answer for. |
| `prime_relationships(array $enrolled, array $pending = [], array $scheduled = []): void` | Vouches, for this request and this viewer, that `$USER` holds a relationship with these courses (section 2.3.2). |
| `reset_caches(): void` | Forgets every memo of the plugin (section 2.1). |

#### 2.3.1 Who may discover an unlisted course

An unlisted course is discoverable by `$USER` when **any** of these holds (`eligible()` and
`compute_course_relationship()`):

1. **A relationship with the course** (`has_course_relationship()`):
   - the course is the site course (everybody participates on the front page);
   - an active enrolment, as `is_enrolled($context, $USER, '', true)` reads it;
   - staff: `is_viewing()` (`moodle/course:view`, held by managers) or
     `moodle/course:viewhiddencourses` (teacher, editing teacher, course creator, manager);
   - a relationship from the **allow-list** `enrolled`, `scheduled`, `pending`, `waitlisted`
     (section 2.5). `suspended` and `expired` keep nothing by themselves; a relationship type added
     later stays out until it is put on the list on purpose.
2. **Could join right now**: the next action (section 2.6) is `open`, `guest` or `conditional`,
   evaluated per course by asking each enrol plugin, without working out refusal reasons.

A visitor who is not logged in and the guest account are refused before any enrol plugin is asked
(`enrol_apply`'s `allow_apply()` checks neither guest status nor a capability). The rule depends on
time: the enrolment methods enforce their windows and places limits, so an unlisted course leaves
the listing of somebody who could otherwise join it while its window is shut or once it is full -
the same answer core's own enrolment icons give.

#### 2.3.2 Cost, and `prime_relationships()`

The stored states cost one statement per request. Eligibility is the expensive half - relationship
queries, `enrol_get_instances()` and the per-instance checks, none cached by core - and runs only for
unlisted courses, at roughly one or more statements per course probed. It is the one part of a
listing that is not flat.

A caller that has already read the viewer's whole `{user_enrolments}` in one statement knows the
answer for those courses. `prime_relationships()` hands it over: it writes **true** into the
relationship memo for each id, for the current viewer, and is consumed by both the course rule and
the category term. Rules:

- **Only true is ever written.** The caller vouches for a relationship it read; it is not
  authoritative about the absence of one, because being staff of a course is a relationship too and
  no enrolment table carries it. An unprimed course is computed as usual.
- **Prime before you ask.** It does not clear answers already memoised for the request.
- `$pending` includes waiting-list rows. Keyed by the viewer; `reset_caches()` drops it.

`tests/access_test.php` holds that a primed course answers without the enrolment statements.

### 2.4 Anonymous predicates

| Method | Contract |
|---|---|
| `discoverability::is_public(int $courseid): bool` | Whether the course's landing page may be served to a visitor who is not logged in. |
| `discoverability::are_public(array $courseids): array` | The batched form; `is_public()` delegates to it so the two can never disagree. |
| `category_discoverability::is_public(int)`, `are_public(array)` | The same for a category's page. |
| `access::filter_courses_public(array)` | A listing filter over `discoverability::are_public()` (section 2.3). |

A **course** is public when all of these hold:

- its own state is `STATE_PUBLIC`;
- the course row exists and is `visible`;
- every category on its path exists and is `visible`;
- no category on its path is unlisted (its own category included).

A **category** is public when its own state is `STATE_PUBLIC`, its own row exists and is
`visible`, every ancestor exists and is `visible`, and no ancestor is unlisted. **Ancestors need not
be public** - the same composition the course side uses; a strict chain would force a site to
publish its root to publish one programme area. The most specific state decides what is inside: an
unlisted course in a public category is never public, and neither is an unlisted subcategory. A
course's publicity does **not** require its category to be public: a public course in a listed
category is public. Whether a category page lists it is the page's decision (section 4.3).

Why these predicates live on the **state** classes and not on `access`/`category_access`:
everything in the access classes is an answer about a viewer (a cohort, a role, a capability), and
"public" is a property of the thing, composed from the stored state and from rows of the course and
category tables with **no capability involved**. That is necessary: core's own answer to "may this
visitor see the course", `core_course_category::can_view_course_info()`
(`course/classes/category.php:704-717`), ends in `has_capability('moodle/category:viewcourselist')`,
and `has_capability()` returns false for user id 0 whenever `forcelogin` is on
(`lib/accesslib.php:475-477`). So the visibility half of core's answer is reproduced from the rows.
An unlisted category on the path refuses for the same reason: an anonymous visitor can hold none of
the three terms that open it.

Contract:

- **Viewer-independent**: the same answer for a visitor, an administrator and a crawler. Memoised
  per id for the request, with no viewer in the key.
- **Fail-closed and non-throwing**: the ids come from an anonymous request, so a missing course,
  a missing category, a missing ancestor or an empty path is an answer, `false`, never an
  exception. No read uses `MUST_EXIST`.
- **Flat**: four statements whatever the number of ids, every `IN` list bounded by the ids asked
  about or the paths they carry, never by a population. For courses: the states, the candidates'
  own rows, the paths of their distinct categories, the visibility of every category on those
  paths, plus `unlisted_ids()` once per request. For categories: the states, the candidates' own
  rows, the visibility of their ancestors, plus `unlisted_ids()`. `tests/discoverability_test.php`,
  `tests/category_discoverability_test.php` and `tests/access_test.php` compare the reads of a call
  over 200 ids with the same call over 2.

### 2.5 The relationship vocabulary

Two public methods turn enrolment rows into a relationship with a course:

```php
access::classify_enrolment(\stdClass $row, int $now): array;   // Pure: no database, no $USER.
access::get_enrolment_state(int $courseid): array;             // $USER, one statement, memoised.
```

Both return `['type' => RELATIONSHIP_*, 'startsat' => int, 'endsat' => int]`, the dates being the
row's own `timestart` and `timeend` (0 when unset, and for `none`). `classify_enrolment()` takes a
`user_enrolments` row joined with its instance, carrying `status`, `timestart`, `timeend`, `enrol`
(the plugin name) and `instancestatus`. It is the single owner of the per-row rule: a caller that
already holds rows (a listing, a card, a cache) calls it rather than re-deriving the rule.

| Constant | Value | Rule, in the order the branches are tried |
|---|---|---|
| `RELATIONSHIP_PENDING` | `pending` | An `enrol_apply` row that is not active and whose end date is unset or ahead: an application awaiting a decision. Tried first, whatever the instance status and start date - that is `enrol_apply`'s own queue rule. |
| `RELATIONSHIP_WAITLISTED` | `waitlisted` | The same, on the waiting list (`status = 2`). |
| `RELATIONSHIP_NONE` | `none` | A row on a disabled instance, or one whose end date precedes its start date. Also: no row at all. |
| `RELATIONSHIP_EXPIRED` | `expired` | Any row whose end date has passed, whatever its status - an ended enrolment, an approved application the expiry sweep suspended, a waiting-list row the queue no longer counts. |
| `RELATIONSHIP_SUSPENDED` | `suspended` | A row that is not active and has not ended. |
| `RELATIONSHIP_SCHEDULED` | `scheduled` | An active row whose start date is still ahead. Core does not call this user enrolled yet. |
| `RELATIONSHIP_ENROLLED` | `enrolled` | An active row, inside its window. |

`get_enrolment_state()` reads every row the viewer holds in the course in one statement and keeps
the strongest by rank: **enrolled > scheduled > pending > waitlisted > suspended > expired > none**.
Between two scheduled rows the earlier start wins (the date the user waits for); between two expired
rows the later end wins (when access ended). A visitor, the guest account and the site course answer
`none` without a statement. Staff status and "could enrol" are not enrolments and are not reported
here.

### 2.6 The next action

```php
access::get_next_action(int $courseid): array;          // Asks each enrol plugin; memoised per viewer.
access::get_next_actions(array $courseids): array;      // A page of courses in at most four statements.
```

What `$USER` may do now to join the course, **independent of the relationship**: a viewer who is
scheduled, has applied or is suspended may still be offered another route in. The answer:

```php
[
    'type'        => NEXT_OPEN | NEXT_GUEST | NEXT_CONDITIONAL | NEXT_BLOCKED | NEXT_NONE,
    'routes'      => [['kind' => 'self'|'apply'|'fee'|'paypal'|'autoenrol', 'instanceid' => int], ...],
    'guest'       => GUEST_FREE | GUEST_KEY | null,
    'conditional' => ['prerequisiteid' => int, 'instanceid' => int] | null,
    'blocked'     => BLOCKED_* | null,
    'opens'       => int | null,   // a timestamp
]
```

| `type` | Meaning |
|---|---|
| `open` | At least one route would take the viewer now; `routes` lists every one, in the course's own order of methods. |
| `guest` | No route, but an enabled guest instance; `guest` is `free` when one asks for no key (free beats key). |
| `conditional` | No route, no guest access, but an `enrol_coursecompleted` instance will enrol the viewer on completing the course it names; the first such instance is reported. |
| `blocked` | Nothing on offer, but a method that exists for the viewer refuses them now; `blocked` carries the most useful reason. |
| `none` | Nothing on offer. A visitor, the guest account, a missing course and the site course answer `none` without asking any plugin. |

`guest` and `conditional` are filled whenever such an offer exists, whatever the summary; `blocked`
and `opens` only when the summary is `blocked`. When several methods refuse, the reason is the most
useful one by `BLOCKED_PRIORITY`: `window` > `full` > `cohort` > `own_row` > `off`.

| `BLOCKED_*` | Value | Meaning |
|---|---|---|
| `BLOCKED_WINDOW` | `window` | The enrolment window has not opened yet, or has closed. |
| `BLOCKED_FULL` | `full` | No place left. |
| `BLOCKED_COHORT` | `cohort` | Restricted to a cohort the viewer is not in. |
| `BLOCKED_OWN_ROW` | `own_row` | The viewer's own enrolment stands in the way: a row on that instance, or one in the course. |
| `BLOCKED_OFF` | `off` | No new enrolments, no price, or a refusal the instance fields do not explain (the self enrolment capability, an autoenrol rule). |

#### 2.6.1 What each enrolment method is asked

Each method is asked the question its own enrolment page asks. Methods other than these (manual,
cohort sync, meta link, ...) offer a learner nothing to act on and are ignored.

| Method | Offers | Asked | Reasons when it refuses, in its own order |
|---|---|---|---|
| `enrol_self` | route `self` | `can_self_enrol($instance, false) === true` (strictly: it can return `null` when its cohort was deleted) | `window`, `off` (new enrolments off), `own_row`, `full`, `cohort`; otherwise `off` |
| `enrol_apply` | route `apply` | `allow_apply()` and the places limit (`is_full()` when the plugin has it), on an instance where the viewer holds no row; only for a build that provides `allow_apply()` | `own_row` first; then `off`, `window`, `cohort` (also for the `-1` restore sentinel); then `full` |
| `enrol_fee`, `enrol_paypal` | route `fee` / `paypal` | what their `enrol_page_hook()` tests before offering the payment button: no row of the viewer's on the instance, the window open, a cost of at least 0.01 (the plugin default when the instance cost is zero or less) | `own_row`, `window`, `off` |
| `enrol_guest` | `guest` | any enabled instance; a key, when set, is asked for on entry | - |
| `enrol_autoenrol` | route `autoenrol` | the plugin's own `enrol_allowed()`; only for a build that provides it | `own_row` (a row on the instance, or any row in the course when the instance does not enrol people already enrolled), `off`, `window`, `full`; a refusal by its rule reads `off` |
| `enrol_coursecompleted` | `conditional` | only for a viewer **actively enrolled in the prerequisite** course the instance names; anybody else is offered nothing by it, not even a refusal, because naming the course to them would defeat the unlisting | `own_row`, `window` |

The methods outside core are optional: `allow_apply()` and `enrol_allowed()` are called through the
plugin object behind `is_callable()`, never by naming a class of theirs.

#### 2.6.2 The `opens` contract

`opens` says when a refused route would take the viewer: the earliest enrolment start date still
ahead among the instances whose **only** refusal is that their window has not opened yet. Each
such instance is asked again by the same rule with its start date cleared, and counts when it then
offers a route (`self`, `apply`, `fee`, `paypal`, `autoenrol`). An instance that would still refuse
(full, cohort, own row, new enrolments off, the self enrolment capability, an autoenrol rule) gives
no date; neither does a window that has closed, one that ends before it starts, nor
`enrol_coursecompleted`, which offers no route.

`blocked` stays the most useful reason across the instances, so it reads `window` unless another
instance's refusal outranks it. **A surface that shows when enrolment opens reads `opens`, never
`blocked`, and never works a date out itself.** Like an open route, a date says when the
instance's own rule admits the viewer, not that a key, a payment or another method will not stand
in the way.

#### 2.6.3 Per course or batch

| | `get_next_action()` | `get_next_actions()` |
|---|---|---|
| Asks | each enrol plugin, per instance | the same rules, read from at most four bulk statements: the enabled instances (with course contexts preloaded), every row the viewer holds, the viewer's cohorts (only when an instance restricts by one), one grouped count per capped instance (only when one is capped) |
| Drops | nothing | `enrol/self:enrolself` and the autoenrol rule, which SQL cannot read |
| May differ | - | may answer `open` where the per-course form answers `blocked`, **never the reverse** |
| `opens` | as above | checks the self enrolment capability before dating and never dates an autoenrol instance with a rule, so it may answer `null` where the per-course form dates, **never the reverse** |
| Input | one course | a **page** of courses: the ids go into one `IN` list and `get_in_or_equal()` does not chunk |

Two rules follow:

- **Neither decides whether a course may be named.** A caller that prints a next action for a course
  in a listing must already have filtered the listing with `filter_courses()`. The batch errs towards
  open by design, so wiring it into a discoverability decision would name unlisted courses to people
  the enrol plugins refuse.
- The discoverability rule itself uses the per-course evaluation **without reasons**: a listing that
  probes unrelated unlisted courses must not pay statements to explain refusals nobody reads.

### 2.7 At a glance

| Method | Viewer | Memo key | Cost |
|---|---|---|---|
| `discoverability::get_states()` | none | course | 1 per request for unknown ids |
| `discoverability::state_sql()` | none | - | 0: a join in your statement |
| `discoverability::are_public()` / `is_public()` | none | course | 4 + `unlisted_ids()` |
| `category_discoverability::are_public()` / `is_public()` | none | category | 4 including `unlisted_ids()` |
| `access::filter_courses_public()` | none | course | as `are_public()` |
| `access::are_courses_discoverable()` / `is_course_discoverable()` | `$USER` | viewer:course | 1 for states + eligibility per unlisted course |
| `access::filter_courses()` | `$USER` | viewer:course | the above + the category predicate + relationship probes for courses under an unlisted category |
| `category_access::are_categories_discoverable()` / `filter_categories()` | `$USER` | viewer:category | at most 4 per request; 1 when nothing is unlisted |
| `access::get_enrolment_state()` | `$USER` | viewer:course | 1 per course |
| `access::get_next_action()` | `$USER` | viewer:course | statements per instance |
| `access::get_next_actions()` | `$USER` | - | at most 4 per call; 0 for a visitor |

---

## 3. Category semantics

### 3.1 State is a property of the path

`\local_unlistedcourses\category_access` answers the per-viewer question:

| Method | Contract |
|---|---|
| `is_category_discoverable(int $categoryid): bool` | Whether `$USER` may learn that this category exists. |
| `are_categories_discoverable(array $categoryids): array` | The same for many. |
| `filter_categories(array $categories): array` | Accepts `core_course_category` objects, `course_categories` records and bare ids in one array, preserves keys. An item that carries its own `path` costs nothing to resolve. |

A category is **effectively unlisted** when it or any ancestor carries the unlisted row, read from
`course_categories.path`. One row withholds a whole subtree, and clearing a child's own row cannot
un-hide it. The quantifier over the path is **AND**: for **every** effectively unlisted category U
on the path, the viewer must satisfy one of three terms. Two nested unlisted categories need
eligibility for both; the inner one is not a way out of the outer one.

### 3.2 The three terms

For each unlisted category U on the path:

1. **Cohort**: the viewer belongs to a cohort whose context is **U's own** context. Not an
   ancestor's and not the system one: a system cohort is how most sites hold their whole student
   body, and treating it as a relationship with one category would admit everybody. Membership is
   read from `{cohort_members}` directly, never through `cohort_get_user_cohorts()`, which filters
   `visible = 1` (`cohort/lib.php`): an invisible cohort is a normal way to run an automatic
   membership rule, and it still grants.
2. **Role**: any role assignment in U's context **or an ancestor category context** of U - the
   path prefix up to and including U. A role at a category below U, or at a course inside it, is a
   relationship with something U contains, not with U, and grants nothing. System-context
   assignments are not read: a role held site-wide says nothing about one category.
3. **Staff**: `has_capability('moodle/category:viewhiddencategories', U)`, core's own idiom for
   "may see a hidden category" (course creator and manager by archetype). This is what admits a
   manager or course creator assigned at the system context.

Further rules:

- **Site admins** discover everything, decided before any term is read.
- **Visitors and the guest account** discover nothing that is effectively unlisted, and the cohort
  and role queries are skipped for them.
- **The empty-set fast path**: when `unlisted_ids()` is empty, every category answers `true` for one
  statement, for everybody. This is what makes it affordable to ask on every listing, and it is held
  by its own mutation gate.
- A category that does not exist answers `false` while anything is unlisted. Category id 0, the top
  level, is not a category: it answers `true` only while nothing is unlisted, so do not ask about it.
- **Role switching does not change a category answer.** The role term is a raw read of
  `{role_assignments}`, which no switch touches, and `has_capability()` at a category context ignores
  a switch made in a course. A manager who switches to student inside a course keeps the category
  tree they administer.

The budget is at most four statements per request whatever the size of the listing: the unlisted
ids, the paths the caller did not carry, the viewer's cohorts at unlisted categories, and the
viewer's category role assignments; context instances come from accesslib's cache on top of that.

Who discovers an unlisted category C, and the courses inside it, in listings:

| Viewer | C in `filter_categories()` | A listed course inside C in `filter_courses()` |
|---|---|---|
| Site admin | yes | yes |
| Holds `moodle/category:viewhiddencategories` at C (manager or course creator, at C, above it or at system) | yes | yes |
| Any role assignment at C or an ancestor category | yes | yes |
| Member of a cohort defined at C | yes | yes |
| Member of a system cohort only; a role at system only without the capability | no | only courses they have a relationship with |
| Editing teacher of a course inside C, enrolled student, applicant | no | their own course only |
| Somebody who merely could self-enrol | no | no (in listings; see 3.3) |
| Visitor or guest | no | no |

### 3.3 The category term applies to listings only

`access::filter_courses()` drops a course whose category is effectively unlisted for this viewer
**unless** the viewer has a relationship with the course: enrolled (or scheduled), an application
pending or on its waiting list, or staff of it. Being able to enrol right now does **not** rescue it:
an open self enrolment instance is the normal case inside an unlisted category, so honouring it would
leave the category rule withholding nothing.

`is_course_discoverable()` and `are_courses_discoverable()` never read the category. Pages reached
by a link - `enrol/index.php`, `course/info.php`, a theme's landing page - are gated on those
methods, and a listing rule placed there would become an enrolment block: somebody handed the link
to a course inside an unlisted category, who may enrol in it, must still be able to. **Never move the
category term into `is_course_discoverable()`.**

The anonymous side is consistent with this: `discoverability::is_public()` refuses a course with an
unlisted category anywhere on its path.

### 3.4 Public categories

| Arrangement | `category_discoverability::is_public()` |
|---|---|
| Public, visible, every ancestor exists and is visible, none unlisted | `true` |
| Public under an unlisted ancestor | `false` |
| Public, but hidden itself or under a hidden ancestor | `false` |
| Listed or unlisted, however visible | `false` |
| An id that does not exist, or a state row whose category is gone | `false`, no exception |

A public category is meant to be named, so none of the surfaces in section 7.3 is a leak for it.
What a visitor is served inside a public category is the theme's decision; the plugin's answer for
the courses is `filter_courses_public()`, which keeps only courses whose **own** state is public. A
category-level act never publishes the courses inside it.

---

## 4. Integrating a theme

### 4.1 Why a theme, and not this plugin

Overriding a core renderer is a theme privilege. `theme_config::renderer_prefixes()`
(`lib/classes/output/theme_config.php:656-666`) returns only `theme_<name>` prefixes, for the theme
and its parents, and Moodle has no `db/renderers.php` mechanism for plugins (a theme's own
`renderers.php` file is loaded for that theme only). A local plugin cannot substitute
`core_course_renderer`. So the **decision** lives here and the **rendering** lives in the site's
theme, which calls the predicates.

### 4.2 Listings

Override the course renderer methods that **produce both the rows and the count**, and filter
there:

- Filter course rows with `access::filter_courses()` where they are fetched, then paginate and count
  what is left. A paging bar or a "N results" line counted before the filter leaks the withheld
  courses (an empty second page, a count larger than the list).
- Filter category nodes with `category_access::filter_categories()` or
  `is_category_discoverable()`, including the expand affordance: an arrow that opens onto nothing is
  an existence signal of its own.
- Keep a net in the method most listings funnel through (`coursecat_courses()`), for surfaces you
  did not enumerate, but do not rely on it alone: the `$totalcount` it receives was counted by
  somebody else.
- Any line that prints a course's category name ("Category: ...", a card badge) must ask
  `is_category_discoverable()` too.

### 4.3 Pages reached by a link

A withheld course or category can still be reached by a guessed or shared URL. Gate those pages on
the per-viewer predicates, early - before core's page code redirects on its own:

- `enrol/index.php?id=N` and `course/info.php?id=N` (which also accepts `?name=<shortname>`) on
  `access::is_course_discoverable()`;
- `course/index.php?categoryid=N` on `category_access::is_category_discoverable()`.

A hook on `\core\hook\after_config` is early enough; skip CLI, AJAX and web service requests and
install or upgrade. Answer a refusal with the same page whatever the reason, so a guessed id learns
nothing. Two recommendations from the reference implementation:

- **Do not ghost visitors.** For somebody who is not logged in the predicate can only answer "no",
  and ghosting would strand a user who would be eligible the moment they sign in. Let core send them
  to the login page; the decision is taken again on the way back.
- **Fail open on a missing dependency** for this per-viewer ghost (nobody is ghosted), but **fail
  closed** for the anonymous gate in 4.4. One withholds a page from somebody who may be entitled to
  it, the other withholds a page from the internet. Pin both with tests that hand the adapter a class
  that does not exist.

### 4.4 Anonymous visitors on a `forcelogin` site

`forcelogin` is not an ambient gate: core reads `$CFG->forcelogin` explicitly in a number of places
(`require_login()` among them), and a page that never calls `require_login()` - as
`login/index.php` does not - is reachable anonymously with `forcelogin` left on. A public landing
page is built that way:

1. **Run `discoverability::is_public()` first**, before the course is looked up, and answer every
   non-public course - unlisted, hidden, listed, or not there at all - with the same refusal (the
   login page), so an anonymous client cannot tell which ids exist.
2. **Do not call core's `can_view_course_info()` for a visitor.** It ends in a capability check, and
   capabilities are hard-denied to user id 0 under `forcelogin`; its visibility half is already
   inside `is_public()`.
3. **List with `access::filter_courses_public()`**, or, for a statement of your own over a whole
   subtree, with `discoverability::state_sql()` compared `= STATE_PUBLIC` together with
   `c.visible = 1`, followed by `are_public()` on the page slice for the category path. Never with a
   per-viewer predicate.
4. A category's anonymous page asks `category_discoverability::is_public()` the same way, before
   anything else. A theme that renders it through `core_course_category::get($id, ..., true)`
   (`alwaysreturnhidden`) must run the predicate first and refuse on `false`; the ordering is the
   whole safety of it.

### 4.5 Files for anonymous pages

Core requires login for a course's `summary` and `overviewfiles` areas under `forcelogin`
(`lib/filelib.php:4964-4967`), so the course image and images embedded in the summary answer a
visitor with a redirect to login. A plugin component's own file areas, on the other hand, are
dispatched by `file_pluginfile()` to `<component>_pluginfile()` with no login check of its own (the
generic branch at the end of `file_pluginfile()`). That is what makes an anonymous image route
possible: serve the files through a pluginfile callback of the **theme's** component and, inside it,

- re-run `discoverability::is_public()` from scratch on every request (the page that minted the URL
  is not trusted);
- check that the course id in the path is the context's own instance;
- serve images only, so an uploaded `.html` cannot become same-origin script for every visitor;
- decide cacheability yourself (`private` keeps a shared proxy from serving an un-published image).

Boost Union's flavour files are not reachable this way: its
`theme_boost_union_flavours_require_login_for_file()` calls `require_login()` whenever `forcelogin` is
on, so a flavour's logo or background answers a visitor with the login page.

---

## 5. Worked examples

### theme_dimensions

The reference implementation: a Moodle 5.3 community theme, child of Boost Union, with a hard
dependency on this plugin (`$plugin->dependencies`). It consumes `access`, `discoverability`,
`category_access` and `category_discoverability`.

| Concern | Theme class | What it calls, and why |
|---|---|---|
| Listings | `theme_dimensions\output\core\course_renderer` (extends Boost Union's) | `access::filter_courses()` in `coursecat_courses()` (the net), and in `coursecat_category_content()`, `search_courses()`, `frontpage_available_courses()` and `tagged_courses()`, which fetch, filter and only then paginate; `category_access::filter_categories()` / `is_category_discoverable()` in `coursecat_category()`, `coursecat_tree()`, `coursecat_subcategories()`, `coursecat_category_content()` (the AJAX expander enters there with an id from the request), `course_category()`, `course_category_name()` and `coursecat_coursebox_content()` (Boost Union's card badge names the category through its own helper). |
| Ghosting by link | `local\hook\after_config`, `local\landing\redirector` | `is_ghosted()` over `access::is_course_discoverable()` for `enrol/index.php` and `course/info.php`; `is_category_ghosted()` over `category_access::is_category_discoverable()` for `course/index.php?categoryid=N`. Visitors are not ghosted; both fail open when the plugin is missing. |
| Course landing page | `landing.php`, `local\landing\request` | No `require_login()`. `request::handle()` refuses a visitor unless `local\landing\publicaccess::is_public()` (a fail-closed adapter over `discoverability::is_public()`), then ghosts a logged-in user through `redirector::is_ghosted()`, then mirrors the guards of `enrol/index.php`, skipping the category capability check for a visitor. |
| What the landing page offers | `local\landing\resolver`, `output\landing_page` | `access::get_enrolment_state()` and `get_next_action()` for the viewer's relationship and the route to offer. |
| Anonymous files | `theme_dimensions_pluginfile()`, `local\landing\publicfiles` | `resolve()` re-checks `publicaccess::is_public()` per request; `viewer_may_see_many()` uses `access::are_courses_discoverable()` for a logged-in reader's cards. |
| Category showcase | `local\category\population`, `guard`, `cards`, `classifier`, `enrichment` | Core visibility first, then `category_access::filter_categories()` and `access::filter_courses()`; `access::prime_relationships()` from the viewer's enrolment rows, read in one statement; `discoverability::state_sql()` to read each course's state inside the subtree statement (`= STATE_PUBLIC` for the anonymous shell); `filter_courses_public()` and `category_discoverability::are_public()` for the anonymous page; `classify_enrolment()`, `get_enrolment_state()` and `get_next_actions()` for the cards and their opening dates. Unrelated unlisted courses are probed per course up to a cap and the rest withheld (fail closed). |
| Anonymous category page | `local\category\publicaccess` | A fail-closed adapter over `category_discoverability::is_public()`, with the theme's own on/off switches folded in, asked by the page, the forward from `course/index.php`, the image route and the Open Graph tags. |
| Tests | `tests/behat/behat_theme_dimensions.php` | A step `the course "X" is "listed\|unlisted\|public" for discoverability` writing through `discoverability::set_state()` as the site administrator. |

### block_compass

A "My courses" block with a **hard dependency** on this plugin. It owns no rule about what an
enrolment row means:

- `block_compass\local\relationship` is its one door to `access::classify_enrolment()`, called once
  per cached inventory row.
- It shows four relationships - `enrolled`, `scheduled`, `pending`, `waitlisted` - and compares
  against the `RELATIONSHIP_*` constants in `explore`, `cards`, `inventory` and `attention`.
- Its `relationship::SHOWN` order mirrors this plugin's rank, and its `relationship_test` holds that
  order against `get_enrolment_state()` over the same rows.

### local_dimensions

A learning-plan plugin for which this one is **optional**. `enrolment_provider::get()` selects
`enrolment_provider_unlisted` only when its `useunlistedcourses` setting is stored as `'1'` and
`unlisted_available()` finds `get_enrolment_state()`, `get_next_action()` and `get_next_actions()`
on `local_unlistedcourses\access`; otherwise it falls back to its own core-only rule.

- Per course it calls `get_enrolment_state()` and `get_next_action()`; for a list, `get_next_actions()`
  once and `get_enrolment_state()` per course.
- It maps the answers from **string literals**, not this plugin's constants, so it never names a class
  of this plugin outside the provider; its `enrolment_provider_unlisted_test` holds the literals
  against the constants wherever this plugin is installed.
- It reads `opens` for the "Enrolment opens on" chip, `startsat`/`endsat` for scheduled and expired
  cards, `guest` (`key` versus free) and `conditional.prerequisiteid`.

---

## 6. The supported API and what a change breaks

The supported API is what this guide documents as public: the methods in sections 2 and 3, the
`STATE_*`, `RELATIONSHIP_*`, `NEXT_*`, `BLOCKED_*` and `GUEST_*` constants **and their string or
integer values**, the array shapes they return, the capabilities, the events and the two settings.
Everything private in `classes/` may change.

A change of meaning is a change to the consumers too:

| Change | Breaks |
|---|---|
| A `RELATIONSHIP_*` value, the rank order, or the row fields `classify_enrolment()` reads | block_compass (`relationship`, `relationship_test`), local_dimensions (literal map and its test), theme_dimensions (`classifier`, cards) |
| A `NEXT_*`, `GUEST_*` or `BLOCKED_*` value, a key of the next-action shape, or the `opens` rule | local_dimensions (`translate()`, the opening-date chip), theme_dimensions (landing page offer, showcase cards) |
| Removing or renaming `get_enrolment_state()`, `get_next_action()` or `get_next_actions()` | local_dimensions silently falls back to its own rule (`unlisted_available()` answers false) |
| The meaning of `is_course_discoverable()` or `filter_courses()` | theme_dimensions listings, the ghost of `enrol/index.php` / `course/info.php`, the landing page and its tests |
| The meaning of `is_category_discoverable()` or `filter_categories()` | theme_dimensions listings, the category ghost and the showcase |
| The composition of `is_public()` / `are_public()` on either class, or `state_sql()` | theme_dimensions anonymous landing page, image route, public category page |
| `set_state()` signature or gate | every Behat step and PHPUnit fixture that writes a state (here and in theme_dimensions) |

---

## 7. What the plugin does not do

### 7.1 It renders nothing

No listing, landing page, enrolment page, Open Graph tag or image route lives here (section 4.1).
A public course or category is **armed but inert** until the site's theme serves it: the plugin
only says "may be served anonymously".

### 7.2 It caches nothing beyond the request

The per-viewer answers depend on cohort membership, and cohort membership can change without an
event: `tool_dynamic_cohorts`, for one, writes `cohort_members` in bulk without firing
`cohort_member_added` or `cohort_member_removed`. A cache invalidated by those events would keep
showing a course to somebody just removed from the cohort that gated it. The answers also depend on
`time()`, through the enrolment windows. Request-scoped memos only; a cross-request cache of the
stored state alone would buy nothing and add a second place to keep coherent.

### 7.3 It does not cover every surface

**Unlisting is a listing rule, not a permission.** The predicates are consulted only where
something calls them. Surfaces that reach course or category names by their own route, and so
still name unlisted ones, include:

| Surface | Notes |
|---|---|
| Web services: `core_course_search_courses`, `core_course_get_courses_by_field`, `core_course_get_categories`, `core_course_get_courses`, `core_enrol_get_users_courses` | `core_course_search_courses` is `ajax => true` (`lib/db/services.php`), callable from any logged-in browser session. Remove the services from the site when the mobile app is not used. |
| The Moodle App | Uses the web services above. |
| Navigation tree, course index drawer, `block_navigation`, the breadcrumb of a course page inside an unlisted category | Node builders run at page initialisation. |
| `block_course_list` | Prints category names without a renderer call; do not place it. |
| Boost Union smart menus listing courses | Cached per user; avoid course-listing smart menus over unlisted courses or categories. |
| Report builder: the course category entity and categories datasource | Keep category columns out of reports shared beyond staff. |
| `course/request.php` | Names the requested category; keep course requests off, or the category listed. |
| `block_myoverview` and other "my courses" surfaces | Name the category of the viewer's own courses, which they already keep. |
| Course files through `pluginfile.php`, badge pages | Reach course names and files by their own route. |
| Theme switches: `allowcategorythemes`, `allowcohortthemes`, `allowuserthemes`, `allowcoursethemes`, `allowthemechangeonurl` | Leaving the enforcing theme leaves its listings behind. The category page warns about the first. |

Enforcing at the capability layer instead - writing `CAP_PREVENT` on `moodle/category:viewcourselist`
in the category context - would reach web services, the app and the navigation, and is not done.
`moodle/category:viewcourselist` is granted by archetype to `guest` and `user` only
(`lib/db/access.php`), so managers and teachers would go dark unless re-granted; the per-session
`coursecat` cache (`lib/db/caches.php`, TTL 600 s) is invalidated by course and category change
events, not by role or capability writes; and cohort membership would have to be materialised into
role assignments, which is the cross-request state section 7.2 rules out.

### 7.4 It answers for the current user only

There is no `is_course_discoverable($courseid, $userid)`. See section 2.1.

---

## 8. Testing your integration

### 8.1 PHPUnit

- **Write the state through the gate**, as somebody allowed to make the transition:

  ```php
  $this->setAdminUser();
  \local_unlistedcourses\discoverability::set_state((int) $course->id, \local_unlistedcourses\discoverability::STATE_UNLISTED);
  \local_unlistedcourses\category_discoverability::set_state((int) $category->id, \local_unlistedcourses\category_discoverability::STATE_PUBLIC);
  ```

  or pass the acting user's id as the third argument. A refusal is a `required_capability_exception`;
  a test of a refusal should also show the same call succeeding for somebody who may make it.
- **Switch viewers with `setUser()`**; the memos are keyed by viewer, but call
  `\local_unlistedcourses\access::reset_caches()` whenever you change what an answer depends on in the
  same request: an enrolment, a cohort membership, a role assignment, or a `visible` flag or path
  written straight to a table (the anonymous memos would otherwise answer from before the write).
- **Ask anonymous questions as user 0** (`$this->setUser(0)`) and set `forcelogin` when the test is
  about it; the public predicates do not read the viewer, but your page code will.
- **Measure statements with `$DB->perf_get_reads()`** around the call, and compare a small input with
  a large one spread over many categories, as this plugin's budget tests do.
- **`enrol_apply` is absent from a fresh test site's `enrol_plugins_enabled`**: enable it before
  creating an instance, or `enrol_get_instances($id, true)` filters the instance out and the predicate
  answers "cannot enrol" for an unrelated reason. Tests that need `enrol_apply`,
  `enrol_autoenrol` or `enrol_coursecompleted` should skip when the plugin or its method is missing.
- **Asynchronous course deletion** (Moodle 5.3): enable `moodlecourse/enablecourseasyncdeletion`,
  call `delete_course($id, false)`, and run the adhoc tasks. Until then the course exists, hidden,
  with its state row; afterwards the row is gone. `tests/course_deletion_test.php` (on the 5.3 line only: 5.2 has no
  asynchronous deletion) runs that real path and is a template for a consumer that keeps rows of its own per course.

### 8.2 Behat

This plugin's context, `tests/behat/behat_local_unlistedcourses.php`, provides one step:

```gherkin
Given the category "CAT1" is "unlisted" for discoverability
```

The category is named by its `idnumber`; the state is `listed`, `unlisted` or `public`; the step
calls `category_discoverability::set_state()` as the site administrator. There is no course step
here; write one in your own context the way theme_dimensions does:

```php
/**
 * Put a course into a discoverability state, as the site administrator.
 *
 * @Given /^the course "(?P<shortname>(?:[^"]|\\")*)" is "(?P<state>listed|unlisted|public)" for discoverability$/
 * @param string $shortname The course shortname.
 * @param string $state One of listed, unlisted, public.
 * @return void
 */
public function the_course_is_for_discoverability(string $shortname, string $state): void {
    global $DB;

    $courseid = (int) $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
    $states = [
        'listed' => \local_unlistedcourses\discoverability::STATE_DEFAULT,
        'unlisted' => \local_unlistedcourses\discoverability::STATE_UNLISTED,
        'public' => \local_unlistedcourses\discoverability::STATE_PUBLIC,
    ];
    \local_unlistedcourses\discoverability::set_state($courseid, $states[$state], (int) get_admin()->id);
}
```

Core's Behat cohort generator can place a cohort in a category context through its
`contextlevel | reference` columns, which is what the cohort term needs. Keep `forcelogin` on in
scenarios about anonymous pages: the point is that they work with it on. A Behat context file must
not carry a `MOODLE_INTERNAL` guard.

### 8.3 This plugin's own suite

| Test | Holds |
|---|---|
| `tests/access_test.php` | the course rule, the category term, `filter_courses_public()`, `prime_relationships()`, viewer-keyed memos |
| `tests/next_action_test.php` | the relationship vocabulary, the next action per method, batch parity, `opens`, statement counts |
| `tests/category_access_test.php` | the three terms, AND over the path, the fast path |
| `tests/discoverability_test.php`, `tests/category_discoverability_test.php` | the gates, the public composition and its budget, unknown stored values, `state_sql()` |
| `tests/local/courseform_test.php` | the hook wiring, through `create_course()` and `update_course()` |
| `tests/backup_restore_test.php` | backup of every course, the restore gate, the restore default, restore into an existing course |
| `tests/course_deletion_test.php` | the `course_deleted` observer on the asynchronous path (5.3 line only) |
| `tests/privacy/provider_test.php` | the privacy provider |
| `tests/behat/*.feature` | the course form control and the category page |

`mutations/*.conf` lists the guards of the production code, each with the test that must redden
when the guard is removed.
