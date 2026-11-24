# Read no facts for enrol_conditional instances in the listing probe: each costs its own statements.
s/\} else if \(\$instance->enrol === 'conditional'\) \{/} else if (false) {/;
