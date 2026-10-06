# Drop the enrolment-relationship term: an applicant awaiting a decision, and a user enrolled
# from a later date, lose the course - from the unlisted-course predicate, and from the listing
# escape that survives an unlisted category.
s/return in_array\(self::get_enrolment_state\(\$courseid\)\['type'\], self::DISCOVERABLE_RELATIONSHIPS, true\);/return false;/;
