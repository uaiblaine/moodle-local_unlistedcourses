# Leave a suspended enrolment out of the lapsed ones: a suspended teacher is staff again.
s/(LAPSED_RELATIONSHIPS = \[\n)        self::RELATIONSHIP_SUSPENDED,\n/$1/;
