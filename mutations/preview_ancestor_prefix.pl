# Read an unlisted ancestor's role term over the whole path instead of the path up to it: a role
# held below the ancestor then counts as opening it, which the predicate never grants.
s/get_in_or_equal\(self::prefix_to\(\$pathids, \$categoryid\), SQL_PARAMS_NAMED/get_in_or_equal(\$pathids, SQL_PARAMS_NAMED/;
