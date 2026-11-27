# Leave an ended enrolment out of the lapsed ones: a teacher whose enrolment ended is staff again.
s/(LAPSED_RELATIONSHIPS = \[\n        self::RELATIONSHIP_SUSPENDED,\n)        self::RELATIONSHIP_EXPIRED,\n/$1/;
