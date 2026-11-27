# Drop the row fact: a role in the course with no enrolment row (state none) reads as lapsed and stops being staff.
s/return \$holdsrow && in_array\(\$state, self::LAPSED_RELATIONSHIPS, true\);/return in_array(\$state, self::LAPSED_RELATIONSHIPS, true);/;
