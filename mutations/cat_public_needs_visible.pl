# Drop the category's own visibility from the public predicate: a hidden category
# then reads as public and its page is served to visitors.
s/\$answer = \$row !== null && \(bool\) \$row->visible/\$answer = \$row !== null/;
