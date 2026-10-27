# Remove the staff escape: managers and admins then fall through to the cohort
# gate and lose the courses they administer.
s/return is_viewing\(\$context\) \|\| has_capability\('moodle\/course:viewhiddencourses', \$context\);/return false;/;
