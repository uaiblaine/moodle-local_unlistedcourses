# Demote every recorded subcategory without asking again after the move.
s/\$categories = self::activated\(category_discoverability::are_public\(\$recorded\['categories'\]\)\);/\$categories = \$recorded['categories'];/;
