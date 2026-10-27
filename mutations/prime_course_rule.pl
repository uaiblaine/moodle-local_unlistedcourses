# Leave are_courses_discoverable() unprimed: only filter_courses() reads in bulk.
s/        self::prime_probe\(array_keys\(array_filter\(\n            \$states,\n            fn\(int \$state\): bool => \$state === discoverability::STATE_UNLISTED\n        \)\)\);\n//;
