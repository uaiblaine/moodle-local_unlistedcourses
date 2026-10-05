# Keep a course completed instance a route for a viewer who already holds a row on it.
s/(is_enrolled\(\$prerequisite, \$USER, '', true\)\n)\s+&& !self::holds_enrolment\(\$instance\)\n/$1/;
