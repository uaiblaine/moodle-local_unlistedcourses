# Decide on the lock-free read: a row committed between that read and the lock is not seen.
s/\[, \$current\] = self::read_row\(\$categoryid\);/[\$row, \$current] = self::read_row(\$categoryid);/;
s/            \/\/ Read again under the lock[^\n]*\n            \[\$row, \$current\] = self::read_row\(\$categoryid\);\n//;
