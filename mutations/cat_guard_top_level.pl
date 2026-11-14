# Drop the id guard from the page guard: require_discoverable(0) then fails on the missing category
# context instead of refusing the id as unknown.
s/        self::require_category_id\(\$categoryid\);\n//;
