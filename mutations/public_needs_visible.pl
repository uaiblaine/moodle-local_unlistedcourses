# Drop the course visibility half of is_public(): a hidden course then serves anonymously.
s/if \(!\$course \|\| !\$course->visible\) \{/if (!\$course) {/;
