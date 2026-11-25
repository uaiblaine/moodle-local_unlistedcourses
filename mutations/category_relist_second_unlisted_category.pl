# Predict a subcategory as if no category above it were unlisted, not only the one listed again.
s/array_intersect\(array_diff\(\$ancestors, \[\$relisted \?\? 0\]\), /array_intersect((\$relisted === null ? \$ancestors : []), /;
