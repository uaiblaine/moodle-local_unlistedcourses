# Drop the cohort check of the self enrolment copy.
s/if \(\$instance->customint5 && !\$facts\['incohort'\]\) \{\n            return false;/if (false) {\n            return false;/;
