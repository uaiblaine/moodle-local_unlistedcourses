# Leave this process's discoverability memos computed from the old rows.
s/        access::reset_caches\(\);\n        if \(\$progress\) \{\n            \$progress->end_progress\(\);/        if (\$progress) {\n            \$progress->end_progress();/;
