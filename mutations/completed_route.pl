# Forget enrol_coursecompleted altogether: an unlisted course the viewer will be enrolled in on
# completing another one is hidden from them.
s/if \(\$instance->enrol === 'coursecompleted'\) \{/if (false) {/;
