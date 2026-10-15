# Remove the site-admin short circuit: an admin then has to satisfy a cohort, a
# role or the staff escape like anybody else.
s/if \(is_siteadmin\(\)\) \{/if (false) {/;
