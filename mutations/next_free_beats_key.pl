# Let the last guest instance decide: a keyed one read after a free one hides the free access.
s/if \(\$guest !== self::GUEST_FREE\) \{/if (true) {/;
