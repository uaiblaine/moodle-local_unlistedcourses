# Take the guard off the category page: core hands the unlisted category to
# anybody logged in, and the capability check then tells it apart from a missing id.
s/^category_access::require_discoverable\(\$id, category_discoverability::CAPABILITY_MANAGE\);\n//m;
