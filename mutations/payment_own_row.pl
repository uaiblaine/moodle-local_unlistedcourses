# Offer fee and paypal to a viewer who already holds a row on the instance; their enrolment page
# shows such a viewer no payment button.
s/        if \(\$holdsrow\) \{\n            return self::outcome\(\$instance, self::NEXT_BLOCKED, self::BLOCKED_OWN_ROW\);\n        \}\n(        if \(!self::inside_window\(\$instance, \$now\)\) \{\n            return self::outcome\(\$instance, self::NEXT_BLOCKED, self::BLOCKED_WINDOW\);\n        \}\n        if \(abs\(self::payment_cost)/$1/;
