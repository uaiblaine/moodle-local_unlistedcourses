# Ask the site course's instances like any other course's.
s/if \(\$courseid == SITEID \|\| self::viewer_context\(\$courseid\) === null\) \{/if (self::viewer_context(\$courseid) === null) {/;
