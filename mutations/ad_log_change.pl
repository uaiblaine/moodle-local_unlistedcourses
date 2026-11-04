# Write the rows without firing the event set_state() fires.
s/                self::log_change\(\$courseid, \$state, \$userid\);\n//;
