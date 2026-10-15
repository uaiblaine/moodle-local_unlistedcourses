# Match the role against the WHOLE path being answered instead of the unlisted
# category's own ancestors: a role at a category below it then unlocks it.
s/\$ownpath = self::prefix_to\(\$pathids, \$unlistedid\);/\$ownpath = \$pathids;/;
