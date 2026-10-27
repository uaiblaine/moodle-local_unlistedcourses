# Read self enrolment facts for courses the enrolment state already keeps.
s/(if \(in_array\(self::\$enrolmentstate\[\$key\]\['type'\], self::DISCOVERABLE_RELATIONSHIPS, true\)\) \{\n)                continue;\n/$1                \$keep = true;\n/;
