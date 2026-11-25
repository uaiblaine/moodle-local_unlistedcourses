# Read a hidden category on a course path as visible: a course under it is then activated.
s/if \(empty\(\$visible\[\$pathid\]\) \|\| !\$visible\[\$pathid\]->visible\) \{/if (empty(\$visible[\$pathid])) {/;
