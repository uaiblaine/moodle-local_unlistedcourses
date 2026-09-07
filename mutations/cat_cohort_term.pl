# Make the cohort term always hold: everybody then passes every unlisted
# category without belonging to any cohort at it.
s/return in_array\(\$unlistedid, \$cohortcats, true\);/return true;/;
