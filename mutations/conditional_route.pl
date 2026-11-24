# Forget enrol_conditional on the per-course path: an unlisted course the viewer meets the conditions of is hidden.
s/if \(\$instance->enrol === 'conditional' && is_callable\(\[\$plugin, 'evaluate_conditions'\]\)\) \{/if (false) {/;
