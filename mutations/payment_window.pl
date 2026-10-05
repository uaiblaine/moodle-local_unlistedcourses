# Ignore the enrolment window of fee and paypal: a course stays discoverable through a payment
# page that offers nothing before the window opens or after it closes.
s/                    && self::inside_window\(\$instance, \$now\)\n//;
