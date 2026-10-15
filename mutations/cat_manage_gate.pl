# Turn the manage capability check into a lookup nobody reads: anyone may then
# unlist or re-list a category.
s/require_capability\(self::CAPABILITY_MANAGE, \$context, \$userid\);/has_capability(self::CAPABILITY_MANAGE, \$context, \$userid);/;
