# Read a hidden course as visible in the composition: a hidden public course is then activated.
s/if \(!\$course \|\| !\$course->visible\) \{/if (!\$course) {/;
