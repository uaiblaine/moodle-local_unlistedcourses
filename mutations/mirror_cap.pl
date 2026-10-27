# Drop the cap check of the self enrolment copy.
s/if \(\$instance->customint3 > 0 && \$facts\['taken'\] >= \$instance->customint3\) \{\n            return false;/if (false) {\n            return false;/;
