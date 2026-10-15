# Offer the public option to everyone who sees the form.
s/if \(\$canpublish \|\| \$current === discoverability::STATE_PUBLIC\) \{/if (true) {/;
