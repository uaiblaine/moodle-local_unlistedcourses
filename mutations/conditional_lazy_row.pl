# Without primed facts, never read the viewer's own row on an enrol_conditional instance.
s/\$facts\['holdsrow'\] : self::holds_enrolment\(\$instance\);/\$facts['holdsrow'] : false;/;
