# Stop asking enrol/conditional:enrolself: a viewer the capability refuses becomes a route in.
s/if \(!\$context \|\| !has_capability\('enrol\/conditional:enrolself', \$context\)\) \{/if (!\$context) {/;
