# Let run() apply any state, public included.
s/if \(\$state === discoverability::STATE_DEFAULT \|\| !in_array\(\$state, discoverability::default_states\(\), true\)\) \{/if (false) {/;
