# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

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
