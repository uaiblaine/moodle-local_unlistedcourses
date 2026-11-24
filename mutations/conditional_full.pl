# Ignore the places limit of an enrol_conditional instance, on every path.
s/if \(\$occupied >= \$places\) \{/if (false) {/;
