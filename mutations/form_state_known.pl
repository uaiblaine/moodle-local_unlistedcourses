# Hand set_state() a number that names no state: it throws after create_course() has inserted the course.
s/ \|\| !in_array\(\$value, discoverability::states\(\), true\)//;
