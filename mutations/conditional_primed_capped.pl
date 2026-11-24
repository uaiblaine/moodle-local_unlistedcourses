# Leave capped enrol_conditional instances out of the probe's grouped count.
s/(its customint5 means nothing\.\n            )if \(\$instance->customint3 > 0\) \{/${1}if (false) {/;
