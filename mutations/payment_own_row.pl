# Offer fee and paypal to a viewer who already holds a row on the instance; their enrolment page
# shows such a viewer no payment button.
s/!self::holds_enrolment\(\$instance\)\n                    && self::inside_window/self::inside_window/;
