# Let a course that is not stored public into the composition: an unlisted course is then activated.
s/if \(\$states\[\$courseid\] !== self::STATE_PUBLIC\) \{/if (false) {/;
