# Forget enrol_autoenrol altogether: an unlisted course it would enrol the viewer in is hidden.
s/if \(\$instance->enrol === 'autoenrol' && is_callable/if (false && is_callable/;
