# Drop the visitor and guest guard of the per-course next action.
s/if \(\$courseid == SITEID \|\| self::viewer_context\(\$courseid\) === null\) \{/if (\$courseid == SITEID) {/;
