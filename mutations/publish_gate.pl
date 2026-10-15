# Turn the publish capability check into a lookup nobody reads: anyone may then
# publish or un-publish a course.
s/require_capability\(self::CAPABILITY_PUBLISH, \$context, \$userid\);/has_capability(self::CAPABILITY_PUBLISH, \$context, \$userid);/;
