# Read a waiting-list row as a plain application: nothing tells the waiting list apart again.
s/\$type = \(\$status === self::APPLY_WAITLIST\) \? self::RELATIONSHIP_WAITLISTED : self::RELATIONSHIP_PENDING;/\$type = self::RELATIONSHIP_PENDING;/;
