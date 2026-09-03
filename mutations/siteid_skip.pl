# Let the migration write a state row for the site course, which set_state()
# refuses and access::eligible()'s frontpage exemption exists to survive.
s/if \(\$courseid == SITEID\) \{/if (false) {/;
