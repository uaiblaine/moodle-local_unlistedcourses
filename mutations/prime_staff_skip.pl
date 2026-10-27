# Read the viewer's rows for staff too: a manager's probe pays a statement it never uses.
s/if \(\$context && !self::is_staff\(\$context\)\) \{/if (\$context) {/;
