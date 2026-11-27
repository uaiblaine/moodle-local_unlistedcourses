# Ignore the capability held above the course: a category teacher with a suspended enrolment there is lapsed.
s/        if \(\$parent && has_capability\(self::STAFF_CAPABILITY, \$parent\)\) \{\n            return true;\n        \}\n//;
