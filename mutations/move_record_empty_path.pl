# Record under a category with no path: its bound LIKE is '/%', and every public row of the site is
# recorded under it and demoted when it settles.
s/        if \(\(string\) \$category->path === ''\) \{\n            return;\n        \}\n//;
