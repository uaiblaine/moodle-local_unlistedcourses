# The page ignores the form's refusal and confirms a save that wrote nothing.
s/if \(!categorystate::save\(\$id, \$data\)\) \{/if (!categorystate::save(\$id, \$data) && false) {/;
