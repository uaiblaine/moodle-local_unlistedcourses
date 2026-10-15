# Stop refusing an apply instance on which the viewer already holds a row: an approved enrolment
# whose end date passed keeps the course discoverable through an application nobody will take.
s/            if \(self::holds_enrolment\(\$instance\)\) \{\n                return self::outcome\(\$instance, self::NEXT_BLOCKED, self::BLOCKED_OWN_ROW\);\n            \}\n//;
