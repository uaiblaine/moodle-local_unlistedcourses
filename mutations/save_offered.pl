# Accept the element from anybody: a caller that copies its own input into
# update_course() or create_course() then unlists or lists for a user the form refuses.
s/        if \(\$state !== null && !self::offered\(\(int\) \$data->id, \$isnewcourse\)\) \{\n            \$state = null;\n        \}\n//;
