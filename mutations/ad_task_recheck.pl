# Apply the queued state even when the default has changed since it was queued.
s/if \(\$state !== default_backfill::state_to_apply\(\)\) \{/if (false) {/;
