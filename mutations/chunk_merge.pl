# Keep only the last chunk's rows: the ids of every earlier chunk read as having no row.
s/\$merged \+= \$read\(\$chunk\);/\$merged = \$read(\$chunk);/;
