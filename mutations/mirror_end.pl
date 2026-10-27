# Drop the end check of the self enrolment copy.
s/if \(\$instance->enrolenddate != 0 && \$instance->enrolenddate < \$now\) \{\n            return false;/if (false) {\n            return false;/;
