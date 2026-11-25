# Ask nothing of an activated subcategory: a delegate then publishes a retained public subcategory.
s/if \(!has_capability\(self::CAPABILITY_PUBLISH, \$context, \$userid\)\) \{/if (false) {/;
