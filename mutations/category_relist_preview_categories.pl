# Count no subcategory in the preview.
s/\$relistcategories = count\(\$activations\[.categories.\]\);/\$relistcategories = 0;/;
