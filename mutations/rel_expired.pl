# Read an ended row as nothing: the learner whose enrolment ended is not told when.
s/(if \(\$timeend !== 0 && \$timeend <= \$now\) \{\n            )return \['type' => self::RELATIONSHIP_EXPIRED, 'startsat' => \$timestart, 'endsat' => \$timeend\];/$1return \$none;/;
