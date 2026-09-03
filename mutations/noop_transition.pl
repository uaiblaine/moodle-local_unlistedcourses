# Remove the unchanged-state short circuit: re-submitting "public" from a frozen
# control then hits the capability check and fails the save. Note this reddens
# through an ERROR (the refused call throws before the event assertion runs),
# not a failed assertion - the sweep counts both.
s/if \(\$current === \$state\) \{/if (false) {/;
