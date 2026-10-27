# Drop the start check of the self enrolment copy.
s/if \(\$instance->enrolstartdate != 0 && \$instance->enrolstartdate > \$now\) \{\n            return false;/if (false) {\n            return false;/;
