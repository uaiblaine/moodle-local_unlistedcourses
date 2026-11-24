# Ignore new enrolments off on an enrol_conditional instance.
s/(function conditional_outcome.*?)if \(\(int\) \$instance->customint6 === 0\) \{/${1}if (false) {/s;
