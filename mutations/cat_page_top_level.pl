# Let an id that names no category through: core then hands id 0 over as the top level, and the
# category context the page asks for next fails on a database error instead of the refusal.
s/if \(\$categoryid <= 0\) \{/if (false) {/;
