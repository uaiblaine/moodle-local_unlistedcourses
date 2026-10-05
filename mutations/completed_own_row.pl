# Keep a course completed instance a route for a viewer who already holds a row on it.
s/if \(!self::holds_enrolment\(\$instance\) && self::inside_window\(\$instance, \$now\)\) \{/if (self::inside_window(\$instance, \$now)) {/;
