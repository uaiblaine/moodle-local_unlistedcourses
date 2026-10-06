# Let a row on a disabled instance count: a staff setting that closes a method then still enrols.
s/        if \(\(int\) \$row->instancestatus !== ENROL_INSTANCE_ENABLED\) \{\n            return \$none;\n        \}\n//;
