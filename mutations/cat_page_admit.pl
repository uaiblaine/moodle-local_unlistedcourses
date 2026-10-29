# Drop the admitting capability from the page guard: a holder of the manage
# capability at the system context, who has no relationship with the category,
# is then refused the page the capability grants.
s/        if \(has_capability\(\$unless, \\core\\context\\coursecat::instance\(\$categoryid\)\)\) \{\n            return;\n        \}\n//;
