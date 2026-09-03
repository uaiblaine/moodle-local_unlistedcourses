# Let a state value this version does not know reach set_state(): a tampered or
# newer backup then aborts the restore with a coding exception instead of being
# ignored.
s/if \(!in_array\(\$state, discoverability::states\(\), true\)\) \{/if (false) {/;
