# Predict from the unlisted ids this request memoised, not from the rows under the lock.
s/\n\s+self::reset_caches\(\);\n(\s+discoverability::reset_caches\(\);\n\s+self::require_relist_allowed)/\n$1/;
