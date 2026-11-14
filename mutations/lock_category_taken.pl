# Write without the category's lock: a lock that belongs to no factory stands in for it.
s/\$lock = state_lock::category\(\$categoryid\);/\$lock = new \\core\\lock\\lock('none', null);/;
