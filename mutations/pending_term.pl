# Drop the pending-application term: an applicant awaiting a decision loses the
# course the moment they apply - from the unlisted-course predicate, and from
# the listing escape that survives an unlisted category.
s/return self::has_pending_enrolment\(\$courseid\);/return false;/;
