# Leave a row that counts for nothing (a disabled method) out of the lapsed ones: such a teacher is staff again.
s/(LAPSED_RELATIONSHIPS = \[\n        self::RELATIONSHIP_SUSPENDED,\n        self::RELATIONSHIP_EXPIRED,\n)        self::RELATIONSHIP_NONE,\n/$1/;
