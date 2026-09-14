# Make a category whose own row is not there answer TRUE instead of failing closed:
# a dangling state row, or an id a visitor invented, then reads as public.
s/\$answer = \$row !== null && \(bool\) \$row->visible/\$answer = \$row === null || (bool) \$row->visible/;
