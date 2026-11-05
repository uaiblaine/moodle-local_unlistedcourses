# Leave the capability of the user the task records to the caller.
s/        require_capability\('moodle\/site:config', \\core\\context\\system::instance\(\), \$userid\);\n//;
