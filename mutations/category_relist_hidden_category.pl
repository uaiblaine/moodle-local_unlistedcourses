# Read a hidden subcategory as visible: a hidden public subcategory is then activated.
s/\$answer = \$row !== null && \(bool\) \$row->visible/\$answer = \$row !== null/;
