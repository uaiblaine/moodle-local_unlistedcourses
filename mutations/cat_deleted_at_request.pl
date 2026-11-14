# Bring back the old behaviour: the state row is dropped by the pre-delete callback, which core runs
# before it can still abort the deletion, so an aborted delete_full() leaves live categories that
# have lost their state.
s/\z/\nfunction local_unlistedcourses_pre_course_category_delete(\$category) {\n    \\local_unlistedcourses\\category_discoverability::on_category_deleted((int) \$category->id);\n}\n/;
