# Make the role term always hold: everybody then passes every unlisted category
# without holding a role anywhere near it.
s/return \(bool\) array_intersect\(\$ownpath, \$rolecats\);/return true;/;
