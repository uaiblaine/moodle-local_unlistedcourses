# Count role holders at this category's own context only. A role at a category
# ABOVE this one opens it just as much, so the preview would under-report who
# still sees the category - and could claim nobody does.
s|self::count_role_holders\(\$pathids\)|self::count_role_holders([\$categoryid])|;
