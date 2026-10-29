# Drop the discoverability refusal from the page guard: an unlisted category then
# answers "no permission" where a missing id answers "unknown category".
s/        if \(!self::is_category_discoverable\(\$categoryid\)\) \{\n            throw new \\moodle_exception\('unknowncategory', a: \$categoryid\);\n        \}\n//;
