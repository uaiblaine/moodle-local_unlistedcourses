# Count role assignments instead of people: somebody with roles at two categories on the path
# becomes two role holders.
s/SELECT COUNT\(DISTINCT ra\.userid\)/SELECT COUNT(ra.userid)/;
