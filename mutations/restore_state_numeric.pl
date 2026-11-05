# Accept an empty or non-numeric state: the int cast reads it as 0, listed, over
# the restore default.
s/if \(!preg_match\('\/\^-\?\\d\+\\z\/', \$raw\)\) \{/if (false) {/;
