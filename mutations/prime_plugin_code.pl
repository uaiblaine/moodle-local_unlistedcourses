# Keep instances of a plugin whose code is absent, which enrol_get_instances() drops.
s/ \|\| !file_exists\("\$CFG->dirroot\/enrol\/\$record->enrol\/lib.php"\)\) \{/) {/;
