# Turn the publish capability check into a lookup nobody reads: anyone holding the
# manage capability may then publish or un-publish a category.
s/require_capability\(self::CAPABILITY_PUBLISH, \$context, \$userid\);/has_capability(self::CAPABILITY_PUBLISH, \$context, \$userid);/;
