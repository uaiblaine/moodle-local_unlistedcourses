# Ignore the enrolment window of a course completed instance: it keeps the course discoverable
# after it has stopped enrolling anybody.
s/if \(!self::holds_enrolment\(\$instance\) && self::inside_window\(\$instance, \$now\)\) \{/if (!self::holds_enrolment(\$instance)) {/;
