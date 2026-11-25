# Predict for a category that is not unlisted as if it were: rows already public are named.
s/if \(self::get_state\(\$categoryid\) !== self::STATE_UNLISTED\) \{\n\s+return \$none;\n\s+\}\n//;
