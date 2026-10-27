# Drop the enrol/self:enrolself check of the self enrolment copy.
s/return has_capability\('enrol\/self:enrolself', \\core\\context\\course::instance\(\(int\) \$instance->courseid\)\);/return true;/;
