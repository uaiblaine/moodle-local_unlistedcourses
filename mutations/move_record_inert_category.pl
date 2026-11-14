# Record every public subcategory, not only the inert ones: one that was public before the deletion is
# then taken back to listed too.
s/'categories' => self::inert\(category_discoverability::are_public\(array_map\('intval', \$categoryids\)\)\),/'categories' => array_map('intval', \$categoryids),/;
