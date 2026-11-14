# Remove the unchanged-state short circuit, both copies (the lock-free read and the one under
# the lock): a save that changes nothing then hits
# the capability check, rewrites the row and fires an event.
s/if \(\$current === \$state\) \{/if (false) {/g;
