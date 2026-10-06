# Neuter the visitor/guest guard: a guest then reaches the enrol plugins, and
# can_self_enrol($instance, false) skips its own guest check; the batch reads their rows.
s/return !isloggedin\(\) \|\| isguestuser\(\);/return false;/;
