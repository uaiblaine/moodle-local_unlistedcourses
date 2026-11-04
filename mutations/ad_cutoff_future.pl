# Without a record, accept a cutoff later than now as well.
s/return \$cutoff <= \\core\\di::get\(\\core\\clock::class\)->time\(\);/return true;/;
