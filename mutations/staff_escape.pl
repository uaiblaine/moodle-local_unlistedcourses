# Remove the staff escape: managers and admins then fall through to the cohort
# gate and lose the courses they administer.
s/(    private static function is_staff\(\\core\\context\\course \$context\): bool \{\n)/$1        return false;\n/;
