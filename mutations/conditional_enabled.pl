# Let the enrol_conditional rule admit through a disabled instance.
s/(function conditional_outcome.*?)if \(\(int\) \$instance->status !== ENROL_INSTANCE_ENABLED\) \{/${1}if (false) {/s;
