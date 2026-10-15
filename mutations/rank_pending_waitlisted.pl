# Rank the waiting list above a fresh application.
s/self::RELATIONSHIP_PENDING => 4,\n        self::RELATIONSHIP_WAITLISTED => 3,/self::RELATIONSHIP_PENDING => 3,\n        self::RELATIONSHIP_WAITLISTED => 4,/;
