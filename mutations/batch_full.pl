# Let the batch ignore the places limit of enrol_self and enrol_autoenrol.
s/\(int\) \(\$taken\[\$id\]->total \?\? 0\),/0,/;
