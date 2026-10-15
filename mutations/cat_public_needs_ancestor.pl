# Drop the whole path term from the public predicate: a category under a hidden or
# unlisted ancestor then reads as public, and "public" outranks the tree above it.
s/\s+&& self::path_admits\(\$categoryid, \(string\) \$row->path, \$visible\);/;/;
