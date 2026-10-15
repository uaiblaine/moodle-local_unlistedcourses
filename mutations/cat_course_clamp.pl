# Remove the category term from the listing filter: a course inside an unlisted
# category is then listed to everybody, and unlisting a category withholds
# nothing at all.
s/if \(!\(\$catanswers\[\$categoryid\] \?\? true\) && !self::has_course_relationship\(\$courseid\)\) \{/if (false) {/;
