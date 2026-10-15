# Remove the unchanged-state short circuit: a save that changes nothing then hits
# the capability check, rewrites the row and fires an event.
s/if \(\$current === \$state\) \{/if (false) {/;
