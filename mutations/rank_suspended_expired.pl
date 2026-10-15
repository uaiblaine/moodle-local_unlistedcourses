# Rank an ended row above a suspended one.
s/self::RELATIONSHIP_SUSPENDED => 2,\n        self::RELATIONSHIP_EXPIRED => 1,/self::RELATIONSHIP_SUSPENDED => 1,\n        self::RELATIONSHIP_EXPIRED => 2,/;
