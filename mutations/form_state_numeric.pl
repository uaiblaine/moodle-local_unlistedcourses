# Accept any string as a number: the cast reads an empty or non-numeric element as 0, listed, over an
# unlisted creation default, and an update lists an unlisted course.
s/if \(!preg_match\('\/\^-\?\\d\+\\z\/', \$value\)\) \{/if (false) {/;
