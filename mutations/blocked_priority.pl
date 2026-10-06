# Report the least useful reason of several refusals.
s/return \$order\[\$candidate\] < \$order\[\$current\] \? \$candidate : \$current;/return \$order[\$candidate] > \$order[\$current] ? \$candidate : \$current;/;
