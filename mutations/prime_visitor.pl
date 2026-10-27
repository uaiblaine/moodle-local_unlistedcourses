# Let the probe read for a visitor and the guest account, whom the rule refuses anyway.
s/if \(!\$courseids \|\| self::refuses_viewer\(\)\) \{/if (!\$courseids) {/;
