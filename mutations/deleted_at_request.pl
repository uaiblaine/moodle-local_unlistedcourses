# Bring back the old behaviour: the state row is dropped when the deletion is requested (core runs
# the pre_course_delete callbacks then and never again), not when core has deleted the course.
s/\z/\nfunction local_unlistedcourses_pre_course_delete(\$course) {\n    \\local_unlistedcourses\\discoverability::on_course_deleted((int) \$course->id);\n}\n/;
