# Never freeze the control: an editor who may not publish then gets a live
# select on a public course.
s/if \(\$current === discoverability::STATE_PUBLIC && !\$canpublish\) \{/if (false) {/;
