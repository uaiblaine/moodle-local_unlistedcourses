# Take the site course for a prerequisite: is_enrolled() is true there for every logged-in user, so
# the per-course path promises the course to everybody while the batch, reading real rows, does not.
s/            if \(\(int\) \$instance->customint1 === \(int\) SITEID\) \{\n                return null;\n            \}\n//;
