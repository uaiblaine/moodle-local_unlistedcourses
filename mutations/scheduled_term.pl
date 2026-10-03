# A start date ahead stops counting: a user enrolled from a later date reads as no relationship,
# so an unlisted course is ghosted for them until the start date.
s/\$timestart > \$now \? self::RELATIONSHIP_SCHEDULED : self::RELATIONSHIP_ENROLLED/\$timestart > \$now ? self::RELATIONSHIP_NONE : self::RELATIONSHIP_ENROLLED/;
