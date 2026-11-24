# Prime every enrol_conditional instance as empty.
s/'taken' => \(int\) \(\$taken\[\$conditionalid\] \?\? 0\),/'taken' => 0,/;
