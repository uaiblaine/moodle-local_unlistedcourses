# Ask is_enrolled() for every course, not only one core's own enrolment memo holds: the answers stay
# right, but every course no row ties the viewer to pays enrol_get_enrolment_end()'s statement.
s/return isset\(\$USER->enrol\['enrolled'\]\[\$courseid\]\) && is_enrolled\(/return is_enrolled(/;
