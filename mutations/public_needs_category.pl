# Drop the category visibility half of the public predicate: a course inside a hidden
# category then serves anonymously.
s/if \(empty\(\$visible\[\$pathid\]\) \|\| !\$visible\[\$pathid\]->visible\) \{/if (false) {/;
