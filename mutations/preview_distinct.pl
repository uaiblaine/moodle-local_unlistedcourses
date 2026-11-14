# Count the union of the terms with its duplicates: a member of two cohorts, or a member who
# also holds a role, is then counted twice, and the headline overstates who still sees the
# category.
s/SELECT COUNT\(DISTINCT e\.userid\)/SELECT COUNT(e.userid)/;
