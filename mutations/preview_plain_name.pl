# Export the cohort name in the escaped spelling. The template puts it in a
# double stash, so an ampersand in a cohort name is then escaped twice and the
# preview names a cohort nobody created.
#
# Anchored on the whole assignment, not on the option alone: a second
# format_string() added ABOVE this one would otherwise steal the substitution,
# and the gate would quietly start breaking something else.
s|'name' => format_string\(\$cohort->name, true, \['context' => \$this->context, 'escape' => false\]\),|'name' => format_string(\$cohort->name, true, ['context' => \$this->context, 'escape' => true]),|;
