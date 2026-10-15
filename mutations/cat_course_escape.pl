# Remove the enrolled/pending/staff escape from the category term: everyone
# working in a course loses it from their own listings the moment the category
# above it is unlisted.
s/ && !self::has_course_relationship\(\$courseid\)\) \{/) {/;
