# Drop the status check of the self enrolment copy.
s/if \(\$instance->status != ENROL_INSTANCE_ENABLED\) \{\n            return false;/if (false) {\n            return false;/;
