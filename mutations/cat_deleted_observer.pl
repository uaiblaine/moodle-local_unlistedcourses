# The observer stops dropping the state row: a deleted category, whether its contents went with it
# or were moved, leaves its row behind.
s/category_discoverability::on_category_deleted\(\$categoryid\);//;
