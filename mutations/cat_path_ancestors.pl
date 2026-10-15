# Look at the category's own id instead of its whole path: an unlisted ancestor
# then withholds nothing, and its listed children are named to everybody.
s/array_intersect\(\$pathids, \$unlisted\)/array_intersect([\$categoryid], \$unlisted)/;
