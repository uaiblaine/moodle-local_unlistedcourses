# Ask the site course's instances in the batch like any other course's.
s/\$asked = array_values\(array_diff\(\$courseids, \[\(int\) SITEID\]\)\);/\$asked = \$courseids;/;
