# Keep every course the anonymous filter is handed, whatever its own state says: a
# listed or unlisted course inside a public category is then served to visitors.
s/if \(!\(\$answers\[\(int\) \$course->id\] \?\? false\)\) \{/if (false) {/;
