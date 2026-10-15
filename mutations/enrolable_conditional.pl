# Stop counting the course completed promise as a way in: the next course of a learner's path is hidden.
s/private const ENROLABLE_ACTIONS = \[self::NEXT_OPEN, self::NEXT_GUEST, self::NEXT_CONDITIONAL\];/private const ENROLABLE_ACTIONS = [self::NEXT_OPEN, self::NEXT_GUEST];/;
