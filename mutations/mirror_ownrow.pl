# Drop the ownrow check of the self enrolment copy.
s/if \(\$facts\['holdsrow'\]\) \{\n            return false;/if (false) {\n            return false;/;
