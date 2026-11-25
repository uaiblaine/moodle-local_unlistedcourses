# Never tell a delegate that somebody who may publish has to list the category.
s/&& !category_discoverability::may_relist\(\$categoryid, \(int\) \$USER->id, \$activations\),/\&\& false,/;
