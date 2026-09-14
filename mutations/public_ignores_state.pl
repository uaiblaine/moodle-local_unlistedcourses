# Make the public predicate skip the state: every visible course then reads as public.
s/if \(\$states\[\$courseid\] !== self::STATE_PUBLIC\) \{/if (false) {/;
