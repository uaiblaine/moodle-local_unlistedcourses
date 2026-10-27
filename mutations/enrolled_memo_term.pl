# Drop the is_enrolled() term: an unexpired entry of core's enrolment memo no longer keeps the course.
s/return isset\(\$USER->enrol\['enrolled'\]\[\$courseid\]\) && is_enrolled\(\$context, \$USER, '', true\);/return false;/;
