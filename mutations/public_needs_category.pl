# Drop the category visibility half of is_public(): a course inside a hidden
# category then serves anonymously.
s/return \$visiblecount === count\(\$ids\);/return true;/;
