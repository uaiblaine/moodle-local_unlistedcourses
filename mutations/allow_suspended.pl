# Let a suspended enrolment keep an unlisted course, as "anything but none" did for no type before.
s/(        self::RELATIONSHIP_PENDING,\n        self::RELATIONSHIP_WAITLISTED,\n)(    \];)/$1        self::RELATIONSHIP_SUSPENDED,\n$2/;
