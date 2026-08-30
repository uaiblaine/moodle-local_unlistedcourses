# Drop the pending-application term: an applicant awaiting a decision loses the
# course from the listing the moment they apply.
s/return self::has_pending_enrolment\(\$courseid\) \|\| self::can_enrol\(\$courseid\);/return self::can_enrol(\$courseid);/;
