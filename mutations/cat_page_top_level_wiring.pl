# Take the id guard off the category page: core is asked about id 0 first.
s/^category_access::require_category_id\(\$id\);\n//m;
