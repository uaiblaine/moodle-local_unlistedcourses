# Stamp a core group membership with the component while the provider links no core_group: the
# test must read the plugin's sources and refuse the uncovered rows.
s/\z/\nfunction local_unlistedcourses_stamped_write_mutation(): void {\n    groups_add_member(1, 2, 'local_unlistedcourses', 0);\n}\n/;
