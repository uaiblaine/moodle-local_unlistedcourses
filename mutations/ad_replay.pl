# Give up on a chunk whose transaction failed instead of replaying it course by course.
s/(                \$ignored = null;\n            \}\n        \}\n)/$1        return [];\n/;
