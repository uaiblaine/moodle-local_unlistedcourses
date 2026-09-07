# Drop the intersection with the unlisted categories ABOVE this one, leaving the
# headline count as this category's own two terms. The predicate ANDs over every
# effectively-unlisted category on the path, so the preview would then name people
# the ancestor withholds the whole subtree from - and would stay silent about
# "visible to nobody" on the one arrangement where nobody is the true answer.
s|\$eligible = array_intersect\(\$eligible, self::eligible_users\(\$ancestorid, \$pathids\)\);|// Mutated: the ancestor's own term is not applied.|;
