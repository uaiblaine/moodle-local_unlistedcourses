# Make the relationship primer write nothing. The answers stay correct - they are
# computed instead - so only the budget assertion sees it, which is the point: a
# primer that silently stopped priming would cost a statement per probed course.
s|self::\$related\[self::memo_key\(\$viewer, \(int\) \$courseid\)\] = true;|// Mutated: the primer writes nothing.|;
