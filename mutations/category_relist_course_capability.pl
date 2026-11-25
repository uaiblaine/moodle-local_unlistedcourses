# Ask nothing of an activated course: a delegate then publishes a retained public course.
s/if \(!has_capability\(discoverability::CAPABILITY_PUBLISH, \$context, \$userid\)\) \{/if (false) {/;
