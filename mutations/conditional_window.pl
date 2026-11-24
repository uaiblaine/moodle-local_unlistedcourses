# Ignore the enrolment window of an enrol_conditional instance.
s/(function conditional_outcome.*?)if \(!self::inside_window\(\$instance, \$now\)\) \{/${1}if (false) {/s;
