# Ignore the enrolment window of a course completed instance: it keeps the course discoverable
# after it has stopped enrolling anybody.
s/(is_enrolled\(\$prerequisite[^\n]*\n\s+&& !self::holds_enrolment\(\$instance\))\n\s+&& self::inside_window\(\$instance, \$now\)/$1/;
