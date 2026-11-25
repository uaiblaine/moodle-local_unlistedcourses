# Ask the course capability at the category above the course instead of at the course:
# an override at the course is ignored and a role only at the course no longer counts.
s/\$context = \\core\\context\\course::instance\(\$courseid\);/\$context = \\core\\context\\course::instance(\$courseid)->get_parent_context();/;
