# Ask the category capability at the category above the subcategory instead of at the subcategory.
s/\$context = \\core\\context\\coursecat::instance\(\$id\);/\$context = \\core\\context\\coursecat::instance(\$id)->get_parent_context();/;
