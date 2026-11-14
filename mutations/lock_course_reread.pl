# Decide on the lock-free read: the row a writer committed between that read and the lock is not
# seen, so its insert breaks the unique key or its delete removes a public row unchecked.
s/\[, \$current\] = self::read_row\(\$courseid\);/[\$row, \$current] = self::read_row(\$courseid);/;
s/            \/\/ Read again under the lock[^\n]*\n            \[\$row, \$current\] = self::read_row\(\$courseid\);\n//;
