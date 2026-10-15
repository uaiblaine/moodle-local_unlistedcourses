# Ignore the enrolment window of fee and paypal: a course stays discoverable through a payment
# page that offers nothing before the window opens or after it closes.
s/        if \(!self::inside_window\(\$instance, \$now\)\) \{\n            return self::outcome\(\$instance, self::NEXT_BLOCKED, self::BLOCKED_WINDOW\);\n        \}\n(        if \(abs\(self::payment_cost)/$1/;
