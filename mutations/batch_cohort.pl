# Let the batch ignore the cohort restriction of enrol_self and enrol_apply.
s/\(int\) \$instance->customint5 === 0 \|\| isset\(\$cohorts\[\(int\) \$instance->customint5\]\),/true,/;
