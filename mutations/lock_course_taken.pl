# Write without the course's lock: a lock that belongs to no factory stands in for it, so two
# writers of one course read, decide and write side by side again.
s/\$lock = state_lock::course\(\$courseid\);/\$lock = new \\core\\lock\\lock('none', null);/;
