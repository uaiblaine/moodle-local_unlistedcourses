# Stop requiring the capability in the course: whoever's enrolment has not lapsed is staff of every course.
s/        if \(!has_capability\(self::STAFF_CAPABILITY, \$context\)\) \{\n            return false;\n        \}\n//;
