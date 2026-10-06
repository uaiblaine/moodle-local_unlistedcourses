# Read a suspended row as nothing: the learner whose enrolment a teacher suspended is told nothing.
s/return \['type' => self::RELATIONSHIP_SUSPENDED, 'startsat' => \$timestart, 'endsat' => \$timeend\];/return \$none;/;
