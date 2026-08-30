# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

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
