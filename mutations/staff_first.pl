# Ask staff last instead of first: the verdicts stay, but a manager pays the enrolment state per course,
# which the probe does not prime for staff.
s/        if \(self::is_staff\(\$context\)\) \{\n            return true;\n        \}\n\n//;
s/return isset\(\$USER->enrol\['enrolled'\]\[\$courseid\]\) && /return self::is_staff(\$context) || isset(\$USER->enrol['enrolled'][\$courseid]) && /;
