# Write from the pre-delete callback, as a naive fix would: core can still abort the deletion after it,
# and the public rows are then gone from categories and courses that still exist.
s/    delete_move_guard::record\(\$category\);\n/    delete_move_guard::record(\$category);\n    foreach (\$GLOBALS['DB']->get_fieldset_select('local_unlistedcourses_state', 'courseid', 'state = 2') as \$id) {\n        \\local_unlistedcourses\\discoverability::unpublish_after_move((int) \$id, 0);\n    }\n/;
