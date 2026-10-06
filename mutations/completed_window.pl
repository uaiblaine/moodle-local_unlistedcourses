# Ignore the enrolment window of a course completed instance: it keeps the course discoverable
# after it has stopped enrolling anybody.
s/        if \(!self::inside_window\(\$instance, \$now\)\) \{\n            return self::outcome\(\$instance, self::NEXT_BLOCKED, self::BLOCKED_WINDOW\);\n        \}\n(        \$outcome = self::outcome\(\$instance, self::NEXT_CONDITIONAL\);)/$1/;
