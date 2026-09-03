# Ask what the creator holds NOW instead of what they will hold once the course
# exists: a course creator then loses the control on a new course.
s/return guess_if_creator_will_have_course_capability\(\$capability, \$context\);/return has_capability(\$capability, \$context);/;
