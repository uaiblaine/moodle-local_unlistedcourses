# Loosen the strict comparison on can_self_enrol(): it returns true, an error
# string, or null, so anything but === true admits the refusal messages too.
s/\$plugin->can_self_enrol\(\$instance, false\) === true/\$plugin->can_self_enrol(\$instance, false) !== false/;
