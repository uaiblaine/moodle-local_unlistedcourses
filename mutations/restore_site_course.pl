# Hand the site course's element to set_state(): a restore over the site home with its configuration
# overwritten then dies on the coding exception set_state() throws for the site course.
s/        if \(\(int\) \$this->task->get_courseid\(\) === \(int\) SITEID\) \{\n            return;\n        \}\n//;
