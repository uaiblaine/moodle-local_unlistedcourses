# Filter the cohort query by c.visible = 1, which is what
# cohort_get_user_cohorts() does and what this query exists to avoid: an
# invisible cohort then grants nothing.
s/JOIN \{cohort\} c ON c\.id = cm\.cohortid/JOIN {cohort} c ON c.id = cm.cohortid AND c.visible = 1/;
