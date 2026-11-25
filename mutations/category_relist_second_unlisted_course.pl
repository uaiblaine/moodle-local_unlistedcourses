# Predict a course as if no category on its path were unlisted, not only the one listed again.
s/array_intersect\(array_diff\(\$ids, \[\$relisted \?\? 0\]\), /array_intersect((\$relisted === null ? \$ids : []), /;
