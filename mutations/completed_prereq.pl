# Drop the prerequisite tie: an unlisted course whose enrol_coursecompleted instance is in its window
# is named to every logged-in user, whatever their relation to the course it waits for.
s/if \(!\$prerequisite \|\| !is_enrolled\(\$prerequisite, \$USER, '', true\)\) \{/if (!\$prerequisite) {/;
