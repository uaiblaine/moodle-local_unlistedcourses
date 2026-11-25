# Predict from the course states this request memoised, not from the rows under the lock.
s/\n\s+discoverability::reset_caches\(\);\n(\s+self::require_relist_allowed)/\n$1/;
