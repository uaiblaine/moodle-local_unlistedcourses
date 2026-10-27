# Drop the newenrols check of the self enrolment copy.
s/if \(!\$instance->customint6\) \{\n            return false;/if (false) {\n            return false;/;
