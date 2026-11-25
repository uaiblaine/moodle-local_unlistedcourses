# Read a hidden category above a subcategory as visible: a subcategory under it is then activated.
s/if \(empty\(\$visible\[\$ancestorid\]\) \|\| !\$visible\[\$ancestorid\]->visible\) \{/if (empty(\$visible[\$ancestorid])) {/;
