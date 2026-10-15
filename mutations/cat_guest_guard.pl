# Neuter the visitor/guest fail-closed: a visitor or a guest then reaches the
# cohort and role terms and is admitted by whichever of them happens to match.
s/\$visitor = !isloggedin\(\) \|\| isguestuser\(\);/\$visitor = false;/;
