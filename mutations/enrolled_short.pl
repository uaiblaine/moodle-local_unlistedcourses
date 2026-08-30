# Remove the active-enrolment short circuit: an enrolled user then has to pass
# the cohort gate like anybody else.
s/if \(is_enrolled\(\$context, \$USER, '', true\)\) \{\n            return true;\n        \}/if (false) {\n            return true;\n        }/;
