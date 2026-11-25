# Let a subcategory that is not stored public into the composition: an unlisted one is then activated.
s/if \(\$states\[\$categoryid\] !== self::STATE_PUBLIC\) \{/if (false) {/;
