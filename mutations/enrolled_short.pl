# Remove the active-enrolment short circuit: the relationship term still keeps the course, but
# every enrolled viewer pays the staff checks and the enrolment-row statement first.
s/if \(is_enrolled\(\$context, \$USER, '', true\)\) \{\n            return true;\n        \}/if (false) {\n            return true;\n        }/;
