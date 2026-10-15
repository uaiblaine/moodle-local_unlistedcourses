# Drop the waiting list from the allow-list: an applicant on it loses the course they applied to.
s/(        self::RELATIONSHIP_PENDING,\n)        self::RELATIONSHIP_WAITLISTED,\n(    \];)/$1$2/;
