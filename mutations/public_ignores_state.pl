# Make is_public() skip the state: every visible course then reads as public.
s/if \(self::get_state\(\$courseid\) !== self::STATE_PUBLIC\) \{/if (self::get_state(\$courseid) === -1) {/;
